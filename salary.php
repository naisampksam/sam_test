<?php
// Salary & incentive. The month's sales (tax invoices, before GST & shipping) fill in by themselves;
// incentive = sales × each person's incentive %. Paid months keep the figures they were paid on.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/orders.php';
require __DIR__ . '/inc/billing.php';

require_login();
if (!cap('salary')) {
    http_response_code(403);
    exit('You are not allowed to see salaries.');
}
$month = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] : date('Y-m');
$from = $month . '-01';
$sales = month_sales($month);
$num = fn($v) => round(max(0, (float)str_replace(',', '.', (string)$v)), 2);

$staff = q('SELECT u.id, u.name, u.username, u.role, u.active, p.base_salary, p.incentive_pct FROM users u LEFT JOIN staff_pay p ON p.user_id = u.id ORDER BY u.active DESC, u.name')->fetchAll();
$rows = [];
foreach (q('SELECT * FROM salaries WHERE month = ?', [$month])->fetchAll() as $r) {
    $rows[(int)$r['user_id']] = $r;
}

/** One person's salary for the month: saved figures, else their defaults with this month's sales. */
function salary_row(array $u, ?array $saved, float $sales): array
{
    $paid = $saved && $saved['status'] === 'paid';
    $r = [
        'base_salary' => (float)($saved['base_salary'] ?? $u['base_salary'] ?? 0),
        'incentive_pct' => (float)($saved['incentive_pct'] ?? $u['incentive_pct'] ?? 0),
        'allowance' => (float)($saved['allowance'] ?? 0), 'deduction' => (float)($saved['deduction'] ?? 0), 'advance' => (float)($saved['advance'] ?? 0),
        'status' => $saved['status'] ?? 'draft', 'paid_on' => $saved['paid_on'] ?? null, 'note' => $saved['note'] ?? '',
        'sales' => $paid ? (float)$saved['sales'] : $sales,
    ];
    $r['incentive'] = $paid ? (float)$saved['incentive'] : round($r['sales'] * $r['incentive_pct'] / 100, 2);
    $r['net'] = round($r['base_salary'] + $r['incentive'] + $r['allowance'] - $r['deduction'] - $r['advance'], 2);
    return $r;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $saved = 0;
    foreach ((array)($_POST['s'] ?? []) as $uid => $in) {
        $uid = (int)$uid;
        if (!is_array($in) || !q('SELECT id FROM users WHERE id = ?', [$uid])->fetch()) {
            continue;
        }
        $old = $rows[$uid] ?? null;
        $wasPaid = $old && $old['status'] === 'paid';
        $paidNow = !empty($in['paid']);
        $base = $num($in['base_salary'] ?? 0);
        $pct = min(100, $num($in['incentive_pct'] ?? 0));
        // Sales & incentive stay as they were on a month already paid; otherwise use this month's sales.
        $sal = $wasPaid && $paidNow ? (float)$old['sales'] : $sales;
        $inc = $wasPaid && $paidNow ? (float)$old['incentive'] : round($sal * $pct / 100, 2);
        $allow = $num($in['allowance'] ?? 0);
        $ded = $num($in['deduction'] ?? 0);
        $adv = $num($in['advance'] ?? 0);
        $net = round($base + $inc + $allow - $ded - $adv, 2);
        $paidOn = $paidNow ? (preg_match('/^\d{4}-\d{2}-\d{2}$/', $in['paid_on'] ?? '') ? $in['paid_on'] : today()) : null;
        if (!$old && !$base && !$pct && !$allow && !$ded && !$adv && !$paidNow) {
            continue; // nothing for this person
        }
        q('INSERT INTO salaries (user_id, month, base_salary, sales, incentive_pct, incentive, allowance, deduction, advance, net, status, paid_on, note, updated_by, updated_at)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE base_salary = VALUES(base_salary), sales = VALUES(sales), incentive_pct = VALUES(incentive_pct), incentive = VALUES(incentive),
             allowance = VALUES(allowance), deduction = VALUES(deduction), advance = VALUES(advance), net = VALUES(net), status = VALUES(status),
             paid_on = VALUES(paid_on), note = VALUES(note), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)',
            [$uid, $month, $base, $sal, $pct, $inc, $allow, $ded, $adv, $net, $paidNow ? 'paid' : 'draft', $paidOn,
                mb_substr(trim((string)($in['note'] ?? '')), 0, 250), current_user()['id'], now()]);
        // Base salary & incentive % are remembered for the next months.
        q('INSERT INTO staff_pay (user_id, base_salary, incentive_pct, updated_at) VALUES (?, ?, ?, ?)
           ON DUPLICATE KEY UPDATE base_salary = VALUES(base_salary), incentive_pct = VALUES(incentive_pct), updated_at = VALUES(updated_at)', [$uid, $base, $pct, now()]);
        $saved++;
    }
    flash($saved ? "Saved salaries for $saved staff." : 'Nothing to save.');
    redirect('salary.php?m=' . $month);
}

