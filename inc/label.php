<?php
// The shipping label (75 mm × 125 mm), shared by order labels (slip.php) and stand-alone labels (labels.php).
declare(strict_types=1);

/** Label fields: form name => [order column, label, input type]. */
const LABEL_FIELDS = [
    'courier' => ['courier', 'Courier partner', 'select'],
    'tracking_no' => ['tracking_no', 'Tracking number (not printed)', 'text'],
    'order_ref' => ['order_ref', 'ORD- (order reference)', 'text'],
    'payment_type' => ['payment_type', 'Payment', 'payment'],
    'cod_amount' => ['cod_amount', 'COD amount to collect ₹', 'money'],
    'contents' => [null, 'Contents (printed on label only)', 'textarea'],
    'ship_name' => ['ship_name', 'Ship-to name', 'text'],
    'ship_phone' => ['ship_phone', 'Ship-to phone', 'tel'],
    'ship_address' => ['ship_address', 'Ship-to address', 'textarea'],
    'ship_pincode' => ['ship_pincode', 'Ship-to PIN', 'pin'],
    'slip_brand' => ['slip_brand', 'Return-to name (customer name)', 'text'],
    'ret_phone' => ['ret_phone', 'Seller phone', 'tel'],
    'ret_address' => ['ret_address', 'Seller address', 'textarea'],
    'ret_pincode' => ['ret_pincode', 'Seller PIN', 'pin'],
];

/** Seller ("Return to") details used when a label has none of its own. */
function label_defaults(): array
{
    return [
        'slip_brand' => (string)setting('slip_brand', setting('company_name', 'Looma Apparels')),
        'ret_phone' => (string)setting('slip_ret_phone', ''),
        'ret_address' => (string)setting('slip_ret_address', ''),
        'ret_pincode' => (string)setting('slip_ret_pincode', ''),
    ];
}

/** A posted label field, cleaned. Returns [value, error]. */
function label_clean(string $k, $raw): array
{
    $v = trim((string)$raw);
    if ($k === 'payment_type') {
        return [in_array($v, ['prepaid', 'cod'], true) ? $v : '', null];
    }
    if ($k === 'cod_amount') {
        $v = str_replace([',', '₹', ' '], '', $v);
        if ($v === '') {
            return [null, null];
        }
        return is_numeric($v) && (float)$v > 0 ? [number_format((float)$v, 2, '.', ''), null] : [null, 'COD amount must be a number.'];
    }
    return [mb_substr($v, 0, in_array($k, ['ship_address', 'ret_address', 'contents'], true) ? 1000 : 150), null];
}

/** ₹ amount without needless decimals: 1250 → "1,250", 99.5 → "99.50". */
function cod_text($amount): string
{
    $a = (float)$amount;
    return function_exists('inr_number') ? inr_number($a, $a == floor($a) ? 0 : 2) : number_format($a, $a == floor($a) ? 0 : 2);
}

/** The editable fields of one label; inputs are named $prefix[key]. */
function label_form_fields(string $prefix, array $v, array $couriers, string $brandList = ''): void
{
    $sec = ['courier' => 'Shipment', 'ship_name' => 'Ship to (customer)', 'slip_brand' => 'Return to (seller)'];
    echo '<div class="grid">';
    foreach (LABEL_FIELDS as $k => [$col, $label, $type]) {
        if (isset($sec[$k])) {
            echo '<h3>' . $sec[$k] . '</h3>';
        }
        $name = h($prefix . '[' . $k . ']');
        if ($type === 'payment') {
            echo '<div class="field full"><span class="lbl">Payment</span><div class="seg-toggle label-pay">';
            foreach (['prepaid' => '✓ Prepaid', 'cod' => '💵 COD (cash on delivery)'] as $pk => $pl) {
                echo '<label><input type="radio" name="' . $name . '" value="' . $pk . '" data-bind="payment_type"' . ($v['payment_type'] === $pk ? ' checked' : '') . '><span>' . $pl . '</span></label>';
            }
            echo '</div></div>';
            continue;
        }
        if ($type === 'money') {
            $amt = $v[$k] !== '' && $v[$k] !== null ? rtrim(rtrim((string)$v[$k], '0'), '.') : '';
            echo '<label class="field full cod-only"' . ($v['payment_type'] === 'cod' ? '' : ' hidden') . '><span class="lbl">' . h($label) . '</span>'
                . '<input type="text" inputmode="decimal" name="' . $name . '" value="' . h($amt) . '" data-bind="cod_amount" placeholder="e.g. 1250" autocomplete="off">'
                . '<small class="hint" data-show-words></small></label>';
            continue;
        }
        echo '<label class="field' . ($type === 'textarea' ? ' full' : '') . '"><span class="lbl">' . h($label) . '</span>';
        if ($type === 'select') {
            $opts = $couriers;
            if ($v[$k] !== '' && !in_array($v[$k], $opts, true)) {
                $opts[] = $v[$k];
            }
            echo '<select name="' . $name . '" data-bind="' . $k . '"><option value="">Select courier…</option>';
            foreach ($opts as $opt) {
                echo '<option' . ($opt === $v[$k] ? ' selected' : '') . '>' . h($opt) . '</option>';
            }
            echo '</select>';
        } elseif ($type === 'textarea') {
            echo '<textarea name="' . $name . '" rows="2" data-bind="' . $k . '">' . h((string)$v[$k]) . '</textarea>';
        } else {
            echo '<input name="' . $name . '" value="' . h((string)$v[$k]) . '" data-bind="' . $k . '" autocomplete="off"'
                . ($k === 'slip_brand' && $brandList !== '' ? ' list="' . h($brandList) . '"' : '')
                . ($type === 'tel' ? ' inputmode="tel"' : '') . ($type === 'pin' ? ' inputmode="numeric" maxlength="6"' : '') . '>';
        }
        echo '</label>';
    }
    echo '</div>';
}

