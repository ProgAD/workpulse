<?php
// leaves.php?action=types     (GET)  -> company ke active leave types
// leaves.php?action=balances  (GET)  -> apna balance (?year=)
// leaves.php?action=apply     (POST) -> leave lagao
// leaves.php?action=mine      (GET)  -> apni requests
// leaves.php                  (GET)  -> admin, poori company ki requests (?status=)
// leaves.php?action=cancel    (POST) -> apni pending/future leave cancel
// leaves.php?action=review    (POST) -> admin, approve / reject

require_once __DIR__ . '/../bootstrap.php';

// working days nikalta hai - weekend (sat/sun) aur company holidays chhod ke.
// half day flags start/end din ka aadha ghata dete hai
function leave_working_days(string $from, string $to, int $companyId, bool $halfStart, bool $halfEnd): float
{
    $stmt = db()->prepare(
        "SELECT holiday_date FROM holidays
         WHERE company_id = ? AND is_optional = 0 AND holiday_date BETWEEN ? AND ?"
    );
    $stmt->execute([$companyId, $from, $to]);
    $holidays = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

    $counts = function (string $d) use ($holidays): bool {
        $dow = (int)date('w', strtotime($d));   // 0 = sunday, 6 = saturday
        return $dow !== 0 && $dow !== 6 && !isset($holidays[$d]);
    };

    $days = 0.0;
    for ($cur = strtotime($from), $end = strtotime($to); $cur <= $end; $cur = strtotime('+1 day', $cur)) {
        if ($counts(date('Y-m-d', $cur))) $days += 1;
    }

    // half day sirf tab ghatao jab wo din count bhi ho raha ho
    if ($halfStart && $counts($from)) $days -= 0.5;
    if ($halfEnd && $to !== $from && $counts($to)) $days -= 0.5;

    return $days;
}

if ($ACTION === 'types' && $METHOD === 'GET') {
    $me = require_auth();
    $stmt = db()->prepare(
        "SELECT id, name, code, is_paid, needs_document, annual_quota, allow_half_day
         FROM leave_types WHERE company_id = ? AND is_active = 1 ORDER BY name"
    );
    $stmt->execute([$me['company_id']]);
    ok($stmt->fetchAll());
}

// admin naya leave type banata hai (PL/SL jaise)
if ($ACTION === 'create_type' && $METHOD === 'POST') {
    $me = require_auth('leave.approve');
    $in = body();
    require_fields($in, ['name', 'code']);

    $code = strtoupper(trim($in['code']));
    if (!preg_match('/^[A-Z]{1,10}$/', $code)) fail('Code Must Be Letters Only, Max 10', 422);

    $stmt = db()->prepare("SELECT id FROM leave_types WHERE company_id = ? AND code = ?");
    $stmt->execute([$me['company_id'], $code]);
    if ($stmt->fetch()) fail('This Code Already Exists', 409);

    $quota = ($in['annual_quota'] ?? '') !== '' ? (float)$in['annual_quota'] : null;

    db()->prepare(
        "INSERT INTO leave_types (company_id, name, code, is_paid, annual_quota)
         VALUES (?, ?, ?, ?, ?)"
    )->execute([
        $me['company_id'], trim($in['name']), $code,
        !empty($in['is_paid']) ? 1 : 0, $quota,
    ]);

    $typeId = (int)db()->lastInsertId();
    audit('create', 'leave_type', $typeId);
    ok(['id' => $typeId], 'Leave type created');
}

if ($ACTION === 'balances' && $METHOD === 'GET') {
    $me   = require_auth();
    $year = (int)($_GET['year'] ?? date('Y'));

    // saare active types dikhao, balance row na ho to 0 se
    $stmt = db()->prepare(
        "SELECT lt.id AS leave_type_id, lt.name, lt.code, lt.is_paid, lt.annual_quota,
                COALESCE(lb.opening, 0)  AS opening,
                COALESCE(lb.accrued, 0)  AS accrued,
                COALESCE(lb.used, 0)     AS used,
                COALESCE(lb.adjusted, 0) AS adjusted
         FROM leave_types lt
         LEFT JOIN leave_balances lb
                ON lb.leave_type_id = lt.id AND lb.user_id = ? AND lb.year = ?
         WHERE lt.company_id = ? AND lt.is_active = 1
         ORDER BY lt.name"
    );
    $stmt->execute([$me['id'], $year, $me['company_id']]);
    $rows = $stmt->fetchAll();

    foreach ($rows as &$r) {
        // quota wale type me available = quota + carry/adjust - used
        $cap = $r['annual_quota'] !== null
            ? (float)$r['annual_quota'] + (float)$r['opening'] + (float)$r['adjusted']
            : null;
        $r['available'] = $cap !== null ? round($cap - (float)$r['used'], 2) : null;
    }
    unset($r);

    ok(['year' => $year, 'balances' => $rows]);
}

