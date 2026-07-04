<?php
// app config. copy to config.local.php to override on your machine (gitignored)

define('APP_NAME', 'WorkPulse');
define('BASE_PATH', dirname(__DIR__));
define('UPLOAD_DIR', BASE_PATH . '/uploads');
define('UPLOAD_URL', '/workpulse/uploads');
define('MAX_UPLOAD_BYTES', 5 * 1024 * 1024);

// XAMPP mariadb listens on unix socket only, not tcp. set DB_SOCKET to '' to use host
define('DB_SOCKET', '/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock');
define('DB_HOST', '127.0.0.1');
define('DB_NAME', 'hrms');
define('DB_USER', 'root');
define('DB_PASS', '');

define('SESSION_NAME', 'wp_session');
define('SESSION_LIFETIME', 60 * 60 * 8); // 8h

define('MAX_FAILED_LOGINS', 5);
define('LOCKOUT_MINS', 15);

if (file_exists(__DIR__ . '/config.local.php')) {
    require __DIR__ . '/config.local.php';
}
