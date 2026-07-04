<?php
// har api file me sabse pehle yehi include hoga
// isse config, db, helpers sab ek saath mil jate hai

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers/Response.php';
require_once __DIR__ . '/helpers/Auth.php';
require_once __DIR__ . '/helpers/LoginId.php';

// koi bhi uncaught error ho to json me hi jaye, html error page nahi
set_exception_handler(function ($e) {
    error_log($e->getMessage());
    if ($e instanceof PDOException) fail('Database error', 500);
    fail('Server error', 500);
});

// method aur action har file me chahiye hota hai
$METHOD = $_SERVER['REQUEST_METHOD'];
$ACTION  = $_GET['action'] ?? '';