if ($ACTION === 'apply' && $METHOD === 'POST') {
    $me = require_auth('leave.apply');
    $in = body();
    require_fields($in, ['leave_type_id', 'from_date', 'to_date']);

    $from = $in['from_date'];
    $to   = $in['to_date'];
    if (strtotime($to) < strtotime($from)) fail('To date cannot be before from date', 422);

    $halfStart = !empty($in['half_day_start']);
    $halfEnd   = !empty($in['half_day_end']);

    $stmt = db()->prepare(
        "SELECT * FROM leave_types WHERE id = ? AND company_id = ? AND is_active = 1"
    );
    $stmt->execute([(int)$in['leave_type_id'], $me['company_id']]);
    $type = $stmt->fetch();
    if (!$type) fail('Invalid leave type', 422);

    if ($type['needs_document'] && empty($in['document_url'])) {
        fail('This leave type needs a supporting document', 422);
    }

    $totalDays = leave_working_days($from, $to, (int)$me['company_id'], $halfStart, $halfEnd);
    if ($totalDays <= 0) fail('Selected range has no working days (all weekend/holiday)', 422);

    // overlap check - us range me pehle se pending/approved leave to nahi
    $stmt = db()->prepare(
        "SELECT id FROM leave_requests
         WHERE user_id = ? AND status IN ('pending','approved')
           AND NOT (to_date < ? OR from_date > ?)"
    );
    $stmt->execute([$me['id'], $from, $to]);
    if ($stmt->fetch()) fail('You already have a leave in these dates', 409);

    // paid + quota wale type me balance check
    if ($type['is_paid'] && $type['annual_quota'] !== null) {
        $year = (int)substr($from, 0, 4);
        $stmt = db()->prepare(
            "SELECT COALESCE(opening,0)+COALESCE(adjusted,0) AS extra, COALESCE(used,0) AS used
             FROM leave_balances WHERE user_id = ? AND leave_type_id = ? AND year = ?"
        );
        $stmt->execute([$me['id'], $type['id'], $year]);
        $bal = $stmt->fetch() ?: ['extra' => 0, 'used' => 0];
        $available = (float)$type['annual_quota'] + (float)$bal['extra'] - (float)$bal['used'];
        if ($totalDays > $available) {
            fail("Not enough balance. Available: {$available} day(s)", 422);
        }
    }

    // req_no saal ke hisab se - LR-2026-00042
    $year = (int)substr($from, 0, 4);
    $seq  = (int)db()->query("SELECT COUNT(*) FROM leave_requests WHERE req_no LIKE 'LR-{$year}-%'")->fetchColumn() + 1;
    $reqNo = sprintf('LR-%d-%05d', $year, $seq);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "INSERT INTO leave_requests
                (req_no, user_id, leave_type_id, from_date, to_date, half_day_start, half_day_end, total_days, reason, document_url)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $reqNo, $me['id'], $type['id'], $from, $to,
            $halfStart ? 1 : 0, $halfEnd ? 1 : 0, $totalDays,
            $in['reason'] ?? null, $in['document_url'] ?? null,
        ]);
        $reqId = (int)$pdo->lastInsertId();

        // approval row - jo bhi HR/admin approve kar sakta hai usme se pehla
        $approvers = admins_of_company((int)$me['company_id'], 'leave.approve');
        if ($approvers) {
            $pdo->prepare(
                "INSERT INTO leave_approvals (leave_request_id, approver_id, level) VALUES (?, ?, 1)"
            )->execute([$reqId, $approvers[0]]);
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    foreach (admins_of_company((int)$me['company_id'], 'leave.approve') as $adminId) {
        notify((int)$adminId, 'leave', 'New leave request',
            $me['emp_code'] . " applied {$totalDays} day(s) ({$reqNo})", 'leave_request', $reqId);
    }

    audit('create', 'leave_request', $reqId);
    ok(['id' => $reqId, 'req_no' => $reqNo, 'total_days' => $totalDays], 'Leave applied');
}

if ($ACTION === 'mine' && $METHOD === 'GET') {
    $me = require_auth();
    $sql =
        "SELECT lr.*, lt.name AS leave_type, lt.code AS leave_code
         FROM leave_requests lr JOIN leave_types lt ON lt.id = lr.leave_type_id
         WHERE lr.user_id = ?";
    $args = [$me['id']];
    if (!empty($_GET['status'])) { $sql .= " AND lr.status = ?"; $args[] = $_GET['status']; }
    $sql .= " ORDER BY lr.created_at DESC";

    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    ok($stmt->fetchAll());
}

