<?php
// Partner: money with Looma — balance, what is due, "I paid" (amount + UTR + screenshot), payout of COD money,
// statement, and GST invoices when they have a GSTIN.
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/partner_money.php';
require __DIR__ . '/../inc/partner_ui.php';

$pu = require_partner();
$p = $pu['partner'];
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'pay') {
        $err = partner_payment_send($pu, $_POST, $_FILES['proof'] ?? null, (int)($_POST['po'] ?? 0) ?: null);
        if (!$err) {
            flash('Thanks — Looma will confirm your payment shortly.');
            redirect('partner/money.php');
        }
        $errors[] = $err;
    } elseif (($_POST['action'] ?? '') === 'payout') {
        $err = partner_payout_request($pu, (float)str_replace([',', '₹', ' '], '', (string)($_POST['amount'] ?? '')), (string)($_POST['note'] ?? ''));
        if (!$err) {
            flash('Payout asked. Looma will transfer it and it shows below.');
            redirect('partner/money.php');
        }
        $errors[] = $err;
    }
}
$a = partner_account($p);
$rows = partner_statement((int)$p['id'], 100);
$forPo = (int)($_GET['po'] ?? 0);
$poFor = $forPo ? partner_order($forPo, (int)$p['id']) : null;
$suggest = '';
if ($poFor) {
    [, $qt] = partner_quote($p, $poFor['items'], (string)$poFor['payment_type']);
    $suggest = (string)max(0, round((float)$qt['total'] - max(0, $a['balance']), 2));
} elseif ($a['due'] > 0) {
    $suggest = (string)$a['due'];
}
$bills = $p['gstin'] !== '' ? q("SELECT b.*, po.ref FROM partner_orders po JOIN bills b ON b.id = po.bill_id WHERE po.partner_id = ? AND b.status = 'final' ORDER BY b.id DESC LIMIT 50", [$p['id']])->fetchAll() : [];
partner_head('Money', 'money', $pu);
?>
<h1 class="pt-title">Money</h1>
<?php if ($errors): ?><div class="pt-note err" role="alert"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>

<section class="pt-card pt-balance <?= $a['balance'] < 0 ? 'neg' : '' ?>">
  <small><?= $a['balance'] < 0 ? 'You owe Looma' : 'Your money with Looma' ?></small>
  <b>₹<?= h(inr_number(abs($a['balance']), 2)) ?></b>
  <span class="pt-sub"><?= h(PARTNER_PAY_MODES[$p['pay_mode']] ?? '') ?><?php if ($p['pay_mode'] === 'credit'): ?> · limit ₹<?= h(inr_number((float)$p['credit_limit'])) ?>, pay within <?= (int)$p['credit_days'] ?> days<?php endif; ?></span>
  <?php if ($a['overdue']): ?><span class="pt-note err">Payment overdue since <?= h(fmt_date($a['due_by'])) ?> — new orders wait until it is paid.</span>
  <?php elseif ($a['due'] > 0 && $a['due_by']): ?><span class="pt-sub">Pay by <b><?= h(fmt_date($a['due_by'])) ?></b></span><?php endif; ?>
  <?php if ($a['pending_in'] > 0): ?><span class="pt-sub">₹<?= h(inr_number($a['pending_in'], 2)) ?> you paid is waiting for Looma to confirm.</span><?php endif; ?>
</section>

<h2 class="pt-h2"><?= $poFor ? 'Pay for ' . h($poFor['ref']) : 'I paid Looma' ?></h2>
<form method="post" enctype="multipart/form-data" class="pt-form-block">
  <?= csrf_field() ?><input type="hidden" name="action" value="pay"><input type="hidden" name="po" value="<?= $poFor ? (int)$poFor['id'] : '' ?>">
  <div class="pt-card pt-fields">
    <label><span>Amount ₹</span><input name="amount" inputmode="decimal" required value="<?= h($_POST['amount'] ?? $suggest) ?>" placeholder="0"></label>
    <label><span>UTR / reference</span><input name="ref" maxlength="100" value="<?= h($_POST['ref'] ?? '') ?>" placeholder="From your UPI / bank app"></label>
    <label><span>Payment screenshot</span><input type="file" name="proof" accept="image/jpeg,image/png,image/webp"></label>
  </div>
  <button class="pt-btn filled wide">Send to Looma</button>
</form>

<?php if ($a['available'] > 0): ?>
<details class="pt-more"><summary>Get your money paid out (₹<?= h(inr_number($a['available'], 2)) ?> available)</summary>
  <form method="post" class="pt-form-block"><?= csrf_field() ?><input type="hidden" name="action" value="payout">
    <div class="pt-card pt-fields">
      <label><span>Amount ₹</span><input name="amount" inputmode="decimal" required value="<?= h((string)$a['available']) ?>"></label>
      <label><span>Send it to</span><input name="note" maxlength="255" placeholder="UPI ID or bank account"></label>
    </div>
    <button class="pt-btn filled wide">Ask for payout</button></form>
  <p class="pt-hint">Or keep it here — it is used for your next orders.</p>
</details>
<?php endif; ?>

<h2 class="pt-h2">Statement</h2>
<?php if (!$rows): ?><p class="pt-sub">Nothing yet.</p><?php else: ?>
<section class="pt-card pt-list">
  <?php foreach ($rows as $l): $in = in_array($l['kind'], LEDGER_IN, true); ?>
    <div class="pt-row pt-led <?= $l['status'] !== 'ok' ? 'pending' : '' ?>">
      <span class="pt-row-main"><b><?= h(LEDGER_KINDS[$l['kind']] ?? $l['kind']) ?><?= $l['po_ref'] ? ' · ' . h($l['po_ref']) : '' ?></b>
        <small><?= h(fmt_date($l['created_at'], true)) ?><?= $l['ref'] !== '' ? ' · ' . h($l['ref']) : '' ?><?= $l['status'] === 'pending' ? ' · waiting for Looma' : ($l['status'] === 'rejected' ? ' · not received' : '') ?></small></span>
      <span class="pt-row-end"><b class="<?= $in ? 'pt-in' : '' ?>"><?= $in ? '+' : '−' ?> ₹<?= h(inr_number((float)$l['amount'], 2)) ?></b></span>
    </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if ($bills): ?>
<h2 class="pt-h2">GST invoices</h2>
<section class="pt-card pt-list">
  <?php foreach ($bills as $b): ?>
    <a class="pt-row" href="<?= h(bill_share_url($b)) ?>" target="_blank" rel="noopener">
      <span class="pt-row-main"><b><?= h($b['number']) ?></b><small><?= h($b['ref']) ?> · <?= h(fmt_date($b['bill_date'])) ?></small></span>
      <span class="pt-row-end"><b>₹<?= h(inr_number((float)$b['total'], 2)) ?></b><small><?= (float)$b['paid'] >= (float)$b['total'] - 0.005 ? 'Paid' : 'Due' ?></small></span></a>
  <?php endforeach; ?>
</section>
<?php endif; ?>
<?php partner_foot(); ?>
