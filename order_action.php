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

if ($stage === 'hold') {
    // Print list: order not ready for printing yet (its blanks are left out of "Blanks to pick").
    $ok = can_edit('printed') && get_order($id);
    if ($ok) {
        q('UPDATE orders SET print_hold = ? WHERE id = ?', [$on ? 1 : 0, $id]);
        log_change($id, 'print_hold', null, $on ? 'not ready for printing' : 'ready for printing');
    }
    flash($ok ? ($on ? order_no($id) . ' moved to “Not ready”.' : order_no($id) . ' is ready to print.') : 'You are not allowed to change that.', $ok ? 'ok' : 'err');
    $back = array_filter(['show' => (string)($_POST['show'] ?? ''), 'q' => trim((string)($_POST['q'] ?? ''))], 'strlen');
    redirect('print_list.php' . ($back ? '?' . http_build_query($back) : ''));
}
$addonKind = null;
foreach (ITEM_ADDONS as $k => $ad) {
    if ($ad['done'] === $stage) {
        $addonKind = $k;
    }
}
$ok = $addonKind !== null ? ($itemId && set_addon_done($id, $itemId, $addonKind, $on))
    : ($stage === 'printed' ? set_printed($id, $itemId ?: null, $on) : set_stage($id, $stage, $on));
$o = $ok ? get_order($id) : null;

if (($_SERVER['HTTP_ACCEPT'] ?? '') === 'application/json') {
    header('Content-Type: application/json');
    if (!$o) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Not allowed']);
        exit;
    }
    $by = '';
    if (($stage === 'printed' || $addonKind !== null) && $itemId) {
        $it = q("SELECT $stage AS d, {$stage}_at AS d_at, {$stage}_by AS d_by FROM order_items WHERE id = ?", [$itemId])->fetch();
        $on = (bool)$it['d'];
        $by = $on ? user_name($it['d_by']) . ' · ' . fmt_date($it['d_at'], true) : '';
    } elseif ($stage !== 'printed') {
        $on = (bool)$o[$stage];
        $by = $on ? user_name($o[$stage . '_by']) . ' · ' . fmt_date($o[$stage . '_at'], true) : '';
    }
    echo json_encode([
        'ok' => true,
        'on' => $on,
        'by' => $by,
        'printed_count' => (int)$o['printed_count'],
        'item_count' => (int)$o['printable_count'],
        'order_printed' => (bool)$o['printed'],
        'status' => order_status($o),
    ]);
    exit;
}
flash($ok ? 'Updated.' : 'You are not allowed to change that.', $ok ? 'ok' : 'err');
redirect('order.php?id=' . $id);
