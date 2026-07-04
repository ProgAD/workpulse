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
