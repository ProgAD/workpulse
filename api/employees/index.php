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

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            "INSERT INTO users (company_id, emp_code, email, password, must_change_password, role_id, status)
             VALUES (?, ?, ?, ?, 1, ?, 'active')"
        )->execute([$me['company_id'], $loginId, $email, password_hash($tempPassword, PASSWORD_BCRYPT), $roleId]);
        $userId = $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO employee_profiles (user_id, first_name, last_name, phone, doj)
             VALUES (?, ?, ?, ?, ?)"
        )->execute([$userId, $nameParts[0], $nameParts[1] ?? null, $in['phone'], $doj]);

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

    $base = "SELECT u.id, u.emp_code, u.email, u.status, u.must_change_password, r.name AS role,
                    p.first_name, p.last_name, p.phone, p.doj
             FROM users u
             JOIN roles r ON r.id = u.role_id
             LEFT JOIN employee_profiles p ON p.user_id = u.id
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