/** The label itself. $refHtml goes top right (order numbers); already escaped. */
function label_card(array $v, string $refHtml): void
{
    $cod = $v['payment_type'] === 'cod';
    $amt = $cod && (float)$v['cod_amount'] > 0;
    ?>
      <article class="slip">
        <div class="s-head">
          <div class="s-carrier" data-show="courier"><?= h((string)$v['courier']) ?></div>
          <div class="s-ord"><?= $refHtml ?></div>
        </div>
        <div class="s-pay <?= $cod ? 'is-cod' : '' ?>" data-pay-box <?= $v['payment_type'] === '' ? 'hidden' : '' ?>>
          <div class="s-pay-top"><b class="s-pay-kind" data-pay-kind><?= $cod ? 'COD' : 'PREPAID' ?></b>
            <b class="s-pay-amt" data-pay-amt><?= $amt ? '₹' . h(cod_text($v['cod_amount'])) : '' ?></b></div>
          <div class="s-pay-words" data-pay-words><?= $amt ? 'Collect: ' . h(amount_in_words((float)$v['cod_amount'])) : ($cod ? '' : 'Do not collect cash') ?></div>
        </div>
        <div class="s-to">
          <div class="s-label">Ship to</div>
          <div class="s-name" data-show="ship_name"><?= h((string)$v['ship_name']) ?></div>
          <div class="s-addr" data-show="ship_address"><?= h(trim((string)$v['ship_address'])) ?></div>
          <div class="s-pin-row">
            <span class="s-pin">PIN <span data-show="ship_pincode"><?= h((string)$v['ship_pincode']) ?></span></span>
            <span class="s-phone">☎ <span data-show="ship_phone"><?= h((string)$v['ship_phone']) ?></span></span>
          </div>
        </div>
        <div class="s-items">
          <div class="s-label">Contents</div>
          <div class="s-contents" data-show="contents"><?= h((string)$v['contents']) ?></div>
        </div>
        <div class="s-from">
          <div class="s-from-text">
            <div class="s-label">Return to</div>
            <b class="s-seller" data-show="slip_brand"><?= h((string)$v['slip_brand']) ?></b>
            <div class="s-raddr" data-show="ret_address"><?= h(trim((string)$v['ret_address'])) ?></div>
            <div>PIN <span data-show="ret_pincode"><?= h((string)$v['ret_pincode']) ?></span> · ☎ <span data-show="ret_phone"><?= h((string)$v['ret_phone']) ?></span></div>
          </div>
        </div>
      </article>
    <?php
}

