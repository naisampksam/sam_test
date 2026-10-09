<?php
// Company expenses by month. Amount is what was paid (including GST); the GST part can be noted separately
// so profit is worked out on the amount before GST.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/orders.php';
require __DIR__ . '/inc/billing.php';

require_login();
if (!cap('accounts')) {
    http_response_code(403);
    exit('You are not allowed to see expenses.');
}
$month = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] : date('Y-m');
$from = $month . '-01';
$cat = in_array($_GET['cat'] ?? '', EXPENSE_CATEGORIES, true) ? $_GET['cat'] : '';
$num = fn($v) => round(max(0, (float)str_replace(',', '.', (string)$v)), 2);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    if ($do === 'add' || $do === 'save') {
        $row = [
            'exp_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['exp_date'] ?? '') ? $_POST['exp_date'] : today(),
            'category' => in_array($_POST['category'] ?? '', EXPENSE_CATEGORIES, true) ? $_POST['category'] : 'Other',
            'description' => mb_substr(trim((string)($_POST['description'] ?? '')), 0, 250),
            'vendor' => mb_substr(trim((string)($_POST['vendor'] ?? '')), 0, 150),
            'amount' => $num($_POST['amount'] ?? 0),
            'gst_amount' => $num($_POST['gst_amount'] ?? 0),
            'mode' => in_array($_POST['mode'] ?? '', PAY_MODES, true) ? $_POST['mode'] : 'Other',
            'ref' => mb_substr(trim((string)($_POST['ref'] ?? '')), 0, 80),
        ];
        $row['gst_amount'] = min($row['gst_amount'], $row['amount']);
        if ($row['amount'] <= 0) {
            flash('Enter the amount paid.', 'err');
        } elseif ($do === 'save' && ($eid = (int)($_POST['id'] ?? 0))) {
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($row)));
            q("UPDATE expenses SET $sets WHERE id = ?", array_merge(array_values($row), [$eid]));
            flash('Expense saved.');
        } else {
            $row += ['created_by' => current_user()['id'], 'created_at' => now()];
            q('INSERT INTO expenses (' . implode(',', array_keys($row)) . ') VALUES (' . rtrim(str_repeat('?,', count($row)), ',') . ')', array_values($row));
            flash('Expense of ' . money($row['amount']) . ' added.');
        }
        redirect('expenses.php?m=' . substr($row['exp_date'], 0, 7));
    }
    if ($do === 'delete') {
        q('DELETE FROM expenses WHERE id = ?', [(int)($_POST['id'] ?? 0)]);
        flash('Expense deleted.');
    }
    redirect('expenses.php?m=' . $month);
}

$where = 'exp_date >= ? AND exp_date <= LAST_DAY(?)';
$params = [$from, $from];
if ($cat !== '') {
    $where .= ' AND category = ?';
    $params[] = $cat;
}
$list = q("SELECT * FROM expenses WHERE $where ORDER BY exp_date DESC, id DESC", $params)->fetchAll();
$byCat = q('SELECT category, SUM(amount) amt, SUM(gst_amount) gst, COUNT(*) n FROM expenses WHERE exp_date >= ? AND exp_date <= LAST_DAY(?) GROUP BY category ORDER BY amt DESC', [$from, $from])->fetchAll();
$tot = array_sum(array_column($byCat, 'amt'));
$totGst = array_sum(array_column($byCat, 'gst'));
$edit = !empty($_GET['edit']) ? q('SELECT * FROM expenses WHERE id = ?', [(int)$_GET['edit']])->fetch() : null;
$prev = date('Y-m', strtotime($from . ' -1 month'));
$next = date('Y-m', strtotime($from . ' +1 month'));
$vendors = array_column(q("SELECT vendor FROM expenses WHERE vendor <> '' GROUP BY vendor ORDER BY MAX(id) DESC LIMIT 50")->fetchAll(), 'vendor');

$pageTitle = 'Expenses';
$active = 'expenses';
require __DIR__ . '/inc/header.php';
$e = $edit ?: ['id' => 0, 'exp_date' => $month === date('Y-m') ? today() : $from, 'category' => 'Other', 'description' => '', 'vendor' => '', 'amount' => '', 'gst_amount' => '', 'mode' => 'UPI', 'ref' => ''];
?>
<div class="page-head">
  <div><h1>Expenses</h1><p class="muted small">Everything the business spends. Used for profit &amp; loss and the balance sheet.</p></div>
  <div class="actions"><a class="btn" href="accounts.php?m=<?= h($month) ?>">📊 Profit &amp; balance sheet</a></div>
</div>
<nav class="month-nav">
  <a class="btn small" href="?m=<?= h($prev) ?>">←</a><b><?= h(date('F Y', strtotime($from))) ?></b><a class="btn small" href="?m=<?= h($next) ?>">→</a>
  <?php if ($month !== date('Y-m')): ?><a class="btn small ghost" href="?m=<?= h(date('Y-m')) ?>">This month</a><?php endif; ?>
</nav>

