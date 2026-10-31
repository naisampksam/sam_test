<?php
// Notifications on this device: the server's public key, turn on / off, and a test message (see inc/push.php).
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/push.php';

$me = require_login();
header('Content-Type: application/json');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['key' => vapid_keys()['public']]);
    exit;
}
csrf_check();
$do = (string)($_POST['do'] ?? '');
$sub = json_decode((string)($_POST['sub'] ?? ''), true);
if ($do === 'subscribe') {
    $ok = is_array($sub) && push_subscribe((int)$me['id'], $sub, (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
    echo json_encode(['ok' => $ok, 'error' => $ok ? null : 'This browser’s notification address was not accepted.']);
} elseif ($do === 'unsubscribe') {
    push_unsubscribe((string)($sub['endpoint'] ?? $_POST['endpoint'] ?? ''));
    echo json_encode(['ok' => true]);
} elseif ($do === 'test') {
    $n = push_to_user((int)$me['id'], ['title' => '🔔 Notifications are on', 'body' => 'You will get a message here when someone gives you a task.',
        'url' => base_url(is_erp_site() ? 'index.php' : 'tasks.php'), 'tag' => 'test']);
    echo json_encode(['ok' => $n > 0, 'sent' => $n]);
} else {
    http_response_code(400);
    echo json_encode(['ok' => false]);
}
