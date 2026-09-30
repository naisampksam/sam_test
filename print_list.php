<?php
// Today's print list: everything waiting to be printed, with the blanks to pick and each item's mock-up.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/orders.php';

require_login();
if (!can_view('printed')) {
    http_response_code(403);
    exit('Not allowed.');
}

$today = today();
$show = in_array($_GET['show'] ?? '', ['due', 'delayed', 'all'], true) ? $_GET['show'] : 'all';
$where = 'o.deleted_at IS NULL AND o.shipped = 0 AND it.plain = 0 AND it.printed = 0';
$params = [];
if ($show === 'due') {
    $where .= ' AND o.due_date <= ?';
    $params[] = $today;
} elseif ($show === 'delayed') {
    $where .= ' AND o.due_date < ?';
    $params[] = $today;
}

$items = q("SELECT it.*, o.customer_id, o.due_date, o.created_at AS order_created
            FROM order_items it JOIN orders o ON o.id = it.order_id
            WHERE $where ORDER BY o.due_date, o.id, it.sort, it.id", $params)->fetchAll();

// Counts for the filter chips.
$counts = [];
foreach (['all' => '', 'due' => ' AND o.due_date <= ?', 'delayed' => ' AND o.due_date < ?'] as $k => $extra) {
    $counts[$k] = (int)q("SELECT IFNULL(SUM(it.quantity),0) FROM order_items it JOIN orders o ON o.id = it.order_id
                          WHERE o.deleted_at IS NULL AND o.shipped = 0 AND it.plain = 0 AND it.printed = 0$extra", $extra ? [$today] : [])->fetchColumn();
}

// Blanks to pick: GSM + product + color → sizes.
$blanks = [];
$sizeOrder = ['XS' => 1, 'S' => 2, 'M' => 3, 'L' => 4, 'XL' => 5, 'XXL' => 6, '2XL' => 6, '3XL' => 7, '4XL' => 8];
foreach ($items as $it) {
    $k = $it['gsm'] . '|' . $it['product'] . '|' . $it['color'];
    $blanks[$k] ??= ['gsm' => $it['gsm'], 'product' => $it['product'], 'color' => $it['color'], 'sizes' => [], 'total' => 0];
    $size = $it['size'] !== '' ? $it['size'] : '?';
    $blanks[$k]['sizes'][$size] = ($blanks[$k]['sizes'][$size] ?? 0) + (int)$it['quantity'];
    $blanks[$k]['total'] += (int)$it['quantity'];
}
foreach ($blanks as &$b) {
    uksort($b['sizes'], fn($x, $y) => ($sizeOrder[strtoupper($x)] ?? 99) <=> ($sizeOrder[strtoupper($y)] ?? 99) ?: strcmp($x, $y));
}
unset($b);
uasort($blanks, fn($x, $y) => [$x['gsm'], $x['product'], $x['color']] <=> [$y['gsm'], $y['product'], $y['color']]);

$images = [];
if ($items && can_view('mockups')) {
    $ids = implode(',', array_map('intval', array_column($items, 'id')));
    foreach (q("SELECT item_id, filename FROM order_images WHERE item_id IN ($ids) ORDER BY id")->fetchAll() as $img) {
        $images[$img['item_id']][] = $img['filename'];
    }
}
$hex = [];
foreach (q('SELECT p.name AS product, c.name, c.hex FROM product_colors c JOIN products p ON p.id = c.product_id')->fetchAll() as $c) {
    $hex[$c['product'] . '|' . $c['name']] = $c['hex'];
}
$printFields = ['front_print' => 'Front', 'back_print' => 'Back', 'chest_print' => 'Chest', 'custom_print' => 'Custom'];
$totalPcs = array_sum(array_column($blanks, 'total'));

$pageTitle = 'Print list';
$active = 'printlist';
require __DIR__ . '/inc/header.php';
?>
<div class="page-head">
  <div><h1>Print list</h1><p class="muted small"><?= plural(count($items), 'item') ?> · <?= plural($totalPcs, 'pc', 'pcs') ?> waiting to print</p></div>
  <div class="actions"><button type="button" class="btn" onclick="window.print()">🖨 Print this list</button></div>
</div>

<nav class="tabs">
  <a href="?show=all" class="<?= $show === 'all' ? 'on' : '' ?>">All waiting <span class="count"><?= $counts['all'] ?></span></a>
  <a href="?show=due" class="<?= $show === 'due' ? 'on' : '' ?>">Due today &amp; late <span class="count"><?= $counts['due'] ?></span></a>
  <a href="?show=delayed" class="<?= $show === 'delayed' ? 'on' : '' ?> <?= $counts['delayed'] ? 'warn' : '' ?>">Delayed <span class="count"><?= $counts['delayed'] ?></span></a>
</nav>

<?php if (!$items): ?>
  <p class="empty">🎉 Nothing waiting to print.</p>
<?php else: ?>

<section class="panel">
  <h2>Blanks to pick <small class="muted">pieces</small></h2>
  <div class="blank-list">
    <?php foreach ($blanks as $b): ?>
      <div class="blank-row">
        <span class="dot" style="background: <?= h($hex[$b['product'] . '|' . $b['color']] ?? '#ccc') ?>"></span>
        <div class="blank-main">
          <div><b><?= h($b['color'] ?: '—') ?></b> <span class="muted">· <?= h(trim($b['gsm'] . ' ' . $b['product'])) ?></span></div>
          <div class="size-pills"><?php foreach ($b['sizes'] as $s => $n): ?><span class="size-pill"><?= h($s) ?> <b>×<?= $n ?></b></span><?php endforeach; ?></div>
        </div>
        <div class="blank-total"><?= $b['total'] ?><small>pcs</small></div>
      </div>
    <?php endforeach; ?>
    <div class="blank-row blank-sum"><div class="blank-main"><b>Total blanks</b></div><div class="blank-total"><?= $totalPcs ?><small>pcs</small></div></div>
  </div>
</section>

<h2 class="section-title">Items to print</h2>
<div class="print-items">
<?php foreach ($items as $it): $late = $it['due_date'] && $it['due_date'] < $today; $imgs = $images[$it['id']] ?? []; ?>
  <article class="panel print-item <?= $late ? 'is-late' : '' ?>" data-id="<?= (int)$it['order_id'] ?>">
    <div class="pi-head">
      <a href="order.php?id=<?= (int)$it['order_id'] ?>"><b><?= h(order_no($it['order_id'])) ?></b></a>
      <span class="muted small"><?= h($it['customer_id']) ?></span>
      <?php if ($late): ?><span class="badge delayed">Delayed</span><?php elseif ($it['due_date'] === $today): ?><span class="badge pending">Due today</span><?php else: ?><span class="muted small">by <?= h(fmt_date($it['due_date'])) ?></span><?php endif; ?>
    </div>
    <div class="pi-body">
      <?php if ($imgs): ?>
        <div class="pi-imgs">
          <?php foreach (array_slice($imgs, 0, 4) as $f): ?>
            <a href="image.php?f=<?= h(urlencode($f)) ?>" class="gal-item" data-full="image.php?f=<?= h(urlencode($f)) ?>"><img loading="lazy" src="image.php?f=<?= h(urlencode(thumb_path($f))) ?>" alt=""></a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <div class="pi-info">
        <div class="pi-blank"><span class="dot" style="background: <?= h($hex[$it['product'] . '|' . $it['color']] ?? '#ccc') ?>"></span>
          <b><?= h($it['color']) ?> · <?= h($it['size']) ?></b> <span class="qty-pill">× <?= (int)$it['quantity'] ?></span></div>
        <div class="muted small"><?= h(trim($it['gsm'] . ' ' . $it['product'])) ?></div>
        <?php foreach ($printFields as $k => $label): if (trim((string)$it[$k]) === '' || !can_view($k)) continue; ?>
          <div class="pi-print"><span class="lbl"><?= $label ?></span> <?= nl2br(h($it[$k])) ?></div>
        <?php endforeach; ?>
        <?php $extra = json_decode($it['extra'] ?: '{}', true) ?: []; foreach (custom_fields() as $k => $f): if ($f['scope'] !== 'item' || empty($extra[$k]) || !can_view($k)) continue; ?>
          <div class="pi-print"><span class="lbl"><?= h($f['label']) ?></span> <?= h($extra[$k]) ?></div>
        <?php endforeach; ?>
        <?php if (can_view('neck_label') && $it['neck_label_on']): ?>
          <div class="pi-print"><span class="lbl">Neck label</span> <?= h($it['neck_label'] ?: 'Yes') ?></div>
        <?php endif; ?>
        <?php if (!$imgs && !array_filter(array_keys($printFields), fn($k) => trim((string)$it[$k]) !== '')): ?>
          <p class="muted small">No mock-up or print details — open the order.</p>
        <?php endif; ?>
      </div>
    </div>
    <button type="button" class="tick big item-tick" data-stage="printed" data-item="<?= (int)$it['id'] ?>" <?= can_edit('printed') ? '' : 'disabled' ?>>
      <span class="box"></span><span><b>Printed</b><small class="by"><?= can_edit('printed') ? 'Tap when done' : 'Not yet' ?></small></span>
    </button>
  </article>
<?php endforeach; ?>
</div>
<?php endif; ?>

<div class="lightbox" id="lightbox" hidden><img alt=""><button type="button" class="lb-close" aria-label="Close">✕</button></div>
<?php require __DIR__ . '/inc/footer.php'; ?>
