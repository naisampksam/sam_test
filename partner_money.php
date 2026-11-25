<?php
// Partner money (team): balances, payments partners sent (confirm), payouts they asked for (pay), COD collected for
// them (tick, or upload the courier's remittance report), and each partner's statement.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/partner_money.php';

require_login();
if (!cap('billing')) {
    http_response_code(403);
    exit('You are not allowed to open partner money (needs the Bills permission).');
}
$pid = (int)($_GET['partner'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = (string)($_POST['action'] ?? '');
    $back = 'partner_money.php' . ($pid ? '?partner=' . $pid : '');
    if (in_array($act, ['confirm', 'reject', 'paid', 'reject_payout'], true)) {
        $ok = partner_ledger_decide((int)($_POST['id'] ?? 0), in_array($act, ['confirm', 'paid'], true), trim((string)($_POST['ref'] ?? '')));
        flash($ok ? ['confirm' => 'Payment confirmed — added to their balance.', 'reject' => 'Payment marked as not received.', 'paid' => 'Payout marked as paid.', 'reject_payout' => 'Payout request turned down.'][$act] : 'Already done.', $ok ? 'ok' : 'err');
    } elseif ($act === 'cod') {
        $n = partner_cod_received((array)($_POST['orders'] ?? []));
        flash($n ? "COD of $n order" . ($n === 1 ? '' : 's') . ' added to the partners’ balances.' : 'Tick the orders whose COD came in.', $n ? 'ok' : 'err');
    } elseif ($act === 'cod_file') {
        require_once __DIR__ . '/inc/vyapar.php';
        $f = $_FILES['report'] ?? null;
        try {
            if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
                throw new RuntimeException('Choose the courier’s COD remittance file (.csv or .xlsx).');
            }
            [$n, $miss] = partner_cod_import(sheet_rows($f['tmp_name'], $f['name']));
            if ($miss < 0) {
                throw new RuntimeException('No AWB / waybill / tracking column found in that file.');
            }
            flash("$n partner COD order" . ($n === 1 ? '' : 's') . ' received' . ($miss ? "; $miss line(s) not partner COD orders or already received." : '.'));
        } catch (Throwable $e) {
            flash($e->getMessage(), 'err');
        }
    } elseif ($act === 'add' && $pid) {
        $err = partner_ledger_add($pid, (string)($_POST['kind'] ?? ''), (float)str_replace([',', '₹'], '', (string)($_POST['amount'] ?? '')), (string)($_POST['ref'] ?? ''), (string)($_POST['note'] ?? ''));
        flash($err ?? 'Saved on their account.', $err ? 'err' : 'ok');
    }
    redirect($back);
}

$partners = partners_all();
$pending = q("SELECT l.*, p.name FROM partner_ledger l JOIN partners p ON p.id = l.partner_id WHERE l.status = 'pending' ORDER BY l.id")->fetchAll();
$cod = partner_cod_waiting($pid);
$pageTitle = 'Partner money';
$active = 'partner_money';
require __DIR__ . '/inc/header.php';
?>
<div class="page-head">
  <div><h1>Partner money</h1><p class="muted small">Balances, payments, payouts and COD of print-on-demand partners.</p></div>
  <div class="actions"><a class="btn" href="<?= h(base_url('partner_orders.php')) ?>">Partner orders</a></div>
</div>

<div class="cards">
<?php foreach ($partners as $p): $a = partner_account($p); ?>
  <a class="card pm-card <?= $pid === (int)$p['id'] ? 'on' : '' ?>" href="?partner=<?= (int)$p['id'] ?>">
    <div class="row-between"><strong><?= h($p['name']) ?></strong><span class="small muted"><?= h(PARTNER_PAY_MODES[$p['pay_mode']] ?? '') ?></span></div>
    <div class="pm-bal <?= $a['balance'] < 0 ? 'neg' : '' ?>">₹<?= h(inr_number($a['balance'], 2)) ?></div>
    <div class="small"><?= $a['balance'] < 0 ? 'Due from them' : 'With Looma (theirs)' ?><?= $a['overdue'] ? ' · <b class="err-text">overdue since ' . h(fmt_date($a['due_by'])) . '</b>' : '' ?>
      <?= $a['pending_in'] ? ' · ₹' . h(inr_number($a['pending_in'])) . ' to confirm' : '' ?><?= $a['pending_out'] ? ' · ₹' . h(inr_number($a['pending_out'])) . ' payout asked' : '' ?></div>
  </a>
<?php endforeach; ?>
</div>

