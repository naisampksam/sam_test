<?php
// Quick tick from the order list / order page: printed, packed, shipped.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/orders.php';

require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}
csrf_check();
$id = (int)($_POST['id'] ?? 0);
$stage = (string)($_POST['stage'] ?? '');
$on = !empty($_POST['on']);
$ok = set_stage($id, $stage, $on);
$o = $ok ? get_order($id) : null;

if (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') {
    header('Content-Type: application/json');
    if (!$o) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Not allowed']);
        exit;
    }
    $st = order_status($o);
    echo json_encode([
        'ok' => true,
        'on' => (bool)$o[$stage],
        'by' => $o[$stage] ? user_name($o[$stage . '_by']) . ' · ' . fmt_date($o[$stage . '_at'], true) : '',
        'status' => $st,
    ]);
    exit;
}
flash($ok ? 'Updated.' : 'You are not allowed to change that.', $ok ? 'ok' : 'err');
redirect('order.php?id=' . $id);
