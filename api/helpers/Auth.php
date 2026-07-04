<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/Response.php';

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// returns the logged-in user row (with role + permissions) or null
function current_user(): ?array
{
    start_session();
    if (empty($_SESSION['user_id'])) return null;

    static $user = null;
    if ($user !== null) return $user;

    $stmt = db()->prepare(
        "SELECT u.id, u.company_id, u.emp_code, u.email, u.status, u.role_id, u.must_change_password, r.name AS role
         FROM users u JOIN roles r ON r.id = u.role_id
         WHERE u.id = ? AND u.delete_flag = 0 AND u.status = 'active'"
    );
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;
    if ($user === null) return null;

    $stmt = db()->prepare(
        "SELECT p.name FROM role_permissions rp
         JOIN permissions p ON p.id = rp.permission_id
         WHERE rp.role_id = ?"
    );
    $stmt->execute([$user['role_id']]);
    $user['permissions'] = $stmt->fetchAll(PDO::FETCH_COLUMN);
    return $user;
}

// gate an endpoint: require_auth() or require_auth('leave.approve')
function require_auth(?string $permission = null): array
{
    $user = current_user();
    if ($user === null) fail('Unauthorized', 401);
    if ($permission !== null && !in_array($permission, $user['permissions'], true)) {
        fail('Forbidden', 403);
    }
    return $user;
}

function audit(string $action, string $entityType, ?int $entityId, $old = null, $new = null): void
{
    $user = current_user();
    $stmt = db()->prepare(
        "INSERT INTO audit_logs (actor_id, action, entity_type, entity_id, old_values, new_values, ip)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    );
    $stmt->execute([
        $user['id'] ?? null,
        $action,
        $entityType,
        $entityId,
        $old !== null ? json_encode($old) : null,
        $new !== null ? json_encode($new) : null,
        $_SERVER['REMOTE_ADDR'] ?? null,
    ]);
}

// kisi user ko notification bhej do - bell icon me dikhega.
// leave approve/reject, payslip ready wagaira sab isi se jaate hai
function notify(int $userId, string $type, string $title, ?string $body = null, ?string $entityType = null, ?int $entityId = null): void
{
    db()->prepare(
        "INSERT INTO notifications (user_id, type, title, body, entity_type, entity_id)
         VALUES (?, ?, ?, ?, ?, ?)"
    )->execute([$userId, $type, $title, $body, $entityType, $entityId]);
}

// company ke saare admin/HR - jinke paas ye permission hai unhe notify karne ke kaam aata hai
function admins_of_company(int $companyId, string $permission): array
{
    $stmt = db()->prepare(
        "SELECT DISTINCT u.id FROM users u
         JOIN role_permissions rp ON rp.role_id = u.role_id
         JOIN permissions p ON p.id = rp.permission_id
         WHERE u.company_id = ? AND p.name = ? AND u.status = 'active' AND u.delete_flag = 0"
    );
    $stmt->execute([$companyId, $permission]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}
