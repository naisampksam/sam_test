<?php
// Partner: their stock kept at Looma — returned (RTO) T-shirts to send again (packing + shipping only), their own blank
// T-shirts for printing (with a "running low" warning), and the orders that came back.
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/partner_stock.php';
require __DIR__ . '/../inc/partner_ui.php';

$pu = require_partner();
$p = $pu['partner'];
$ret = partner_stock_rows((int)$p['id'], 'return');
$blank = partner_stock_rows((int)$p['id'], 'blank', true);
$low = partner_blanks_low($p);
$rto = q("SELECT po.id, po.ref, po.ship_name, po.rto_at, po.items FROM partner_orders po WHERE po.partner_id = ? AND po.rto_at IS NOT NULL ORDER BY po.rto_at DESC LIMIT 50", [$p['id']])->fetchAll();
partner_head('Stock', 'stock', $pu);
?>
<h1 class="pt-title">Stock at Looma</h1>
<?php if ($low): ?><div class="pt-note err">Your own T-shirts running low: <?= h(implode(', ', array_map(fn($r) => $r['product'] . ' · ' . $r['color'] . ' · ' . $r['size'] . ' (' . $r['qty'] . ')', $low))) ?>. Send more to Looma.</div><?php endif; ?>

<h2 class="pt-h2">Returned T-shirts <small>send again for packing + shipping only</small></h2>
<?php if (!$ret): ?><p class="pt-sub">None. Orders that come back (RTO) appear here.</p><?php else: ?>
<section class="pt-card pt-list">
  <?php foreach ($ret as $r): ?>
    <div class="pt-row"><?php if ($r['img']): ?><img class="pt-thumb" src="<?= h(partner_img($r['img'])) ?>" alt="" loading="lazy"><?php else: ?><span class="pt-thumb">👕</span><?php endif; ?>
      <span class="pt-row-main"><b><?= h($r['design_name'] ?: 'Design') ?></b><small><?= h($r['color'] . ' · ' . $r['size'] . ' · ' . $r['product'] . ' · ' . $r['gsm']) ?></small></span>
      <span class="pt-row-end"><b><?= (int)$r['qty'] ?></b><a class="pt-btn tinted pt-small" href="<?= h(base_url('partner/order.php?new=1&stock=' . (int)$r['id'])) ?>">Send</a></span></div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<h2 class="pt-h2">Your own T-shirts <small>blanks you sent, for printing</small></h2>
<?php if (!$blank): ?><p class="pt-sub">None. If you send Looma your own blank T-shirts, Looma records them here and you pick “My own T-shirt” in a new order.</p><?php else: ?>
<section class="pt-card pt-list">
  <?php foreach ($blank as $r): ?>
    <div class="pt-row"><span class="pt-thumb">👕</span>
      <span class="pt-row-main"><b><?= h($r['color'] . ' · ' . $r['size']) ?></b><small><?= h($r['product'] . ' · ' . $r['gsm']) ?></small></span>
      <span class="pt-row-end"><b class="<?= in_array($r['id'], array_column($low, 'id')) ? 'pt-low' : '' ?>"><?= (int)$r['qty'] ?></b><?php if ($r['qty'] > 0): ?><a class="pt-btn tinted pt-small" href="<?= h(base_url('partner/order.php?new=1&stock=' . (int)$r['id'])) ?>">Use</a><?php endif; ?></span></div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($rto): ?>
<h2 class="pt-h2">Orders that came back (RTO)</h2>
<section class="pt-card pt-list">
  <?php foreach ($rto as $r): $its = json_decode($r['items'] ?: '[]', true) ?: []; ?>
    <a class="pt-row" href="<?= h(base_url('partner/order.php?id=' . (int)$r['id'])) ?>"><span class="pt-row-main"><b><?= h($r['ship_name']) ?></b><small><?= h($r['ref']) ?> · <?= plural((int)array_sum(array_column($its, 'qty')), 'T-shirt') ?></small></span>
      <span class="pt-row-end"><span class="pt-pill s-rejected">Returned</span><small><?= h(fmt_date($r['rto_at'])) ?></small></span></a>
  <?php endforeach; ?>
</section>
<?php endif; ?>
<?php partner_foot(); ?>
