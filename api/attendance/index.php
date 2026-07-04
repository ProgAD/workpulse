<?php
// attendance.php?action=punch_in   (POST) -> aaj ki entry, check_in laga do
// attendance.php?action=punch_out  (POST) -> check_out + hours count
// attendance.php?action=today      (GET)  -> aaj ka apna status
// attendance.php?action=mine       (GET)  -> mahine ka apna record (?month=&year=)
// attendance.php                    (GET)  -> admin, ek din ki puri company (?date=)
// attendance.php?action=regularize (POST) -> "punch out bhul gaya" request
// attendance.php?action=regularizations (GET)  -> admin, pending requests
// attendance.php?action=review     (POST) -> admin, request approve/reject

require_once __DIR__ . '/../bootstrap.php';

// din/time company ke timezone me chahiye, warna raat 12 baje ke aaspaas date galat aa jati hai
$tzStmt = db()->prepare("SELECT c.timezone FROM companies c JOIN users u ON u.company_id = c.id WHERE u.id = ?");
$me0 = current_user();
if ($me0) {
    $tzStmt->execute([$me0['id']]);
    date_default_timezone_set($tzStmt->fetchColumn() ?: 'Asia/Kolkata');
}

// user ki assign ki hui shift (koi bhi na ho to null - phir default 8/4 ghante maan lenge)
function my_shift(int $userId): ?array
{
    $stmt = db()->prepare(
        "SELECT s.* FROM employee_profiles p JOIN shifts s ON s.id = p.shift_id WHERE p.user_id = ?"
    );
    $stmt->execute([$userId]);
    return $stmt->fetch() ?: null;
}

// shift start + grace se late ka pata chalta hai
function marked_late(string $checkIn, ?array $shift): int
{
    if (!$shift || empty($shift['start_time'])) return 0;
    $deadline = strtotime(substr($checkIn, 0, 10) . ' ' . $shift['start_time']) + (int)$shift['grace_mins'] * 60;
    return strtotime($checkIn) > $deadline ? 1 : 0;
}

// kitne minute kaam hua uspe present / half day decide hota hai
function day_status(int $workMins, ?array $shift): string
{
    $full = $shift ? (float)$shift['full_day_hours'] * 60 : 480;
    $half = $shift ? (float)$shift['half_day_hours'] * 60 : 240;
    return $workMins >= $full ? 'present' : 'half_day';
}

if ($ACTION === 'punch_in' && $METHOD === 'POST') {
    $me = require_auth();
    $today = date('Y-m-d');
    $now   = date('Y-m-d H:i:s');
    $shift = my_shift($me['id']);
    $late  = marked_late($now, $shift);

    $in  = body();
    $lat = $in['lat'] ?? null;
    $lng = $in['lng'] ?? null;

    $stmt = db()->prepare("SELECT id, check_in FROM attendance WHERE user_id = ? AND att_date = ?");
    $stmt->execute([$me['id'], $today]);
    $row = $stmt->fetch();

    // ek hi check_in per din, dobara mat lagne do
    if ($row && $row['check_in']) fail('You already punched in today', 409);

    if ($row) {
        // row pehle se thi (leave/holiday marker) - usi ko present bana do
        db()->prepare(
            "UPDATE attendance SET check_in = ?, status = 'present', is_late = ?, shift_id = ?, source = 'web' WHERE id = ?"
        )->execute([$now, $late, $shift['id'] ?? null, $row['id']]);
    } else {
        db()->prepare(
            "INSERT INTO attendance (user_id, att_date, shift_id, status, check_in, is_late, source)
             VALUES (?, ?, ?, 'present', ?, ?, 'web')"
        )->execute([$me['id'], $today, $shift['id'] ?? null, $now, $late]);
    }

    $attId = $row ? (int)$row['id'] : (int)db()->lastInsertId();
    // lat/lng abhi schema me store nahi karte, bas note kar lete hai audit me
    audit('punch_in', 'attendance', $attId, null, ['at' => $now, 'lat' => $lat, 'lng' => $lng]);
    ok(['check_in' => $now, 'is_late' => $late], $late ? 'Punched in (late)' : 'Punched in');
}

