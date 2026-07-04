<?php
// employees.php                    (GET)  -> company ke saare employees (admin)
// employees.php?id=5               (GET)  -> ek employee ki detail
// employees.php?action=create      (POST) -> naya employee, login id + temp password system banata hai
// employees.php?action=update&id=5 (POST) -> basic edit
// employees.php?action=remove&id=5 (POST) -> soft delete

require_once __DIR__ . '/../bootstrap.php';

// admin employee banata hai, id aur pehla password system generate karta hai
if ($ACTION === 'create' && $METHOD === 'POST') {
    $me = require_auth('employee.edit_all');
    $in = body();
    require_fields($in, ['name', 'email', 'phone']);

    $email = trim(strtolower($in['email']));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Invalid email address', 422);
    if (!preg_match('/^\d{10}$/', $in['phone'])) fail('Enter a valid 10 digit phone', 422);

    $stmt = db()->prepare("SELECT id FROM users WHERE email = ? AND delete_flag = 0");
    $stmt->execute([$email]);
    if ($stmt->fetch()) fail('Email already registered', 409);

    $stmt = db()->prepare("SELECT name FROM companies WHERE id = ?");
    $stmt->execute([$me['company_id']]);
    $companyName = $stmt->fetchColumn();

    $roleId = db()->query("SELECT id FROM roles WHERE name = 'employee'")->fetchColumn();
    $nameParts = preg_split('/\s+/', trim($in['name']), 2);

    // doj diya to usi saal ke hisab se id banegi
    $doj = !empty($in['doj']) ? $in['doj'] : date('Y-m-d');
    $joinYear = (int)substr($doj, 0, 4);

    $loginId = generate_login_id($me['company_id'], $companyName, $nameParts[0], $nameParts[1] ?? null, $joinYear);
    $tempPassword = generate_temp_password();

    // optional fields - jo aaye wahi jayenge, baaki null
    $opt = fn($k) => !empty($in[$k]) ? trim($in[$k]) : null;

    $dob     = $opt('dob');
    $gender  = in_array($in['gender'] ?? '', ['male', 'female', 'other'], true) ? $in['gender'] : null;
    $marital = in_array($in['marital_status'] ?? '', ['single', 'married'], true) ? $in['marital_status'] : null;
    $addr    = $opt('address');
    $empType  = in_array($in['emp_type'] ?? '', ['full_time', 'part_time', 'contract', 'intern'], true) ? $in['emp_type'] : 'full_time';
    $workMode = in_array($in['work_mode'] ?? '', ['onsite', 'remote', 'hybrid'], true) ? $in['work_mode'] : 'onsite';

    if (!empty($in['personal_email']) && !filter_var($in['personal_email'], FILTER_VALIDATE_EMAIL)) {
        fail('Invalid personal email', 422);
    }
    if (!empty($in['emergency_phone']) && !preg_match('/^\d{10}$/', $in['emergency_phone'])) {
        fail('Emergency Phone Must Be 10 Digits', 422);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // department/designation naam se aate hai, na ho to ban jate hai
        $deptId = null;
        if (!empty($in['department'])) {
            $dname = trim($in['department']);
            $stmt = $pdo->prepare("SELECT id FROM departments WHERE company_id = ? AND name = ? AND delete_flag = 0");
            $stmt->execute([$me['company_id'], $dname]);
            $deptId = $stmt->fetchColumn();
            if (!$deptId) {
                $code = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', $dname), 0, 10)) ?: 'DEPT';
                $pdo->prepare("INSERT INTO departments (company_id, name, code) VALUES (?, ?, ?)")
                    ->execute([$me['company_id'], $dname, $code]);
                $deptId = $pdo->lastInsertId();
            }
        }

        $desigId = null;
        if (!empty($in['designation'])) {
            $tname = trim($in['designation']);
            $stmt = $pdo->prepare("SELECT id FROM designations WHERE company_id = ? AND title = ? AND delete_flag = 0");
            $stmt->execute([$me['company_id'], $tname]);
            $desigId = $stmt->fetchColumn();
            if (!$desigId) {
                $pdo->prepare("INSERT INTO designations (company_id, title) VALUES (?, ?)")
                    ->execute([$me['company_id'], $tname]);
                $desigId = $pdo->lastInsertId();
            }
        }

        $pdo->prepare(
            "INSERT INTO users (company_id, emp_code, email, password, must_change_password, role_id, status)
             VALUES (?, ?, ?, ?, 1, ?, 'active')"
        )->execute([$me['company_id'], $loginId, $email, password_hash($tempPassword, PASSWORD_BCRYPT), $roleId]);
        $userId = $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO employee_profiles
                (user_id, first_name, last_name, phone, personal_email, nationality, doj, dob,
                 gender, marital_status, blood_group, current_address, permanent_address,
                 emergency_contact, emergency_phone,
                 department_id, designation_id, emp_type, work_mode,
                 bank_account, bank_name, bank_ifsc, pan, uan)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
            $userId, $nameParts[0], $nameParts[1] ?? null, $in['phone'],
            $opt('personal_email'), $opt('nationality'), $doj, $dob,
            $gender, $marital, $opt('blood_group'), $addr, $opt('permanent_address'),
            $opt('emergency_contact'), $opt('emergency_phone'),
            $deptId, $desigId, $empType, $workMode,
            $opt('bank_account'), $opt('bank_name'),
            $opt('bank_ifsc') ? strtoupper($opt('bank_ifsc')) : null,
            $opt('pan') ? strtoupper($opt('pan')) : null, $opt('uan'),
        ]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    audit('create', 'employee', (int)$userId);

    // temp password sirf yahi ek baar milta hai, db me sirf hash hai
    ok([
        'user_id'       => (int)$userId,
        'login_id'      => $loginId,
        'temp_password' => $tempPassword,
    ], 'Employee created. Share the login id and password with them.');
}

