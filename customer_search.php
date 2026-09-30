<?php
// Customer suggestions for the order form: matches customer ID, phone or name.
require __DIR__ . '/inc/bootstrap.php';

require_login();
header('Content-Type: application/json');
if (!cap('create') && !can_edit('customer_id')) {
    echo '[]';
    exit;
}
$s = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($s) < 2) {
    echo '[]';
    exit;
}
$digits = preg_replace('/\D/', '', $s);
$rows = q("SELECT code, name, phone, address, pincode FROM customers
           WHERE code LIKE ? OR name LIKE ? " . (strlen($digits) >= 3 ? "OR REPLACE(REPLACE(phone, ' ', ''), '-', '') LIKE ?" : '') . "
           ORDER BY (code = ?) DESC, updated_at DESC LIMIT 8",
    strlen($digits) >= 3 ? ["$s%", "%$s%", "%$digits%", $s] : ["$s%", "%$s%", $s])->fetchAll();

// Only return the parts of the address this user may see.
$out = [];
foreach ($rows as $r) {
    $out[] = [
        'code' => $r['code'],
        'name' => can_view('ship_name') ? $r['name'] : '',
        'phone' => can_view('ship_phone') ? $r['phone'] : '',
        'address' => can_view('ship_address') ? (string)$r['address'] : '',
        'pincode' => can_view('ship_pincode') ? $r['pincode'] : '',
    ];
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
