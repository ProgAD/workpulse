<?php
// front controller. all /workpulse/api/* requests land here via .htaccess
// route files register handlers with route('GET', '/auth/me', fn() => ...)

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/helpers/Response.php';
require_once __DIR__ . '/helpers/Auth.php';

$GLOBALS['routes'] = [];

function route(string $method, string $pattern, callable $handler): void
{
    $GLOBALS['routes'][] = [strtoupper($method), $pattern, $handler];
}

// path after /api, e.g. /auth/login
function request_path(): string
{
    $uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
    $base = '/workpulse/api';
    if (str_starts_with($uri, $base)) $uri = substr($uri, strlen($base));
    return '/' . trim($uri, '/');
}

function dispatch(): void
{
    $method = $_SERVER['REQUEST_METHOD'];
    $path = request_path();

    foreach ($GLOBALS['routes'] as [$m, $pattern, $handler]) {
        if ($m !== $method) continue;
        // {id} style params
        $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>[^/]+)', $pattern) . '$#';
        if (preg_match($regex, $path, $matches)) {
            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);
            $handler($params);
            return;
        }
    }
    fail('Not found: ' . $method . ' ' . $path, 404);
}

route('GET', '/health', function () {
    db()->query('SELECT 1');
    ok(['app' => APP_NAME, 'db' => 'up']);
});

foreach (glob(__DIR__ . '/routes/*.php') as $routeFile) {
    require $routeFile;
}

try {
    dispatch();
} catch (PDOException $e) {
    error_log($e->getMessage());
    fail('Database error', 500);
} catch (Throwable $e) {
    error_log($e->getMessage());
    fail('Server error', 500);
}
