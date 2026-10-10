<?php
// Bills list and monthly sales report. "Sales" = tax invoices that are not cancelled, before GST and shipping.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/orders.php';
require __DIR__ . '/inc/billing.php';

require_login();
if (!cap('billing')) {
    http_response_code(403);
    exit('You are not allowed to see bills.');
}
$month = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] : date('Y-m');
$type = isset(BILL_TYPES[$_GET['type'] ?? '']) ? $_GET['type'] : '';
$s = trim((string)($_GET['q'] ?? ''));
$dueOnly = !empty($_GET['due']);
$from = $month . '-01';

$where = ['bill_date >= ?', 'bill_date <= LAST_DAY(?)'];
$params = [$from, $from];
if ($type !== '') {
    $where[] = 'type = ?';
    $params[] = $type;
}
if ($s !== '') {
    $where[] = "CONCAT_WS(' ', number, bill_name, customer_code, bill_phone) LIKE ?";
    $params[] = '%' . $s . '%';
}
if ($dueOnly) {
    $where[] = "status = 'final' AND converted_to IS NULL AND total > paid";
}
$bills = q('SELECT * FROM bills WHERE ' . implode(' AND ', $where) . ' ORDER BY bill_date DESC, id DESC', $params)->fetchAll();

$r = q("SELECT COUNT(*) n, IFNULL(SUM(taxable), 0) sales, IFNULL(SUM(tax_total), 0) tax, IFNULL(SUM(shipping), 0) ship,
               IFNULL(SUM(total), 0) total, IFNULL(SUM(paid), 0) paid
        FROM bills WHERE status = 'final' AND converted_to IS NULL AND bill_date >= ? AND bill_date <= LAST_DAY(?)", [$from, $from])->fetch();
$allDue = (float)q("SELECT IFNULL(SUM(total - paid), 0) FROM bills WHERE status = 'final' AND converted_to IS NULL AND total > paid")->fetchColumn();
$prev = date('Y-m', strtotime($from . ' -1 month'));
$next = date('Y-m', strtotime($from . ' +1 month'));

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bills-' . $month . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['Number', 'Type', 'Date', 'Customer', 'GSTIN', 'Sales value (before tax)', 'GST', 'Shipping', 'Total', 'Received', 'Status']);
    foreach ($bills as $b) {
        fputcsv($out, [$b['number'], BILL_TYPES[$b['type']], $b['bill_date'], $b['bill_name'], $b['bill_gstin'], $b['taxable'], $b['tax_total'], $b['shipping'], $b['total'], $b['paid'], $b['status']]);
    }
    exit;
}

$pageTitle = 'Bills';
$active = 'bills';
require __DIR__ . '/inc/header.php';
$qs = fn(array $o) => '?' . http_build_query(array_filter(array_merge(['m' => $month, 'type' => $type, 'q' => $s, 'due' => $dueOnly ? 1 : ''], $o), fn($v) => $v !== '' && $v !== null));
?>
<div class="page-head">
  <div><h1>Bills</h1><p class="muted small">Invoices (no branding, GST added) and GST invoices (with your name and GSTIN) take stock and count as sales and dues.</p></div>
  <div class="actions">
    <a class="btn primary" href="bill.php?new=1&type=invoice">+ New invoice</a>
  </div>
</div>

<nav class="month-nav">
  <a class="btn small" href="<?= h($qs(['m' => $prev])) ?>">←</a>
  <b><?= h(date('F Y', strtotime($from))) ?></b>
  <a class="btn small" href="<?= h($qs(['m' => $next])) ?>">→</a>
  <?php if ($month !== date('Y-m')): ?><a class="btn small ghost" href="<?= h($qs(['m' => date('Y-m')])) ?>">This month</a><?php endif; ?>
</nav>

