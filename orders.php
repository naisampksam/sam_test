<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/orders.php';

require_login();

$tabs = [
    'open' => 'All open', 'print' => 'To print', 'pack' => 'To pack', 'ship' => 'To ship',
    'due' => 'Due today', 'delayed' => 'Delayed', 'shipped' => 'Shipped', 'all' => 'All',
];
$g = [
    'tab' => isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'open',
    'q' => trim((string)($_GET['q'] ?? '')),
    'from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : '',
    'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : '',
    'by' => in_array($_GET['by'] ?? '', ['created', 'printed', 'packed', 'shipped'], true) ? $_GET['by'] : 'created',
    'courier' => (string)($_GET['courier'] ?? ''),
];

// Quick date filters (order date): one tap for today, yesterday, etc.
$today = today();
$quick = [
    'today' => ['Today', $today, $today],
    'yesterday' => ['Yesterday', date('Y-m-d', strtotime('-1 day')), date('Y-m-d', strtotime('-1 day'))],
    'daybefore' => ['Day before yesterday', date('Y-m-d', strtotime('-2 days')), date('Y-m-d', strtotime('-2 days'))],
    '3d' => ['Last 3 days', date('Y-m-d', strtotime('-2 days')), $today],
    '7d' => ['Last 7 days', date('Y-m-d', strtotime('-6 days')), $today],
    'month' => ['This month', date('Y-m-01'), $today],
    'lastmonth' => ['Last month', date('Y-m-01', strtotime('first day of last month')), date('Y-m-t', strtotime('last day of last month'))],
];
$activeQuick = '';
foreach ($quick as $k => [, $from, $to]) {
    if ($g['from'] === $from && $g['to'] === $to && $g['by'] === 'created') {
        $activeQuick = $k;
        break;
    }
}

// Tab counters (follow the date filter, so "Today" shows today's numbers on every tab)
$counts = [];
foreach (array_keys($tabs) as $t) {
    [$w, $p] = order_filter_sql(['tab' => $t, 'from' => $g['from'], 'to' => $g['to'], 'by' => $g['by']]);
    $counts[$t] = (int)q("SELECT COUNT(*) FROM orders o WHERE $w", $p)->fetchColumn();
}

