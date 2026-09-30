<?php
// One-tap ticks: Printed (per item, or all items), Packed and Shipped (per order).
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
$itemId = (int)($_POST['item'] ?? 0);

$ok = $stage === 'printed'
    ? set_printed($id, $itemId ?: null, $on)
    : set_stage($id, $stage, $on);
$o = $ok ? get_order($id) : null;

if (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') {
    header('Content-Type: application/json');
    if (!$o) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Not allowed']);
        exit;
    }
    $by = '';
    if ($stage === 'printed' && $itemId) {
        $it = q('SELECT printed, printed_at, printed_by FROM order_items WHERE id = ?', [$itemId])->fetch();
        $on = (bool)$it['printed'];
        $by = $on ? user_name($it['printed_by']) . ' · ' . fmt_date($it['printed_at'], true) : '';
    } elseif ($stage !== 'printed') {
        $on = (bool)$o[$stage];
        $by = $on ? user_name($o[$stage . '_by']) . ' · ' . fmt_date($o[$stage . '_at'], true) : '';
    }
    echo json_encode([
        'ok' => true,
        'on' => $on,
        'by' => $by,
        'printed_count' => (int)$o['printed_count'],
        'item_count' => (int)$o['item_count'],
        'order_printed' => (bool)$o['printed'],
        'status' => order_status($o),
    ]);
    exit;
}
flash($ok ? 'Updated.' : 'You are not allowed to change that.', $ok ? 'ok' : 'err');
redirect('order.php?id=' . $id);