<?php if ($pending): ?>
<section class="panel">
  <h2>To check <span class="count"><?= count($pending) ?></span></h2>
  <?php foreach ($pending as $l): ?>
    <div class="pm-row">
      <div><b><?= h($l['name']) ?></b> · <?= $l['kind'] === 'payment' ? 'says they paid' : 'asks for a payout of' ?> <b>₹<?= h(inr_number((float)$l['amount'], 2)) ?></b>
        <span class="small muted"> · <?= h(fmt_date($l['created_at'], true)) ?><?= $l['ref'] !== '' ? ' · UTR ' . h($l['ref']) : '' ?><?= $l['note'] !== '' ? ' · ' . h($l['note']) : '' ?></span>
        <?php if ($l['proof'] !== ''): ?><a href="image.php?f=<?= h(urlencode($l['proof'])) ?>" target="_blank" rel="noopener"><img class="pm-proof" src="image.php?f=<?= h(urlencode(thumb_path($l['proof']))) ?>" alt="Payment screenshot"></a><?php endif; ?></div>
      <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$l['id'] ?>">
        <?php if ($l['kind'] === 'payment'): ?>
          <button class="btn small primary" name="action" value="confirm">✓ Received</button><button class="btn small ghost" name="action" value="reject" onclick="return confirm('Not received?')">Not received</button>
        <?php else: ?>
          <input name="ref" placeholder="UTR of your transfer" maxlength="100"><button class="btn small primary" name="action" value="paid">✓ Paid</button><button class="btn small ghost" name="action" value="reject_payout">Turn down</button>
        <?php endif; ?></form>
    </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<section class="panel">
  <h2>COD to receive <small class="muted">shipped partner COD orders — tick when the courier pays you</small></h2>
  <?php if ($cod): ?>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cod">
      <div class="table-wrap"><table class="table compact"><thead><tr><th><input type="checkbox" onclick="document.querySelectorAll('[name=&quot;orders[]&quot;]').forEach(c => c.checked = this.checked)" aria-label="All"></th><th>Partner</th><th>Order</th><th>Customer</th><th>Courier · AWB</th><th>Shipped</th><th class="num">COD</th></tr></thead><tbody>
      <?php foreach ($cod as $c): ?><tr><td><input type="checkbox" name="orders[]" value="<?= (int)$c['id'] ?>"></td><td><?= h($c['partner_name']) ?></td><td><a href="order.php?id=<?= (int)$c['id'] ?>"><?= h($c['ref']) ?></a></td>
        <td><?= h($c['ship_name']) ?></td><td><?= h(trim($c['courier'] . ' ' . $c['tracking_no'])) ?></td><td class="small"><?= h(fmt_date($c['shipped_at'])) ?></td><td class="num">₹<?= h(inr_number((float)$c['cod_amount'])) ?></td></tr><?php endforeach; ?>
      </tbody></table></div>
      <button class="btn primary">✓ COD received for ticked orders</button></form>
  <?php else: ?><p class="muted">Nothing waiting.</p><?php endif; ?>
  <form method="post" enctype="multipart/form-data" class="inline-form" data-plain-submit style="margin-top:12px"><?= csrf_field() ?><input type="hidden" name="action" value="cod_file">
    <span class="small">Or upload the courier’s COD remittance report:</span><input type="file" name="report" accept=".csv,.xlsx" required><button class="btn small">Upload</button></form>
  <p class="hint">Delhivery / DTDC / others: any file with an AWB, waybill or tracking number column. Matching partner COD orders are marked received.</p>
</section>

<?php if ($pid && ($p = partner_by_id($pid))): $a = partner_account($p); ?>
<section class="panel" id="statement">
  <h2>Statement · <?= h($p['name']) ?> <small class="muted">balance ₹<?= h(inr_number($a['balance'], 2)) ?></small></h2>
  <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="add">
    <select name="kind"><option value="payment">Payment received (cash / bank)</option><option value="credit">Credit to them (correction)</option><option value="debit">Charge to them (correction)</option></select>
    <input name="amount" inputmode="decimal" placeholder="₹ amount" required><input name="ref" placeholder="UTR / reference" maxlength="100"><input name="note" placeholder="Note" maxlength="255"><button class="btn small primary">Add</button></form>
  <div class="table-wrap"><table class="table compact"><thead><tr><th>Date</th><th>What</th><th>Reference</th><th class="num">In</th><th class="num">Out</th><th></th></tr></thead><tbody>
  <?php foreach (partner_statement($pid) as $l): $in = in_array($l['kind'], LEDGER_IN, true); ?>
    <tr class="<?= $l['status'] !== 'ok' ? 'muted' : '' ?>"><td class="small"><?= h(fmt_date($l['created_at'], true)) ?></td><td><?= h(LEDGER_KINDS[$l['kind']] ?? $l['kind']) ?><?= $l['note'] !== '' ? ' · ' . h($l['note']) : '' ?></td>
      <td class="small"><?= h($l['ref']) ?><?= $l['order_id'] ? ' <a href="order.php?id=' . (int)$l['order_id'] . '">' . h(order_no($l['order_id'])) . '</a>' : '' ?><?= $l['bill_id'] ? ' <a href="bill.php?id=' . (int)$l['bill_id'] . '">bill</a>' : '' ?></td>
      <td class="num"><?= $in ? '₹' . h(inr_number((float)$l['amount'], 2)) : '' ?></td><td class="num"><?= !$in ? '₹' . h(inr_number((float)$l['amount'], 2)) : '' ?></td>
      <td class="small"><?= $l['status'] === 'ok' ? '' : h($l['status'] === 'pending' ? 'waiting' : 'not done') ?></td></tr>
  <?php endforeach; ?>
  </tbody></table></div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php'; ?>