// apna pura profile - koi bhi logged in user (profile page ke liye)
if ($ACTION === 'me_profile' && $METHOD === 'GET') {
    $me = require_auth();
    $stmt = db()->prepare(
        "SELECT u.id, u.emp_code, u.email, u.status, r.name AS role,
                p.photo_url,
                p.first_name, p.last_name, p.phone, p.personal_email, p.nationality,
                p.dob, p.gender, p.marital_status, p.blood_group,
                p.current_address, p.permanent_address,
                p.emergency_contact, p.emergency_phone,
                p.doj, p.emp_type, p.work_mode,
                p.bank_name, p.bank_ifsc, p.bank_account, p.pan, p.uan,
                p.about, p.job_love, p.interests, p.skills, p.certifications,
                d.name AS department, g.title AS designation, c.name AS company
         FROM users u
         JOIN roles r ON r.id = u.role_id
         JOIN companies c ON c.id = u.company_id
         LEFT JOIN employee_profiles p ON p.user_id = u.id
         LEFT JOIN departments d ON d.id = p.department_id
         LEFT JOIN designations g ON g.id = p.designation_id
         WHERE u.id = ?"
    );
    $stmt->execute([$me['id']]);
    ok($stmt->fetch());
}

// employee khud SIRF ye badal sakta hai: phone, marital status, address (+ photo alag action se)
// baaki sab (naam, dob, bank, job info...) admin ke through hi badalta hai
if ($ACTION === 'update_self' && $METHOD === 'POST') {
    $me = require_auth('employee.edit_self');
    $in = body();

    if (!empty($in['phone']) && !preg_match('/^\d{10}$/', $in['phone'])) {
        fail('Enter a valid 10 digit phone', 422);
    }
    if (isset($in['marital_status']) && $in['marital_status'] !== ''
        && !in_array($in['marital_status'], ['single', 'married'], true)) {
        fail('Invalid marital status', 422);
    }

    $allowed = ['phone', 'marital_status', 'current_address', 'permanent_address',
                'about', 'job_love', 'interests', 'skills', 'certifications'];
    $sets = [];
    $vals = [];
    foreach ($allowed as $f) {
        if (array_key_exists($f, $in)) {
            $sets[] = "$f = ?";
            $vals[] = $in[$f] === '' ? null : $in[$f];
        }
    }
    if ($sets) {
        $vals[] = $me['id'];
        db()->prepare("UPDATE employee_profiles SET " . implode(', ', $sets) . " WHERE user_id = ?")
            ->execute($vals);
    }

    audit('update', 'employee_profile', (int)$me['id']);
    ok(null, 'Profile updated');
}

