<?php
// CSV download of the current order list (opens in Excel / Google Sheets). Only columns the user may view.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/orders.php';

require_login();
if (!cap('export')) {
    http_response_code(403);
    exit('Not allowed.');
}

$g = [
    'tab' => (string)($_GET['tab'] ?? 'all'),
    'q' => (string)($_GET['q'] ?? ''),
    'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '',
    'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '',
    'by' => (string)($_GET['by'] ?? 'created'),
    'courier' => (string)($_GET['courier'] ?? ''),
];
[$where, $params] = order_filter_sql($g);
$rows = q("SELECT o.*, (SELECT COUNT(*) FROM order_images i WHERE i.order_id = o.id) AS img_count FROM orders o WHERE $where ORDER BY o.id", $params)->fetchAll();

$cols = ['order_no' => 'Order no', 'created_at' => 'Created', 'created_by' => 'Created by'];
foreach (all_fields() as $k => $f) {
    if (!can_view($k)) {
        continue;
    }
    if ($f['type'] === 'stage') {
        $cols[$k] = $f['label'];
        $cols[$k . '_at'] = ucfirst($k) . ' at';
        $cols[$k . '_by'] = ucfirst($k) . ' by';
    } elseif ($k === 'mockups') {
        $cols['img_count'] = 'Mock-up images';
    } else {
        $cols[$k] = $f['label'];
    }
}
$cols['status'] = 'Status';

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="looma-orders-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows ₹ and Malayalam correctly
fputcsv($out, array_map(fn($l) => str_replace('✓', '', $l), array_values($cols)));
foreach ($rows as $o) {
    $extra = json_decode($o['extra'] ?: '{}', true) ?: [];
    $st = order_status($o);
    $line = [];
    foreach (array_keys($cols) as $c) {
        $line[] = match (true) {
            $c === 'order_no' => order_no($o['id']),
            $c === 'status' => $st['label'] . ($st['delayed'] ? ' (DELAYED)' : ''),
            str_ends_with($c, '_by') => user_name($o[$c]),
            in_array($c, STAGES, true) => $o[$c] ? 'Yes' : 'No',
            str_starts_with($c, 'cf_') => $extra[$c] ?? '',
            default => (string)($o[$c] ?? ''),
        };
    }
    // Stop spreadsheet formula injection.
    fputcsv($out, array_map(fn($v) => preg_match('/^[=+\-@]/', (string)$v) ? "'" . $v : $v, $line));
}
fclose($out);
