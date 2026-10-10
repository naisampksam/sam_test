<?php
// Mock-up images uploaded as soon as they are picked on the order form (so saving the order is quick).
// POST image=<file> → {id, thumb}; POST delete=<id> → removes it again. Unused uploads are cleared after 2 days.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/orders.php';

require_login();
header('Content-Type: application/json');
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !can_edit('mockups')) {
    http_response_code(403);
    exit(json_encode(['error' => 'Not allowed.']));
}
csrf_check();
$uid = (int)current_user()['id'];

// Tidy up: picked but never saved on an order.
foreach (q('SELECT * FROM temp_uploads WHERE created_at < ?', [date('Y-m-d H:i:s', strtotime('-2 days'))])->fetchAll() as $old) {
    q('DELETE FROM temp_uploads WHERE id = ?', [$old['id']]);
    unlink_if_unused($old['filename']);
}

if (isset($_POST['delete'])) {
    $t = q('SELECT * FROM temp_uploads WHERE id = ? AND user_id = ?', [(int)$_POST['delete'], $uid])->fetch();
    if ($t) {
        q('DELETE FROM temp_uploads WHERE id = ?', [$t['id']]);
        unlink_if_unused($t['filename']);
    }
    exit(json_encode(['ok' => true]));
}

$f = $_FILES['image'] ?? null;
if (!$f) {
    http_response_code(400);
    exit(json_encode(['error' => 'No image.']));
}
[$path, $err] = store_upload($f);
if (!$path) {
    http_response_code(400);
    exit(json_encode(['error' => $err ?: 'Could not save the image.']));
}
q('INSERT INTO temp_uploads (user_id, filename, original_name, created_at) VALUES (?, ?, ?, ?)', [$uid, $path, mb_substr((string)$f['name'], 0, 250), now()]);
echo json_encode(['id' => (int)db()->lastInsertId(), 'thumb' => 'image.php?f=' . rawurlencode(thumb_path($path))]);
