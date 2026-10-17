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
    $mDue = (float)q("SELECT IFNULL(SUM(total - paid), 0) FROM bills WHERE status = 'final' AND converted_to IS NULL AND total > paid")->fetchColumn();
    $lowStock = cap('stock') ? (int)q('SELECT COUNT(*) FROM stock_items WHERE active = 1 AND track = 1 AND qty <= low_level')->fetchColumn() : 0; ?>
<div class="stats">
  <a class="stat" href="bills.php?m=<?= h(substr($day, 0, 7)) ?>"><span class="stat-label">Sales in <?= h(date('F', strtotime($day))) ?></span><span class="stat-num"><?= h(money($mSales, 0)) ?></span><span class="stat-sub">before GST &amp; shipping</span></a>
  <a class="stat <?= $mDue > 0 ? 'stat-warn' : '' ?>" href="dues.php"><span class="stat-label">Due from customers</span><span class="stat-num"><?= h(money($mDue, 0)) ?></span><span class="stat-sub">tap to see who owes</span></a>
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
    <h2>Team · <?= $isToday ? 'today' : h(fmt_date($day)) ?> <small class="muted"><a href="performance.php">full performance →</a></small></h2>
    <?php require_once __DIR__ . '/inc/tasks.php'; $team = staff_performance($day, $day); $teamStaff = staff_list();
    $teamRows = array_filter($teamStaff, fn($u) => !empty($team[$u['id']])); ?>
    <?php if (!$teamRows): ?><p class="muted">No activity.</p><?php else: ?>
    <div class="table-wrap">
    <table class="table compact">
      <thead><tr><th>Staff</th><th class="num">Created</th><th class="num">Printed</th><th class="num">Packed</th><th class="num">Shipped</th><th class="num">Tasks done</th><th class="num">Pending</th><th class="num">Late</th></tr></thead>
      <tbody>
      <?php foreach ($teamRows as $tu): $r = $team[$tu['id']]; $g = fn(string $k) => (int)round($r[$k] ?? 0); ?>
        <tr onclick="location='performance.php?p=today&amp;user=<?= (int)$tu['id'] ?>'"><td><a href="performance.php?p=today&amp;user=<?= (int)$tu['id'] ?>"><?= h($tu['name'] ?: $tu['username']) ?></a></td>
          <td class="num"><?= $g('orders_pcs') ?: '·' ?></td><td class="num"><?= $g('printed') ?: '·' ?></td><td class="num"><?= $g('packed_pcs') ?: '·' ?></td><td class="num"><?= $g('shipped_pcs') ?: '·' ?></td>
          <td class="num"><b><?= $g('done') ?: '·' ?></b></td><td class="num"><?= $g('pending') ?: '·' ?></td>
          <td class="num"><?= $g('overdue') + $g('done_late') ? '<b class="err-text">' . ($g('overdue') + $g('done_late')) . '</b>' : '·' ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <p class="muted small">Order columns in pieces. Late = tasks past their due date.</p>
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

<?php
// ---------------------------------------------------------------- production: fabric → pieces
require_once __DIR__ . '/inc/billing.php';
$allBatches = q('SELECT * FROM production_batches ORDER BY batch_date, id')->fetchAll();
if ($allBatches):
    // Earlier batches of the same GSM + product + colour, for comparing each batch.
    $groups = [];
    $stats = [];
    foreach ($allBatches as $b) {
        $k = $b['gsm'] . '|' . $b['product'] . '|' . $b['color'];
        $st = batch_stats($b);
        $prev = $groups[$k] ?? [];
        $cmp = null;
        if ($prev) {
            $avg = fn(string $f) => ($v = array_filter(array_column($prev, $f), fn($x) => $x !== null)) ? array_sum($v) / count($v) : null;
            $share = [];
            foreach ($st['sizes'] as $sz => $_) {
                $share[$sz] = array_sum(array_map(fn($p) => $p['share'][$sz] ?? 0, $prev)) / count($prev);
            }
            $cmp = ['n' => count($prev), 'per_kg' => $avg('per_kg'), 'waste_pct' => $avg('waste_pct'), 'share' => $share,
                'cut_pct' => $avg('cut_pct'), 'other_pct' => $avg('other_pct'), 'cut_cost_pc' => $avg('cut_cost_pc'), 'cost_pc' => $avg('cost_pc'),
                'g' => array_sum(array_map(fn($p) => $p['g_per_pc'], $prev)) / count($prev)];
        }
        $stats[$b['id']] = $st + ['cmp' => $cmp, 'b' => $b];
        $groups[$k][] = $st;
    }
    $recent = array_slice(array_reverse($stats, true), 0, 12, true);
    $pct = fn(float $a, float $b) => $b != 0 ? ($a - $b) / $b * 100 : 0;
    $hex = [];
    foreach (q('SELECT p.name AS product, c.name, c.hex FROM product_colors c JOIN products p ON p.id = c.product_id')->fetchAll() as $c) {
        $hex[$c['product'] . '|' . $c['name']] = $c['hex'];
    }
