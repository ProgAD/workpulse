<?php
// notifications.php                    (GET)  -> apni notifications + unread count
// notifications.php?action=mark_read   (POST) -> ek ({id}) ya sab ({all:true}) read

require_once __DIR__ . '/../bootstrap.php';

if ($ACTION === 'mark_read' && $METHOD === 'POST') {
    $me = require_auth();
    $in = body();

    if (!empty($in['all'])) {
        db()->prepare("UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL")
            ->execute([$me['id']]);
        ok(null, 'All marked read');
    }

    require_fields($in, ['id']);
    // sirf apni notification, dusre ki id de di to kuch update nahi hoga
    db()->prepare("UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL")
        ->execute([(int)$in['id'], $me['id']]);
    ok(null, 'Marked read');
}

if ($METHOD === 'GET') {
    $me    = require_auth();
    $limit = min((int)($_GET['limit'] ?? 30), 100);

    $stmt = db()->prepare(
        "SELECT id, type, title, body, entity_type, entity_id, read_at, created_at
         FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT {$limit}"
    );
    $stmt->execute([$me['id']]);
    $rows = $stmt->fetchAll();

    $c = db()->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL");
    $c->execute([$me['id']]);

    ok(['unread' => (int)$c->fetchColumn(), 'items' => $rows]);
}

fail('Unknown action: ' . $ACTION, 404);
