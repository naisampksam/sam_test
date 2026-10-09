<?php
// Stock item for a blank (GSM / product / colour / size): stock left, price, GST and HSN — shown on the order form.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/orders.php';
require __DIR__ . '/inc/billing.php';

require_login();
header('Content-Type: application/json');
$it = ['item_type' => 'print'];
foreach (['gsm', 'product', 'color', 'size'] as $k) {
    $it[$k] = trim((string)($_GET[$k] ?? ''));
}
$s = $it['size'] !== '' || $it['product'] !== '' ? stock_for_item($it) : null;
echo json_encode($s ? ['found' => true, 'name' => $s['name'], 'qty' => (float)$s['qty'], 'unit' => $s['unit'], 'price' => (float)$s['sale_price'],
    'gst' => (float)$s['gst_rate'], 'hsn' => $s['hsn'], 'sku' => $s['sku']] : ['found' => false], JSON_UNESCAPED_UNICODE);
