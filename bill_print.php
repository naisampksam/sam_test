<?php
// Printable bill (A4). "Plain" bills carry no Looma name, address, GSTIN, bank details or terms.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/orders.php';
require __DIR__ . '/inc/billing.php';

// Customers open it with the private link from WhatsApp (?t=…); staff open it by id after logging in.
$token = (string)($_GET['t'] ?? '');
$shared = $token !== '';
if ($shared) {
    $b = preg_match('/^[a-f0-9]{32}$/', $token) ? (q('SELECT * FROM bills WHERE share_token = ?', [$token])->fetch() ?: null) : null;
} else {
    require_login();
    if (!cap('billing')) {
        http_response_code(403);
        exit('Not allowed.');
    }
    $b = get_bill((int)($_GET['id'] ?? 0));
}
if (!$b) {
    http_response_code(404);
    exit('Bill not found.');
}
$lines = bill_items((int)$b['id']);
[$calc, $t] = compute_bill($lines, (float)$b['discount'], (float)$b['shipping'], (bool)$b['inter_state']);
$plain = $b['branding'] === 'plain';
$title = $b['type'] === 'proforma' ? 'PROFORMA INVOICE' : ($b['tax_total'] > 0 || $b['seller_gstin'] !== '' ? 'TAX INVOICE' : 'INVOICE');
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($b['number']) ?></title>
<style>
  @page { size: A4; margin: 12mm; }
  * { box-sizing: border-box; }
  body { font-family: Arial, Helvetica, sans-serif; color: #111; font-size: 10.5pt; margin: 0; background: #eee; }
  .sheet { background: #fff; max-width: 210mm; margin: 16px auto; padding: 14mm 12mm; box-shadow: 0 2px 12px rgba(0,0,0,.15); }
  .bar { max-width: 210mm; margin: 12px auto 0; display: flex; gap: 8px; }
  .bar button, .bar a { font: inherit; padding: 8px 14px; border-radius: 8px; border: 1px solid #bbb; background: #fff; color: #111; text-decoration: none; cursor: pointer; }
  .bar .primary { background: #2563eb; color: #fff; border-color: #2563eb; }
  .top { display: flex; justify-content: space-between; gap: 16px; border-bottom: 2px solid #111; padding-bottom: 10px; }
  .seller h1 { margin: 0 0 4px; font-size: 17pt; }
  .seller div { white-space: pre-line; line-height: 1.35; }
  .doc { text-align: right; }
  .doc h2 { margin: 0 0 6px; font-size: 14pt; letter-spacing: .06em; }
  .doc table td { padding: 1px 0 1px 10px; }
  .parties { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin: 12px 0; }
  .parties .lbl { font-size: 8pt; text-transform: uppercase; letter-spacing: .1em; color: #555; font-weight: 700; margin-bottom: 3px; }
  .parties div.addr { white-space: pre-line; line-height: 1.35; }
  table.items { width: 100%; border-collapse: collapse; margin-top: 6px; }
  table.items th, table.items td { border: 1px solid #999; padding: 5px 6px; vertical-align: top; }
  table.items th { background: #f1f1f1; font-size: 8.5pt; text-transform: uppercase; }
  .r { text-align: right; white-space: nowrap; }
  .sum { display: flex; justify-content: space-between; gap: 16px; margin-top: 10px; }
  .sum .words { flex: 1; }
  .sum table { border-collapse: collapse; min-width: 75mm; }
  .sum td { padding: 3px 6px; }
  .sum tr.total td { border-top: 2px solid #111; font-weight: 700; font-size: 12pt; }
  .gst { border-collapse: collapse; margin-top: 8px; font-size: 9pt; }
  .gst th, .gst td { border: 1px solid #bbb; padding: 3px 6px; }
  .foot { display: flex; justify-content: space-between; gap: 16px; margin-top: 18px; }
  .foot .bank, .foot .terms { white-space: pre-line; font-size: 9pt; line-height: 1.35; }
  .sign { text-align: right; min-width: 60mm; }
  .sign .line { margin-top: 36px; border-top: 1px solid #111; padding-top: 3px; font-size: 9pt; }
  .muted { color: #666; font-size: 8.5pt; }
  .cancel { color: #c00; font-weight: 700; border: 2px solid #c00; display: inline-block; padding: 2px 8px; margin-top: 4px; }
  .bar { flex-wrap: wrap; }
  .bar .wa { background: #1fa855; color: #fff; border-color: #1fa855; font-weight: 700; }
  .bar button:disabled { opacity: .6; }
  .bar .pulse { box-shadow: 0 0 0 4px rgba(31,168,85,.35); animation: pulse 1.2s ease-in-out 3; }
  @keyframes pulse { 50% { box-shadow: 0 0 0 9px rgba(31,168,85,.15); } }
  .share-note { max-width: 210mm; margin: 8px auto 0; padding: 10px 12px; background: #e7f7ee; border-radius: 8px; color: #145c32; }
  /* While making the PDF / picture: always the A4 layout, whatever the screen. */
  body.capturing .sheet { width: 794px !important; max-width: none !important; margin: 0 !important; padding: 40px 36px !important; box-shadow: none !important; }
  body.capturing .top, body.capturing .sum, body.capturing .foot { flex-direction: row !important; }
  body.capturing .doc { text-align: right !important; }
  @media screen and (max-width: 820px) {
    .sheet { margin: 8px; padding: 16px 12px; } .top, .sum, .foot { flex-direction: column; } .doc { text-align: left; }
    .doc table td { padding: 1px 10px 1px 0; } .sum table { min-width: 0; width: 100%; } table.items { font-size: 9pt; } table.items th, table.items td { padding: 4px; }
    .sign { text-align: left; } .bar { padding: 0 8px; }
  }
  @media screen and (max-width: 480px) {
    table.items { font-size: 7.5pt; } table.items th { font-size: 6.5pt; } table.items th, table.items td { padding: 3px 2px; }
    .bar > * { flex: 1 1 auto; text-align: center; }
  }
  body.capturing table.items { font-size: 10.5pt !important; }
  body.capturing table.items th { font-size: 8.5pt !important; }
  body.capturing table.items th, body.capturing table.items td { padding: 5px 6px !important; }
  @media print { body { background: #fff; } .bar { display: none; } .sheet { box-shadow: none; margin: 0; max-width: none; padding: 0; } }
</style>
</head>
<body data-assets="<?= h(base_url('assets/')) ?>" data-file="<?= h(str_replace('/', '-', $b['number'])) ?>"
      data-text="<?= h($shared ? '' : bill_whatsapp_text($b)) ?>" data-wa="<?= h($shared ? '' : (string)wa_number((string)$b['bill_phone'])) ?>">
<div class="bar">
  <?php if ($shared): ?>
    <button class="primary" data-share="download">⬇ Download PDF</button>
    <button onclick="window.print()">🖨 Print</button>
  <?php else: ?>
    <button class="wa" data-share="pdf">📄 Send PDF on WhatsApp</button>
    <button class="wa" data-share="png">🖼 Send picture on WhatsApp</button>
    <button data-share="download">⬇ Download PDF</button>
    <button onclick="window.print()">🖨 Print</button>
    <a href="bill.php?id=<?= (int)$b['id'] ?>">← Back</a>
  <?php endif; ?>
</div>
<p class="share-note" id="shareNote" hidden></p>
<div class="sheet">
  <div class="top">
    <div class="seller">
      <?php if ($b['seller_name'] !== ''): ?><h1><?= h($b['seller_name']) ?></h1><?php endif; ?>
      <?php if ((string)$b['seller_address'] !== ''): ?><div><?= h((string)$b['seller_address']) ?></div><?php endif; ?>
      <?php if ($b['seller_phone'] !== ''): ?><div>Phone: <?= h($b['seller_phone']) ?><?= !$plain && setting('inv_seller_email', '') !== '' ? ' · ' . h(setting('inv_seller_email', '')) : '' ?></div><?php endif; ?>
      <?php if ($b['seller_gstin'] !== ''): ?><div><b>GSTIN: <?= h($b['seller_gstin']) ?></b></div><?php endif; ?>
    </div>
    <div class="doc">
      <h2><?= $title ?></h2>
      <table>
        <tr><td><?= $b['type'] === 'proforma' ? 'Proforma no' : 'Invoice no' ?></td><td><b><?= h($b['number']) ?></b></td></tr>
        <tr><td>Date</td><td><b><?= h(date('d-m-Y', strtotime($b['bill_date']))) ?></b></td></tr>
        <?php if ($b['bill_state'] !== ''): ?><tr><td>Place of supply</td><td><?= h($b['bill_state']) ?></td></tr><?php endif; ?>
      </table>
      <?php if ($b['status'] === 'cancelled'): ?><div class="cancel">CANCELLED</div><?php endif; ?>
    </div>
  </div>

  <div class="parties">
    <div>
      <div class="lbl">Bill to</div>
      <b><?= h($b['bill_name'] ?: '—') ?></b>
      <div class="addr"><?= h(trim((string)$b['bill_address'] . ($b['bill_pincode'] !== '' ? ' – ' . $b['bill_pincode'] : ''))) ?></div>
      <?php if ($b['bill_phone'] !== ''): ?><div>Phone: <?= h($b['bill_phone']) ?></div><?php endif; ?>
      <?php if ($b['bill_gstin'] !== ''): ?><div><b>GSTIN: <?= h($b['bill_gstin']) ?></b></div><?php endif; ?>
    </div>
  </div>

  <table class="items">
    <thead><tr><th>#</th><th>Description</th><th>HSN</th><th class="r">Qty</th><th class="r">Rate</th><th class="r">Taxable</th><th class="r">GST</th><th class="r">Amount</th></tr></thead>
    <tbody>
    <?php foreach ($calc as $i => $l): ?>
      <tr><td><?= $i + 1 ?></td><td><?= h($l['description']) ?></td><td><?= h($l['hsn']) ?></td><td class="r"><?= qty_fmt($l['qty']) ?> <?= h($l['unit']) ?></td>
        <td class="r"><?= inr_number((float)$l['rate'], 2) ?></td><td class="r"><?= inr_number($l['taxable'], 2) ?></td>
        <td class="r"><?= qty_fmt($l['gst_rate']) ?>%<br><span class="muted"><?= inr_number($l['tax'], 2) ?></span></td><td class="r"><?= inr_number($l['taxable'] + $l['tax'], 2) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <div class="sum">
    <div class="words">
      <div class="muted">Amount in words</div>
      <b><?= h(amount_in_words((float)$b['total'])) ?></b>
      <?php if ($t['by_rate'] && $t['tax_total'] > 0): ?>
        <table class="gst">
          <tr><th>GST %</th><th class="r">Taxable</th><?php if ($b['inter_state']): ?><th class="r">IGST</th><?php else: ?><th class="r">CGST</th><th class="r">SGST</th><?php endif; ?></tr>
          <?php foreach ($t['by_rate'] as $g): ?>
            <tr><td><?= qty_fmt($g['rate']) ?>%</td><td class="r"><?= inr_number($g['taxable'], 2) ?></td>
              <?php if ($b['inter_state']): ?><td class="r"><?= inr_number($g['tax'], 2) ?></td><?php else: ?><td class="r"><?= inr_number($g['tax'] / 2, 2) ?></td><td class="r"><?= inr_number($g['tax'] / 2, 2) ?></td><?php endif; ?></tr>
          <?php endforeach; ?>
        </table>
      <?php endif; ?>
      <?php if ($b['notes']): ?><p><?= nl2br(h((string)$b['notes'])) ?></p><?php endif; ?>
    </div>
    <table>
      <tr><td>Sub total</td><td class="r"><?= inr_number($t['subtotal'], 2) ?></td></tr>
      <?php if ($t['discount'] > 0): ?><tr><td>Discount</td><td class="r">−<?= inr_number($t['discount'], 2) ?></td></tr><?php endif; ?>
      <tr><td>Taxable value</td><td class="r"><?= inr_number($t['taxable'], 2) ?></td></tr>
      <?php if ($b['inter_state']): ?><tr><td>IGST</td><td class="r"><?= inr_number($t['igst'], 2) ?></td></tr>
      <?php else: ?><tr><td>CGST</td><td class="r"><?= inr_number($t['cgst'], 2) ?></td></tr><tr><td>SGST</td><td class="r"><?= inr_number($t['sgst'], 2) ?></td></tr><?php endif; ?>
      <?php if ($t['shipping'] > 0): ?><tr><td>Shipping</td><td class="r"><?= inr_number($t['shipping'], 2) ?></td></tr><?php endif; ?>
      <?php if (abs($t['round_off']) > 0.001): ?><tr><td>Round off</td><td class="r"><?= inr_number($t['round_off'], 2) ?></td></tr><?php endif; ?>
      <tr class="total"><td>Total ₹</td><td class="r"><?= inr_number($t['total'], 2) ?></td></tr>
      <?php if ($b['type'] === 'invoice' && (float)$b['paid'] > 0): ?>
        <tr><td>Received</td><td class="r"><?= inr_number((float)$b['paid'], 2) ?></td></tr>
        <tr><td><b>Balance</b></td><td class="r"><b><?= inr_number(max(0, (float)$b['total'] - (float)$b['paid']), 2) ?></b></td></tr>
      <?php endif; ?>
    </table>
  </div>

  <div class="foot">
    <div>
      <?php if (!$plain && setting('inv_bank', '') !== ''): ?><div class="muted">Bank / UPI</div><div class="bank"><?= h(setting('inv_bank', '')) ?></div><?php endif; ?>
      <?php if (!$plain && setting('inv_terms', '') !== ''): ?><div class="muted" style="margin-top:8px">Terms</div><div class="terms"><?= h(setting('inv_terms', '')) ?></div><?php endif; ?>
    </div>
    <div class="sign">
      <?php if ($b['seller_name'] !== ''): ?>For <b><?= h($b['seller_name']) ?></b><?php endif; ?>
      <div class="line">Authorised signatory</div>
    </div>
  </div>
  <p class="muted" style="text-align:center;margin-top:14px">This is a computer generated <?= $b['type'] === 'proforma' ? 'proforma invoice and is not a demand for tax' : 'invoice' ?>.</p>
</div>
<?php if (!empty($_GET['print'])): ?><script>window.addEventListener('load', function () { setTimeout(function () { window.print(); }, 300); });</script><?php endif; ?>
<script src="<?= h(asset('assets/bill_share.js')) ?>"></script>
</body>
</html>
