<?php
// Profit & loss for a month or financial year, GST summary and a simple balance sheet.
// Income = tax invoices (before GST) + shipping charged. Costs = expenses (before GST), staff salaries included.
// Balance sheet: cash & bank (opening balance + money received − expenses paid),
// money customers owe, stock at cost, other assets; minus GST to pay and loans.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/orders.php';
require __DIR__ . '/inc/billing.php';

require_login();
if (!cap('accounts')) {
    http_response_code(403);
    exit('You are not allowed to see accounts.');
}
$num = fn($v) => round((float)str_replace(',', '.', (string)$v), 2);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && is_admin()) {
    csrf_check();
    set_setting('acct_opening_cash', (string)$num($_POST['acct_opening_cash'] ?? 0));
    set_setting('acct_opening_date', preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['acct_opening_date'] ?? '') ? $_POST['acct_opening_date'] : today());
    set_setting('acct_other_assets', (string)max(0, $num($_POST['acct_other_assets'] ?? 0)));
    set_setting('acct_loans', (string)max(0, $num($_POST['acct_loans'] ?? 0)));
    flash('Saved.');
    redirect('accounts.php?' . http_build_query(array_intersect_key($_GET, ['m' => 1, 'fy' => 1])));
}

// Period: a month (?m=YYYY-MM) or a financial year (?fy=1 with ?m inside it).
$month = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] : date('Y-m');
$isFy = !empty($_GET['fy']);
if ($isFy) {
    $fy = fin_year($month . '-01');
    $startYear = 2000 + (int)substr($fy, 0, 2);
    $from = $startYear . '-04-01';
    $to = ($startYear + 1) . '-03-31';
    $periodLabel = 'Financial year 20' . $fy;
} else {
    $from = $month . '-01';
    $to = date('Y-m-t', strtotime($from));
    $periodLabel = date('F Y', strtotime($from));
}
$asOf = min($to, today());