// ---------------------------------------------------------------- salary slip
if (!empty($_GET['slip'])) {
    $u = null;
    foreach ($staff as $x) {
        if ((int)$x['id'] === (int)$_GET['slip']) {
            $u = $x;
        }
    }
    if (!$u) {
        exit('Not found.');
    }
    $r = salary_row($u, $rows[(int)$u['id']] ?? null, $sales);
    $co = setting('inv_seller_name', setting('company_name', 'Looma Apparels'));
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Salary slip <?= h($u['name']) ?> <?= h($month) ?></title>
    <style>body{font-family:Arial,sans-serif;max-width:170mm;margin:20px auto;color:#111;font-size:11pt}h1{margin:0;font-size:16pt}table{width:100%;border-collapse:collapse;margin-top:14px}td{padding:6px 8px;border-bottom:1px solid #ddd}.r{text-align:right}.t td{border-top:2px solid #111;font-weight:700;font-size:13pt}.muted{color:#666;font-size:9pt}button{padding:8px 14px}@media print{button{display:none}}</style></head><body>
    <button onclick="window.print()">🖨 Print</button>
    <h1><?= h($co) ?></h1><div class="muted"><?= h(setting('inv_seller_address', '')) ?></div>
    <h2>Salary slip · <?= h(date('F Y', strtotime($from))) ?></h2>
    <p><b><?= h($u['name'] ?: $u['username']) ?></b><br><?= $r['status'] === 'paid' ? 'Paid on ' . h(date('d-m-Y', strtotime((string)$r['paid_on']))) : 'Not paid yet' ?></p>
    <table>
      <tr><td>Basic salary</td><td class="r"><?= number_format($r['base_salary'], 2) ?></td></tr>
      <tr><td>Incentive (<?= qty_fmt($r['incentive_pct']) ?>% of sales <?= number_format($r['sales'], 2) ?>)</td><td class="r"><?= number_format($r['incentive'], 2) ?></td></tr>
      <?php if ($r['allowance']): ?><tr><td>Allowance / bonus</td><td class="r"><?= number_format($r['allowance'], 2) ?></td></tr><?php endif; ?>
      <?php if ($r['deduction']): ?><tr><td>Deductions</td><td class="r">−<?= number_format($r['deduction'], 2) ?></td></tr><?php endif; ?>
      <?php if ($r['advance']): ?><tr><td>Advance taken</td><td class="r">−<?= number_format($r['advance'], 2) ?></td></tr><?php endif; ?>
      <tr class="t"><td>Net pay ₹</td><td class="r"><?= number_format($r['net'], 2) ?></td></tr>
    </table>
    <p class="muted">Sales = tax invoices of <?= h(date('F Y', strtotime($from))) ?>, before GST and shipping.<?= $r['note'] !== '' ? ' Note: ' . h($r['note']) : '' ?></p>
    <p style="margin-top:50px;display:flex;justify-content:space-between"><span>Employee signature</span><span>For <?= h($co) ?></span></p>
    </body></html><?php
    exit;
}

$pageTitle = 'Salary & incentive';
$active = 'salary';
require __DIR__ . '/inc/header.php';
$prev = date('Y-m', strtotime($from . ' -1 month'));
$next = date('Y-m', strtotime($from . ' +1 month'));
$total = 0.0;
?>
<div class="page-head">
  <div><h1>Salary &amp; incentive</h1><p class="muted small">Incentive = this month’s sales × each person’s incentive %. Sales come from tax invoices, before GST and shipping.</p></div>
</div>
<nav class="month-nav">
  <a class="btn small" href="?m=<?= h($prev) ?>">←</a><b><?= h(date('F Y', strtotime($from))) ?></b><a class="btn small" href="?m=<?= h($next) ?>">→</a>
  <?php if ($month !== date('Y-m')): ?><a class="btn small ghost" href="?m=<?= h(date('Y-m')) ?>">This month</a><?php endif; ?>
</nav>
<div class="stats">
  <a class="stat" href="bills.php?m=<?= h($month) ?>"><span class="stat-label">Sales this month</span><span class="stat-num" id="monthSales" data-sales="<?= h((string)$sales) ?>"><?= h(money($sales, 0)) ?></span><span class="stat-sub">excluding GST &amp; shipping · from bills</span></a>
  <div class="stat"><span class="stat-label">Total salaries</span><span class="stat-num" id="salTotal">–</span><span class="stat-sub">net pay for the month</span></div>
</div>

<form method="post" id="salForm" style="margin-top:14px">
  <?= csrf_field() ?>
  <div class="sal-cards">
  <?php foreach ($staff as $u): if (!$u['active'] && empty($rows[(int)$u['id']])) continue; $r = salary_row($u, $rows[(int)$u['id']] ?? null, $sales); $k = 's[' . (int)$u['id'] . ']'; $paid = $r['status'] === 'paid'; ?>
    <section class="panel sal-card <?= $paid ? 'is-paid' : '' ?>" data-sal data-sales="<?= h((string)$r['sales']) ?>" data-frozen="<?= $paid ? '1' : '' ?>" data-frozen-inc="<?= h((string)$r['incentive']) ?>">
      <div class="item-head"><h2><?= h($u['name'] ?: $u['username']) ?> <small class="muted"><?= $u['role'] === 'admin' ? 'Admin' : 'Staff' ?></small></h2>
        <a class="btn small ghost" href="?m=<?= h($month) ?>&slip=<?= (int)$u['id'] ?>" target="_blank">🖨 Slip</a></div>
      <div class="grid">
        <label class="field"><span class="lbl">Basic salary ₹</span><input type="number" step="any" min="0" name="<?= $k ?>[base_salary]" value="<?= h((string)$r['base_salary']) ?>" data-k="base"></label>
        <label class="field"><span class="lbl">Incentive % of sales</span><input type="number" step="any" min="0" max="100" name="<?= $k ?>[incentive_pct]" value="<?= h((string)$r['incentive_pct']) ?>" data-k="pct" <?= $paid ? 'readonly' : '' ?>></label>
        <div class="field"><span class="lbl">Incentive ₹</span><div class="val strong" data-out="inc"></div><small class="hint">on sales <?= h(money($r['sales'], 0)) ?><?= $paid && abs($r['sales'] - $sales) > 0.5 ? ' (as paid)' : '' ?></small></div>
        <label class="field"><span class="lbl">Allowance / bonus ₹</span><input type="number" step="any" min="0" name="<?= $k ?>[allowance]" value="<?= h((string)$r['allowance']) ?>" data-k="allow"></label>
        <label class="field"><span class="lbl">Deductions ₹</span><input type="number" step="any" min="0" name="<?= $k ?>[deduction]" value="<?= h((string)$r['deduction']) ?>" data-k="ded"></label>
        <label class="field"><span class="lbl">Advance taken ₹</span><input type="number" step="any" min="0" name="<?= $k ?>[advance]" value="<?= h((string)$r['advance']) ?>" data-k="adv"></label>
        <div class="field"><span class="lbl">Net pay</span><div class="val strong net" data-out="net"></div></div>
        <label class="field"><span class="lbl">Note</span><input name="<?= $k ?>[note]" value="<?= h($r['note']) ?>"></label>
        <label class="field check"><input type="checkbox" name="<?= $k ?>[paid]" value="1" <?= $paid ? 'checked' : '' ?>> Paid on <input type="date" name="<?= $k ?>[paid_on]" value="<?= h((string)($r['paid_on'] ?: today())) ?>" class="w-date"></label>
      </div>
    </section>
  <?php endforeach; ?>
  </div>
  <div class="sticky-actions"><span class="total-pcs" id="salBar"></span><button class="btn primary">💾 Save salaries</button></div>
</form>
<script>
(function () {
  var n = function (v) { v = parseFloat(v); return isFinite(v) ? v : 0; };
  var rs = function (v) { return '₹' + v.toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); };
  function calc() {
    var total = 0;
    document.querySelectorAll('[data-sal]').forEach(function (c) {
      var g = function (k) { return n(c.querySelector('[data-k="' + k + '"]').value); };
      var inc = c.dataset.frozen ? n(c.dataset.frozenInc) : Math.round(n(c.dataset.sales) * g('pct')) / 100;
      var net = g('base') + inc + g('allow') - g('ded') - g('adv');
      c.querySelector('[data-out="inc"]').textContent = rs(inc);
      c.querySelector('[data-out="net"]').textContent = rs(net);
      total += net;
    });
    document.getElementById('salTotal').textContent = rs(total);
    document.getElementById('salBar').textContent = 'Total ' + rs(total);
  }
  document.getElementById('salForm').addEventListener('input', calc);
  calc();
})();
</script>
<?php require __DIR__ . '/inc/footer.php'; ?>
