<?php
// Shipping label, 75 mm × 125 mm. One order (?id=) or several (?ids=1,2,3).
// Every field on the label can be changed at print time; "Save & print" also stores the changes on the order.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/orders.php';

require_login();
if (!cap('slips')) {
    http_response_code(403);
    exit('You are not allowed to print shipping labels.');
}

$ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? $_GET['id'] ?? ''))))));
if (!$ids) {
    redirect('orders.php');
}
$ids = array_slice($ids, 0, 200);

/** Label fields: form name => [order column, label, input type]. */
const LABEL_FIELDS = [
    'courier' => ['courier', 'Courier partner', 'select'],
    'tracking_no' => ['tracking_no', 'AWB / tracking number', 'text'],
    'order_ref' => ['order_ref', 'ORD- (order reference)', 'text'],
    'contents' => [null, 'Contents (printed on label only)', 'textarea'],
    'ship_name' => ['ship_name', 'Customer name', 'text'],
    'ship_phone' => ['ship_phone', 'Customer phone', 'tel'],
    'ship_address' => ['ship_address', 'Customer address', 'textarea'],
    'ship_pincode' => ['ship_pincode', 'Customer PIN', 'pin'],
    'slip_brand' => ['slip_brand', 'Seller name', 'text'],
    'ret_phone' => ['ret_phone', 'Seller phone', 'tel'],
    'ret_address' => ['ret_address', 'Seller address', 'textarea'],
    'ret_pincode' => ['ret_pincode', 'Seller PIN', 'pin'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $saved = 0;
    foreach ((array)($_POST['f'] ?? []) as $oid => $vals) {
        $oid = (int)$oid;
        if (!in_array($oid, $ids, true) || !is_array($vals) || !($old = get_order($oid))) {
            continue;
        }
        $changes = [];
        foreach (LABEL_FIELDS as $k => [$col]) {
            if ($col === null || !array_key_exists($k, $vals)) {
                continue;
            }
            $v = mb_substr(trim((string)$vals[$k]), 0, $col === 'ship_address' || $col === 'ret_address' ? 1000 : 150);
            if ((string)$old[$col] !== $v) {
                $changes[$col] = $v;
                log_change($oid, $col, $old[$col], $v === '' ? '(empty)' : $v);
            }
        }
        if ($changes) {
            update_row('orders', $oid, $changes + ['updated_at' => now(), 'updated_by' => current_user()['id']]);
            remember_customer($oid);
            $saved++;
        }
    }
    flash($saved ? 'Label details saved.' : 'No changes to save.');
    redirect('slip.php?ids=' . implode(',', $ids) . (!empty($_POST['print']) ? '&print=1' : ''));
}

$in = implode(',', $ids);
$orders = q("SELECT * FROM orders WHERE id IN ($in) AND deleted_at IS NULL ORDER BY FIELD(id, $in)")->fetchAll();
$itemsBy = [];
foreach (q("SELECT * FROM order_items WHERE order_id IN ($in) ORDER BY sort, id")->fetchAll() as $it) {
    $itemsBy[$it['order_id']][] = $it;
}
$defaults = [
    'slip_brand' => setting('slip_brand', setting('company_name', 'Looma Apparels')),
    'ret_phone' => setting('slip_ret_phone', ''),
    'ret_address' => setting('slip_ret_address', ''),
    'ret_pincode' => setting('slip_ret_pincode', ''),
];
$suggest = [
    'courier' => couriers(),
    'slip_brand' => array_values(array_unique(array_filter(array_merge([$defaults['slip_brand']],
        array_column(q("SELECT DISTINCT slip_brand FROM orders WHERE slip_brand <> '' ORDER BY slip_brand LIMIT 50")->fetchAll(), 'slip_brand'))))),
];
$f = flash();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Shipping label<?= count($orders) === 1 ? ' ' . h(order_no($orders[0]['id'])) : 's (' . count($orders) . ')' ?></title>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= h(asset('assets/style.css')) ?>">
<style>
  @page { size: 75mm 125mm; margin: 0; }
  body.slip-page { background: var(--bg); }
  .slip-top { max-width: 1100px; margin: 0 auto; padding: 16px 16px 0; }
  .slip-bar { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 10px 0 6px; }
  .slip-bar .opts { display: flex; gap: 14px; flex-wrap: wrap; font-size: .88rem; margin-left: auto; }
  .slip-bar .opts label { display: inline-flex; gap: 6px; align-items: center; cursor: pointer; }
  .label-blocks { max-width: 1100px; margin: 0 auto; padding: 8px 16px 40px; display: grid; gap: 18px; }
  .label-block { display: grid; gap: 16px; align-items: start; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius); padding: 16px; box-shadow: var(--shadow); }
  @media (min-width: 860px) { .label-block { grid-template-columns: 1fr auto; } }
  .label-form { min-width: 0; }
  .label-form h2 { display: flex; justify-content: space-between; gap: 8px; }
  .label-form .grid { grid-template-columns: 1fr 1fr; gap: 10px; }
  .label-form .grid .full { grid-column: 1 / -1; }
  .label-form .grid > .field.full { order: 0; } /* keep each address inside its own section */
  .label-form h3 { margin: 10px 0 0; font-size: .74rem; text-transform: uppercase; letter-spacing: .08em; color: var(--muted); grid-column: 1 / -1; }
  .label-form input, .label-form textarea { min-height: 40px; padding: 8px 11px; }
  .label-preview { display: flex; justify-content: center; }

  /* The label itself — sized in mm so it prints exactly 75 × 125 mm. */
  .slip {
    width: 75mm; height: 125mm; overflow: hidden; background: #fff; color: #000;
    font-family: Inter, Arial, sans-serif; font-size: 8.5pt; line-height: 1.22;
    padding: 3mm; display: flex; flex-direction: column; gap: 1.6mm;
    box-shadow: 0 2px 14px rgba(0,0,0,.18); border-radius: 2mm;
  }
  .slip * { color: #000; }
  .s-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 2mm; }
  .s-carrier { font-weight: 800; font-size: 14pt; text-transform: uppercase; letter-spacing: .02em; line-height: 1.05; word-break: break-word; }
  .s-ord { text-align: right; font-size: 7pt; line-height: 1.25; white-space: nowrap; }
  .s-ord b { display: block; font-size: 10.5pt; }
  .s-awb { border-top: .45mm solid #000; border-bottom: .45mm solid #000; padding: 1.4mm 0; text-align: center; }
  .s-awb svg { width: 100%; height: 13mm; display: block; }
  .s-awb .s-awbno { font-weight: 800; font-size: 10pt; letter-spacing: .08em; margin-top: .6mm; }
  .s-awb.empty svg { display: none; }
  .s-label { font-size: 6.5pt; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; margin-bottom: .5mm; }
  .s-to { border: .45mm solid #000; border-radius: 1.4mm; padding: 1.8mm 2.2mm; }
  .s-name { font-weight: 800; font-size: 11pt; line-height: 1.15; text-transform: uppercase; word-break: break-word; }
  .s-addr { font-size: 8.6pt; margin-top: .6mm; white-space: pre-line; word-break: break-word; max-height: 21mm; overflow: hidden; }
  .s-pin-row { display: flex; justify-content: space-between; align-items: baseline; gap: 2mm; margin-top: 1mm; }
  .s-pin { font-weight: 800; font-size: 12.5pt; letter-spacing: .06em; }
  .s-phone { font-weight: 700; font-size: 9.5pt; }
  .s-items { flex: 1; min-height: 0; overflow: hidden; font-size: 7.2pt; }
  .s-contents { white-space: pre-line; font-size: 9pt; font-weight: 700; line-height: 1.35; }
  .s-from { display: flex; gap: 2mm; align-items: flex-end; border-top: .3mm dashed #000; padding-top: 1.6mm; }
  .s-from-text { flex: 1; min-width: 0; font-size: 8.2pt; line-height: 1.3; }
  .s-from-text b.s-seller { font-size: 9.5pt; text-transform: uppercase; display: block; }
  .s-from-text .s-raddr { white-space: pre-line; word-break: break-word; }
  .no-items .s-items > * { display: none; }

  @media print {
    body.slip-page { background: #fff; margin: 0; }
    .slip-top, .label-form, .alert { display: none !important; }
    .label-blocks { display: block; padding: 0; max-width: none; }
    .label-block { display: block; padding: 0; border: 0; box-shadow: none; background: none; }
    .label-preview { display: block; }
    .slip { box-shadow: none; border-radius: 0; page-break-after: always; break-after: page; }
    .label-block:last-child .slip { page-break-after: auto; break-after: auto; }
  }
</style>
</head>
<body class="slip-page">
<form method="post" id="labelForm">
<?= csrf_field() ?>
<div class="slip-top">
  <a class="back" href="<?= count($orders) === 1 ? 'order.php?id=' . (int)$orders[0]['id'] : 'orders.php?tab=pack' ?>">← Back</a>
  <h1>Shipping label<?= count($orders) > 1 ? 's · ' . count($orders) . ' orders' : '' ?></h1>
  <?php if ($f): ?><p class="alert <?= h($f['type']) ?>" style="margin-top:10px"><?= h($f['msg']) ?></p><?php endif; ?>
  <?php if (!$orders): ?><p class="alert err">No orders found.</p><?php endif; ?>
  <div class="slip-bar">
    <button class="btn primary" name="print" value="1">💾 Save &amp; print</button>
    <button class="btn" name="save" value="1">Save only</button>
    <button class="btn ghost" type="button" onclick="window.print()">🖨 Print without saving</button>
    <div class="opts">
      <label><input type="checkbox" data-opt="items" checked> Contents</label>
    </div>
  </div>
  <p class="muted small">Courier list is managed in Catalog → Couriers. Printer: paper 75 × 125 mm (or label 3×5″), margins <b>none</b>, scale <b>100%</b>. Changes show on the label as you type.</p>
</div>

<datalist id="dl_slip_brand"><?php foreach ($suggest['slip_brand'] as $c): ?><option value="<?= h($c) ?>"><?php endforeach; ?></datalist>

<div class="label-blocks">
<?php foreach ($orders as $o):
    $oid = (int)$o['id'];
    $v = [];
    foreach (LABEL_FIELDS as $k => [$col]) {
        $v[$k] = $col === null ? '' : (string)($o[$col] ?? '');
        if ($v[$k] === '' && isset($defaults[$k])) {
            $v[$k] = (string)$defaults[$k];
        }
    }
    $its = $itemsBy[$oid] ?? [];
    $v['contents'] = label_contents($its);
?>
  <div class="label-block" data-label>
    <div class="label-form">
      <h2><span><?= h(order_no($oid)) ?> <small class="muted"><?= h($o['customer_id']) ?></small></span></h2>
      <div class="grid">
        <?php $sec = ['courier' => 'Shipment', 'ship_name' => 'Ship to (customer)', 'slip_brand' => 'Return to (seller)'];
        foreach (LABEL_FIELDS as $k => [$col, $label, $type]):
            if (isset($sec[$k])): ?><h3><?= $sec[$k] ?></h3><?php endif;
            $name = 'f[' . $oid . '][' . $k . ']';
            $full = $type === 'textarea' ? ' full' : ''; ?>
          <label class="field<?= $full ?>"><span class="lbl"><?= h($label) ?></span>
            <?php if ($type === 'select'):
                $opts = $suggest[$k];
                if ($v[$k] !== '' && !in_array($v[$k], $opts, true)) {
                    $opts[] = $v[$k];
                } ?>
              <select name="<?= h($name) ?>" data-bind="<?= $k ?>">
                <option value="">Select courier…</option>
                <?php foreach ($opts as $opt): ?><option <?= $opt === $v[$k] ? 'selected' : '' ?>><?= h($opt) ?></option><?php endforeach; ?>
              </select>
            <?php elseif ($type === 'textarea'): ?>
              <textarea name="<?= h($name) ?>" rows="2" data-bind="<?= $k ?>"><?= h($v[$k]) ?></textarea>
            <?php else: ?>
              <input name="<?= h($name) ?>" value="<?= h($v[$k]) ?>" data-bind="<?= $k ?>" autocomplete="off"
                <?= isset($suggest[$k]) ? 'list="dl_' . $k . '"' : '' ?>
                <?= $type === 'tel' ? 'inputmode="tel"' : '' ?><?= $type === 'pin' ? 'inputmode="numeric" maxlength="6"' : '' ?>>
            <?php endif; ?>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="label-preview">
      <article class="slip">
        <div class="s-head">
          <div class="s-carrier" data-show="courier"><?= h($v['courier']) ?></div>
          <div class="s-ord">ORD- <b data-show="order_ref"><?= h($v['order_ref']) ?></b><?= h(order_no($oid)) ?></div>
        </div>
        <div class="s-awb <?= $v['tracking_no'] === '' ? 'empty' : '' ?>">
          <svg data-barcode="<?= h($v['tracking_no']) ?>"></svg>
          <div class="s-awbno">AWB / Tracking: <span data-show="tracking_no"><?= h($v['tracking_no']) ?></span></div>
        </div>
        <div class="s-to">
          <div class="s-label">Ship to</div>
          <div class="s-name" data-show="ship_name"><?= h($v['ship_name']) ?></div>
          <div class="s-addr" data-show="ship_address"><?= h(trim($v['ship_address'])) ?></div>
          <div class="s-pin-row">
            <span class="s-pin">PIN <span data-show="ship_pincode"><?= h($v['ship_pincode']) ?></span></span>
            <span class="s-phone">☎ <span data-show="ship_phone"><?= h($v['ship_phone']) ?></span></span>
          </div>
        </div>
        <div class="s-items">
          <div class="s-label">Contents</div>
          <div class="s-contents" data-show="contents"><?= h($v['contents']) ?></div>
        </div>
        <div class="s-from">
          <div class="s-from-text">
            <div class="s-label">Return to</div>
            <b class="s-seller" data-show="slip_brand"><?= h($v['slip_brand']) ?></b>
            <div class="s-raddr" data-show="ret_address"><?= h(trim($v['ret_address'])) ?></div>
            <div>PIN <span data-show="ret_pincode"><?= h($v['ret_pincode']) ?></span> · ☎ <span data-show="ret_phone"><?= h($v['ret_phone']) ?></span></div>
          </div>
        </div>
      </article>
    </div>
  </div>
<?php endforeach; ?>
</div>
</form>

<script src="<?= h(asset('assets/barcode.js')) ?>"></script>
<script>
(function () {
  function drawBarcode(svg, text) {
    svg.closest('.s-awb').classList.toggle('empty', !text);
    if (text) code128Svg(svg, text);
  }
  document.querySelectorAll('svg[data-barcode]').forEach(function (svg) { drawBarcode(svg, svg.dataset.barcode); });

  // Live preview: typing in a field updates its label.
  function onEdit(e) {
    var k = e.target.dataset.bind;
    if (!k) return;
    var block = e.target.closest('[data-label]');
    var v = k === 'ship_address' || k === 'ret_address' || k === 'contents' ? e.target.value.trim() : e.target.value;
    block.querySelectorAll('[data-show="' + k + '"]').forEach(function (n) { n.textContent = v; });
    if (k === 'tracking_no') drawBarcode(block.querySelector('svg[data-barcode]'), v.trim());
  }
  document.addEventListener('input', onEdit);
  document.addEventListener('change', onEdit); // dropdowns
  // Option: hide contents on all labels.
  document.querySelectorAll('[data-opt]').forEach(function (c) {
    c.addEventListener('change', function () { document.body.classList.toggle('no-' + c.dataset.opt, !c.checked); });
  });
  <?php if (!empty($_GET['print'])): ?>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });<?php endif; ?>
})();
</script>
</body>
</html>
