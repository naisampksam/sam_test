<?php
// Saved quotations: reopen, send again on WhatsApp, print or delete. A quotation never becomes an order or a bill.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/orders.php';
require __DIR__ . '/inc/billing.php';

require_login();
if (!cap('billing') && !can_view('bill')) {
    http_response_code(403);
    exit('You are not allowed to see prices.');
}
$s = trim((string)($_GET['q'] ?? ''));
$where = '1=1';
$args = [];
if ($s !== '') {
    $digits = preg_replace('/\D/', '', $s);
    $where = '(number LIKE ? OR customer_name LIKE ?' . (strlen($digits) >= 3 ? ' OR REPLACE(customer_phone, " ", "") LIKE ?' : '') . ')';
    $args = ["%$s%", "%$s%"];
    if (strlen($digits) >= 3) {
        $args[] = "%$digits%";
    }
}
$rows = q("SELECT * FROM quotations WHERE $where ORDER BY id DESC LIMIT 200", $args)->fetchAll();

$pageTitle = 'Quotations';
$active = 'quotes';
require __DIR__ . '/inc/header.php';
?>
<div class="page-head">
  <div><h1>Quotations</h1><p class="muted small">Saved quotations — open one to send it again or print it. They stay quotations: no order, bill or stock is made.</p></div>
  <div class="actions"><a class="btn primary" href="bill.php?new=1">📝 New quotation</a></div>
</div>
<form class="filters" method="get">
  <input type="search" name="q" value="<?= h($s) ?>" placeholder="Search number, customer, phone">
  <button class="btn">Search</button>
</form>
<?php if (!$rows): ?>
  <p class="empty"><?= $s !== '' ? 'No quotations match.' : 'No saved quotations yet. Make one from New order or New quotation and tap “Save quotation”.' ?></p>
<?php else: ?>
<section class="panel"><div class="table-wrap">
  <table class="table">
    <thead><tr><th>Quotation</th><th>Customer</th><th class="num">Total</th><th>Made</th><th>Valid till</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): $d = json_decode((string)$r['data'], true) ?: []; $till = date('Y-m-d', strtotime(substr($r['created_at'], 0, 10) . ' +' . (int)($d['valid_days'] ?? 7) . ' days')); ?>
      <tr onclick="window.open('quote.php?id=<?= (int)$r['id'] ?>')">
        <td><a href="quote.php?id=<?= (int)$r['id'] ?>" target="_blank"><b><?= h($r['number']) ?></b></a><br><small class="muted"><?= count($d['lines'] ?? []) ?> line<?= count($d['lines'] ?? []) === 1 ? '' : 's' ?></small></td>
        <td><?= h($r['customer_name'] ?: '—') ?><?= $r['customer_phone'] !== '' ? '<br><small class="muted">' . h($r['customer_phone']) . '</small>' : '' ?></td>
        <td class="num"><b><?= h(money((float)$r['total'], 0)) ?></b></td>
        <td class="nowrap"><small><?= h(fmt_date($r['created_at'], true)) ?><br><span class="muted"><?= h(user_name($r['created_by'])) ?></span></small></td>
        <td class="nowrap"><?= $till < today() ? '<span class="badge delayed">Expired</span>' : h(fmt_date($till)) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div></section>
<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php'; ?>
