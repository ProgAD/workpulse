<?php
// documents/index.php                  (GET)  -> apne documents (admin ?user_id= se kisi ke bhi)
// documents/index.php?action=upload    (POST) -> apna document upload (multipart: file, title, doc_type)
// documents/index.php?action=remove    (POST) -> apna document soft delete ({id})

require_once __DIR__ . '/../bootstrap.php';

if ($ACTION === 'upload' && $METHOD === 'POST') {
    $me = require_auth();
    $in = body();
    require_fields($in, ['title']);

    if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        fail('Please Select A File', 422);
    }
    if ($_FILES['file']['size'] > MAX_UPLOAD_BYTES) fail('File Is Larger Than 5MB', 422);

    $mime = mime_content_type($_FILES['file']['tmp_name']);
    $extMap = [
        'application/pdf' => 'pdf',
        'image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp',
    ];
    if (!isset($extMap[$mime])) fail('Only PDF, JPG, PNG Or WEBP Files Are Allowed', 422);

    $fname = 'doc_' . bin2hex(random_bytes(8)) . '.' . $extMap[$mime];
    if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0777, true);
    if (!move_uploaded_file($_FILES['file']['tmp_name'], UPLOAD_DIR . '/' . $fname)) {
        fail('Could Not Save The File', 500);
    }

    db()->prepare(
        "INSERT INTO employee_documents (user_id, doc_type, title, file_url, file_size, mime_type, uploaded_by)
         VALUES (?, ?, ?, ?, ?, ?, ?)"
    )->execute([
        $me['id'], $in['doc_type'] ?? 'other', trim($in['title']),
        UPLOAD_URL . '/' . $fname, (int)$_FILES['file']['size'], $mime, $me['id'],
    ]);

    $docId = (int)db()->lastInsertId();
    audit('create', 'document', $docId);
    ok(['id' => $docId, 'file_url' => UPLOAD_URL . '/' . $fname], 'Document uploaded');
}

if ($ACTION === 'remove' && $METHOD === 'POST') {
    $me = require_auth();
    $in = body();
    require_fields($in, ['id']);

    // sirf apna document hata sakte ho
    $stmt = db()->prepare("UPDATE employee_documents SET delete_flag = 1 WHERE id = ? AND user_id = ?");
    $stmt->execute([(int)$in['id'], $me['id']]);
    if (!$stmt->rowCount()) fail('Document not found', 404);

    audit('delete', 'document', (int)$in['id']);
    ok(null, 'Document removed');
}

if ($METHOD === 'GET') {
    $me  = require_auth();
    $uid = (int)($_GET['user_id'] ?? $me['id']);

    // dusre ka sirf admin dekh sakta hai
    if ($uid !== (int)$me['id'] && !in_array('employee.view_all', $me['permissions'], true)) {
        fail('Forbidden', 403);
    }

    $stmt = db()->prepare(
        "SELECT d.id, d.doc_type, d.title, d.file_url, d.file_size, d.mime_type,
                d.verified_at, d.created_at
         FROM employee_documents d
         JOIN users u ON u.id = d.user_id
         WHERE d.user_id = ? AND u.company_id = ? AND d.delete_flag = 0
         ORDER BY d.created_at DESC"
    );
    $stmt->execute([$uid, $me['company_id']]);
    ok($stmt->fetchAll());
}

fail('Unknown action: ' . $ACTION, 404);
