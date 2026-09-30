<?php
// Packing slip / shipping label, 75 mm × 125 mm. One order (?id=) or several (?ids=1,2,3).
// The brand name printed on each slip can be changed per order (white-label / dropshipping).
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/orders.php';

require_login();
if (!can_view('ship_address')) {
    http_response_code(403);
    exit('You are not allowed to print shipping slips.');
}

$ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? $_GET['id'] ?? ''))))));
if (!$ids) {
    redirect('orders.php');
}
$ids = array_slice($ids, 0, 200);
$canBrand = can_edit('packed') || can_edit('shipped') || cap('create');
$defaultBrand = setting('slip_brand', setting('company_name', 'Looma Apparels'));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canBrand) {
    csrf_check();
    $brand = mb_substr(trim((string)($_POST['brand'] ?? '')), 0, 100);
    foreach ($ids as $oid) {
        $old = q('SELECT slip_brand FROM orders WHERE id = ?', [$oid])->fetchColumn();
        if ($old !== false && $old !== $brand) {
            q('UPDATE orders SET slip_brand = ? WHERE id = ?', [$brand, $oid]);
            log_change($oid, 'slip_brand', $old, $brand ?: '(default)');
        }
    }
    redirect('slip.php?ids=' . implode(',', $ids) . (!empty($_POST['print']) ? '&print=1' : ''));
}

$in = implode(',', $ids);
$orders = q("SELECT * FROM orders WHERE id IN ($in) AND deleted_at IS NULL ORDER BY FIELD(id, $in)")->fetchAll();
$itemsBy = [];
foreach (q("SELECT * FROM order_items WHERE order_id IN ($in) ORDER BY sort, id")->fetchAll() as $it) {
    $itemsBy[$it['order_id']][] = $it;
}
// Brands used before, offered as suggestions.
$brands = array_values(array_unique(array_filter(array_merge([$defaultBrand],
    array_column(q("SELECT DISTINCT slip_brand FROM orders WHERE slip_brand <> '' ORDER BY slip_brand LIMIT 50")->fetchAll(), 'slip_brand')))));
