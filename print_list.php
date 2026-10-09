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
// Items still to print; plain T-shirts too while their neck label / chest logo is not done.
$toPrint = '((it.plain = 0 AND it.printed = 0) OR (it.plain = 1 AND ((it.neck_label_on = 1 AND it.neck_done = 0) OR (it.chest_logo_on = 1 AND it.logo_done = 0))))';
$where = 'o.deleted_at IS NULL AND o.shipped = 0 AND ' . $toPrint;
$params = [];
if ($show === 'due') {
    $where .= ' AND o.due_date <= ?';
    $params[] = $today;
} elseif ($show === 'delayed') {
    $where .= ' AND o.due_date < ?';
    $params[] = $today;
}

$all = q("SELECT it.*, o.customer_id, o.cust_seq, o.customer_name, o.due_date, o.print_processed, o.print_processed_at, o.print_processed_by, o.created_at AS order_created, o.print_hold
          FROM order_items it JOIN orders o ON o.id = it.order_id
          WHERE $where ORDER BY o.due_date, o.id, it.sort, it.id", $params)->fetchAll();
// Orders marked "Not ready" stay out of the blanks and the items to print until someone marks them ready.
$items = array_values(array_filter($all, fn($it) => !$it['print_hold']));
$held = [];
foreach ($all as $it) {
    if ($it['print_hold']) {
        $held[$it['order_id']] ??= ['customer_id' => customer_order_no($it), 'due_date' => $it['due_date'], 'items' => []];
        $held[$it['order_id']]['items'][] = $it;
    }
}
$canHold = can_edit('printed');
$search = trim((string)($_GET['q'] ?? ''));

// Counts for the filter chips.
$counts = [];
foreach (['all' => '', 'due' => ' AND o.due_date <= ?', 'delayed' => ' AND o.due_date < ?'] as $k => $extra) {
    $counts[$k] = (int)q("SELECT IFNULL(SUM(it.quantity),0) FROM order_items it JOIN orders o ON o.id = it.order_id
                          WHERE o.deleted_at IS NULL AND o.shipped = 0 AND o.print_hold = 0 AND $toPrint$extra", $extra ? [$today] : [])->fetchColumn();
}

// Blanks to pick: GSM + product + color → sizes.
$blanks = [];
$sizeOrder = ['XS' => 1, 'S' => 2, 'M' => 3, 'L' => 4, 'XL' => 5, 'XXL' => 6, '2XL' => 6, '3XL' => 7, '4XL' => 8];
$noBlank = ['print_only' => 0, 'dtf_roll' => 0.0];
foreach ($items as $it) {
    if (!item_has_blank($it)) {
        $noBlank[$it['item_type']] += $it['item_type'] === 'dtf_roll' ? (float)$it['length_m'] : (int)$it['quantity'];
        continue;
    }
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
    foreach (q("SELECT item_id, filename, original_name, kind FROM order_images WHERE item_id IN ($ids) ORDER BY id")->fetchAll() as $img) {
        $images[$img['item_id']][] = $img;
    }
    $allImages = with_design_images($items, $images);
    $images = array_map(fn($list) => array_column(images_of($list, ''), 'filename'), $allImages);
}
$hex = [];
foreach (q('SELECT p.name AS product, c.name, c.hex FROM product_colors c JOIN products p ON p.id = c.product_id')->fetchAll() as $c) {
    $hex[$c['product'] . '|' . $c['name']] = $c['hex'];
}
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
  <p class="empty"><?= $held ? 'Nothing ready to print — the orders below are marked “Not ready”.' : '🎉 Nothing waiting to print.' ?></p>
<?php else: ?>

<section class="panel">
  <h2>Blanks to pick <small class="muted">pieces</small></h2>
  <div class="blank-list">
    <?php foreach ($blanks as $b): ?>
      <div class="blank-row">
        <span class="dot" style="background: <?= h($hex[$b['product'] . '|' . $b['color']] ?? '#ccc') ?>"></span>
        <div class="blank-main">
          <?php $spec = trim($b['gsm'] . ' ' . $b['product']); ?>
          <div><b><?= h($b['color'] ?: ($spec === '' ? 'T-shirt not chosen yet' : 'Colour not set')) ?></b><?php if ($spec !== ''): ?> <span class="muted">· <?= h($spec) ?></span><?php endif; ?></div>
          <div class="size-pills"><?php foreach ($b['sizes'] as $s => $n): ?><span class="size-pill"><?= h($s === '?' || $s === '' ? 'No size' : $s) ?> <b>×<?= $n ?></b></span><?php endforeach; ?></div>
        </div>
        <div class="blank-total"><?= $b['total'] ?><small>pcs</small></div>
      </div>
    <?php endforeach; ?>
    <?php if ($noBlank['print_only']): ?>
      <div class="blank-row"><span class="dot" style="background: transparent"></span><div class="blank-main"><b>Print only</b><span class="muted small">no T-shirt to pick</span></div><div class="blank-total"><?= $noBlank['print_only'] ?><small>prints</small></div></div>
    <?php endif; ?>
    <?php if ($noBlank['dtf_roll']): ?>
      <div class="blank-row"><span class="dot" style="background: transparent"></span><div class="blank-main"><b>DTF roll</b><span class="muted small">24" film</span></div><div class="blank-total"><?= rtrim(rtrim(number_format($noBlank['dtf_roll'], 2, '.', ''), '0'), '.') ?><small>metres</small></div></div>
    <?php endif; ?>
    <div class="blank-row blank-sum"><div class="blank-main"><b>Total T-shirts</b></div><div class="blank-total"><?= $totalPcs ?><small>pcs</small></div></div>
  </div>
</section>

<h2 class="section-title">Items to print</h2>
<div class="filters pi-search no-print">
  <input type="search" id="piSearch" value="<?= h($search) ?>" placeholder="Search order no, customer ID, sub-order, colour, print…" autocomplete="off">
</div>
<p class="empty" id="piNone" hidden>No items match your search.</p>
<div class="print-items">
<?php foreach ($items as $it): $late = $it['due_date'] && $it['due_date'] < $today; $imgs = $images[$it['id']] ?? []; ?>
  <article class="panel print-item <?= $late ? 'is-late' : '' ?>" data-id="<?= (int)$it['order_id'] ?>">
    <div class="pi-head">
      <a href="order.php?id=<?= (int)$it['order_id'] ?>"><b><?= h(order_no($it['order_id'])) ?></b></a>
      <span class="muted small"><b><?= h(customer_order_no($it)) ?></b><?= $it['customer_name'] !== '' && can_view('customer_name') ? ' · ' . h($it['customer_name']) : '' ?></span>
      <?php if ($late): ?><span class="badge delayed">Delayed</span><?php elseif ($it['due_date'] === $today): ?><span class="badge pending">Due today</span><?php else: ?><span class="muted small">by <?= h(fmt_date($it['due_date'])) ?></span><?php endif; ?>
      <?php if (can_view('print_processed')): $pp = (bool)$it['print_processed']; ?>
        <button type="button" class="tick pi-pp <?= $pp ? 'done' : '' ?>" data-stage="print_processed" <?= can_edit('print_processed') ? '' : 'disabled' ?>
                title="<?= $pp ? h(user_name($it['print_processed_by']) . ' · ' . fmt_date($it['print_processed_at'], true)) : '' ?>"><span class="box"><?= $pp ? '✓' : '' ?></span> Print processed</button>
      <?php endif; ?>
      <?php if ($canHold): ?>
        <form method="post" action="order_action.php" class="pi-hold no-print" onsubmit="return confirm('Mark <?= h(order_no($it['order_id'])) ?> as not ready for printing? Its T-shirts are taken off the blanks list until you mark it ready.');">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$it['order_id'] ?>"><input type="hidden" name="stage" value="hold"><input type="hidden" name="on" value="1"><input type="hidden" name="show" value="<?= h($show) ?>"><input type="hidden" name="q" value="<?= h($search) ?>">
          <button class="btn small ghost" title="Not ready for printing yet — leave out of the blanks list">⏸ Not ready</button>
        </form>
      <?php endif; ?>
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
        <?php if (item_has_blank($it)): ?>
          <div class="pi-blank"><span class="dot" style="background: <?= h($hex[$it['product'] . '|' . $it['color']] ?? '#ccc') ?>"></span>
            <b><?= h($it['color']) ?> · <?= h($it['size']) ?></b> <span class="qty-pill">× <?= (int)$it['quantity'] ?></span></div>
          <div class="muted small"><?= h(trim($it['gsm'] . ' ' . $it['product'])) ?></div>
        <?php else: ?>
          <div class="pi-blank"><b><?= h(item_spec($it)) ?></b><?php if ($it['item_type'] !== 'dtf_roll'): ?> <span class="qty-pill">× <?= (int)$it['quantity'] ?></span><?php endif; ?></div>
        <?php endif; ?>
        <?php if ($it['design_name'] !== ''): ?><div class="small">⭐ Design <b><?= h($it['design_name']) ?></b></div><?php endif; ?>
        <?php if ($it['sub_order_id'] !== '' && can_view('sub_order_id')): ?><div class="small">Sub-order <b>#<?= h($it['sub_order_id']) ?></b></div><?php endif; ?>
        <?php foreach (print_lines($it) as $line): ?>
          <div class="pi-print"><span class="lbl"><?= h(str_replace(' print', '', $line['label'])) ?></span><?php if ($line['size'] !== ''): ?><span class="tag size-tag"><?= h($line['size']) ?></span><?php endif; ?> <?= nl2br(h($line['text'])) ?></div>
        <?php endforeach; ?>
        <?php $extra = json_decode($it['extra'] ?: '{}', true) ?: []; foreach (custom_fields() as $k => $f): if ($f['scope'] !== 'item' || empty($extra[$k]) || !can_view($k)) continue; ?>
          <div class="pi-print"><span class="lbl"><?= h($f['label']) ?></span> <?= h($extra[$k]) ?></div>
        <?php endforeach; ?>
        <?php foreach (ITEM_ADDONS as $kind => $ad): if (!can_view($ad['field']) || empty($it[$ad['on']])) continue;
            $own = can_view('mockups') ? images_of($allImages[$it['id']] ?? [], $kind) : []; $done = !empty($it[$ad['done']]); ?>
          <div class="pi-addon">
            <div class="pi-print"><span class="lbl"><?= h($ad['label']) ?></span> <?= h($it[$ad['field']] ?: 'Yes') ?></div>
            <?php if ($own): ?><div class="pi-addon-imgs"><?php foreach ($own as $img): ?><a href="image.php?f=<?= h(urlencode($img['filename'])) ?>" class="gal-item" data-full="image.php?f=<?= h(urlencode($img['filename'])) ?>"><img loading="lazy" src="image.php?f=<?= h(urlencode(thumb_path($img['filename']))) ?>" alt=""></a><?php endforeach; ?></div><?php endif; ?>
            <button type="button" class="tick addon-tick <?= $done ? 'done' : '' ?>" data-stage="<?= $ad['done'] ?>" data-item="<?= (int)$it['id'] ?>" <?= can_edit('printed') ? '' : 'disabled' ?>><span class="box"><?= $done ? '✓' : '' ?></span> <?= h($ad['label']) ?> done</button>
          </div>
        <?php endforeach; ?>
        <?php if (!$imgs && !print_lines($it) && !$it['neck_label_on'] && !$it['chest_logo_on']): ?>
          <p class="muted small">No mock-up or print details — open the order.</p>
        <?php endif; ?>
      </div>
    </div>
    <?php if (!$it['plain']): ?>
    <button type="button" class="tick big item-tick" data-stage="printed" data-item="<?= (int)$it['id'] ?>" <?= can_edit('printed') ? '' : 'disabled' ?>>
      <span class="box"></span><span><b>Printed</b><small class="by"><?= can_edit('printed') ? 'Tap when done' : 'Not yet' ?></small></span>
    </button>
    <?php endif; ?>
  </article>
<?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($held): ?>
<section class="panel held-list no-print">
  <h2>Not ready for printing <small class="muted"><?= plural(count($held), 'order') ?> · not in the blanks list</small></h2>
  <?php foreach ($held as $oid => $o): $late = $o['due_date'] && $o['due_date'] < $today; ?>
    <div class="held-row">
      <div class="held-main">
        <div><a href="order.php?id=<?= (int)$oid ?>"><b><?= h(order_no($oid)) ?></b></a> <span class="muted small"><?= h($o['customer_id']) ?></span>
          <?php if ($late): ?><span class="badge delayed">Delayed</span><?php else: ?><span class="muted small">by <?= h(fmt_date($o['due_date'])) ?></span><?php endif; ?></div>
        <div class="muted small"><?= h(implode(' · ', array_map(fn($it) => item_has_blank($it) ? trim($it['color'] . ' ' . $it['size']) . ' ×' . (int)$it['quantity'] : item_spec($it), $o['items']))) ?></div>
      </div>
      <?php if ($canHold): ?>
        <form method="post" action="order_action.php">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$oid ?>"><input type="hidden" name="stage" value="hold"><input type="hidden" name="on" value="0"><input type="hidden" name="show" value="<?= h($show) ?>">
          <button class="btn small primary">▶ Ready to print</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<div class="lightbox" id="lightbox" hidden><img alt=""><button type="button" class="lb-close" aria-label="Close">✕</button></div>
<?php require __DIR__ . '/inc/footer.php'; ?>
