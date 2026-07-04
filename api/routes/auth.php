<?php
// POST /auth/login, POST /auth/logout, GET /auth/me

route('POST', '/auth/login', function () {
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
});

route('POST', '/auth/logout', function () {
    require_auth();
    start_session();
    session_destroy();
    ok(null, 'Logged out');
});

route('GET', '/auth/me', function () {
    ok(require_auth());
});