$sameBrand = count(array_unique(array_column($orders, 'slip_brand'))) === 1 ? ($orders[0]['slip_brand'] ?? '') : '';

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$origin = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost');
$from = trim((string)setting('slip_from', ''));
$showFrom = !isset($_GET['nofrom']);
$showItems = !isset($_GET['noitems']);
$qs = fn(array $extra) => '?' . http_build_query(array_merge(['ids' => implode(',', $ids)], array_filter([
    'nofrom' => $showFrom ? null : 1, 'noitems' => $showItems ? null : 1]), $extra));
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Packing slip<?= count($orders) === 1 ? ' ' . h(order_no($orders[0]['id'])) : 's (' . count($orders) . ')' ?></title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= h(asset('assets/style.css')) ?>">
<style>
  @page { size: 75mm 125mm; margin: 0; }
  body.slip-page { background: var(--bg); }
  .slip-tools { max-width: 720px; margin: 0 auto; padding: 16px; }
  .slip-tools .panel { margin-bottom: 12px; }
  .slip-tools form.brand-form { display: flex; gap: 8px; flex-wrap: wrap; align-items: flex-end; }
  .slip-tools form.brand-form label { flex: 1 1 220px; }
  .slip-opts { display: flex; gap: 14px; flex-wrap: wrap; font-size: .88rem; margin-top: 10px; }
  .slips { display: flex; flex-wrap: wrap; gap: 18px; justify-content: center; padding: 8px 16px 40px; }

  /* The label itself — sized in mm so it prints exactly 75 × 125 mm. */
  .slip {
    width: 75mm; height: 125mm; overflow: hidden; background: #fff; color: #000;
    font-family: Inter, Arial, sans-serif; font-size: 8.5pt; line-height: 1.25;
    padding: 3.5mm; display: flex; flex-direction: column; gap: 2mm;
    box-shadow: 0 2px 14px rgba(0,0,0,.18); border-radius: 2mm;
  }
  .slip * { color: #000; }
  .s-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 2mm; border-bottom: .5mm solid #000; padding-bottom: 1.8mm; }
  .s-brand { font-weight: 800; font-size: 12.5pt; letter-spacing: .04em; text-transform: uppercase; line-height: 1.05; word-break: break-word; }
  .s-order { text-align: right; white-space: nowrap; }
  .s-order b { display: block; font-size: 10.5pt; }
  .s-order small { font-size: 7pt; }
  .s-label { font-size: 6.5pt; font-weight: 700; letter-spacing: .14em; text-transform: uppercase; margin-bottom: .6mm; }
  .s-to { border: .45mm solid #000; border-radius: 1.5mm; padding: 2mm 2.4mm; }
  .s-name { font-weight: 800; font-size: 11.5pt; line-height: 1.15; }
  .s-addr { font-size: 9pt; margin-top: .8mm; white-space: pre-line; word-break: break-word; }
  .s-pin-row { display: flex; justify-content: space-between; align-items: baseline; gap: 2mm; margin-top: 1.4mm; }
  .s-pin { font-weight: 800; font-size: 13pt; letter-spacing: .08em; }
  .s-phone { font-weight: 700; font-size: 9.5pt; }
  .s-items { flex: 1; min-height: 0; overflow: hidden; }
  .s-items ul { list-style: none; margin: 0; padding: 0; display: grid; gap: .5mm; font-size: 7.6pt; }
  .s-items li { display: flex; gap: 1.2mm; }
  .s-items li b { flex: none; }
  .s-foot { display: flex; gap: 2.4mm; align-items: flex-end; border-top: .3mm dashed #000; padding-top: 2mm; }
  .s-qr { width: 21mm; height: 21mm; flex: none; }
  .s-qr svg, .s-qr img { width: 100%; height: 100%; display: block; }
  .s-meta { flex: 1; min-width: 0; font-size: 7pt; display: grid; gap: .8mm; }
  .s-meta .s-track { font-size: 8.5pt; font-weight: 700; word-break: break-all; }
  .s-from { white-space: pre-line; font-size: 6.6pt; line-height: 1.2; }

  @media print {
    body.slip-page { background: #fff; margin: 0; }
    .slip-tools { display: none !important; }
    .slips { display: block; padding: 0; }
    .slip { box-shadow: none; border-radius: 0; page-break-after: always; break-after: page; }
    .slip:last-child { page-break-after: auto; break-after: auto; }
  }
</style>
</head>
<body class="slip-page">
<div class="slip-tools">
  <a class="back" href="<?= count($orders) === 1 ? 'order.php?id=' . (int)$orders[0]['id'] : 'orders.php?tab=pack' ?>">← Back</a>
  <h1 style="margin:4px 0 14px">Packing slip<?= count($orders) > 1 ? 's · ' . count($orders) . ' orders' : '' ?></h1>
  <?php if (!$orders): ?><p class="alert err">No orders found.</p><?php endif; ?>
  <section class="panel">
    <?php if ($canBrand): ?>
    <form method="post" class="brand-form" action="slip.php<?= h($qs([])) ?>">
      <?= csrf_field() ?>
      <label class="field"><span class="lbl">Brand name on <?= count($orders) > 1 ? 'these slips' : 'this slip' ?></span>
        <input name="brand" list="brandList" value="<?= h($sameBrand) ?>" placeholder="<?= h($defaultBrand) ?> (default)" maxlength="100">
        <datalist id="brandList"><?php foreach ($brands as $b): ?><option value="<?= h($b) ?>"><?php endforeach; ?></datalist>
      </label>
      <button class="btn" name="save" value="1">Save brand</button>
      <button class="btn primary" name="print" value="1">Save &amp; print</button>
    </form>
    <p class="hint">Leave empty to use the default (<?= h($defaultBrand) ?>). The brand is remembered for <?= count($orders) > 1 ? 'these orders' : 'this order' ?> and used in WhatsApp messages too.</p>
    <?php else: ?>
      <button class="btn primary" onclick="window.print()">🖨 Print</button>
    <?php endif; ?>
    <div class="slip-opts">
      <a href="<?= h($qs(['nofrom' => $showFrom ? 1 : null])) ?>"><?= $showFrom ? '☑' : '☐' ?> Sender address</a>
      <a href="<?= h($qs(['noitems' => $showItems ? 1 : null])) ?>"><?= $showItems ? '☑' : '☐' ?> Item list</a>
      <a href="#" onclick="window.print(); return false;">🖨 Print now</a>
    </div>
  </section>
  <p class="muted small">Printer setting: paper size 75 × 125 mm (or “label 3×5 in”), margins none, scale 100%.</p>
</div>

<div class="slips">
<?php foreach ($orders as $o):
    $brand = $o['slip_brand'] !== '' ? $o['slip_brand'] : $defaultBrand;
    $its = $itemsBy[$o['id']] ?? [];
    $pcs = array_sum(array_column($its, 'quantity'));
    $url = $origin . base_url('order.php?id=' . (int)$o['id']);
?>
  <article class="slip">
    <div class="s-head">
      <div class="s-brand"><?= h($brand) ?></div>
      <div class="s-order"><b><?= h(order_no($o['id'])) ?></b><small><?= h(date('d M Y', strtotime($o['created_at']))) ?> · <?= $pcs ?> pc<?= $pcs === 1 ? '' : 's' ?></small></div>
    </div>
    <div class="s-to">
      <div class="s-label">Ship to</div>
      <div class="s-name"><?= h($o['ship_name']) ?></div>
      <div class="s-addr"><?= h(trim((string)$o['ship_address'])) ?></div>
      <div class="s-pin-row">
        <span class="s-pin">PIN <?= h($o['ship_pincode']) ?></span>
        <span class="s-phone">☎ <?= h($o['ship_phone']) ?></span>
      </div>
    </div>
    <div class="s-items">
      <?php if ($showItems): ?>
        <div class="s-label">Contents</div>
        <ul>
          <?php foreach (array_slice($its, 0, 7) as $it): ?>
            <li><b><?= (int)$it['quantity'] ?>×</b><span><?= h(implode(' · ', array_filter([$it['gsm'], $it['product'], $it['color'], $it['size']], 'strlen'))) ?><?= $it['plain'] ? ' (plain)' : '' ?></span></li>
          <?php endforeach; ?>
          <?php if (count($its) > 7): ?><li><b>+</b><span><?= count($its) - 7 ?> more items</span></li><?php endif; ?>
        </ul>
      <?php endif; ?>
    </div>
    <div class="s-foot">
      <div class="s-qr" data-qr="<?= h($url) ?>"></div>
      <div class="s-meta">
        <?php if ($o['courier'] !== '' || $o['tracking_no'] !== ''): ?>
          <div><?= h($o['courier']) ?><div class="s-track"><?= h($o['tracking_no']) ?></div></div>
        <?php endif; ?>
        <div>Customer: <b><?= h($o['customer_id']) ?></b></div>
        <?php if ($showFrom): ?>
          <div class="s-from"><b>From: <?= h($brand) ?></b><?= $from !== '' && $brand === $defaultBrand ? "\n" . h($from) : '' ?></div>
        <?php endif; ?>
      </div>
    </div>
  </article>
<?php endforeach; ?>
</div>

<script src="<?= h(asset('assets/qrcode.js')) ?>"></script>
<script>
  // Draw the QR codes (scan with a staff phone to open the order and tick Packed / Shipped).
  document.querySelectorAll('[data-qr]').forEach(function (el) {
    var qr = qrcode(0, 'M');
    qr.addData(el.dataset.qr);
    qr.make();
    el.innerHTML = qr.createSvgTag({ cellSize: 2, margin: 0, scalable: true });
  });
  <?php if (!empty($_GET['print'])): ?>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });<?php endif; ?>
</script>
</body>
</html>
