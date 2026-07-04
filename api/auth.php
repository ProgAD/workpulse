<?php
// auth.php?action=login   (POST) -> email/password se login
// auth.php?action=logout  (POST)
// auth.php?action=me      (GET)  -> current logged in user + permissions

require_once __DIR__ . '/bootstrap.php';

if ($ACTION === 'login' && $METHOD === 'POST') {
    $in = body();
    require_fields($in, ['email', 'password']);

    $stmt = db()->prepare(
        "SELECT id, email, password, status, failed_logins, locked_until
         FROM users WHERE email = ? AND deleted_at IS NULL"
    );
    $stmt->execute([trim(strtolower($in['email']))]);
    $user = $stmt->fetch();

    if (!$user) fail('Invalid email or password', 401);

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
    $in = body();
    require_fields($in, ['company', 'name', 'email', 'password']);

    $email = trim(strtolower($in['email']));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail('Invalid email address', 422);
    if (strlen($in['password']) < 6) fail('Password must be at least 6 characters', 422);

    $stmt = db()->prepare("SELECT id FROM users WHERE email = ? AND deleted_at IS NULL");
    $stmt->execute([$email]);
    if ($stmt->fetch()) fail('Email already registered. Try logging in.', 409);

    $roleId = db()->query("SELECT id FROM roles WHERE name = 'admin'")->fetchColumn();

    // name ko first/last me tod do
    $nameParts = preg_split('/\s+/', trim($in['name']), 2);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO companies (name) VALUES (?)")
            ->execute([trim($in['company'])]);
        $companyId = $pdo->lastInsertId();

        $pdo->prepare(
            "INSERT INTO users (company_id, emp_code, email, password, role_id, status, email_verified_at)
             VALUES (?, 'EMP001', ?, ?, ?, 'active', NOW())"
        )->execute([$companyId, $email, password_hash($in['password'], PASSWORD_BCRYPT), $roleId]);
        $userId = $pdo->lastInsertId();

        $pdo->prepare("INSERT INTO employee_profiles (user_id, first_name, last_name) VALUES (?, ?, ?)")
            ->execute([$userId, $nameParts[0], $nameParts[1] ?? null]);

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