// ---------------------------------------------------------------- profit & loss
$inv = q("SELECT IFNULL(SUM(taxable), 0) sales, IFNULL(SUM(shipping), 0) ship, IFNULL(SUM(tax_total), 0) gst, COUNT(*) n
          FROM bills WHERE status = 'final' AND converted_to IS NULL AND bill_date BETWEEN ? AND ?", [$from, $to])->fetch();
$exp = q('SELECT category, SUM(amount - gst_amount) net, SUM(gst_amount) gst FROM expenses WHERE exp_date BETWEEN ? AND ? GROUP BY category ORDER BY net DESC', [$from, $to])->fetchAll();
$income = (float)$inv['sales'] + (float)$inv['ship'];
$expTotal = array_sum(array_column($exp, 'net'));
$costs = $expTotal;
$profit = $income - $costs;
$gstOut = (float)$inv['gst'];
$gstIn = array_sum(array_column($exp, 'gst'));

// ---------------------------------------------------------------- balance sheet as at $asOf
$openDate = setting('acct_opening_date', '2000-01-01');
$opening = (float)setting('acct_opening_cash', '0');
$received = (float)q('SELECT IFNULL(SUM(amount), 0) FROM bill_payments WHERE paid_on BETWEEN ? AND ?', [$openDate, $asOf])->fetchColumn();
$spent = (float)q('SELECT IFNULL(SUM(amount), 0) FROM expenses WHERE exp_date BETWEEN ? AND ?', [$openDate, $asOf])->fetchColumn();
$cash = $opening + $received - $spent;
$receivable = (float)q("SELECT IFNULL(SUM(GREATEST(b.total - IFNULL((SELECT SUM(p.amount) FROM bill_payments p WHERE p.bill_id = b.id AND p.paid_on <= ?), 0), 0)), 0)
                        FROM bills b WHERE b.status = 'final' AND b.converted_to IS NULL AND b.bill_date <= ?", [$asOf, $asOf])->fetchColumn();
$stockValue = (float)q('SELECT IFNULL(SUM(GREATEST(qty, 0) * cost_price), 0) FROM stock_items WHERE active = 1')->fetchColumn();
$otherAssets = (float)setting('acct_other_assets', '0');
$gstOutAll = (float)q("SELECT IFNULL(SUM(tax_total), 0) FROM bills WHERE status = 'final' AND converted_to IS NULL AND bill_date <= ?", [$asOf])->fetchColumn();
$gstInAll = (float)q('SELECT IFNULL(SUM(gst_amount), 0) FROM expenses WHERE exp_date <= ?', [$asOf])->fetchColumn();
$gstPayable = max(0, $gstOutAll - $gstInAll);
$loans = (float)setting('acct_loans', '0');
$assets = $cash + $receivable + $stockValue + $otherAssets;
$liabilities = $gstPayable + $loans;
$worth = $assets - $liabilities;

$pageTitle = 'Profit & balance sheet';
$active = 'accounts';
require __DIR__ . '/inc/header.php';
$prev = $isFy ? date('Y-m', strtotime($from . ' -1 year')) : date('Y-m', strtotime($from . ' -1 month'));
$next = $isFy ? date('Y-m', strtotime($from . ' +1 year')) : date('Y-m', strtotime($from . ' +1 month'));
$fyq = $isFy ? '&fy=1' : '';
$row = fn(string $l, float $v, string $cls = '') => '<tr class="' . $cls . '"><td>' . $l . '</td><td class="num">' . h(money($v)) . '</td></tr>';
?>
<div class="page-head">
  <div><h1>Profit &amp; balance sheet</h1><p class="muted small">Worked out from bills, payments, expenses and stock.</p></div>
  <div class="actions"><a class="btn" href="expenses.php?m=<?= h($month) ?>">+ Expense</a><button type="button" class="btn" onclick="window.print()">🖨 Print</button></div>
</div>
<nav class="month-nav">
  <a class="btn small" href="?m=<?= h($prev) . $fyq ?>">←</a><b><?= h($periodLabel) ?></b><a class="btn small" href="?m=<?= h($next) . $fyq ?>">→</a>
  <span class="seg-links"><a class="<?= $isFy ? '' : 'on' ?>" href="?m=<?= h($month) ?>">Month</a><a class="<?= $isFy ? 'on' : '' ?>" href="?m=<?= h($month) ?>&fy=1">Financial year</a></span>
</nav>

<div class="stats">
  <div class="stat"><span class="stat-label">Income</span><span class="stat-num"><?= h(money($income, 0)) ?></span><span class="stat-sub"><?= (int)$inv['n'] ?> invoice<?= (int)$inv['n'] === 1 ? '' : 's' ?>, before GST</span></div>
  <div class="stat"><span class="stat-label">Costs</span><span class="stat-num"><?= h(money($costs, 0)) ?></span><span class="stat-sub">all expenses</span></div>
  <div class="stat <?= $profit < 0 ? 'stat-warn' : '' ?>"><span class="stat-label"><?= $profit < 0 ? 'Loss' : 'Profit' ?></span><span class="stat-num"><?= h(money(abs($profit), 0)) ?></span><span class="stat-sub"><?= $income > 0 ? round($profit / $income * 100) . '% of income' : '' ?></span></div>
  <div class="stat"><span class="stat-label">Net worth</span><span class="stat-num"><?= h(money($worth, 0)) ?></span><span class="stat-sub">as on <?= h(date('d M Y', strtotime($asOf))) ?></span></div>
</div>

<div class="two-col" style="margin-top:14px">
  <section class="panel">
    <h2>Profit &amp; loss <small class="muted"><?= h($periodLabel) ?></small></h2>
    <table class="table compact totals-table acct-table"><tbody>
      <tr class="sub"><td colspan="2"><b>Income</b></td></tr>
      <?= $row('Sales (before GST)', (float)$inv['sales']) ?>
      <?= $row('Shipping charged', (float)$inv['ship']) ?>
      <?= $row('<b>Total income</b>', $income, 'total') ?>
      <tr class="sub"><td colspan="2"><b>Costs</b></td></tr>
      <?php foreach ($exp as $x): ?><?= $row(h($x['category']), (float)$x['net']) ?><?php endforeach; ?>
      <?= $row('<b>Total costs</b>', $costs, 'total') ?>
      <?= $row('<b>' . ($profit < 0 ? 'Net loss' : 'Net profit') . '</b>', $profit, 'total ' . ($profit < 0 ? 'loss' : 'profit')) ?>
    </tbody></table>
    <h3 class="est-h3">GST <small class="muted"><?= h($periodLabel) ?></small></h3>
    <table class="table compact totals-table acct-table"><tbody>
      <?= $row('GST collected on invoices', $gstOut) ?>
      <?= $row('GST paid on expenses', $gstIn) ?>
      <?= $row('<b>GST to pay</b>', $gstOut - $gstIn, 'total') ?>
    </tbody></table>
  </section>

  <section class="panel">
    <h2>Balance sheet <small class="muted">as on <?= h(date('d M Y', strtotime($asOf))) ?></small></h2>
    <table class="table compact totals-table acct-table"><tbody>
      <tr class="sub"><td colspan="2"><b>What the business has</b></td></tr>
      <?= $row('Cash &amp; bank <small class="muted">(estimate)</small>', $cash) ?>
      <?= $row('Money customers owe', $receivable) ?>
      <?= $row('Stock at cost <small class="muted">(today)</small>', $stockValue) ?>
      <?= $row('Machines &amp; other assets', $otherAssets) ?>
      <?= $row('<b>Total assets</b>', $assets, 'total') ?>
      <tr class="sub"><td colspan="2"><b>What the business owes</b></td></tr>
      <?= $row('GST to pay', $gstPayable) ?>
      <?= $row('Loans &amp; other dues', $loans) ?>
      <?= $row('<b>Total liabilities</b>', $liabilities, 'total') ?>
      <?= $row('<b>Net worth (owner’s capital)</b>', $worth, 'total ' . ($worth < 0 ? 'loss' : 'profit')) ?>
    </tbody></table>
    <p class="hint">Cash &amp; bank = opening balance <?= h(money($opening, 0)) ?> (<?= h(date('d M Y', strtotime($openDate))) ?>) + received <?= h(money($received, 0)) ?> − expenses <?= h(money($spent, 0)) ?>.</p>
    <?php if (is_admin()): ?>
    <details class="edit-box">
      <summary class="btn small">✎ Opening balance, assets &amp; loans</summary>
      <form method="post" class="grid" style="margin-top:10px">
        <?= csrf_field() ?>
        <label class="field"><span class="lbl">Cash + bank balance on</span><input type="date" name="acct_opening_date" value="<?= h(setting('acct_opening_date', today())) ?>"></label>
        <label class="field"><span class="lbl">Opening cash + bank ₹</span><input type="number" step="any" name="acct_opening_cash" value="<?= h((string)$opening) ?>"></label>
        <label class="field"><span class="lbl">Machines &amp; other assets ₹</span><input type="number" step="any" min="0" name="acct_other_assets" value="<?= h((string)$otherAssets) ?>"></label>
        <label class="field"><span class="lbl">Loans &amp; other dues ₹</span><input type="number" step="any" min="0" name="acct_loans" value="<?= h((string)$loans) ?>"></label>
        <div class="field"><span class="lbl">&nbsp;</span><button class="btn primary">Save</button></div>
      </form>
    </details>
    <?php endif; ?>
  </section>
</div>
<p class="hint">This is a simple management view for running the business — your accountant’s books and GST returns may differ.</p>
<?php require __DIR__ . '/inc/footer.php'; ?>