<section class="panel" id="expForm">
  <h2><?= $edit ? 'Edit expense' : 'Add an expense' ?></h2>
  <form method="post" class="grid">
    <?= csrf_field() ?><input type="hidden" name="do" value="<?= $edit ? 'save' : 'add' ?>"><input type="hidden" name="id" value="<?= (int)$e['id'] ?>">
    <label class="field"><span class="lbl">Date</span><input type="date" name="exp_date" value="<?= h($e['exp_date']) ?>" required></label>
    <label class="field"><span class="lbl">Category</span><select name="category"><?php foreach (EXPENSE_CATEGORIES as $c): ?><option <?= $e['category'] === $c ? 'selected' : '' ?>><?= h($c) ?></option><?php endforeach; ?></select></label>
    <label class="field"><span class="lbl">Amount paid ₹ (incl. GST)</span><input type="number" step="any" min="0" name="amount" value="<?= h((string)$e['amount']) ?>" required></label>
    <label class="field"><span class="lbl">GST in it ₹ (if you have a GST bill)</span><input type="number" step="any" min="0" name="gst_amount" value="<?= h((string)$e['gst_amount']) ?>"></label>
    <label class="field"><span class="lbl">What for</span><input name="description" value="<?= h($e['description']) ?>" placeholder="e.g. October rent, 20 kg black fabric"></label>
    <label class="field"><span class="lbl">Paid to</span><input name="vendor" value="<?= h($e['vendor']) ?>" list="dlVendors"><datalist id="dlVendors"><?php foreach ($vendors as $v): ?><option value="<?= h($v) ?>"><?php endforeach; ?></datalist></label>
    <label class="field"><span class="lbl">Paid by</span><select name="mode"><?php foreach (PAY_MODES as $m): ?><option <?= $e['mode'] === $m ? 'selected' : '' ?>><?= $m ?></option><?php endforeach; ?></select></label>
    <label class="field"><span class="lbl">Bill / ref no</span><input name="ref" value="<?= h($e['ref']) ?>"></label>
    <div class="field"><span class="lbl">&nbsp;</span><div><button class="btn primary"><?= $edit ? 'Save' : '+ Add expense' ?></button><?php if ($edit): ?> <a class="btn ghost" href="?m=<?= h($month) ?>">Cancel</a><?php endif; ?></div></div>
  </form>
  <p class="hint">Salaries are counted from the Salary page — don’t add them here again. Stock you buy can be added here as “Blank T-shirts / fabric”.</p>
</section>

<div class="stats">
  <div class="stat"><span class="stat-label">Spent this month</span><span class="stat-num"><?= h(money($tot, 0)) ?></span><span class="stat-sub"><?= count($list) ?> entr<?= count($list) === 1 ? 'y' : 'ies' ?><?= $cat !== '' ? ' in ' . h($cat) : '' ?></span></div>
  <div class="stat"><span class="stat-label">GST paid (input)</span><span class="stat-num"><?= h(money($totGst, 0)) ?></span><span class="stat-sub">can be set off against GST collected</span></div>
</div>

<?php if ($byCat): ?>
<section class="panel" style="margin-top:14px">
  <h2>By category</h2>
  <div class="cat-bars">
    <?php foreach ($byCat as $c): $pct = $tot > 0 ? round($c['amt'] / $tot * 100) : 0; ?>
      <a class="cat-bar <?= $cat === $c['category'] ? 'on' : '' ?>" href="?m=<?= h($month) ?>&cat=<?= h(urlencode($c['category'])) ?>">
        <span class="cb-name"><?= h($c['category']) ?></span><span class="cb-track"><i style="width:<?= $pct ?>%"></i></span><b><?= h(money((float)$c['amt'], 0)) ?></b>
      </a>
    <?php endforeach; ?>
  </div>
  <?php if ($cat !== ''): ?><p><a class="btn small ghost" href="?m=<?= h($month) ?>">Show all categories</a></p><?php endif; ?>
</section>
<?php endif; ?>

<?php if (!$list): ?>
  <p class="empty">No expenses in <?= h(date('F Y', strtotime($from))) ?> yet.</p>
<?php else: ?>
<section class="panel">
  <div class="table-wrap"><table class="table compact">
    <thead><tr><th>Date</th><th>Expense</th><th class="num">Amount</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($list as $x): ?>
      <tr>
        <td><?= h(date('d M', strtotime($x['exp_date']))) ?></td>
        <td class="wrap-cell"><b><?= h($x['category']) ?></b><?= $x['description'] !== '' ? ' · ' . h($x['description']) : '' ?><br><small class="muted"><?= h(implode(' · ', array_filter([$x['vendor'], $x['mode'], $x['ref'] !== '' ? '#' . $x['ref'] : '']))) ?></small></td>
        <td class="num"><b><?= h(money((float)$x['amount'])) ?></b><?= (float)$x['gst_amount'] > 0 ? '<br><small class="muted">GST ' . h(money((float)$x['gst_amount'])) . '</small>' : '' ?></td>
        <td class="row-btns"><a class="btn small ghost" href="?m=<?= h($month) ?>&edit=<?= (int)$x['id'] ?>#expForm">✎</a>
          <form method="post" class="inline" onsubmit="return confirm('Delete this expense?');"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>"><button class="btn small ghost">🗑</button></form></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php'; ?>