if ($ACTION === 'punch_out' && $METHOD === 'POST') {
    $me = require_auth();
    $today = date('Y-m-d');
    $now   = date('Y-m-d H:i:s');

    $stmt = db()->prepare("SELECT * FROM attendance WHERE user_id = ? AND att_date = ?");
    $stmt->execute([$me['id'], $today]);
    $row = $stmt->fetch();

    if (!$row || !$row['check_in']) fail('Punch in first', 409);
    if ($row['check_out']) fail('You already punched out today', 409);

    $workMins = (int) round((strtotime($now) - strtotime($row['check_in'])) / 60);
    $shift = my_shift($me['id']);
    $status = day_status($workMins, $shift);

    db()->prepare("UPDATE attendance SET check_out = ?, work_mins = ?, status = ? WHERE id = ?")
        ->execute([$now, $workMins, $status, $row['id']]);

    audit('punch_out', 'attendance', (int)$row['id']);
    ok(['check_out' => $now, 'work_mins' => $workMins, 'status' => $status], 'Punched out');
}

if ($ACTION === 'today' && $METHOD === 'GET') {
    $me = require_auth();
    $stmt = db()->prepare("SELECT * FROM attendance WHERE user_id = ? AND att_date = ?");
    $stmt->execute([$me['id'], date('Y-m-d')]);
    $row = $stmt->fetch() ?: null;

    ok([
        'date'        => date('Y-m-d'),
        'server_time' => date('Y-m-d H:i:s'),
        'attendance'  => $row,
        // frontend ko button toggle karne ke liye
        'can_punch_in'  => !$row || !$row['check_in'],
        'can_punch_out' => $row && $row['check_in'] && !$row['check_out'],
    ]);
}

if ($ACTION === 'mine' && $METHOD === 'GET') {
    $me    = require_auth();
    $month = (int)($_GET['month'] ?? date('n'));
    $year  = (int)($_GET['year'] ?? date('Y'));

    $stmt = db()->prepare(
        "SELECT att_date, status, check_in, check_out, work_mins, is_late, is_regularized
         FROM attendance
         WHERE user_id = ? AND MONTH(att_date) = ? AND YEAR(att_date) = ?
         ORDER BY att_date"
    );
    $stmt->execute([$me['id'], $month, $year]);
    $rows = $stmt->fetchAll();

    // upar dikhane ke liye chhota summary
    $summary = ['present' => 0, 'half_day' => 0, 'absent' => 0, 'on_leave' => 0, 'late' => 0];
    foreach ($rows as $r) {
        if (isset($summary[$r['status']])) $summary[$r['status']]++;
        if ($r['is_late']) $summary['late']++;
    }

    ok(['month' => $month, 'year' => $year, 'days' => $rows, 'summary' => $summary]);
}

if ($ACTION === 'regularize' && $METHOD === 'POST') {
    $me = require_auth();
    $in = body();
    require_fields($in, ['att_date', 'reason']);

    db()->prepare(
        "INSERT INTO regularization_requests (user_id, att_date, req_check_in, req_check_out, reason)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([
        $me['id'], $in['att_date'],
        $in['req_check_in'] ?? null, $in['req_check_out'] ?? null,
        trim($in['reason']),
    ]);
    $reqId = (int)db()->lastInsertId();

    // HR ko bata do ki review karna hai
    foreach (admins_of_company((int)$me['company_id'], 'attendance.regularize_approve') as $adminId) {
        notify((int)$adminId, 'regularization', 'Attendance regularization request',
            $me['emp_code'] . ' - ' . $in['att_date'], 'regularization', $reqId);
    }

    audit('create', 'regularization', $reqId);
    ok(['id' => $reqId], 'Request submitted');
}

if ($ACTION === 'regularizations' && $METHOD === 'GET') {
    $me = require_auth('attendance.regularize_approve');
    $status = $_GET['status'] ?? 'pending';

    $stmt = db()->prepare(
        "SELECT rr.*, u.emp_code, p.first_name, p.last_name
         FROM regularization_requests rr
         JOIN users u ON u.id = rr.user_id
         LEFT JOIN employee_profiles p ON p.user_id = u.id
         WHERE u.company_id = ? AND rr.status = ?
         ORDER BY rr.created_at DESC"
    );
    $stmt->execute([$me['company_id'], $status]);
    ok($stmt->fetchAll());
}

