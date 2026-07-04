<?php
// json response helpers. every api response goes through these

function json_out(array $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function ok($data = null, string $message = 'success'): void
{
    json_out(['success' => true, 'message' => $message, 'data' => $data]);
}

function fail(string $message, int $status = 400, $errors = null): void
{
    json_out(['success' => false, 'message' => $message, 'errors' => $errors], $status);
}

function body(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === '' || $raw === false) return $_POST;
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

// require_fields(body(), ['email', 'password'])
function require_fields(array $data, array $fields): void
{
    $missing = [];
    foreach ($fields as $f) {
        if (!isset($data[$f]) || $data[$f] === '') $missing[] = $f;
    }
    if ($missing) fail('Missing required fields: ' . implode(', ', $missing), 422);
}
