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
$rows = q("SELECT o.*, " . ORDER_TOTALS_SQL . " FROM orders o WHERE $where ORDER BY o.id", $params)->fetchAll();

// One row per item; order columns repeat on each item row.
$cols = ['order_no' => 'Order no', 'item_no' => 'Item', 'item_type' => 'Item type', 'created_at' => 'Created', 'created_by' => 'Created by'];
foreach (all_fields() as $k => $f) {
    if (!can_view($k)) {
        continue;
    }
    if ($f['type'] === 'stage') {
        $cols[$k] = str_replace(' (each item)', '', $f['label']);
        $cols[$k . '_at'] = ucfirst($k) . ' at';
        $cols[$k . '_by'] = ucfirst($k) . ' by';
    } elseif ($k === 'quantity') {
        $cols['quantity'] = $f['label'];
        $cols['length_m'] = 'DTF roll (m)';
    } elseif ($k === 'mockups') {
        $cols['img_count'] = 'Mock-up images';
    } else {
        $cols[$k] = $f['label'];
    }
}
$cols['status'] = 'Order status';

$imgCounts = [];
foreach (q('SELECT item_id, COUNT(*) n FROM order_images GROUP BY item_id')->fetchAll() as $r) {
    $imgCounts[(int)$r['item_id']] = (int)$r['n'];
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="looma-orders-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel shows ₹ and Malayalam correctly
fputcsv($out, array_map(fn($l) => trim(str_replace('✓', '', $l)), array_values($cols)));
foreach ($rows as $o) {
    $o['extra'] = json_decode($o['extra'] ?: '{}', true) ?: [];
    $st = order_status($o);
    foreach (order_items((int)$o['id']) as $n => $it) {
        $line = [];
        foreach (array_keys($cols) as $c) {
            $itemCol = field_scope($c) === 'item' || in_array($c, ['printed_at', 'printed_by'], true);
            $row = $itemCol ? $it : $o;
            $line[] = match (true) {
                $c === 'order_no' => order_no($o['id']),
                $c === 'item_no' => $n + 1,
                $c === 'item_type' => ITEM_TYPES[$it['item_type']] ?? $it['item_type'],
                $c === 'length_m' => $it['item_type'] === 'dtf_roll' ? (string)$it['length_m'] : '',
                $c === 'status' => $st['label'] . ($st['delayed'] ? ' (DELAYED)' : ''),
                $c === 'img_count' => $imgCounts[(int)$it['id']] ?? 0,
                str_ends_with($c, '_by') => user_name($row[$c]),
                in_array($c, STAGES, true) => $row[$c] ? 'Yes' : 'No',
                str_starts_with($c, 'cf_') => $row['extra'][$c] ?? '',
                default => (string)($row[$c] ?? ''),
            };
        }
        // Stop spreadsheet formula injection (plain phone numbers like +91 98470 12345 are left as they are).
        fputcsv($out, array_map(fn($v) => preg_match('/^[=+\-@]/', (string)$v) && !preg_match('/^\+[\d\s-]+$/', (string)$v) ? "'" . $v : $v, $line));
    }
}
fclose($out);
