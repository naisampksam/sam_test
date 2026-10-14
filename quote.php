<?php
// Quotation: the New order or New bill form priced like a bill, shown as a quotation to send to the customer.
// Nothing is saved — no order, no bill, no stock, no customer. Opens in a new tab so the form stays as it was.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/orders.php';
require __DIR__ . '/inc/billing.php';

require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    exit('Open a quotation from the New order or New quotation page (📝 Quotation).');
}
if (!cap('billing') && !can_view('bill')) {
    http_response_code(403);
    exit('You are not allowed to see prices.');
}
csrf_check();

$P = $_POST;
$str = fn($v, int $max = 150) => mb_substr(trim((string)$v), 0, $max);
$num = fn($v) => (float)str_replace([',', '₹', ' '], '', (string)$v);
$sellerState = setting('inv_state', 'Kerala');
$looma = [
    'seller_name' => setting('inv_seller_name', setting('company_name', 'Looma Apparels')), 'seller_address' => setting('inv_seller_address', ''),
    'seller_phone' => setting('inv_seller_phone', ''), 'seller_gstin' => setting('inv_gstin', ''),
];

if (isset($P['lines'])) {
    // ---- from the New bill page: its lines as typed
    $branding = ($P['branding'] ?? 'plain') === 'looma' ? 'looma' : 'plain';
    $head = [
        'name' => $str($P['bill_name'] ?? ''), 'phone' => $str($P['bill_phone'] ?? '', 40), 'address' => $str($P['bill_address'] ?? '', 1000),
        'pincode' => $str($P['bill_pincode'] ?? '', 12), 'gstin' => strtoupper($str($P['bill_gstin'] ?? '', 20)), 'state' => $str($P['bill_state'] ?? '', 60),
        'inter_state' => !empty($P['inter_state']), 'discount' => max(0, $num($P['discount'] ?? 0)), 'shipping' => max(0, $num($P['shipping'] ?? 0)),
        'notes' => $str($P['notes'] ?? '', 2000),
    ];
    $seller = $branding === 'looma' ? $looma : ['seller_name' => $str($P['seller_name'] ?? ''), 'seller_address' => $str($P['seller_address'] ?? '', 1000),
        'seller_phone' => $str($P['seller_phone'] ?? '', 40), 'seller_gstin' => strtoupper($str($P['seller_gstin'] ?? '', 20))];
    $lines = [];
    foreach ((array)$P['lines'] as $l) {
        if (!is_array($l) || (trim((string)($l['description'] ?? '')) === '' && $num($l['rate'] ?? 0) == 0)) {
            continue;
        }
        $lines[] = ['description' => $str($l['description'] ?? '', 300) ?: 'Item', 'hsn' => $str($l['hsn'] ?? '', 20), 'qty' => max(0, $num($l['qty'] ?? 1)),
            'unit' => $str($l['unit'] ?? 'pcs', 20) ?: 'pcs', 'rate' => max(0, $num($l['rate'] ?? 0)), 'gst_rate' => max(0, $num($l['gst_rate'] ?? 0))];
    }
} else {
    // ---- from the order form: the items, priced exactly as the order's bill would be
    $b = is_array($P['bill'] ?? null) ? $P['bill'] : [];
    $branding = !empty($b['gst']) ? 'looma' : 'plain';
    $state = $str($b['state'] ?? '', 60);
    $head = [
        'name' => $str(($P['customer_name'] ?? '') !== '' ? $P['customer_name'] : ($P['ship_name'] ?? '')), 'phone' => $str($P['ship_phone'] ?? '', 40),
        'address' => $str($P['ship_address'] ?? '', 1000), 'pincode' => $str($P['ship_pincode'] ?? '', 12), 'gstin' => strtoupper($str($b['gstin'] ?? '', 20)),
        'state' => $state, 'inter_state' => $state !== '' && mb_strtolower($state) !== mb_strtolower($sellerState),
        'discount' => max(0, $num($b['discount'] ?? 0)), 'shipping' => max(0, $num($b['shipping'] ?? 0)), 'notes' => '',
    ];
    if ($head['name'] === '' && ($P['customer_id'] ?? '') !== '') {
        $head['name'] = $str($P['customer_id']);
    }
    $seller = $branding === 'looma' ? $looma : ['seller_name' => '', 'seller_address' => '', 'seller_phone' => '', 'seller_gstin' => ''];
    $items = [];
    foreach ((array)($P['items'] ?? []) as $key => $ip) {
        if (!is_array($ip) || !empty($ip['delete'])) {
            continue;
        }
        // An item already on the order keeps what is not on the form (fields this user cannot change).
        $it = preg_match('/^e(\d+)$/', (string)$key, $m) ? (q('SELECT * FROM order_items WHERE id = ?', [(int)$m[1]])->fetch() ?: []) : [];
        $it += ['item_type' => 'print', 'gsm' => '', 'product' => '', 'color' => '', 'size' => '', 'quantity' => 1, 'length_m' => null, 'rate' => null,
            'print_rate' => null, 'neck_rate' => null, 'print_spec' => '', 'neck_label_on' => 0, 'chest_logo_on' => 0];
        foreach (PRINT_PLACES as $pk => [, $sizeCol]) {
            $it += [$pk => '', $sizeCol => ''];
        }
        $type = (string)($ip['item_type'] ?? $it['item_type']);
        $it['item_type'] = isset(ITEM_TYPES[$type]) ? $type : 'print';
        foreach (['gsm', 'product', 'color', 'size'] as $f) {
            if (isset($ip[$f])) {
                $it[$f] = $str($ip[$f], 100);
            }
        }
        if (!item_has_blank($it)) {
            $it['gsm'] = $it['product'] = $it['color'] = $it['size'] = '';
        }
        foreach (PRINT_PLACES as $pk => [, $sizeCol]) {
            foreach ([$pk, $sizeCol] as $f) {
                if (isset($ip[$f])) {
                    $it[$f] = $str($ip[$f], 500);
                }
            }
        }
        if (is_array($ip['spec'] ?? null)) {
            $spec = print_spec($it);
            $n = fn($v) => trim((string)$v) === '' ? null : max(0, round($num($v), 2));
            foreach ($ip['spec'] as $pk => $sp) {
                if (isset(PRINT_PLACES[$pk]) && is_array($sp)) {
                    $spec[$pk] = ['m' => isset(PRINT_METHODS[$sp['m'] ?? '']) ? $sp['m'] : 'dtf', 'n' => max(1, min(20, (int)($sp['n'] ?? 1))),
                        'st' => max(0, (int)preg_replace('/\D/', '', (string)($sp['st'] ?? ''))), 'pr' => $n($sp['pr'] ?? ''), 'dg' => $n($sp['dg'] ?? '')];
                }
            }
            $it['print_spec'] = json_encode($spec);
        }
        foreach (ITEM_ADDONS as $ad) {
            if (isset($ip[$ad['on']])) {
                $it[$ad['on']] = !empty($ip[$ad['on']]) ? 1 : 0;
            }
        }
        if (isset($ip['quantity'])) {
            $it['quantity'] = max(1, (int)$ip['quantity']);
        }
        if ($it['item_type'] === 'dtf_roll') {
            $it['length_m'] = max(0, round($num($ip['length_m'] ?? $it['length_m']), 2));
            $it['quantity'] = 1;
        }
        foreach (['rate', 'print_rate', 'neck_rate'] as $f) {
            if (array_key_exists($f, $ip)) {
                $it[$f] = trim((string)$ip[$f]) === '' ? null : max(0, $num($ip[$f]));
            }
        }
        $items[] = $it;
    }
    $lines = bill_lines_from_items($items, false);
}

