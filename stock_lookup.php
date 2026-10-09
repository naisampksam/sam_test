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
// Price comes from the catalog product (with its quantity chart), else the stock item.
$cp = catalog_product($it['gsm'], $it['product']);
$price = $cp && $cp['price'] !== null ? (float)$cp['price'] : ($s ? (float)$s['sale_price'] : 0);
$tiers = $cp ? parse_tiers((string)$cp['price_tiers']) : [];
echo json_encode(['found' => (bool)$s, 'name' => $s['name'] ?? '', 'qty' => $s ? (float)$s['qty'] : 0, 'unit' => $s['unit'] ?? 'pcs', 'price' => $price, 'tiers' => $tiers,
    'gst' => $s ? (float)$s['gst_rate'] : (float)setting('inv_default_gst', '5'), 'hsn' => $s['hsn'] ?? setting('inv_default_hsn', '6109')], JSON_UNESCAPED_UNICODE);
