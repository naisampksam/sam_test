<?php
// Stock left for a blank (GSM / product / colour / size) — shown on the order form while choosing.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/orders.php';
require __DIR__ . '/inc/billing.php';

require_login();
header('Content-Type: application/json');
$it = ['item_type' => 'print'];
foreach (['gsm', 'product', 'color', 'size'] as $k) {
    $it[$k] = trim((string)($_GET[$k] ?? ''));
}
$s = $it['size'] !== '' && $it['color'] !== '' ? stock_for_item($it) : null;
echo json_encode($s ? ['found' => true, 'name' => $s['name'], 'qty' => (float)$s['qty'], 'unit' => $s['unit']] : ['found' => false], JSON_UNESCAPED_UNICODE);