function label_head(string $title): void
{
    ?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?></title>
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
  .label-form input, .label-form textarea, .label-form select { min-height: 40px; padding: 8px 11px; }
  .label-form .seg-toggle input { min-height: 0; }
  .label-preview { display: flex; justify-content: center; }
  .label-find { position: relative; margin-bottom: 6px; }

  /* The label itself — sized in mm so it prints exactly 75 × 125 mm. */
  .slip {
    width: 75mm; height: 125mm; overflow: hidden; background: #fff; color: #000;
    font-family: Inter, Arial, sans-serif; font-size: 8.5pt; line-height: 1.22;
    padding: 3mm; display: flex; flex-direction: column; gap: 1.6mm;
    box-shadow: 0 2px 14px rgba(0,0,0,.18); border-radius: 2mm;
  }
  .slip * { color: #000; }
  .s-head { display: flex; justify-content: space-between; align-items: flex-start; gap: 2mm; border-bottom: .45mm solid #000; padding-bottom: 1.6mm; }
  .s-carrier { font-weight: 800; font-size: 14pt; text-transform: uppercase; letter-spacing: .02em; line-height: 1.05; word-break: break-word; }
  .s-ord { text-align: right; font-size: 7pt; line-height: 1.25; white-space: nowrap; }
  .s-ord b { display: block; font-size: 10.5pt; }
  .s-ord b.s-cno { font-size: 13pt; font-weight: 800; letter-spacing: .02em; }
  .s-ord .s-ref { display: block; }
  .s-ord .s-ref b { display: inline; font-size: 9pt; }
  .s-pay { border: .45mm solid #000; border-radius: 1.4mm; padding: 1.2mm 2.2mm; }
  .s-pay.is-cod { border-width: .7mm; }
  .s-pay-top { display: flex; justify-content: space-between; align-items: baseline; gap: 2mm; }
  .s-pay-kind { font-size: 13pt; font-weight: 800; letter-spacing: .06em; }
  .s-pay-amt { font-size: 15pt; font-weight: 800; white-space: nowrap; }
  .s-pay-words { font-size: 7.6pt; font-weight: 700; line-height: 1.25; }
  .s-pay-words:empty { display: none; }
  .s-label { font-size: 6.5pt; font-weight: 800; letter-spacing: .14em; text-transform: uppercase; margin-bottom: .5mm; }
  .s-to { border: .45mm solid #000; border-radius: 1.4mm; padding: 1.8mm 2.2mm; }
  .s-name { font-weight: 800; font-size: 11pt; line-height: 1.15; text-transform: uppercase; word-break: break-word; }
  .s-addr { font-size: 10pt; font-weight: 700; line-height: 1.3; margin-top: .8mm; white-space: pre-line; word-break: break-word; max-height: 34mm; overflow: hidden; }
  .s-pin-row { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: baseline; gap: 1mm 2mm; margin-top: 1.4mm; }
  .s-pin { font-weight: 800; font-size: 10pt; letter-spacing: .04em; }
  .s-phone { font-weight: 800; font-size: 13pt; letter-spacing: .02em; white-space: nowrap; }
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
<?php
}

/** Live preview: typing in a field updates its label. */
function label_script(bool $autoPrint): void
{
    ?>
<script>
(function () {
  function words(amount) {
    var n = Math.round(amount);
    var ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen',
      'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    var tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    function two(x) { return x < 20 ? ones[x] : (tens[Math.floor(x / 10)] + ' ' + ones[x % 10]).trim(); }
    function three(x) { return ((x >= 100 ? ones[Math.floor(x / 100)] + ' Hundred ' : '') + two(x % 100)).trim(); }
    if (!n) return 'Zero Rupees Only';
    var parts = [];
    [[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand']].forEach(function (d) {
      if (n >= d[0]) { parts.push(three(Math.floor(n / d[0])) + ' ' + d[1]); n %= d[0]; }
    });
    if (n > 0) parts.push(three(n));
    return parts.join(' ') + ' Rupees Only';
  }
  function money(v) {
    var whole = v === Math.floor(v);
    return v.toLocaleString('en-IN', { minimumFractionDigits: whole ? 0 : 2, maximumFractionDigits: whole ? 0 : 2 });
  }
  function syncPay(block) {
    var sel = block.querySelector('[data-bind=payment_type]:checked');
    var kind = sel ? sel.value : '';
    var amtIn = block.querySelector('[data-bind=cod_amount]');
    var v = amtIn ? parseFloat((amtIn.value || '').replace(/[,₹\s]/g, '')) : 0;
    var box = block.querySelector('[data-pay-box]');
    var only = block.querySelector('.cod-only');
    if (only) only.hidden = kind !== 'cod';
    box.hidden = kind === '';
    box.classList.toggle('is-cod', kind === 'cod');
    box.querySelector('[data-pay-kind]').textContent = kind === 'cod' ? 'COD' : 'PREPAID';
    box.querySelector('[data-pay-amt]').textContent = kind === 'cod' && v > 0 ? '₹' + money(v) : '';
    box.querySelector('[data-pay-words]').textContent = kind === 'cod' ? (v > 0 ? 'Collect: ' + words(v) : '') : 'Do not collect cash';
    var hint = block.querySelector('[data-show-words]');
    if (hint) hint.textContent = v > 0 ? words(v) : 'Printed on the label in numbers and words';
  }
  function onEdit(e) {
    var k = e.target.dataset && e.target.dataset.bind;
    if (!k) return;
    var block = e.target.closest('[data-label]');
    if (k === 'payment_type' || k === 'cod_amount') return syncPay(block);
    var v = k === 'ship_address' || k === 'ret_address' || k === 'contents' ? e.target.value.trim() : e.target.value;
    block.querySelectorAll('[data-show="' + k + '"]').forEach(function (n) { n.textContent = v; });
  }
  document.addEventListener('input', onEdit);
  document.addEventListener('change', onEdit); // dropdowns, payment
  document.querySelectorAll('[data-label]').forEach(syncPay);
  // Option: hide contents on all labels.
  document.querySelectorAll('[data-opt]').forEach(function (c) {
    c.addEventListener('change', function () { document.body.classList.toggle('no-' + c.dataset.opt, !c.checked); });
  });
  <?php if ($autoPrint): ?>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });<?php endif; ?>
})();
</script>
    <?php
}
