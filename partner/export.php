<?php
// Partner: download their orders (Excel opens it) — status, customer, T-shirts, payment, courier, tracking, charge.
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/partner_money.php';

$pu = require_partner();
$p = $pu['partner'];
$from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d', strtotime('-90 days'));
$to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : today();
$rows = q('SELECT po.*, o.shipped, o.packed, o.printed, o.print_processed, o.courier AS o_courier, o.tracking_no, o.due_date, o.shipped_at, o.deleted_at,
                  (SELECT amount FROM partner_ledger l WHERE l.partner_order_id = po.id AND l.kind = \'charge\' LIMIT 1) AS charge
           FROM partner_orders po LEFT JOIN orders o ON o.id = po.order_id WHERE po.partner_id = ? AND po.created_at BETWEEN ? AND ? ORDER BY po.id', [$p['id'], $from . ' 00:00:00', $to . ' 23:59:59'])->fetchAll();
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9]+/i', '-', strtolower($p['name'])) . '-orders-' . $from . '-to-' . $to . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // Excel reads the ₹ and Malayalam correctly
fputcsv($out, ['Order no.', 'Sent on', 'Status', 'Customer', 'Mobile', 'Address', 'Pincode', 'T-shirts', 'Pieces', 'Payment', 'COD amount', 'Courier', 'Tracking', 'Shipped on', 'Returned (RTO)', 'Charge ₹'], ',', '"', '');
$safe = fn($v) => preg_match('/^[=+\-@]/', (string)$v) ? "'" . $v : (string)$v; // a cell never runs as a formula
foreach ($rows as $r) {
    $o = $r['order_id'] && !$r['deleted_at'] ? $r + ['courier' => $r['o_courier']] : null;
    [$label] = partner_order_stage($r, $o);
    $items = json_decode($r['items'] ?: '[]', true) ?: [];
    fputcsv($out, array_map($safe, [$r['ref'], substr($r['created_at'], 0, 16), $label, $r['ship_name'], $r['ship_phone'], str_replace("\n", ', ', (string)$r['ship_address']), $r['ship_pincode'],
        implode('; ', array_map(fn($it) => $it['design'] . ' – ' . $it['product'] . ' ' . $it['color'] . ' ' . $it['size'] . ' × ' . $it['qty'], $items)),
        array_sum(array_column($items, 'qty')), $r['payment_type'] === 'cod' ? 'COD' : 'Prepaid', $r['payment_type'] === 'cod' ? $r['cod_amount'] : '',
        $o ? $r['o_courier'] : '', $o ? $r['tracking_no'] : '', $o && $r['shipped_at'] ? substr($r['shipped_at'], 0, 10) : '', $r['rto_at'] ? substr($r['rto_at'], 0, 10) : '',
        $r['charge'] !== null ? $r['charge'] : '']), ',', '"', '');
}
fclose($out);