if ($ACTION === 'review' && $METHOD === 'POST') {
    $me = require_auth('attendance.regularize_approve');
    $in = body();
    require_fields($in, ['id', 'action']);
    if (!in_array($in['action'], ['approve', 'reject'], true)) fail('action must be approve or reject', 422);

    // request isi company ka ho
    $stmt = db()->prepare(
        "SELECT rr.* FROM regularization_requests rr
         JOIN users u ON u.id = rr.user_id
         WHERE rr.id = ? AND u.company_id = ?"
    );
    $stmt->execute([(int)$in['id'], $me['company_id']]);
    $req = $stmt->fetch();
    if (!$req) fail('Request not found', 404);
    if ($req['status'] !== 'pending') fail('Already reviewed', 409);

    $newStatus = $in['action'] === 'approve' ? 'approved' : 'rejected';

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "UPDATE regularization_requests
             SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_comment = ? WHERE id = ?"
        )->execute([$newStatus, $me['id'], $in['comment'] ?? null, $req['id']]);

        // approve hua to us din ki attendance thik kar do
        if ($newStatus === 'approved') {
            $shift = my_shift((int)$req['user_id']);
            $checkIn  = $req['req_check_in'];
            $checkOut = $req['req_check_out'];
            $workMins = ($checkIn && $checkOut)
                ? (int) round((strtotime($checkOut) - strtotime($checkIn)) / 60) : null;
            $status = $workMins !== null ? day_status($workMins, $shift) : 'present';

            // row ho to update, na ho to bana do (unique user+date)
            $pdo->prepare(
                "INSERT INTO attendance (user_id, att_date, shift_id, status, check_in, check_out, work_mins, is_regularized, source)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1, 'admin')
                 ON DUPLICATE KEY UPDATE
                    status = VALUES(status), check_in = VALUES(check_in), check_out = VALUES(check_out),
                    work_mins = VALUES(work_mins), is_regularized = 1"
            )->execute([$req['user_id'], $req['att_date'], $shift['id'] ?? null, $status, $checkIn, $checkOut, $workMins]);
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    notify((int)$req['user_id'], 'regularization',
        'Regularization ' . $newStatus,
        $req['att_date'] . (!empty($in['comment']) ? ' - ' . $in['comment'] : ''),
        'regularization', (int)$req['id']);

    audit($newStatus, 'regularization', (int)$req['id']);
    ok(null, 'Request ' . $newStatus);
}

// bacha hua GET = admin ka din bhar ka view
if ($METHOD === 'GET') {
    $me   = require_auth('attendance.view_all');
    $date = $_GET['date'] ?? date('Y-m-d');

    // us din ki attendance sabki - jisne punch nahi kiya wo bhi dikhe (LEFT JOIN).
    // shift bhi join karte hai taaki extra hours full_day_hours ke hisab se nikle
    // (a.shift_id = jis din ka actual shift, warna profile ka assigned shift)
    $stmt = db()->prepare(
        "SELECT u.id AS user_id, u.emp_code, p.first_name, p.last_name,
                a.status, a.check_in, a.check_out, a.work_mins, a.is_late, a.is_regularized,
                s.full_day_hours, s.name AS shift_name
         FROM users u
         LEFT JOIN employee_profiles p ON p.user_id = u.id
         LEFT JOIN attendance a ON a.user_id = u.id AND a.att_date = ?
         LEFT JOIN shifts s ON s.id = COALESCE(a.shift_id, p.shift_id)
         WHERE u.company_id = ? AND u.delete_flag = 0 AND u.status = 'active'
         ORDER BY u.emp_code"
    );
    $stmt->execute([$date, $me['company_id']]);
    $rows = $stmt->fetchAll();

    // company registration date - calendar me isse pehle back-date allow nahi
    $since = db()->prepare("SELECT DATE(created_at) FROM companies WHERE id = ?");
    $since->execute([$me['company_id']]);

    ok(['date' => $date, 'company_since' => $since->fetchColumn(), 'rows' => $rows]);
}

fail('Unknown action: ' . $ACTION, 404);
