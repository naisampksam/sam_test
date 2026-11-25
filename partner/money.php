<?php
// Partner: money with Looma — balance, what is due, "I paid" (amount + UTR + screenshot), payout of COD money,
// statement. (GST invoices are sent by Looma separately, not shown here.)
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/partner_money.php';
require __DIR__ . '/../inc/partner_ui.php';

$pu = require_partner();
$p = $pu['partner'];
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'pay') {
        // Paying for an order: at least what the order still needs.
        $pf = (int)($_POST['po'] ?? 0) ? partner_order((int)$_POST['po'], (int)$p['id']) : null;
        $minPay = 0.0;
        if ($pf && $pf['status'] === 'new') {
            [, $qq] = partner_quote($p, $pf['items'], (string)$pf['payment_type'], partner_dest($pf));
            $minPay = max(0, round((float)$qq['total'] - partner_account($p)['balance'], 2));
        }
        $paidAmt = (float)str_replace([',', '₹', ' '], '', (string)($_POST['amount'] ?? ''));
        $err = $minPay > 0 && $paidAmt < $minPay - 0.004 ? 'Order ' . $pf['ref'] . ' needs at least ₹' . inr_number($minPay, 2) . '.' : partner_payment_send($pu, $_POST, $_FILES['proof'] ?? null, (int)($_POST['po'] ?? 0) ?: null);
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
$minPay = 0.0;
if ($poFor) {
    [, $qt] = partner_quote($p, $poFor['items'], (string)$poFor['payment_type'], partner_dest($poFor));
    $minPay = max(0, round((float)$qt['total'] - $a['balance'], 2));
    $suggest = (string)$minPay;
} elseif ($a['due'] > 0) {
    $suggest = (string)$a['due'];
}
// Wallet / pay in advance: rounded up to the next ₹100 (540 → 600); a link may bring the amount chosen in the pop-up.
if (partner_prepays($p) && $suggest !== '') {
    $suggest = (string)partner_round_up((float)$suggest);
}
if (isset($_GET['amount']) && is_numeric($_GET['amount']) && (float)$_GET['amount'] >= $minPay) {
    $suggest = (string)round((float)$_GET['amount'], 2);
}
$hasQr = partner_qr_file($p) !== '';
// Orders that wait for money (wallet / pay in advance): what each still needs.
$waiting = [];
if (partner_prepays($p)) {
    // the wallet pays the orders in turn; money owed to Looma comes first
    $bal = $a['balance'];
    $sum = 0.0;
    foreach (q("SELECT id FROM partner_orders WHERE partner_id = ? AND status = 'new' ORDER BY id", [$p['id']])->fetchAll(PDO::FETCH_COLUMN) as $wid) {
        $wo = partner_order((int)$wid, (int)$p['id']);
        [, $wq] = partner_quote($p, $wo['items'], (string)$wo['payment_type'], partner_dest($wo));
        $need = round(min((float)$wq['total'], max(0, (float)$wq['total'] - $bal)), 2);
        $bal -= (float)$wq['total'];
        $sum += (float)$wq['total'];
        if ($need > 0.004) {
            $waiting[] = ['id' => (int)$wid, 'ref' => $wo['ref'], 'name' => $wo['ship_name'], 'total' => (float)$wq['total'], 'need' => $need];
        }
    }
    $allNeed = max(0, round($sum - $a['balance'], 2));
    if (!$poFor && !isset($_GET['amount']) && $waiting) {
        $suggest = (string)partner_round_up($allNeed);
    }
}
partner_head('Money', 'money', $pu);
?>
<h1 class="pt-title">Money</h1>
<?php if ($errors): ?><div class="pt-note err" role="alert"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>

<section class="pt-card pt-balance <?= $a['balance'] < 0 ? 'neg' : '' ?>">
  <small><?= $a['balance'] < 0 ? 'You owe Looma' : (partner_prepays($p) ? 'Wallet balance' : 'Your money with Looma') ?></small>
  <b>₹<?= h(inr_number(abs($a['balance']), 2)) ?></b>
  <span class="pt-sub"><?= h(PARTNER_PAY_MODES[$p['pay_mode']] ?? '') ?><?php if ($p['pay_mode'] === 'credit'): ?> · limit ₹<?= h(inr_number((float)$p['credit_limit'])) ?>, pay within <?= (int)$p['credit_days'] ?> days<?php endif; ?></span>
  <?php if ($a['overdue']): ?><span class="pt-note err">Payment overdue since <?= h(fmt_date($a['due_by'])) ?> — new orders wait until it is paid.</span>
  <?php elseif ($a['due'] > 0 && $a['due_by']): ?><span class="pt-sub">Pay by <b><?= h(fmt_date($a['due_by'])) ?></b></span><?php endif; ?>
  <?php if ($a['pending_in'] > 0): ?><span class="pt-sub">⏳ ₹<?= h(inr_number($a['pending_in'], 2)) ?> you paid is waiting for Looma to confirm.</span><?php endif; ?>
</section>

<nav class="pt-acts" aria-label="What you can do">
  <a href="#recharge"><span aria-hidden="true">👛</span><b><?= partner_prepays($p) ? 'Recharge wallet' : 'Pay Looma' ?></b><small>Scan the QR, send the screenshot</small></a>
  <a href="#payout"><span aria-hidden="true">💸</span><b>Get COD money</b><small><?= $a['available'] > 0 ? '₹' . h(inr_number($a['available'], 2)) . ' ready' : 'Nothing to pay out yet' ?></small></a>
  <a href="#statement"><span aria-hidden="true">🧾</span><b>Statement</b><small>Every order and payment</small></a>
  <a href="<?= h(base_url('partner/index.php')) ?>#export"><span aria-hidden="true">⬇️</span><b>Excel</b><small>Download your orders</small></a>
</nav>

<?php if ($waiting && !$poFor): ?>
<h2 class="pt-h2">Waiting for your recharge</h2>
<section class="pt-card pt-list">
  <?php foreach ($waiting as $w): ?>
    <a class="pt-row" href="<?= h(base_url('partner/money.php?po=' . $w['id'])) ?>#recharge"><span class="pt-row-main"><b><?= h($w['ref']) ?></b><small><?= h($w['name']) ?> · order ₹<?= h(inr_number($w['total'], 2)) ?></small></span>
      <span class="pt-row-end"><b class="pt-need">needs ₹<?= h(inr_number($w['need'], 2)) ?></b><small>Pay ›</small></span></a>
  <?php endforeach; ?>
</section>
<p class="pt-hint">Recharge once for all of them: ₹<?= h(inr_number(partner_round_up($allNeed))) ?> is filled in below<?= $a['balance'] < 0 ? ' (with the ₹' . h(inr_number(-$a['balance'], 2)) . ' you owe Looma)' : '' ?>.</p>
<?php endif; ?>

<h2 class="pt-h2" id="recharge"><?= $poFor ? 'Pay for ' . h($poFor['ref']) : (partner_prepays($p) ? 'Recharge your wallet' : 'Pay Looma') ?></h2>
<form method="post" enctype="multipart/form-data" class="pt-form-block pt-stepform" id="ptPayForm">
  <?= csrf_field() ?><input type="hidden" name="action" value="pay"><input type="hidden" name="po" value="<?= $poFor ? (int)$poFor['id'] : '' ?>">
  <ol class="pt-stepper">
    <li><span class="pt-stepn">1</span><div class="pt-stepb"><b>How much?</b>
      <label class="pt-amount"><span class="pt-vh">Amount ₹</span><input name="amount" type="number" step="1" inputmode="decimal" required value="<?= h($_POST['amount'] ?? $suggest) ?>" placeholder="₹ amount"<?= $minPay > 0 ? ' min="' . h((string)$minPay) . '"' : ' min="1"' ?>></label>
      <p class="pt-hint"><?= $minPay > 0 ? 'At least ₹' . h(inr_number($minPay, 2)) . ' for ' . h($poFor['ref']) . '. ' : '' ?><?= partner_prepays($p) ? 'Rounded up to the next ₹100 — what is left stays in your wallet for the next orders. You can change it.' : 'Type what you pay.' ?></p></div></li>
    <li><span class="pt-stepn">2</span><div class="pt-stepb"><b>Scan and pay</b>
      <?php if ($hasQr): ?>
        <img class="pt-qr" src="<?= h(base_url('partner/qr.php')) ?>" alt="Looma payment QR code" width="220" height="220">
        <p class="pt-hint" style="text-align:center">Open GPay, PhonePe, Paytm or any UPI app, scan, and pay <b data-payamt>₹<?= h($_POST['amount'] ?? $suggest) ?></b>.</p>
      <?php else: ?>
        <p class="pt-hint">Chat with Looma on WhatsApp — we send you the payment QR code.</p>
        <a class="pt-btn filled wide pt-wa" href="<?= h(partner_recharge_link($p, (float)($suggest !== '' ? $suggest : 0), $poFor['ref'] ?? '')) ?>" target="_blank" rel="noopener">Get the QR on WhatsApp</a>
      <?php endif; ?></div></li>
    <li><span class="pt-stepn">3</span><div class="pt-stepb"><b>Send the proof</b>
      <div class="pt-fields">
        <label><span>UTR / reference</span><input name="ref" maxlength="100" value="<?= h($_POST['ref'] ?? '') ?>" placeholder="12-digit number in your UPI app"></label>
        <label><span>Payment screenshot</span><input type="file" name="proof" accept="image/jpeg,image/png,image/webp"></label>
      </div></div></li>
  </ol>
  <button class="pt-btn filled wide">Send to Looma</button>
  <p class="pt-hint" style="text-align:center">Looma checks it and adds it to your <?= partner_prepays($p) ? 'wallet' : 'account' ?> — waiting orders then go ahead by themselves.<?php if ($hasQr): ?> <a class="pt-wa-link" style="display:inline" href="<?= h(partner_recharge_link($p, 0)) ?>" target="_blank" rel="noopener">Questions? WhatsApp Looma</a><?php endif; ?></p>
</form>
<script>(function () { var f = document.getElementById('ptPayForm'), a = f.elements.amount, o = f.querySelector('[data-payamt]');
  if (o) a.addEventListener('input', function () { o.textContent = '₹' + (a.value || 0); }); })();</script>

<h2 class="pt-h2" id="payout">Get your COD money</h2>
<?php if ($a['available'] > 0): ?>
  <form method="post" class="pt-form-block"><?= csrf_field() ?><input type="hidden" name="action" value="payout">
    <p class="pt-sub">₹<?= h(inr_number($a['available'], 2)) ?> of cash-on-delivery money is ready. Looma transfers it to you — or keep it here for your next orders.</p>
    <div class="pt-fields">
      <label><span>Amount ₹</span><input name="amount" inputmode="decimal" required value="<?= h((string)$a['available']) ?>"></label>
      <label><span>Send it to</span><input name="note" maxlength="255" placeholder="UPI ID or bank account"></label>
    </div>
    <button class="pt-btn tinted wide">Ask for payout</button></form>
<?php else: ?>
  <p class="pt-sub">When your customers pay cash on delivery, the money comes here. Then you can ask Looma to transfer it, or use it for your next orders.</p>
<?php endif; ?>

<h2 class="pt-h2" id="statement">Statement</h2>
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

<?php partner_foot(); ?>