?>
<section class="panel" id="production">
  <h2>🧵 Production · fabric to pieces <small class="muted">from “Add stock” with fabric &amp; rib weight</small></h2>
  <div class="table-wrap"><table class="table compact prod-sum">
    <thead><tr><th>Product · colour</th><th class="num">Batches</th><th class="num">Fabric + rib</th><th class="num">Pieces</th><th class="num">Pcs / kg</th><th class="num">g / pc</th><th class="num">Waste</th><th class="num">Cutting ₹/pc</th><th class="num">Cost / pc</th></tr></thead>
    <tbody>
    <?php foreach ($groups as $k => $list): [$g, $pr, $col] = explode('|', $k, 3);
        $kg = array_sum(array_column($list, 'kg')); $pcs = array_sum(array_column($list, 'pcs'));
        $w = array_filter($list, fn($x) => $x['waste_kg'] !== null);
        $wkg = array_sum(array_column($w, 'waste_kg')); $wbase = array_sum(array_column($w, 'kg'));
        $cc = array_filter($list, fn($x) => $x['cutting_cost'] !== null); $ccPcs = array_sum(array_column($cc, 'pcs'));
        $tc = array_filter($list, fn($x) => $x['total_cost'] !== null); $tcPcs = array_sum(array_column($tc, 'pcs')); ?>
      <tr><td><span class="dot" style="background:<?= h($hex[$pr . '|' . $col] ?? '#ccc') ?>"></span> <b><?= h($col) ?></b> <span class="muted small"><?= h($g . ' · ' . $pr) ?></span></td>
        <td class="num"><?= count($list) ?></td><td class="num"><?= h(qty_fmt(round($kg, 2))) ?> kg</td><td class="num"><?= $pcs ?></td>
        <td class="num"><b><?= $kg > 0 ? number_format($pcs / $kg, 2) : '—' ?></b></td><td class="num"><?= $pcs ? round($kg * 1000 / $pcs) : '—' ?></td>
        <td class="num"><?= $wbase > 0 ? number_format($wkg / $wbase * 100, 1) . '%' : '<span class="muted">—</span>' ?></td>
        <td class="num"><?= $ccPcs ? '₹' . number_format(array_sum(array_column($cc, 'cutting_cost')) / $ccPcs, 2) : '<span class="muted">—</span>' ?></td>
        <td class="num"><?= $tcPcs ? '<b>₹' . number_format(array_sum(array_column($tc, 'total_cost')) / $tcPcs, 2) . '</b>' : '<span class="muted">—</span>' ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="hint">Pcs / kg goes down when a batch has more big sizes, so the <b>waste %</b> is the fair comparison. Waste is the cutting + other waste you weighed; without it, it is worked out from the finished weight of one piece per size. These averages are offered in the <a href="estimate.php">cost estimator</a>.</p>

  <h3 class="perm-group">Latest batches</h3>
  <div class="batches">
  <?php foreach ($recent as $bid => $st): $b = $st['b']; $c = $st['cmp']; ?>
    <article class="batch">
      <div class="batch-head">
        <div><span class="dot" style="background:<?= h($hex[$b['product'] . '|' . $b['color']] ?? '#ccc') ?>"></span> <b><?= h($b['color']) ?></b> · <?= h($b['gsm'] . ' ' . $b['product']) ?>
          <div class="muted small"><?= h(fmt_date($b['batch_date'])) ?> · fabric <?= h(qty_fmt((float)$b['fabric_kg'])) ?> kg<?= (float)$b['rib_kg'] > 0 ? ' + rib ' . h(qty_fmt((float)$b['rib_kg'])) . ' kg' : '' ?><?= $b['note'] !== '' ? ' · ' . h($b['note']) : '' ?></div></div>
        <?php if (cap('stock')): ?><div class="batch-tools">
          <a class="icon-btn" href="stock.php?batch=<?= (int)$bid ?>" title="Edit batch">✎</a>
          <form method="post" action="stock.php" onsubmit="return confirm('Remove this production batch from the report? (Stock is not changed.)')"><?= csrf_field() ?><input type="hidden" name="do" value="del_batch"><input type="hidden" name="batch" value="<?= (int)$bid ?>"><button class="icon-btn" title="Remove batch">✕</button></form>
        </div><?php endif; ?>
      </div>
      <div class="batch-kpis">
        <div><span>Pieces</span><b><?= $st['pcs'] ?></b></div>
        <div><span>Pcs per kg</span><b><?= number_format($st['per_kg'], 2) ?></b>
          <?php if ($c && $c['per_kg']): $d = $pct($st['per_kg'], $c['per_kg']); ?><small class="<?= $d >= 0 ? 'ok-text' : 'err-text' ?>"><?= $d >= 0 ? '▲' : '▼' ?> <?= number_format(abs($d), 1) ?>% vs <?= number_format($c['per_kg'], 2) ?></small><?php endif; ?></div>
        <div><span>Fabric per piece</span><b><?= round($st['g_per_pc']) ?> g</b>
          <?php if ($c): ?><small class="muted">earlier <?= round($c['g']) ?> g</small><?php endif; ?></div>
        <div><span>Waste<?= $st['waste_measured'] ? ' (weighed)' : '' ?></span><?php if ($st['waste_pct'] !== null): ?><b class="<?= $st['waste_pct'] > 25 ? 'err-text' : '' ?>"><?= number_format($st['waste_pct'], 1) ?>%</b><small class="muted"><?= number_format($st['waste_kg'], 1) ?> kg of <?= h(qty_fmt(round($st['kg'], 2))) ?> kg</small>
            <?php if ($c && $c['waste_pct'] !== null): $d = $st['waste_pct'] - $c['waste_pct']; ?><small class="<?= $d <= 0.05 ? 'ok-text' : 'err-text' ?>"><?= abs($d) < 0.05 ? '= same as earlier ' . number_format($c['waste_pct'], 1) . '%' : ($d < 0 ? '▼ ' : '▲ ') . number_format(abs($d), 1) . ' points vs ' . number_format($c['waste_pct'], 1) . '%' ?></small><?php endif; ?>
          <?php else: ?><b class="muted">—</b><small class="muted">weigh the waste or add piece weights</small><?php endif; ?></div>
      </div>
      <?php if ($st['cost_pc'] !== null): $pp = $st['parts_pc']; ?>
      <div class="batch-cost">
        <div><span>Making cost per piece</span><b>₹<?= number_format($st['cost_pc'], 2) ?></b>
          <?php if ($c && $c['cost_pc'] !== null): $d = $st['cost_pc'] - $c['cost_pc']; ?><small class="<?= $d <= 0 ? 'ok-text' : 'err-text' ?>"><?= abs($d) < 0.005 ? '= same as earlier' : ($d < 0 ? '▼ ₹' : '▲ ₹') . number_format(abs($d), 2) . ' vs ₹' . number_format($c['cost_pc'], 2) ?></small><?php endif; ?></div>
        <small class="muted">₹<?= h(inr_number($st['total_cost'], 0)) ?> for <?= $st['pcs'] ?> pcs ·
          <?= h(implode(' + ', array_filter(array_map(fn($k, $l) => $pp[$k] !== null ? $l . ' ₹' . number_format($pp[$k], 2) : null, ['fabric', 'rib', 'cutting', 'cmt', 'transport'], ['fabric', 'rib', 'cutting', 'CMT', 'transport'])))) ?></small>
      </div>
      <?php endif; ?>
      <?php if ($st['cut_kg'] !== null || $st['other_kg'] !== null || $st['cutting_cost'] !== null || ($st['waste_measured'] && $st['calc_waste_pct'] !== null)): ?>
      <div class="batch-kpis">
        <?php if ($st['cut_kg'] !== null): ?><div><span>Cutting waste</span><b><?= h(qty_fmt($st['cut_kg'])) ?> kg</b><small class="muted"><?= number_format($st['cut_pct'], 1) ?>%<?= $c && $c['cut_pct'] !== null ? ' · earlier ' . number_format($c['cut_pct'], 1) . '%' : '' ?></small></div><?php endif; ?>
        <?php if ($st['other_kg'] !== null): ?><div><span>Other waste</span><b><?= h(qty_fmt($st['other_kg'])) ?> kg</b><small class="muted"><?= number_format($st['other_pct'], 1) ?>%<?= $c && $c['other_pct'] !== null ? ' · earlier ' . number_format($c['other_pct'], 1) . '%' : '' ?></small></div><?php endif; ?>
        <?php if ($st['cutting_cost'] !== null): ?><div><span>Cutting cost</span><b>₹<?= number_format($st['cut_cost_pc'], 2) ?> / pc</b><small class="muted">₹<?= h(inr_number($st['cutting_cost'], 0)) ?> for the batch<?= $c && $c['cut_cost_pc'] !== null ? ' · earlier ₹' . number_format($c['cut_cost_pc'], 2) : '' ?></small></div><?php endif; ?>
        <?php if ($st['waste_measured'] && $st['calc_waste_pct'] !== null): ?><div><span>Not in T-shirts</span><b><?= number_format($st['calc_waste_pct'], 1) ?>%</b><small class="muted">from piece weights<?= abs($st['calc_waste_pct'] - $st['waste_pct']) >= 1 ? ' · ' . number_format($st['calc_waste_pct'] - $st['waste_pct'], 1) . ' points not weighed' : '' ?></small></div><?php endif; ?>
      </div>
      <?php endif; ?>
      <div class="table-wrap"><table class="table compact size-split">
        <thead><tr><th>Size</th><?php foreach ($st['sizes'] as $sz => $n): ?><th class="num"><?= h($sz) ?></th><?php endforeach; ?><th class="num">Total</th></tr></thead>
        <tbody>
          <tr><td>Pieces</td><?php foreach ($st['sizes'] as $n): ?><td class="num"><b><?= (int)$n ?></b></td><?php endforeach; ?><td class="num"><b><?= $st['pcs'] ?></b></td></tr>
          <tr><td>Share</td><?php foreach ($st['share'] as $v): ?><td class="num"><?= round($v) ?>%</td><?php endforeach; ?><td></td></tr>
          <?php if ($c): ?><tr class="muted"><td>Earlier (<?= $c['n'] ?>)</td><?php foreach ($st['sizes'] as $sz => $_): $d = $st['share'][$sz] - $c['share'][$sz]; ?><td class="num"><?= round($c['share'][$sz]) ?>%<?php if (abs($d) >= 3): ?> <small class="<?= $d > 0 ? 'ok-text' : 'err-text' ?>"><?= $d > 0 ? '+' : '−' ?><?= round(abs($d)) ?></small><?php endif; ?></td><?php endforeach; ?><td></td></tr><?php endif; ?>
        </tbody>
      </table></div>
      <?php if (!$c): ?><p class="muted small" style="margin:6px 0 0">First batch of <?= h($b['color']) ?> — the next one is compared with this.</p><?php endif; ?>
    </article>
  <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>

<?php require __DIR__ . '/inc/footer.php'; ?>
