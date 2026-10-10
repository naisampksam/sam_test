<?php
// Payments due: who owes money, defaulters (shipped / old bills still unpaid), each customer's bills and payments,
// and all money received. A bill = tax invoice or proforma that is not cancelled or converted.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/orders.php';
require __DIR__ . '/inc/billing.php';

require_login();
if (!cap('billing') && !can_view('bill')) {
    http_response_code(403);
    exit('You are not allowed to see payments.');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_admin()) {
    csrf_check();
    set_setting('due_days', (string)max(1, min(365, (int)($_POST['due_days'] ?? 7))));
    flash('Saved.');
    redirect('dues.php');
}
$days = (int)setting('due_days', '7');
$view = in_array($_GET['view'] ?? '', ['all', 'paid', 'defaulters'], true) ? $_GET['view'] : 'defaulters';
$cust = trim((string)($_GET['customer'] ?? ''));

// Open bills with money still to come, and whether each one is overdue.
$limit = date('Y-m-d H:i:s', strtotime("-$days days"));
$open = q("SELECT b.*, o.shipped, o.shipped_at, b.total - b.paid AS due,
              COALESCE(NULLIF(b.customer_code, ''), NULLIF(b.bill_name, ''), 'Walk-in') AS party,
              CASE WHEN (o.id IS NOT NULL AND o.shipped = 1 AND o.shipped_at <= ?) OR (o.id IS NULL AND b.bill_date <= ?) THEN 1 ELSE 0 END AS overdue
           FROM bills b LEFT JOIN orders o ON o.id = b.order_id AND o.deleted_at IS NULL
           WHERE b.status = 'final' AND b.converted_to IS NULL AND b.total - b.paid > 0.5
           ORDER BY b.bill_date, b.id", [$limit, substr($limit, 0, 10)])->fetchAll();
$byParty = [];
foreach ($open as $b) {
    $p = &$byParty[$b['party']];
    $p ??= ['party' => $b['party'], 'name' => $b['bill_name'], 'phone' => $b['bill_phone'], 'bills' => 0, 'billed' => 0.0, 'paid' => 0.0, 'due' => 0.0, 'overdue' => 0.0, 'oldest' => $b['bill_date'], 'shipped_unpaid' => 0];
    $p['bills']++;
    $p['billed'] += (float)$b['total'];
    $p['paid'] += (float)$b['paid'];
    $p['due'] += (float)$b['due'];
    $p['overdue'] += $b['overdue'] ? (float)$b['due'] : 0;
    $p['shipped_unpaid'] += $b['shipped'] ? 1 : 0;
    $p['name'] = $p['name'] ?: $b['bill_name'];
    $p['phone'] = $p['phone'] ?: $b['bill_phone'];
    unset($p);
}
uasort($byParty, fn($a, $b) => [$b['overdue'], $b['due']] <=> [$a['overdue'], $a['due']]);
$defaulters = array_filter($byParty, fn($p) => $p['overdue'] > 0);
$totDue = array_sum(array_column($byParty, 'due'));
$totOver = array_sum(array_column($defaulters, 'overdue'));
$month = date('Y-m');
$recvMonth = (float)q('SELECT IFNULL(SUM(amount), 0) FROM bill_payments WHERE paid_on >= ?', [$month . '-01'])->fetchColumn();

$pageTitle = 'Payments due';
$active = 'dues';
require __DIR__ . '/inc/header.php';

/** WhatsApp reminder for what a customer still owes. */
function due_reminder_link(array $p): ?string
{
    $n = wa_number((string)$p['phone']);
    if (!$n) {
        return null;
    }
    $text = 'Hi ' . ($p['name'] ?: $p['party']) . ', this is a gentle reminder from ' . setting('company_name', 'Looma Apparels')
        . ': ' . money($p['due'], 0) . ' is pending for your order' . ($p['bills'] > 1 ? 's' : '') . '. Kindly pay at the earliest. Thank you 🙏';
    return 'https://wa.me/' . $n . '?text=' . rawurlencode($text);
}

// ---------------------------------------------------------------- one customer
if ($cust !== '') {
    $bills = q("SELECT b.*, o.shipped, o.shipped_at, b.total - b.paid AS due FROM bills b LEFT JOIN orders o ON o.id = b.order_id
                WHERE COALESCE(NULLIF(b.customer_code, ''), NULLIF(b.bill_name, ''), 'Walk-in') = ? AND b.status = 'final' AND b.converted_to IS NULL
                ORDER BY b.bill_date DESC, b.id DESC", [$cust])->fetchAll();
    $pays = q("SELECT p.*, b.number FROM bill_payments p JOIN bills b ON b.id = p.bill_id
               WHERE COALESCE(NULLIF(b.customer_code, ''), NULLIF(b.bill_name, ''), 'Walk-in') = ? ORDER BY p.paid_on DESC, p.id DESC", [$cust])->fetchAll();
    $p = $byParty[$cust] ?? ['party' => $cust, 'name' => $bills[0]['bill_name'] ?? '', 'phone' => $bills[0]['bill_phone'] ?? '', 'bills' => 0, 'due' => 0, 'overdue' => 0];
    $billed = array_sum(array_map(fn($b) => (float)$b['total'], $bills));
    $paidAll = array_sum(array_map(fn($x) => (float)$x['amount'], $pays));
    ?>
    <div class="page-head"><div><a class="back" href="dues.php">← Payments due</a>
      <h1><?= h($cust) ?> <?php if ($p['overdue'] > 0): ?><span class="badge delayed">Defaulter</span><?php elseif ($p['due'] > 0): ?><span class="badge printing">Owes</span><?php else: ?><span class="badge shipped">All paid</span><?php endif; ?></h1>
      <p class="muted small"><?= h(trim(($p['name'] ?? '') . ($p['phone'] ? ' · ' . $p['phone'] : ''))) ?></p></div>
      <div class="actions"><?php if (($p['due'] ?? 0) > 0 && ($wl = due_reminder_link($p))): ?><a class="btn wa-btn" href="<?= h($wl) ?>" target="_blank" rel="noopener"><?= wa_icon() ?> Send reminder</a><?php endif; ?></div></div>
    <div class="stats">
      <div class="stat"><span class="stat-label">Billed</span><span class="stat-num"><?= h(money($billed, 0)) ?></span><span class="stat-sub"><?= count($bills) ?> bills</span></div>
      <div class="stat"><span class="stat-label">Paid so far</span><span class="stat-num"><?= h(money($paidAll, 0)) ?></span><span class="stat-sub"><?= count($pays) ?> payments</span></div>
      <div class="stat <?= $p['due'] > 0 ? 'stat-warn' : '' ?>"><span class="stat-label">Balance</span><span class="stat-num"><?= h(money((float)$p['due'], 0)) ?></span><span class="stat-sub"><?= $p['overdue'] > 0 ? h(money((float)$p['overdue'], 0)) . ' overdue' : 'nothing overdue' ?></span></div>
    </div>
    <section class="panel"><h2>Bills</h2>
      <div class="table-wrap"><table class="table compact"><thead><tr><th>Bill</th><th>Date</th><th>Order</th><th class="num">Total</th><th class="num">Paid</th><th class="num">Balance</th></tr></thead><tbody>
      <?php foreach ($bills as $b): $u = $b['order_id'] ? 'order.php?id=' . (int)$b['order_id'] . '#payment' : 'bill.php?id=' . (int)$b['id']; ?>
        <tr onclick="location='<?= h($u) ?>'"><td><a href="<?= h($u) ?>"><?= h($b['number']) ?></a><?= $b['type'] === 'proforma' ? ' <small class="muted">proforma</small>' : '' ?></td><td><?= h(fmt_date($b['bill_date'])) ?></td>
          <td><?= $b['order_id'] ? h(order_no($b['order_id'])) . ($b['shipped'] ? ' <span class="badge shipped">Shipped</span>' : '') : '—' ?></td>
          <td class="num"><?= h(money((float)$b['total'], 0)) ?></td><td class="num"><?= h(money((float)$b['paid'], 0)) ?></td>
          <td class="num"><b class="<?= $b['due'] > 0.5 ? 'err-text' : 'ok-text' ?>"><?= $b['due'] > 0.5 ? h(money((float)$b['due'], 0)) : 'Paid' ?></b></td></tr>
      <?php endforeach; ?>
      <?php if (!$bills): ?><tr><td colspan="6" class="muted">No bills.</td></tr><?php endif; ?>
      </tbody></table></div>
    </section>
    <section class="panel"><h2>Payments received</h2>
      <div class="table-wrap"><table class="table compact"><thead><tr><th>Date</th><th>What</th><th>Mode</th><th>Bill</th><th class="num">Amount</th></tr></thead><tbody>
      <?php foreach ($pays as $x): ?>
        <tr><td><?= h(fmt_date($x['paid_on'])) ?></td><td><?= h($x['note'] ?: 'Payment') ?></td><td><?= h($x['mode']) ?></td><td><?= h($x['number']) ?></td><td class="num"><b><?= h(money((float)$x['amount'], 0)) ?></b></td></tr>
      <?php endforeach; ?>
      <?php if (!$pays): ?><tr><td colspan="5" class="muted">Nothing received yet.</td></tr><?php endif; ?>
      </tbody></table></div>
    </section>
    <?php
    require __DIR__ . '/inc/footer.php';
    exit;
}
?>
<div class="page-head"><div><h1>Payments due</h1>
  <p class="muted small">Money still to come from customers. <b>Defaulters</b>: the order was shipped (or the bill was made) more than <?= $days ?> days ago and it is still not fully paid.</p></div></div>
<div class="stats">
  <div class="stat"><span class="stat-label">To collect</span><span class="stat-num"><?= h(money($totDue, 0)) ?></span><span class="stat-sub"><?= count($byParty) ?> customers</span></div>
  <a class="stat <?= $defaulters ? 'stat-warn' : '' ?>" href="?view=defaulters"><span class="stat-label">Defaulters</span><span class="stat-num"><?= count($defaulters) ?></span><span class="stat-sub"><?= h(money($totOver, 0)) ?> overdue</span></a>
  <div class="stat"><span class="stat-label">Received this month</span><span class="stat-num"><?= h(money($recvMonth, 0)) ?></span><span class="stat-sub"><?= h(date('F')) ?></span></div>
</div>
<nav class="tabs" style="margin:14px 0">
  <a class="<?= $view === 'defaulters' ? 'on' : '' ?>" href="?view=defaulters">⚠ Defaulters <span class="count"><?= count($defaulters) ?></span></a>
  <a class="<?= $view === 'all' ? 'on' : '' ?>" href="?view=all">All dues <span class="count"><?= count($byParty) ?></span></a>
  <a class="<?= $view === 'paid' ? 'on' : '' ?>" href="?view=paid">💰 Payments received</a>
</nav>

<?php if ($view === 'paid'):
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : $month . '-01';
    $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : today();
    $rows = q("SELECT p.*, b.number, b.order_id, COALESCE(NULLIF(b.customer_code, ''), NULLIF(b.bill_name, ''), 'Walk-in') AS party
               FROM bill_payments p JOIN bills b ON b.id = p.bill_id WHERE p.paid_on BETWEEN ? AND ? ORDER BY p.paid_on DESC, p.id DESC", [$from, $to])->fetchAll();
    $modes = [];
    foreach ($rows as $x) { $modes[$x['mode']] = ($modes[$x['mode']] ?? 0) + (float)$x['amount']; }
    ?>
  <form class="filters" method="get"><input type="hidden" name="view" value="paid">
    <input type="date" name="from" value="<?= h($from) ?>"><input type="date" name="to" value="<?= h($to) ?>"><button class="btn">Show</button></form>
  <p class="small"><b><?= h(money(array_sum($modes), 0)) ?></b> received<?php foreach ($modes as $m => $a): ?> · <?= h($m) ?> <?= h(money($a, 0)) ?><?php endforeach; ?></p>
  <section class="panel"><div class="table-wrap"><table class="table compact">
    <thead><tr><th>Date</th><th>Customer</th><th>What</th><th>Mode</th><th>Bill</th><th class="num">Amount</th></tr></thead><tbody>
    <?php foreach ($rows as $x): ?>
      <tr onclick="location='dues.php?customer=<?= h(urlencode($x['party'])) ?>'"><td><?= h(fmt_date($x['paid_on'])) ?></td><td><a href="dues.php?customer=<?= h(urlencode($x['party'])) ?>"><?= h($x['party']) ?></a></td>
        <td><?= h($x['note'] ?: 'Payment') ?></td><td><?= h($x['mode']) ?></td><td><?= h($x['number']) ?></td><td class="num"><b><?= h(money((float)$x['amount'], 0)) ?></b></td></tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="6" class="muted">No payments in these dates.</td></tr><?php endif; ?>
    </tbody></table></div></section>
<?php else: $list = $view === 'defaulters' ? $defaulters : $byParty; ?>
  <?php if (!$list): ?>
    <p class="empty"><?= $view === 'defaulters' ? '👍 No defaulters — nothing overdue.' : '👍 Nobody owes money.' ?></p>
  <?php else: ?>
  <section class="panel"><div class="table-wrap"><table class="table compact dues-table">
    <thead><tr><th>Customer</th><th class="num hide-sm">Bills</th><th class="num hide-sm">Billed</th><th class="num hide-sm">Paid</th><th class="num">Balance</th><th>Due since</th><th></th></tr></thead><tbody>
    <?php foreach ($list as $p): $u = 'dues.php?customer=' . urlencode($p['party']); $age = (int)((time() - strtotime($p['oldest'])) / 86400); ?>
      <tr onclick="location='<?= h($u) ?>'">
        <td><a href="<?= h($u) ?>"><b><?= h($p['party']) ?></b></a><?php if ($p['name'] && $p['name'] !== $p['party']): ?><br><small class="muted"><?= h($p['name']) ?></small><?php endif; ?></td>
        <td class="num hide-sm"><?= $p['bills'] ?></td><td class="num hide-sm"><?= h(money($p['billed'], 0)) ?></td><td class="num hide-sm"><?= h(money($p['paid'], 0)) ?></td>
        <td class="num"><b class="err-text"><?= h(money($p['due'], 0)) ?></b><?php if ($p['overdue'] > 0 && $p['overdue'] < $p['due']): ?><br><small class="muted"><?= h(money($p['overdue'], 0)) ?> overdue</small><?php endif; ?></td>
        <td><?= h(fmt_date($p['oldest'])) ?><br><small class="<?= $p['overdue'] > 0 ? 'err-text' : 'muted' ?>"><?= $age ?> days<?= $p['shipped_unpaid'] ? ' · shipped' : '' ?></small></td>
        <td><?php if ($wl = due_reminder_link($p)): ?><a class="btn small wa-btn" href="<?= h($wl) ?>" target="_blank" rel="noopener" onclick="event.stopPropagation()"><?= wa_icon() ?> Remind</a><?php endif; ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div></section>
  <?php endif; ?>
<?php endif; ?>
<?php if (is_admin()): ?>
  <form method="post" class="inline-add" style="margin-top:12px"><?= csrf_field() ?>
    <label class="small">Defaulter after <input type="number" name="due_days" min="1" max="365" value="<?= $days ?>" style="width:80px"> days unpaid (from shipping, or from the bill date when there is no order)</label>
    <button class="btn small">Save</button></form>
<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php'; ?>
