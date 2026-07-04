<?php
// app config. machine-specific values live in config/.env (gitignored),
// see config/.env.example

function env(string $key, string $default = ''): string
{
    static $vars = null;
    if ($vars === null) {
        $vars = [];
        $file = __DIR__ . '/.env';
        if (is_readable($file)) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                $vars[trim($k)] = trim($v);
            }
        }
    }
    return $vars[$key] ?? $default;
}

define('APP_NAME', 'WorkPulse');
define('BASE_PATH', dirname(__DIR__));
define('UPLOAD_DIR', BASE_PATH . '/assests/uploads');
define('UPLOAD_URL', '/workpulse/assests/uploads');
define('MAX_UPLOAD_BYTES', 5 * 1024 * 1024);

define('DB_SOCKET', env('DB_SOCKET'));
define('DB_HOST', env('DB_HOST', '127.0.0.1'));
define('DB_NAME', env('DB_NAME', 'hrms'));
define('DB_USER', env('DB_USER', 'root'));
define('DB_PASS', env('DB_PASS', ''));

define('SESSION_NAME', 'wp_session');
define('SESSION_LIFETIME', 60 * 60 * 8); // 8h

define('MAX_FAILED_LOGINS', 5);
define('LOCKOUT_MINS', 15);
