<?php
// quick check ke liye - server + db dono chal rahe hai ya nahi

require_once __DIR__ . '/bootstrap.php';

db()->query('SELECT 1');
ok(['app' => APP_NAME, 'db' => 'up']);