<div class="stats">
  <div class="stat"><span class="stat-label">Sales</span><span class="stat-num"><?= h(money((float)$r['sales'], 0)) ?></span><span class="stat-sub">before GST &amp; shipping · <?= (int)$r['n'] ?> invoice<?= (int)$r['n'] === 1 ? '' : 's' ?></span></div>
  <div class="stat"><span class="stat-label">GST collected</span><span class="stat-num"><?= h(money((float)$r['tax'], 0)) ?></span><span class="stat-sub">shipping <?= h(money((float)$r['ship'], 0)) ?></span></div>
  <div class="stat"><span class="stat-label">Billed (total)</span><span class="stat-num"><?= h(money((float)$r['total'], 0)) ?></span><span class="stat-sub">received <?= h(money((float)$r['paid'], 0)) ?></span></div>
  <a class="stat <?= $allDue > 0 ? 'stat-warn' : '' ?>" href="<?= h($qs(['due' => 1, 'm' => $month])) ?>"><span class="stat-label">Due from customers</span><span class="stat-num"><?= h(money($allDue, 0)) ?></span><span class="stat-sub">all months · tap for this month</span></a>
</div>

<form class="filters" method="get" style="margin-top:14px">
  <input type="hidden" name="m" value="<?= h($month) ?>">
  <input type="search" name="q" value="<?= h($s) ?>" placeholder="Search number, customer, phone…">
  <select name="type"><option value="">All bills</option><?php foreach (BILL_TYPES as $k => $l): ?><option value="<?= $k ?>" <?= $type === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select>
  <button class="btn">Search</button>
  <a class="btn ghost" href="<?= h($qs(['export' => 'csv'])) ?>">⬇ CSV</a>
  <?php if ($s !== '' || $type !== '' || $dueOnly): ?><a class="btn ghost" href="?m=<?= h($month) ?>">Clear</a><?php endif; ?>
</form>

<?php if (!$bills): ?>
  <p class="empty">No bills in <?= h(date('F Y', strtotime($from))) ?><?= $s !== '' || $type !== '' || $dueOnly ? ' for this search' : '' ?>.</p>
<?php else: ?>
<section class="panel">
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Bill</th><th>Customer</th><th class="num">Sales value</th><th class="num">Total</th><th>Status</th></tr></thead>
    <tbody>
    <?php foreach ($bills as $b): $due = (float)$b['total'] - (float)$b['paid']; $url = 'bill.php?id=' . (int)$b['id']; ?>
      <tr onclick="location='<?= h($url) ?>'" class="<?= $b['status'] === 'cancelled' ? 'inactive' : '' ?>">
        <td><a href="<?= h($url) ?>"><b><?= h($b['number']) ?></b></a><br><small class="muted"><?= h(fmt_date($b['bill_date'])) ?> · <?= $b['type'] === 'proforma' ? 'Proforma' : 'Invoice' ?><?= $b['branding'] === 'plain' ? ' · plain' : '' ?></small></td>
        <td><?php if ($b['bill_name'] !== ''): ?><?= h($b['bill_name']) ?><?= $b['customer_code'] !== '' ? '<br><small class="muted">' . h($b['customer_code']) . '</small>' : '' ?><?php else: ?><?= $b['customer_code'] !== '' ? h($b['customer_code']) : '<span class="muted">Walk-in</span>' ?><?php endif; ?></td>
        <td class="num"><?= h(money((float)$b['taxable'])) ?></td>
        <td class="num"><b><?= h(money((float)$b['total'])) ?></b></td>
        <td><?php if ($b['status'] === 'cancelled'): ?><span class="badge delayed">Cancelled</span>
          <?php elseif ($b['type'] === 'proforma'): ?><span class="badge pending"><?= $b['converted_to'] ? 'Converted' : 'Proforma' ?></span>
          <?php elseif ($due <= 0.001): ?><span class="badge shipped">Paid</span>
          <?php else: ?><span class="badge printing">Due <?= h(money($due, 0)) ?></span><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php'; ?>