[$where, $params] = order_filter_sql($g);
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 40;
$total = (int)q("SELECT COUNT(*) FROM orders o WHERE $where", $params)->fetchColumn();
$totalQty = (int)q("SELECT IFNULL(SUM(it.quantity),0) FROM order_items it JOIN orders o ON o.id = it.order_id WHERE $where", $params)->fetchColumn();
$order = $g['tab'] === 'shipped' ? 'o.shipped_at DESC' : ($g['tab'] === 'all' ? 'o.id DESC' : 'o.due_date ASC, o.id ASC');
$rows = q("SELECT o.*, " . ORDER_TOTALS_SQL . ", (SELECT filename FROM order_images i WHERE i.order_id = o.id ORDER BY i.id LIMIT 1) AS first_img,
                  (SELECT COUNT(*) FROM order_images i WHERE i.order_id = o.id) AS img_count
           FROM orders o WHERE $where ORDER BY $order LIMIT $per OFFSET " . (($page - 1) * $per), $params)->fetchAll();

// Items of the listed orders, for the item lines on each card.
$itemsBy = [];
if ($rows) {
    $ids = array_column($rows, 'id');
    foreach (q('SELECT * FROM order_items WHERE order_id IN (' . implode(',', array_map('intval', $ids)) . ') ORDER BY sort, id')->fetchAll() as $it) {
        $itemsBy[$it['order_id']][] = $it;
    }
}
$hex = [];
foreach (q('SELECT p.name AS product, c.name, c.hex FROM product_colors c JOIN products p ON p.id = c.product_id')->fetchAll() as $c) {
    $hex[$c['product'] . '|' . $c['name']] = $c['hex'];
}

function qs(array $over): string
{
    global $g;
    return '?' . http_build_query(array_filter(array_merge($g, $over), fn($v) => $v !== '' && $v !== null));
}

$pageTitle = 'Orders';
$active = 'orders';
require __DIR__ . '/inc/header.php';
?>
<div class="page-head">
  <h1>Orders</h1>
  <div class="actions">
    <?php if ($rows && cap('slips') && in_array($g['tab'], ['pack', 'ship', 'open', 'due', 'delayed'], true)): ?>
      <a class="btn" href="slip.php?ids=<?= h(implode(',', array_column($rows, 'id'))) ?>">🖨 Labels for these <?= count($rows) ?></a>
    <?php endif; ?>
    <?php if (cap('export')): ?><a class="btn" href="export.php<?= h(qs([])) ?>">⬇ Excel/CSV</a><?php endif; ?>
    <?php if (cap('create')): ?><a class="btn primary" href="order.php?new=1">+ New order</a><?php endif; ?>
  </div>
</div>

<nav class="tabs" aria-label="Order status">
  <?php foreach ($tabs as $k => $label): ?>
    <a href="<?= h(qs(['tab' => $k, 'page' => null])) ?>" class="<?= $g['tab'] === $k ? 'on' : '' ?> <?= $k === 'delayed' && $counts[$k] ? 'warn' : '' ?>">
      <?= h($label) ?> <span class="count"><?= $counts[$k] ?></span>
    </a>
  <?php endforeach; ?>
</nav>

<nav class="date-chips" aria-label="Order date">
  <span class="muted small">Order date:</span>
  <a href="<?= h(qs(['from' => null, 'to' => null, 'by' => null, 'page' => null])) ?>" class="<?= !$g['from'] && !$g['to'] ? 'on' : '' ?>">All dates</a>
  <?php foreach ($quick as $k => [$label, $from, $to]): ?>
    <a href="<?= h(qs(['from' => $from, 'to' => $to, 'by' => 'created', 'page' => null])) ?>" class="<?= $activeQuick === $k ? 'on' : '' ?>"><?= h($label) ?></a>
  <?php endforeach; ?>
  <?php if (($g['from'] || $g['to']) && !$activeQuick): ?>
    <span class="chip-custom on"><?= h(($g['from'] ? fmt_date($g['from']) : '…') . ' – ' . ($g['to'] ? fmt_date($g['to']) : '…')) ?></span>
  <?php endif; ?>
</nav>

<form class="filters" method="get">
  <input type="hidden" name="tab" value="<?= h($g['tab']) ?>">
  <input type="search" name="q" value="<?= h($g['q']) ?>" placeholder="Search customer ID, name, phone, order no…">
  <details <?= (($g['from'] || $g['to']) && !$activeQuick) || $g['courier'] || $g['by'] !== 'created' ? 'open' : '' ?>>
    <summary>More filters</summary>
    <div class="filter-grid">
      <label>Date of
        <select name="by">
          <?php foreach (['created' => 'Order created', 'printed' => 'Printed', 'packed' => 'Packed', 'shipped' => 'Shipped'] as $k => $l): ?>
            <option value="<?= $k ?>" <?= $g['by'] === $k ? 'selected' : '' ?>><?= $l ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>From <input type="date" name="from" value="<?= h($g['from']) ?>"></label>
      <label>To <input type="date" name="to" value="<?= h($g['to']) ?>"></label>
      <?php if (can_view('courier')): ?>
      <label>Delivery partner
        <select name="courier"><option value="">Any</option>
          <?php foreach (couriers() as $c): ?><option <?= $g['courier'] === $c ? 'selected' : '' ?>><?= h($c) ?></option><?php endforeach; ?>
        </select>
      </label>
      <?php endif; ?>
    </div>
  </details>
  <button class="btn">Search</button>
  <?php if ($g['q'] || $g['from'] || $g['to'] || $g['courier']): ?><a class="btn ghost" href="?tab=<?= h($g['tab']) ?>">Clear</a><?php endif; ?>
</form>

<p class="muted small"><?= $total ?> order<?= $total === 1 ? '' : 's' ?> · <?= $totalQty ?> pcs</p>

<?php if (!$rows): ?>
  <p class="empty">No orders here.</p>
<?php endif; ?>

<div class="cards">
<?php foreach ($rows as $o): $st = order_status($o); $its = $itemsBy[$o['id']] ?? []; $first = $its[0] ?? null; ?>
  <article class="card order-card <?= $st['delayed'] ? 'is-delayed' : '' ?>" data-id="<?= (int)$o['id'] ?>">
    <a class="card-link" href="order.php?id=<?= (int)$o['id'] ?>" aria-label="Open order <?= h(order_no($o['id'])) ?>"></a>
    <div class="card-top">
      <?php if (can_view('mockups') && $o['first_img']): ?>
        <img class="thumb" loading="lazy" src="image.php?f=<?= h(urlencode(thumb_path($o['first_img']))) ?>" alt="">
      <?php else: ?>
        <div class="thumb swatch-thumb" style="--sw: <?= h($first && can_view('color') ? ($hex[$first['product'] . '|' . $first['color']] ?? '#ddd') : '#ddd') ?>">👕</div>
      <?php endif; ?>
      <div class="card-main">
        <div class="row-between">
          <strong><?= h(order_no($o['id'])) ?></strong>
          <span class="badge <?= h($st['key']) ?>"><?= h($st['label']) ?></span>
        </div>
        <?php if (can_view('customer_id')): ?><div class="cust">Cust: <b><?= h($o['customer_id']) ?></b></div><?php endif; ?>
        <?php $to = array_filter([can_view('ship_name') ? $o['ship_name'] : '', can_view('ship_pincode') ? $o['ship_pincode'] : ''], 'strlen'); if ($to): ?>
          <div class="small muted">📍 <?= h(implode(' · ', $to)) ?></div>
        <?php endif; ?>
        <div class="spec"><b class="qty"><?= plural((int)$o['total_qty'], 'pc', 'pcs') ?></b> · <?= plural((int)$o['item_count'], 'item') ?>
          <?php if (can_view('mockups') && $o['img_count']): ?> · 🖼 <?= (int)$o['img_count'] ?><?php endif; ?></div>
        <ul class="item-lines">
          <?php foreach (array_slice($its, 0, 4) as $it): ?>
            <li><?php if ($it['plain']): ?><i class="pdot plain" title="Plain T-shirt"></i><?php elseif (can_view('printed')): ?><i class="pdot <?= $it['printed'] ? 'on' : '' ?>" title="<?= $it['printed'] ? 'Printed' : 'Not printed' ?>"></i><?php endif; ?>
              <span><?php
                if (!item_has_blank($it)) {
                    echo h(item_spec($it));
                } else {
                    $bits = [];
                    foreach (['gsm', 'product', 'color', 'size'] as $f) {
                        if (can_view($f) && $it[$f] !== '') {
                            $bits[] = h($it[$f]);
                        }
                    }
                    echo implode(' · ', $bits) ?: 'Item';
                }
              ?><?php if (can_view('quantity') && $it['item_type'] !== 'dtf_roll'): ?> <b>× <?= (int)$it['quantity'] ?></b><?php endif; ?><?php if ($it['plain']): ?> <span class="mini-tag">plain</span><?php endif; ?><?php if ($it['sub_order_id'] !== '' && can_view('sub_order_id')): ?> <span class="mini-tag">#<?= h($it['sub_order_id']) ?></span><?php endif; ?></span></li>
          <?php endforeach; ?>
          <?php if (count($its) > 4): ?><li class="more">+ <?= count($its) - 4 ?> more items</li><?php endif; ?>
        </ul>
        <div class="meta">
          <?php if ($st['delayed']): ?><span class="badge delayed">⚠ Delayed</span><?php endif; ?>
          <?php if (can_view('due_date') && $o['due_date'] && !$o['shipped']): ?><span>Dispatch by <?= h(fmt_date($o['due_date'])) ?></span><?php endif; ?>
          <?php if ($o['shipped'] && can_view('courier') && $o['courier']): ?><span><?= h($o['courier']) ?> <?= can_view('tracking_no') ? h($o['tracking_no']) : '' ?></span><?php endif; ?>
        </div>
      </div>
    </div>
    <div class="ticks">
      <?php if (can_view('printed')): ?>
        <?php if ((int)$o['printable_count'] === 0): ?>
          <span class="tick info done"><span class="box">–</span> Plain</span>
        <?php else: ?>
          <span class="tick info <?= $o['printed'] ? 'done' : '' ?>"><span class="box"><?= $o['printed'] ? '✓' : '' ?></span> Printed <?= (int)$o['printed_count'] ?>/<?= (int)$o['printable_count'] ?></span>
        <?php endif; ?>
      <?php endif; ?>
      <?php foreach (ORDER_STAGES as $s): if (!can_view($s)) continue; ?>
        <button type="button" class="tick <?= $o[$s] ? 'done' : '' ?>" data-stage="<?= $s ?>" <?= can_edit($s) ? '' : 'disabled' ?>
                title="<?= $o[$s] ? h(user_name($o[$s . '_by']) . ' · ' . fmt_date($o[$s . '_at'], true)) : '' ?>">
          <span class="box"><?= $o[$s] ? '✓' : '' ?></span> <?= ucfirst($s) ?>
        </button>
      <?php endforeach; ?>
    </div>
  </article>
<?php endforeach; ?>
</div>

<?php if ($total > $per): $pages = (int)ceil($total / $per); ?>
<nav class="pager">
  <?php if ($page > 1): ?><a class="btn" href="<?= h(qs(['page' => $page - 1])) ?>">← Prev</a><?php endif; ?>
  <span>Page <?= $page ?> of <?= $pages ?></span>
  <?php if ($page < $pages): ?><a class="btn" href="<?= h(qs(['page' => $page + 1])) ?>">Next →</a><?php endif; ?>
</nav>
<?php endif; ?>

<?php require __DIR__ . '/inc/footer.php'; ?>