// apni profile photo (multipart: photo)
if ($ACTION === 'upload_photo' && $METHOD === 'POST') {
    $me = require_auth('employee.edit_self');

    if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
        fail('Please Select A Photo', 422);
    }
    if ($_FILES['photo']['size'] > MAX_UPLOAD_BYTES) fail('Photo Is Larger Than 5MB', 422);

    $mime = mime_content_type($_FILES['photo']['tmp_name']);
    $extMap = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    if (!isset($extMap[$mime])) fail('Only JPG, PNG Or WEBP Images Are Allowed', 422);

    $fname = 'pic_' . bin2hex(random_bytes(8)) . '.' . $extMap[$mime];
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0777, true);
    if (!move_uploaded_file($_FILES['photo']['tmp_name'], UPLOAD_DIR . '/' . $fname)) {
        fail('Could Not Save The Photo', 500);
    }

    $url = UPLOAD_URL . '/' . $fname;
    db()->prepare("UPDATE employee_profiles SET photo_url = ? WHERE user_id = ?")
        ->execute([$url, $me['id']]);

    audit('update', 'employee_photo', (int)$me['id']);
    ok(['photo_url' => $url], 'Photo updated');
}

if ($ACTION === 'update' && $METHOD === 'POST') {
    $me = require_auth('employee.edit_all');
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) fail('id required', 422);
    $in = body();

    // employee isi company ka hona chahiye
    $stmt = db()->prepare("SELECT id FROM users WHERE id = ? AND company_id = ? AND delete_flag = 0");
    $stmt->execute([$id, $me['company_id']]);
    if (!$stmt->fetch()) fail('Employee not found', 404);

    if (!empty($in['name'])) {
        $nameParts = preg_split('/\s+/', trim($in['name']), 2);
        db()->prepare("UPDATE employee_profiles SET first_name = ?, last_name = ? WHERE user_id = ?")
            ->execute([$nameParts[0], $nameParts[1] ?? null, $id]);
    }
    if (!empty($in['phone'])) {
        if (!preg_match('/^\d{10}$/', $in['phone'])) fail('Enter a valid 10 digit phone', 422);
        db()->prepare("UPDATE employee_profiles SET phone = ? WHERE user_id = ?")
            ->execute([$in['phone'], $id]);
    }
    if (!empty($in['status']) && in_array($in['status'], ['active', 'suspended', 'exited'], true)) {
        db()->prepare("UPDATE users SET status = ? WHERE id = ?")->execute([$in['status'], $id]);
    }

    audit('update', 'employee', $id);
    ok(null, 'Employee updated');
}

if ($ACTION === 'remove' && $METHOD === 'POST') {
    $me = require_auth('employee.edit_all');
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) fail('id required', 422);
    if ($id === (int)$me['id']) fail('You cannot remove yourself', 422);

    $stmt = db()->prepare("UPDATE users SET delete_flag = 1, status = 'exited' WHERE id = ? AND company_id = ?");
    $stmt->execute([$id, $me['company_id']]);
    if (!$stmt->rowCount()) fail('Employee not found', 404);

    audit('delete', 'employee', $id);
    ok(null, 'Employee removed');
}

// list / single - default GET
if ($METHOD === 'GET') {
    $me = require_auth('employee.view_all');

    // today_status card ke status dot ke liye - aaj present / on_leave / absent
    $base = "SELECT u.id, u.emp_code, u.email, u.status, u.must_change_password, r.name AS role,
                    p.photo_url, p.first_name, p.last_name, p.phone, p.doj, p.dob, p.gender,
                    p.current_address, p.emp_type, p.work_mode,
                    d.name AS department, g.title AS designation,
                    CASE
                      WHEN a.status IN ('present','half_day') THEN 'present'
                      WHEN EXISTS (SELECT 1 FROM leave_requests lr
                                   WHERE lr.user_id = u.id AND lr.status = 'approved'
                                     AND CURDATE() BETWEEN lr.from_date AND lr.to_date) THEN 'on_leave'
                      ELSE 'absent'
                    END AS today_status
             FROM users u
             JOIN roles r ON r.id = u.role_id
             LEFT JOIN employee_profiles p ON p.user_id = u.id
             LEFT JOIN departments d ON d.id = p.department_id
             LEFT JOIN designations g ON g.id = p.designation_id
             LEFT JOIN attendance a ON a.user_id = u.id AND a.att_date = CURDATE()
             WHERE u.company_id = ? AND u.delete_flag = 0";

    if (!empty($_GET['id'])) {
        $stmt = db()->prepare($base . " AND u.id = ?");
        $stmt->execute([$me['company_id'], (int)$_GET['id']]);
        $row = $stmt->fetch();
        if (!$row) fail('Employee not found', 404);
        ok($row);
    }

    $stmt = db()->prepare($base . " ORDER BY u.created_at DESC");
    $stmt->execute([$me['company_id']]);
    ok($stmt->fetchAll());
}

fail('Unknown action: ' . $ACTION, 404);
