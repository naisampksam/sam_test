<?php
// Customer suggestions for the order form: matches customer ID, customer name, ship-to name or phone.
// With ?code= it looks up one customer ID exactly and also returns the order number that order would get (C101-2).
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/orders.php';

require_login();
header('Content-Type: application/json');
if (!cap('create') && !can_edit('customer_id') && !can_edit('customer_name')) {
    echo '[]';
    exit;
}

/** Only the parts this user may see. */
function customer_out(array $r): array
{
    return [
        'code' => $r['code'],
        'customer_name' => can_view('customer_name') ? $r['name'] : '',
        'name' => can_view('ship_name') ? $r['ship_name'] : '',
        'phone' => can_view('ship_phone') ? $r['phone'] : '',
        'address' => can_view('ship_address') ? (string)$r['address'] : '',
        'pincode' => can_view('ship_pincode') ? $r['pincode'] : '',
    ];
}

if (isset($_GET['code'])) {
    $code = trim((string)$_GET['code']);
    if ($code === '') {
        echo json_encode(['found' => false, 'order_no' => '']);
        exit;
    }
    $c = q('SELECT * FROM customers WHERE code = ?', [$code])->fetch();
    // Existing order keeping its customer keeps its number; otherwise the next one for the day the order was created.
    $o = !empty($_GET['order']) ? get_order((int)$_GET['order']) : null;
    if ($o && mb_strtolower(trim($o['customer_id'])) === mb_strtolower($code) && $o['cust_seq']) {
        $seq = (int)$o['cust_seq'];
    } else {
        $seq = next_cust_seq($code, $o ? substr($o['created_at'], 0, 10) : today(), $o ? (int)$o['id'] : 0);
    }
    echo json_encode(['found' => (bool)$c, 'customer' => $c ? customer_out($c) : null,
        'order_no' => customer_order_no(['customer_id' => $c['code'] ?? $code, 'cust_seq' => $seq])], JSON_UNESCAPED_UNICODE);
    exit;
}

$s = trim((string)($_GET['q'] ?? ''));
if (mb_strlen($s) < 2) {
    echo '[]';
    exit;
}
$digits = preg_replace('/\D/', '', $s);
$rows = q("SELECT * FROM customers
           WHERE code LIKE ? OR name LIKE ? OR ship_name LIKE ? " . (strlen($digits) >= 3 ? "OR REPLACE(REPLACE(phone, ' ', ''), '-', '') LIKE ?" : '') . "
           ORDER BY (code = ?) DESC, updated_at DESC LIMIT 8",
    strlen($digits) >= 3 ? ["$s%", "%$s%", "%$s%", "%$digits%", $s] : ["$s%", "%$s%", "%$s%", $s])->fetchAll();
echo json_encode(array_map('customer_out', $rows), JSON_UNESCAPED_UNICODE);
