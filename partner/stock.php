<?php
// Partner: their stock kept at Looma — returned (RTO) T-shirts to send again (packing + shipping only), and, for
// print-only partners (switched on by Looma), their own blank T-shirts with a "running low" warning.
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/partner_stock.php';
require __DIR__ . '/../inc/partner_ui.php';

$pu = require_partner();
$p = $pu['partner'];
$own = partner_own_blanks($p);
$ret = partner_stock_rows((int)$p['id'], 'return');
$blank = $own ? partner_stock_rows((int)$p['id'], 'blank', true) : [];
$low = $own ? partner_blanks_low($p) : [];
$rto = q("SELECT po.id, po.ref, po.ship_name, po.rto_at, po.items FROM partner_orders po WHERE po.partner_id = ? AND po.rto_at IS NOT NULL ORDER BY po.rto_at DESC LIMIT 50", [$p['id']])->fetchAll();
partner_head('Stock', 'stock', $pu);
?>
<header class="pt-pagehead"><h1 class="pt-title">Stock at Looma</h1>
  <p class="pt-lead"><?= $own ? 'Returned T-shirts and your own blank T-shirts, kept safe at Looma.' : 'T-shirts that came back to Looma, ready to send to a new customer.' ?></p></header>
<?php if ($low): ?><div class="pt-note warn">Running low on your own T-shirts: <?= h(implode(', ', array_map(fn($r) => $r['color'] . ' ' . $r['size'] . ' (' . $r['qty'] . ')', $low))) ?>. Send more to Looma.</div><?php endif; ?>

<section class="pt-section">
  <div class="pt-section-head"><h2>Returned T-shirts</h2><p>Send again for packing + shipping only.</p></div>
  <?php if (!$ret): ?>
    <div class="pt-empty-card"><span class="pt-empty-ico">↩</span><b>No returns</b><p>If a parcel comes back to Looma, its T-shirts wait here for you.</p></div>
  <?php else: ?>
  <div class="pt-card pt-list">
    <?php foreach ($ret as $r): ?>
      <div class="pt-row"><?php if ($r['img']): ?><img class="pt-thumb" src="<?= h(partner_img($r['img'])) ?>" alt="" loading="lazy"><?php else: ?><span class="pt-thumb">👕</span><?php endif; ?>
        <span class="pt-row-main"><b><?= h($r['design_name'] ?: 'Design') ?></b><small><?= h($r['color'] . ' · ' . $r['size'] . ' · ' . $r['product']) ?></small></span>
        <span class="pt-row-end"><span class="pt-qty"><?= (int)$r['qty'] ?></span><a class="pt-btn tinted pt-small" href="<?= h(base_url('partner/order.php?new=1&stock=' . (int)$r['id'])) ?>">Send</a></span></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>

<?php if ($own): ?>
<section class="pt-section">
  <div class="pt-section-head"><h2>Your own T-shirts</h2><p>Blanks you sent to Looma for printing.</p></div>
  <?php if (!$blank): ?>
    <div class="pt-empty-card"><span class="pt-empty-ico">👕</span><b>Nothing received yet</b><p>When your blank T-shirts reach Looma, they show here. Then pick “My own T-shirt” in a new order.</p></div>
  <?php else: ?>
  <div class="pt-card pt-list">
    <?php $lowIds = array_column($low, 'id'); foreach ($blank as $r): ?>
      <div class="pt-row"><span class="pt-swatchdot" aria-hidden="true"></span>
        <span class="pt-row-main"><b><?= h($r['color'] . ' · ' . $r['size']) ?></b><small><?= h($r['product'] . ' · ' . $r['gsm']) ?></small></span>
        <span class="pt-row-end"><span class="pt-qty <?= in_array($r['id'], $lowIds) ? 'low' : '' ?>"><?= (int)$r['qty'] ?></span><?php if ($r['qty'] > 0): ?><a class="pt-btn tinted pt-small" href="<?= h(base_url('partner/order.php?new=1&stock=' . (int)$r['id'])) ?>">Use</a><?php endif; ?></span></div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($rto): ?>
<section class="pt-section">
  <div class="pt-section-head"><h2>Orders that came back</h2></div>
  <div class="pt-card pt-list">
    <?php foreach ($rto as $r): $its = json_decode($r['items'] ?: '[]', true) ?: []; ?>
      <a class="pt-row" href="<?= h(base_url('partner/order.php?id=' . (int)$r['id'])) ?>"><span class="pt-row-main"><b><?= h($r['ship_name']) ?></b><small><?= h($r['ref']) ?> · <?= plural((int)array_sum(array_column($its, 'qty')), 'T-shirt') ?></small></span>
        <span class="pt-row-end"><span class="pt-pill s-rejected">Returned</span><small><?= h(fmt_date($r['rto_at'])) ?></small></span></a>
    <?php endforeach; ?>
  </div>
</section>
<?php endif; ?>
<?php partner_foot(); ?>