if ($ACTION === 'cancel' && $METHOD === 'POST') {
    $me = require_auth();
    $in = body();
    require_fields($in, ['id']);

    $stmt = db()->prepare("SELECT * FROM leave_requests WHERE id = ? AND user_id = ?");
    $stmt->execute([(int)$in['id'], $me['id']]);
    $lr = $stmt->fetch();
    if (!$lr) fail('Leave request not found', 404);
    if (!in_array($lr['status'], ['pending', 'approved'], true)) fail('This leave cannot be cancelled', 409);
    // shuru ho chuki leave cancel nahi hogi
    if (strtotime($lr['from_date']) < strtotime(date('Y-m-d'))) fail('Leave has already started', 409);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE leave_requests SET status = 'cancelled' WHERE id = ?")->execute([$lr['id']]);

        // approved thi to used wapas kar do
        if ($lr['status'] === 'approved') {
            $type = db()->prepare("SELECT is_paid, annual_quota FROM leave_types WHERE id = ?");
            $type->execute([$lr['leave_type_id']]);
            $t = $type->fetch();
            if ($t && $t['is_paid'] && $t['annual_quota'] !== null) {
                $pdo->prepare(
                    "UPDATE leave_balances SET used = GREATEST(used - ?, 0)
                     WHERE user_id = ? AND leave_type_id = ? AND year = ?"
                )->execute([$lr['total_days'], $me['id'], $lr['leave_type_id'], (int)substr($lr['from_date'], 0, 4)]);
            }
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    audit('cancel', 'leave_request', (int)$lr['id']);
    ok(null, 'Leave cancelled');
}

if ($ACTION === 'review' && $METHOD === 'POST') {
    $me = require_auth('leave.approve');
    $in = body();
    require_fields($in, ['id', 'action']);
    if (!in_array($in['action'], ['approve', 'reject'], true)) fail('action must be approve or reject', 422);

    // request isi company ka ho
    $stmt = db()->prepare(
        "SELECT lr.*, lt.is_paid, lt.annual_quota
         FROM leave_requests lr
         JOIN leave_types lt ON lt.id = lr.leave_type_id
         JOIN users u ON u.id = lr.user_id
         WHERE lr.id = ? AND u.company_id = ?"
    );
    $stmt->execute([(int)$in['id'], $me['company_id']]);
    $lr = $stmt->fetch();
    if (!$lr) fail('Leave request not found', 404);
    if ($lr['status'] !== 'pending') fail('Already reviewed', 409);

    $newStatus = $in['action'] === 'approve' ? 'approved' : 'rejected';

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE leave_requests SET status = ? WHERE id = ?")->execute([$newStatus, $lr['id']]);

        // approval history - row pehle se ho ya na ho, dono handle
        $pdo->prepare(
            "INSERT INTO leave_approvals (leave_request_id, approver_id, level, action, comment, acted_at)
             VALUES (?, ?, 1, ?, ?, NOW())
             ON DUPLICATE KEY UPDATE approver_id = VALUES(approver_id), action = VALUES(action),
                                     comment = VALUES(comment), acted_at = NOW()"
        )->execute([$lr['id'], $me['id'], $newStatus, $in['comment'] ?? null]);

        // approve + paid + quota -> balance me used badha do
        if ($newStatus === 'approved' && $lr['is_paid'] && $lr['annual_quota'] !== null) {
            $pdo->prepare(
                "INSERT INTO leave_balances (user_id, leave_type_id, year, used)
                 VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE used = used + VALUES(used)"
            )->execute([$lr['user_id'], $lr['leave_type_id'], (int)substr($lr['from_date'], 0, 4), $lr['total_days']]);
        }

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    notify((int)$lr['user_id'], 'leave', 'Leave ' . $newStatus,
        $lr['req_no'] . (!empty($in['comment']) ? ' - ' . $in['comment'] : ''), 'leave_request', (int)$lr['id']);

    audit($newStatus, 'leave_request', (int)$lr['id']);
    ok(null, 'Leave ' . $newStatus);
}

// bacha hua GET = admin ki poori list
if ($METHOD === 'GET') {
    $me  = require_auth('leave.view_all');
    $sql =
        "SELECT lr.*, lt.name AS leave_type, lt.code AS leave_code,
                u.emp_code, p.first_name, p.last_name
         FROM leave_requests lr
         JOIN leave_types lt ON lt.id = lr.leave_type_id
         JOIN users u ON u.id = lr.user_id
         LEFT JOIN employee_profiles p ON p.user_id = u.id
         WHERE u.company_id = ?";
    $args = [$me['company_id']];
    if (!empty($_GET['status']))  { $sql .= " AND lr.status = ?";  $args[] = $_GET['status']; }
    if (!empty($_GET['user_id'])) { $sql .= " AND lr.user_id = ?"; $args[] = (int)$_GET['user_id']; }
    $sql .= " ORDER BY lr.created_at DESC";

    $stmt = db()->prepare($sql);
    $stmt->execute($args);
    ok($stmt->fetchAll());
}

fail('Unknown action: ' . $ACTION, 404);