[$calc, $t] = compute_bill($lines, $head['discount'], $head['shipping'], $head['inter_state']);
$validDays = max(1, (int)setting('quote_validity_days', '7'));
$validText = date('d-m-Y', strtotime("+$validDays days")) . ' (' . ($validDays === 7 ? '1 week' : $validDays . ($validDays === 1 ? ' day' : ' days')) . ')';
$no = 'QT-' . date('ymd-Hi');
$brand = $seller['seller_name'] !== '' ? $seller['seller_name'] : '';

// WhatsApp message: a short summary; the PDF / picture carries the full quotation.
$msg = 'Hi ' . (trim(explode(' ', $head['name'])[0] ?? '') ?: 'there') . ', here is your quotation' . ($brand !== '' ? ' from ' . $brand : '') . ":\n";
foreach ($calc as $l) {
    $msg .= '• ' . $l['description'] . ' — ' . qty_fmt($l['qty']) . ' ' . $l['unit'] . ' × ' . money((float)$l['rate']) . "\n";
}
$msg .= 'Total: ' . money($t['total']) . ($t['tax_total'] > 0 ? ' (incl. GST)' : '') . "\nValid for " . ($validDays === 7 ? '1 week' : $validDays . ($validDays === 1 ? ' day' : ' days')) . ' (till ' . date('d M Y', strtotime("+$validDays days")) . '). Thank you!';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Quotation <?= h($no) ?></title>
<style>
  @page { size: A4; margin: 12mm; }
  * { box-sizing: border-box; }
  body { font-family: Arial, Helvetica, sans-serif; color: #1b2433; font-size: 10.5pt; margin: 0; background: #eef1f5; }
  .sheet { background: #fff; max-width: 210mm; margin: 16px auto; padding: 14mm 12mm; box-shadow: 0 2px 12px rgba(0,0,0,.15); }
  .bar { max-width: 210mm; margin: 12px auto 0; display: flex; gap: 8px; flex-wrap: wrap; }
  .bar button, .bar a { font: inherit; padding: 8px 14px; border-radius: 8px; border: 1px solid #bbb; background: #fff; color: #111; text-decoration: none; cursor: pointer; }
  .bar .wa { background: #1fa855; color: #fff; border-color: #1fa855; font-weight: 700; }
  .bar button:disabled { opacity: .6; }
  .bar .pulse { box-shadow: 0 0 0 4px rgba(31,168,85,.35); animation: pulse 1.2s ease-in-out 3; }
  @keyframes pulse { 50% { box-shadow: 0 0 0 9px rgba(31,168,85,.15); } }
  .note { max-width: 210mm; margin: 8px auto 0; padding: 10px 12px; background: #fff7e0; border-radius: 8px; color: #6b4a00; }
  .share-note { max-width: 210mm; margin: 8px auto 0; padding: 10px 12px; background: #e7f7ee; border-radius: 8px; color: #145c32; }
  .q-head { display: flex; justify-content: space-between; gap: 16px; align-items: flex-start; }
  .q-title { font-size: 26pt; font-weight: 800; letter-spacing: .12em; color: #2c5cc5; margin: 0; }
  .q-sub { color: #667085; margin-top: 2px; }
  .seller { text-align: right; line-height: 1.4; }
  .seller b { font-size: 13pt; }
  .seller div { white-space: pre-line; }
  .meta { display: grid; grid-template-columns: 1.4fr 1fr; gap: 16px; margin: 18px 0 14px; padding: 12px 14px; background: #f3f6fb; border-radius: 10px; }
  .meta .lbl { font-size: 8pt; text-transform: uppercase; letter-spacing: .1em; color: #667085; font-weight: 700; margin-bottom: 3px; }
  .meta .addr { white-space: pre-line; line-height: 1.35; }
  .meta table td { padding: 1px 0; } .meta table td:first-child { color: #667085; padding-right: 12px; }
  table.items { width: 100%; border-collapse: collapse; }
  table.items th { background: #2c5cc5; color: #fff; font-size: 8.5pt; text-transform: uppercase; letter-spacing: .04em; padding: 7px 6px; text-align: left; }
  table.items td { padding: 7px 6px; border-bottom: 1px solid #e4e8ef; vertical-align: top; }
  table.items tr:nth-child(even) td { background: #fafbfd; }
  .r { text-align: right !important; white-space: nowrap; }
  .sum { display: flex; justify-content: space-between; gap: 16px; margin-top: 12px; }
  .sum .words { flex: 1; }
  .sum table { border-collapse: collapse; min-width: 75mm; }
  .sum td { padding: 3px 6px; }
  .sum tr.total td { background: #2c5cc5; color: #fff; font-weight: 700; font-size: 12.5pt; padding: 7px 6px; }
  .terms { margin-top: 18px; font-size: 9pt; line-height: 1.45; color: #475467; white-space: pre-line; }
  .foot { margin-top: 22px; text-align: center; color: #667085; font-size: 9pt; }
  .muted { color: #667085; font-size: 8.5pt; }
  body.capturing .sheet { width: 794px !important; max-width: none !important; margin: 0 !important; padding: 40px 36px !important; box-shadow: none !important; }
  body.capturing .q-head, body.capturing .sum { flex-direction: row !important; }
  body.capturing .meta { grid-template-columns: 1.4fr 1fr !important; }
  body.capturing .seller { text-align: right !important; }
  body.capturing table.items { font-size: 10.5pt !important; }
  @media screen and (max-width: 820px) {
    .sheet { margin: 8px; padding: 16px 12px; } .q-head, .sum { flex-direction: column; } .seller { text-align: left; }
    .meta { grid-template-columns: 1fr; } .sum table { min-width: 0; width: 100%; } table.items { font-size: 9pt; } .bar { padding: 0 8px; } .note, .share-note { margin: 8px; }
    .q-title { font-size: 20pt; }
  }
  @media print { body { background: #fff; } .bar, .note, .share-note { display: none !important; } .sheet { box-shadow: none; margin: 0; max-width: none; padding: 0; } }
</style>
</head>
<body data-assets="<?= h(base_url('assets/')) ?>" data-file="<?= h('Quotation-' . $no) ?>"
      data-text="<?= h($msg) ?>" data-wa="<?= h((string)wa_number($head['phone'])) ?>">
<div class="bar">
  <button class="wa" data-share="pdf">📄 Send PDF on WhatsApp</button>
  <button class="wa" data-share="png">🖼 Send picture on WhatsApp</button>
  <button data-share="download">⬇ Download PDF</button>
  <button onclick="window.print()">🖨 Print</button>
  <button onclick="window.close()">✕ Close</button>
</div>
<p class="note">📝 Quotation only — nothing is saved (no order, no bill, no stock). To change it, edit the form in the other tab and tap 📝 Quotation again.</p>
<p class="share-note" id="shareNote" hidden></p>
<div class="sheet">
  <div class="q-head">
    <div>
      <h1 class="q-title">QUOTATION</h1>
      <div class="q-sub">Price estimate — not a bill</div>
    </div>
    <?php if ($seller['seller_name'] !== '' || $seller['seller_address'] !== ''): ?>
    <div class="seller">
      <?php if ($seller['seller_name'] !== ''): ?><b><?= h($seller['seller_name']) ?></b><?php endif; ?>
      <?php if ($seller['seller_address'] !== ''): ?><div><?= h($seller['seller_address']) ?></div><?php endif; ?>
      <?php if ($seller['seller_phone'] !== ''): ?><div>Phone: <?= h($seller['seller_phone']) ?></div><?php endif; ?>
      <?php if ($seller['seller_gstin'] !== ''): ?><div>GSTIN: <?= h($seller['seller_gstin']) ?></div><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>

  <div class="meta">
    <div>
      <div class="lbl">Quotation for</div>
      <b><?= h($head['name'] ?: '—') ?></b>
      <?php $addr = trim($head['address'] . ($head['pincode'] !== '' ? ' – ' . $head['pincode'] : '')); if ($addr !== ''): ?><div class="addr"><?= h($addr) ?></div><?php endif; ?>
      <?php if ($head['phone'] !== ''): ?><div>Phone: <?= h($head['phone']) ?></div><?php endif; ?>
      <?php if ($head['gstin'] !== ''): ?><div>GSTIN: <?= h($head['gstin']) ?></div><?php endif; ?>
    </div>
    <table>
      <tr><td>Quotation no</td><td><b><?= h($no) ?></b></td></tr>
      <tr><td>Date</td><td><b><?= h(date('d-m-Y')) ?></b></td></tr>
      <tr><td>Valid till</td><td><b><?= h($validText) ?></b></td></tr>
    </table>
  </div>

  <?php if (!$calc): ?>
    <p><b>No items yet.</b> Add products on the form, then tap 📝 Quotation again.</p>
  <?php else: ?>
  <table class="items">
    <thead><tr><th>#</th><th>Item</th><th class="r">Qty</th><th class="r">Rate ₹</th><?php if ($t['tax_total'] > 0): ?><th class="r">GST</th><?php endif; ?><th class="r">Amount ₹</th></tr></thead>
    <tbody>
    <?php foreach ($calc as $i => $l): ?>
      <tr><td><?= $i + 1 ?></td><td><?= h($l['description']) ?></td><td class="r"><?= qty_fmt($l['qty']) ?> <?= h($l['unit']) ?></td>
        <td class="r"><?= inr_number((float)$l['rate'], 2) ?></td>
        <?php if ($t['tax_total'] > 0): ?><td class="r"><?= qty_fmt($l['gst_rate']) ?>%</td><?php endif; ?>
        <td class="r"><?= inr_number($l['taxable'] + $l['tax'], 2) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <div class="sum">
    <div class="words">
      <div class="muted">Amount in words</div>
      <b><?= h(amount_in_words($t['total'])) ?></b>
      <?php if ($head['notes'] !== ''): ?><p><?= nl2br(h($head['notes'])) ?></p><?php endif; ?>
    </div>
    <table>
      <tr><td>Sub total</td><td class="r"><?= inr_number($t['subtotal'], 2) ?></td></tr>
      <?php if ($t['discount'] > 0): ?><tr><td>Discount</td><td class="r">−<?= inr_number($t['discount'], 2) ?></td></tr><?php endif; ?>
      <?php if ($t['shipping'] > 0): ?><tr><td>Shipping<?= $t['ship_tax'] > 0 ? ' (+' . qty_fmt($t['ship_gst']) . '% GST)' : '' ?></td><td class="r"><?= inr_number($t['shipping'], 2) ?></td></tr><?php endif; ?>
      <?php if ($t['tax_total'] > 0): ?>
        <?php if ($head['inter_state']): ?><tr><td>IGST</td><td class="r"><?= inr_number($t['igst'], 2) ?></td></tr>
        <?php else: ?><tr><td>CGST</td><td class="r"><?= inr_number($t['cgst'], 2) ?></td></tr><tr><td>SGST</td><td class="r"><?= inr_number($t['sgst'], 2) ?></td></tr><?php endif; ?>
      <?php endif; ?>
      <?php if (abs($t['round_off']) > 0.001): ?><tr><td>Round off</td><td class="r"><?= inr_number($t['round_off'], 2) ?></td></tr><?php endif; ?>
      <tr class="total"><td>Total ₹</td><td class="r"><?= inr_number($t['total'], 2) ?></td></tr>
    </table>
  </div>
  <?php endif; ?>

  <div class="terms"><?= h(str_replace('{days}', (string)$validDays, setting('quote_terms', '') ?: "Prices are valid for 1 week, till the date above.\n50% advance to confirm the order; balance before dispatch.\nDelivery time is counted from the day the design and advance are received.")) ?></div>
  <p class="foot">Thank you<?= $brand !== '' ? ' for choosing ' . h($brand) : '' ?>! This is a quotation, not a bill.</p>
</div>
<script src="<?= h(asset('assets/bill_share.js')) ?>"></script>
</body>
</html>
