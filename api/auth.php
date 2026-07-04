<?php
// auth.php?action=login   (POST) -> email/password se login
// auth.php?action=logout  (POST)
// auth.php?action=me      (GET)  -> current logged in user + permissions

require_once __DIR__ . '/bootstrap.php';

if ($ACTION === 'login' && $METHOD === 'POST') {
    $in = body();
    require_fields($in, ['email', 'password']);

    // email field me login id (emp_code) ya email dono chalta hai
    $identifier = trim($in['email']);
    $stmt = db()->prepare(
        "SELECT id, email, password, status, failed_logins, locked_until
         FROM users
         WHERE (email = ? OR emp_code = ?) AND deleted_at IS NULL"
    );
    $stmt->execute([strtolower($identifier), strtoupper($identifier)]);
    $user = $stmt->fetch();

    if (!$user) fail('Invalid login id / email or password', 401);

    if ($user['locked_until'] !== null && strtotime($user['locked_until']) > time()) {
        fail('Account locked. Try again later.', 423);
    }
    if ($user['status'] !== 'active') {
        fail('Account is not active. Contact HR.', 403);
    }

    if (!password_verify($in['password'], $user['password'])) {
        // galat password -> counter badhao, 5 pe lock
        $failed = $user['failed_logins'] + 1;
        $lock = $failed >= MAX_FAILED_LOGINS
            ? date('Y-m-d H:i:s', time() + LOCKOUT_MINS * 60)
            : null;
        db()->prepare("UPDATE users SET failed_logins = ?, locked_until = ? WHERE id = ?")
            ->execute([$failed, $lock, $user['id']]);
        fail('Invalid email or password', 401);
    }

    db()->prepare(
        "UPDATE users SET failed_logins = 0, locked_until = NULL,
         last_login_at = NOW(), last_login_ip = ? WHERE id = ?"
    )->execute([$_SERVER['REMOTE_ADDR'] ?? null, $user['id']]);

    start_session();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $user['id'];

    audit('login', 'user', (int)$user['id']);
    ok(current_user(), 'Logged in');
}

// naya company registration - banane wala admin ban jata hai,
// employees ko baad me admin invite karta hai
if ($ACTION === 'signup' && $METHOD === 'POST') {
    // formdata me aata hai (logo file ke saath), body() $_POST utha leta hai
    $in = body();
    require_fields($in, ['company', 'name', 'email', 'phone', 'password']);

    $email = trim(strtolower($in['email']));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Invalid email address', 422);
    if (strlen($in['password']) < 6) fail('Password must be at least 6 characters', 422);
    if (!preg_match('/^\d{10}$/', $in['phone'])) fail('Enter a valid 10 digit phone', 422);

    $stmt = db()->prepare("SELECT id FROM users WHERE email = ? AND deleted_at IS NULL");
    $stmt->execute([$email]);
    if ($stmt->fetch()) fail('Email already registered. Try logging in.', 409);

    // company logo optional hai
    $logoUrl = null;
    if (!empty($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
        if ($_FILES['logo']['size'] > MAX_UPLOAD_BYTES) fail('Logo too big, max 5MB', 422);
        $mime = mime_content_type($_FILES['logo']['tmp_name']);
        $extMap = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/svg+xml' => 'svg'];
        if (!isset($extMap[$mime])) fail('Logo must be an image (png/jpg/webp/svg)', 422);
        // random naam taaki koi guess na kar sake aur clash na ho
        $fname = 'logo_' . bin2hex(random_bytes(8)) . '.' . $extMap[$mime];
        if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0777, true);
        // apache daemon user se chalta hai, folder writable nahi to yahi pakdo
        if (!move_uploaded_file($_FILES['logo']['tmp_name'], UPLOAD_DIR . '/' . $fname)) {
            error_log('logo upload failed - check uploads folder permissions');
            fail('Could not save logo. Try again or skip it.', 500);
        }
        $logoUrl = UPLOAD_URL . '/' . $fname;
    }

    $roleId = db()->query("SELECT id FROM roles WHERE name = 'admin'")->fetchColumn();

    // name ko first/last me tod do
    $nameParts = preg_split('/\s+/', trim($in['name']), 2);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO companies (name, phone, logo_url) VALUES (?, ?, ?)")
            ->execute([trim($in['company']), $in['phone'], $logoUrl]);
        $companyId = $pdo->lastInsertId();

        // system generated login id, admin is company ka pehla joinee hota hai
        $loginId = generate_login_id($companyId, $in['company'], $nameParts[0], $nameParts[1] ?? null);

        $pdo->prepare(
            "INSERT INTO users (company_id, emp_code, email, password, role_id, status, email_verified_at)
             VALUES (?, ?, ?, ?, ?, 'active', NOW())"
        )->execute([$companyId, $loginId, $email, password_hash($in['password'], PASSWORD_BCRYPT), $roleId]);
        $userId = $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO employee_profiles (user_id, first_name, last_name, phone) VALUES (?, ?, ?, ?)")
            ->execute([$userId, $nameParts[0], $nameParts[1] ?? null, $in['phone']]);

        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }

    start_session();
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;

    audit('signup', 'company', (int)$companyId);
    ok(current_user(), 'Account created');
}

// system generated password badalne ke liye (aur waise bhi)
if ($ACTION === 'change_password' && $METHOD === 'POST') {
    $user = require_auth();
    $in = body();
    require_fields($in, ['old_password', 'new_password']);

    if (strlen($in['new_password']) < 6) fail('New password must be at least 6 characters', 422);

    $stmt = db()->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$user['id']]);
    $hash = $stmt->fetchColumn();

    if (!password_verify($in['old_password'], $hash)) fail('Current password is wrong', 401);

    db()->prepare("UPDATE users SET password = ?, must_change_password = 0 WHERE id = ?")
        ->execute([password_hash($in['new_password'], PASSWORD_BCRYPT), $user['id']]);

    audit('password_change', 'user', (int)$user['id']);
    ok(null, 'Password changed');
}

if ($ACTION === 'logout' && $METHOD === 'POST') {
    require_auth();
    start_session();
    session_destroy();
    ok(null, 'Logged out');
}

if ($ACTION === 'me' && $METHOD === 'GET') {
    ok(require_auth());
}

fail('Unknown action: ' . $ACTION, 404);
