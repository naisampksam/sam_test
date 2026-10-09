<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/orders.php';

require_login();
if (!cap('dashboard')) {
    redirect('orders.php');
}

$day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['day'] ?? '') ? $_GET['day'] : today();
$dayStart = "$day 00:00:00";
$dayEnd = "$day 23:59:59";
$today = today();

/** Orders and pieces whose order-level date column (created/packed/shipped) is in range; printed is counted per item. */
function sum_between(string $stage, string $from, string $to): array
{
    if ($stage === 'printed') {
        $r = q("SELECT COUNT(*) n, IFNULL(SUM(it.quantity),0) pcs FROM order_items it JOIN orders o ON o.id = it.order_id
                WHERE o.deleted_at IS NULL AND it.printed_at BETWEEN ? AND ?", [$from, $to])->fetch();
    } else {
        $r = q("SELECT COUNT(DISTINCT o.id) n, IFNULL(SUM(it.quantity),0) pcs FROM orders o JOIN order_items it ON it.order_id = o.id
                WHERE o.deleted_at IS NULL AND o.{$stage}_at BETWEEN ? AND ?", [$from, $to])->fetch();
    }
    return ['n' => (int)$r['n'], 'pcs' => (int)$r['pcs']];
}

$daily = [];
foreach (['created', 'printed', 'packed', 'shipped'] as $k) {
    $daily[$k] = sum_between($k, $dayStart, $dayEnd);
}

$pipe = [];
foreach (['print' => 'To print', 'pack' => 'To pack', 'ship' => 'To ship', 'due' => 'Due today', 'delayed' => 'Delayed'] as $tab => $label) {
    [$w, $p] = order_filter_sql(['tab' => $tab]);
    $n = (int)q("SELECT COUNT(*) FROM orders o WHERE $w", $p)->fetchColumn();
    // "To print" counts only the pieces not printed yet.
    $pcs = (int)q("SELECT IFNULL(SUM(it.quantity),0) FROM order_items it JOIN orders o ON o.id = it.order_id WHERE $w" . ($tab === 'print' ? ' AND it.printed = 0 AND it.plain = 0' : ''), $p)->fetchColumn();
    $pipe[$tab] = ['label' => $label, 'n' => $n, 'pcs' => $pcs];
}

$delayed = q("SELECT o.*, " . ORDER_TOTALS_SQL . ",
                (SELECT GROUP_CONCAT(" . ITEM_LINE_SQL . " ORDER BY it.sort, it.id SEPARATOR ' | ')
                 FROM order_items it WHERE it.order_id = o.id) AS item_lines
              FROM orders o WHERE o.deleted_at IS NULL AND o.shipped = 0 AND o.due_date < ? ORDER BY o.due_date, o.id LIMIT 25", [$today])->fetchAll();

// On-time dispatch, last 30 days
$since = date('Y-m-d', strtotime('-29 days'));
$ot = q('SELECT COUNT(*) n, SUM(DATE(shipped_at) <= due_date) ontime,
               AVG(TIMESTAMPDIFF(HOUR, created_at, shipped_at)) avg_h
        FROM orders WHERE deleted_at IS NULL AND shipped = 1 AND shipped_at >= ?', [$since . ' 00:00:00'])->fetch();
$onTimePct = $ot['n'] ? round(100 * $ot['ontime'] / $ot['n']) : null;
$avgDays = $ot['avg_h'] !== null ? round($ot['avg_h'] / 24, 1) : null;

// Last 14 days trend (ending on the selected day)
$trendFrom = date('Y-m-d', strtotime("$day -13 days"));
$trend = [];
for ($i = 0; $i < 14; $i++) {
    $d = date('Y-m-d', strtotime("$trendFrom +$i days"));
    $trend[$d] = ['created' => 0, 'printed' => 0, 'packed' => 0, 'shipped' => 0];
}
foreach (['created', 'printed', 'packed', 'shipped'] as $k) {
    $col = $k === 'printed' ? 'it.printed_at' : "o.{$k}_at";
    $rows = q("SELECT DATE($col) d, SUM(it.quantity) pcs FROM order_items it JOIN orders o ON o.id = it.order_id
               WHERE o.deleted_at IS NULL AND $col BETWEEN ? AND ? GROUP BY DATE($col)", ["$trendFrom 00:00:00", $dayEnd])->fetchAll();
    foreach ($rows as $r) {
        if (isset($trend[$r['d']])) {
            $trend[$r['d']][$k] = (int)$r['pcs'];
        }
    }
}
$maxBar = max(1, ...array_values(array_map(fn($t) => max($t['printed'], $t['shipped']), $trend)));

// Staff activity on the selected day (pieces)
$staff = [];
foreach (['created', 'printed', 'packed', 'shipped'] as $k) {
    [$col, $by] = $k === 'printed' ? ['it.printed_at', 'it.printed_by'] : ["o.{$k}_at", "o.{$k}_by"];
    foreach (q("SELECT $by uid, SUM(it.quantity) pcs FROM order_items it JOIN orders o ON o.id = it.order_id
                WHERE o.deleted_at IS NULL AND $col BETWEEN ? AND ? GROUP BY $by", [$dayStart, $dayEnd])->fetchAll() as $r) {
        $staff[$r['uid']][$k] = (int)$r['pcs'];
    }
}

$couriersDay = q('SELECT o.courier, COUNT(DISTINCT o.id) n, SUM(it.quantity) pcs FROM orders o JOIN order_items it ON it.order_id = o.id
                  WHERE o.deleted_at IS NULL AND o.shipped_at BETWEEN ? AND ? GROUP BY o.courier ORDER BY pcs DESC', [$dayStart, $dayEnd])->fetchAll();
$products30 = q('SELECT it.gsm, it.product, SUM(it.quantity) pcs FROM order_items it JOIN orders o ON o.id = it.order_id
                 WHERE o.deleted_at IS NULL AND o.created_at >= ? GROUP BY it.gsm, it.product ORDER BY pcs DESC LIMIT 8', [$since . ' 00:00:00'])->fetchAll();

$isToday = $day === $today;
$pageTitle = 'Dashboard';
$active = 'dashboard';
require __DIR__ . '/inc/header.php';
?>
<div class="page-head">
  <h1>Dashboard</h1>
  <form class="day-pick" method="get">
    <a class="btn ghost" href="?day=<?= h(date('Y-m-d', strtotime("$day -1 day"))) ?>" aria-label="Previous day">‹</a>
    <input type="date" name="day" value="<?= h($day) ?>" max="<?= h($today) ?>" onchange="this.form.submit()">
    <?php if (!$isToday): ?><a class="btn ghost" href="?day=<?= h(date('Y-m-d', strtotime("$day +1 day"))) ?>" aria-label="Next day">›</a>
      <a class="btn" href="?">Today</a><?php endif; ?>
  </form>
</div>

<?php if (cap('billing')):
    require_once __DIR__ . '/inc/billing.php';
    $mSales = month_sales(substr($day, 0, 7));
    $mDue = (float)q("SELECT IFNULL(SUM(total - paid), 0) FROM bills WHERE type = 'invoice' AND status = 'final' AND total > paid")->fetchColumn();
    $lowStock = cap('stock') ? (int)q('SELECT COUNT(*) FROM stock_items WHERE active = 1 AND qty <= low_level')->fetchColumn() : 0; ?>
<div class="stats">
  <a class="stat" href="bills.php?m=<?= h(substr($day, 0, 7)) ?>"><span class="stat-label">Sales in <?= h(date('F', strtotime($day))) ?></span><span class="stat-num"><?= h(money($mSales, 0)) ?></span><span class="stat-sub">before GST &amp; shipping</span></a>
  <a class="stat <?= $mDue > 0 ? 'stat-warn' : '' ?>" href="bills.php?due=1"><span class="stat-label">Due from customers</span><span class="stat-num"><?= h(money($mDue, 0)) ?></span><span class="stat-sub">unpaid invoices</span></a>
  <?php if (cap('stock')): ?><a class="stat <?= $lowStock ? 'stat-warn' : '' ?>" href="stock.php?low=1"><span class="stat-label">Low stock</span><span class="stat-num"><?= $lowStock ?></span><span class="stat-sub">items to reorder</span></a><?php endif; ?>
</div>
<?php endif; ?>

<h2 class="section-title"><?= $isToday ? 'Today' : h(fmt_date($day)) ?></h2>
<div class="stats">
  <?php foreach (['created' => 'New orders', 'printed' => 'Printed', 'packed' => 'Packed', 'shipped' => 'Shipped'] as $k => $label): ?>
    <a class="stat" href="orders.php?tab=all&by=<?= $k ?>&from=<?= h($day) ?>&to=<?= h($day) ?>">
      <span class="stat-label"><?= $label ?></span>
      <span class="stat-num"><?= $daily[$k]['pcs'] ?> <small>pcs</small></span>
      <span class="stat-sub"><?= $daily[$k]['n'] ?> <?= $k === 'printed' ? 'item' : 'order' ?><?= $daily[$k]['n'] === 1 ? '' : 's' ?></span>
    </a>
  <?php endforeach; ?>
</div>

<h2 class="section-title">Right now</h2>
<div class="stats pipeline">
  <?php foreach ($pipe as $tab => $p): ?>
    <a class="stat <?= $tab === 'delayed' && $p['n'] ? 'stat-alert' : '' ?> <?= $tab === 'due' && $p['n'] ? 'stat-warn' : '' ?>" href="orders.php?tab=<?= $tab ?>">
      <span class="stat-label"><?= $tab === 'delayed' && $p['n'] ? '⚠ ' : '' ?><?= h($p['label']) ?></span>
      <span class="stat-num"><?= $p['n'] ?></span>
      <span class="stat-sub"><?= $p['pcs'] ?> pcs</span>
    </a>
  <?php endforeach; ?>
  <div class="stat">
    <span class="stat-label">On-time dispatch (30 days)</span>
    <span class="stat-num"><?= $onTimePct === null ? '—' : $onTimePct . '%' ?></span>
    <span class="stat-sub"><?= $avgDays === null ? 'No shipments yet' : 'avg ' . $avgDays . ' days to ship' ?></span>
  </div>
</div>

<?php if ($delayed): ?>
<section class="panel panel-alert">
  <h2>⚠ Delayed orders <small class="muted">(not shipped within <?= (int)setting('dispatch_days', '2') ?> days)</small></h2>
  <div class="table-wrap">
  <table class="table">
    <thead><tr><th>Order</th><th>Customer</th><th>Items</th><th class="num">Qty</th><th>Dispatch by</th><th>Stage</th></tr></thead>
    <tbody>
    <?php foreach ($delayed as $o): $st = order_status($o); $late = (int)((strtotime($today) - strtotime($o['due_date'])) / 86400); ?>
      <tr onclick="location='order.php?id=<?= (int)$o['id'] ?>'">
        <td><a href="order.php?id=<?= (int)$o['id'] ?>"><?= h(order_no($o['id'])) ?></a></td>
        <td><?= h(customer_order_no($o)) ?></td>
        <td class="wrap-cell"><?= h(mb_strimwidth((string)$o['item_lines'], 0, 90, '…')) ?></td>
        <td class="num"><?= (int)$o['total_qty'] ?></td>
        <td><?= h(fmt_date($o['due_date'])) ?> <span class="late"><?= $late ?>d late</span></td>
        <td><span class="badge <?= h($st['key']) ?>"><?= h($st['label']) ?></span></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  </div>
</section>
<?php endif; ?>

<section class="panel viz-root">
  <h2>Last 14 days <small class="muted">pieces per day</small></h2>
  <div class="legend">
    <span><i class="key k1"></i>Printed</span>
    <span><i class="key k2"></i>Shipped</span>
  </div>
  <div class="bars" role="img" aria-label="Pieces printed and shipped per day, last 14 days">
    <?php foreach ($trend as $d => $t): ?>
      <div class="bar-col <?= $d === $day ? 'sel' : '' ?>" tabindex="0">
        <div class="bar-pair">
          <div class="bar b1" style="height: <?= round(100 * $t['printed'] / $maxBar, 1) ?>%"></div>
          <div class="bar b2" style="height: <?= round(100 * $t['shipped'] / $maxBar, 1) ?>%"></div>
        </div>
        <div class="bar-x"><?= date('j', strtotime($d)) ?><br><small><?= date('D', strtotime($d))[0] ?></small></div>
        <div class="bar-tip"><b><?= h(date('D, d M', strtotime($d))) ?></b><br>New: <?= $t['created'] ?> pcs<br>Printed: <?= $t['printed'] ?><br>Packed: <?= $t['packed'] ?><br>Shipped: <?= $t['shipped'] ?></div>
      </div>
    <?php endforeach; ?>
  </div>
  <details>
    <summary>Show as table</summary>
    <div class="table-wrap">
    <table class="table compact">
      <thead><tr><th>Date</th><th class="num">New</th><th class="num">Printed</th><th class="num">Packed</th><th class="num">Shipped</th></tr></thead>
      <tbody>
      <?php foreach (array_reverse($trend, true) as $d => $t): ?>
        <tr><td><a href="?day=<?= h($d) ?>"><?= h(date('D, d M', strtotime($d))) ?></a></td>
          <td class="num"><?= $t['created'] ?></td><td class="num"><?= $t['printed'] ?></td><td class="num"><?= $t['packed'] ?></td><td class="num"><?= $t['shipped'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </details>
</section>

<div class="two-col">
  <section class="panel">
    <h2>Staff work · <?= $isToday ? 'today' : h(fmt_date($day)) ?> <small class="muted">pcs</small></h2>
    <?php if (!$staff): ?><p class="muted">No activity.</p><?php else: ?>
    <div class="table-wrap">
    <table class="table compact">
      <thead><tr><th>Staff</th><th class="num">Created</th><th class="num">Printed</th><th class="num">Packed</th><th class="num">Shipped</th></tr></thead>
      <tbody>
      <?php foreach ($staff as $uid => $s): ?>
        <tr><td><?= h(user_name($uid)) ?></td>
          <?php foreach (['created', 'printed', 'packed', 'shipped'] as $k): ?><td class="num"><?= $s[$k] ?? 0 ?></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </section>

  <section class="panel">
    <h2>Shipped by courier · <?= $isToday ? 'today' : h(fmt_date($day)) ?></h2>
    <?php if (!$couriersDay): ?><p class="muted">Nothing shipped.</p><?php else: ?>
<div class="table-wrap">    <table class="table compact">
      <thead><tr><th>Courier</th><th class="num">Orders</th><th class="num">Pcs</th></tr></thead>
      <tbody>
      <?php foreach ($couriersDay as $c): ?>
        <tr><td><?= h($c['courier'] ?: '(not set)') ?></td><td class="num"><?= (int)$c['n'] ?></td><td class="num"><?= (int)$c['pcs'] ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <?php endif; ?>
  </section>
</div>

<section class="panel">
  <h2>Top products · last 30 days</h2>
  <?php if (!$products30): ?><p class="muted">No orders yet.</p><?php else: ?>
<div class="table-wrap">  <table class="table compact">
    <thead><tr><th>Product</th><th class="num">Pcs ordered</th></tr></thead>
    <tbody>
    <?php foreach ($products30 as $p): ?>
      <tr><td><?= h(trim($p['gsm'] . ' ' . $p['product']) ?: '(not set)') ?></td><td class="num"><?= (int)$p['pcs'] ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<?php require __DIR__ . '/inc/footer.php'; ?>
