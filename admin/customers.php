<?php
// Admin only: customer book with each customer's order history.
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/orders.php';

require_admin();

$code = trim((string)($_GET['code'] ?? ''));
$pageTitle = 'Customers';
$active = 'customers';

if ($code !== '') {
    $c = q('SELECT * FROM customers WHERE code = ?', [$code])->fetch();
    $orders = q("SELECT o.*, " . ORDER_TOTALS_SQL . ",
                   (SELECT GROUP_CONCAT(CONCAT_WS(' ', it.gsm, it.product, it.color, it.size, CONCAT('×', it.quantity)) ORDER BY it.sort, it.id SEPARATOR ' | ')
                    FROM order_items it WHERE it.order_id = o.id) AS item_lines
                 FROM orders o WHERE o.deleted_at IS NULL AND o.customer_id = ? ORDER BY o.id DESC", [$code])->fetchAll();
    $totPcs = array_sum(array_column($orders, 'total_qty'));
    require __DIR__ . '/../inc/header.php';
    ?>
    <div class="page-head">
      <div><a class="back" href="<?= h(base_url('admin/customers.php')) ?>">← Customers</a><h1><?= h($code) ?></h1>
        <p class="muted small"><?= count($orders) ?> order<?= count($orders) === 1 ? '' : 's' ?> · <?= $totPcs ?> pcs</p></div>
      <div class="actions"><a class="btn primary" href="<?= h(base_url('order.php?new=1&customer=' . urlencode($code))) ?>">+ New order for this customer</a></div>
    </div>
    <?php if ($c): ?>
    <section class="panel address">
      <div class="item-head"><h2>Latest shipping address</h2><button type="button" class="btn small" data-copy-address>Copy</button></div>
      <div class="grid">
        <div class="field"><span class="lbl">Name</span><div class="val"><?= h($c['name']) ?></div></div>
        <div class="field"><span class="lbl">Phone</span><div class="val"><a href="tel:<?= h(preg_replace('/[^\d+]/', '', $c['phone'])) ?>"><?= h($c['phone']) ?></a></div></div>
        <div class="field full"><span class="lbl">Address</span><div class="val"><?= nl2br(h($c['address'])) ?></div></div>
        <div class="field"><span class="lbl">Pincode</span><div class="val"><?= h($c['pincode']) ?></div></div>
      </div>
    </section>
    <?php endif; ?>
    <section class="panel">
      <h2>Orders</h2>
      <?php if (!$orders): ?><p class="muted">No orders.</p><?php else: ?>
      <div class="table-wrap"><table class="table">
        <thead><tr><th>Order</th><th>Date</th><th>Items</th><th class="num">Pcs</th><th>Status</th><th>Courier</th></tr></thead>
        <tbody>
        <?php foreach ($orders as $o): $st = order_status($o); ?>
          <tr onclick="location='<?= h(base_url('order.php?id=' . (int)$o['id'])) ?>'">
            <td><a href="<?= h(base_url('order.php?id=' . (int)$o['id'])) ?>"><?= h(order_no($o['id'])) ?></a></td>
            <td><?= h(fmt_date($o['created_at'])) ?></td>
            <td class="wrap-cell"><?= h(mb_strimwidth((string)$o['item_lines'], 0, 90, '…')) ?></td>
            <td class="num"><?= (int)$o['total_qty'] ?></td>
            <td><span class="badge <?= h($st['key']) ?>"><?= h($st['label']) ?></span><?= $st['delayed'] ? ' <span class="badge delayed">Delayed</span>' : '' ?></td>
            <td><?= h(trim($o['courier'] . ' ' . $o['tracking_no'])) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php endif; ?>
    </section>
    <?php
    require __DIR__ . '/../inc/footer.php';
    exit;
}

$s = trim((string)($_GET['q'] ?? ''));
$params = [];
$where = '1';
if ($s !== '') {
    $where = '(c.code LIKE ? OR c.name LIKE ? OR c.phone LIKE ? OR c.pincode = ?)';
    $params = ["%$s%", "%$s%", "%$s%", $s];
}
$list = q("SELECT c.*,
             (SELECT COUNT(*) FROM orders o WHERE o.customer_id = c.code AND o.deleted_at IS NULL) AS n_orders,
             (SELECT IFNULL(SUM(it.quantity),0) FROM order_items it JOIN orders o ON o.id = it.order_id WHERE o.customer_id = c.code AND o.deleted_at IS NULL) AS pcs,
             (SELECT MAX(o.created_at) FROM orders o WHERE o.customer_id = c.code AND o.deleted_at IS NULL) AS last_order
           FROM customers c WHERE $where ORDER BY last_order DESC LIMIT 200", $params)->fetchAll();
require __DIR__ . '/../inc/header.php';
?>
<div class="page-head"><div><h1>Customers</h1><p class="muted small">Saved automatically from orders. Tap a customer to see all their orders.</p></div></div>
<form class="filters" method="get">
  <input type="search" name="q" value="<?= h($s) ?>" placeholder="Search customer ID, name, phone, pincode…">
  <button class="btn">Search</button>
</form>
<?php if (!$list): ?><p class="empty">No customers yet — they are added when orders are created.</p><?php else: ?>
<section class="panel">
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Customer ID</th><th>Name</th><th>Phone</th><th>Pincode</th><th class="num">Orders</th><th class="num">Pcs</th><th>Last order</th></tr></thead>
    <tbody>
    <?php foreach ($list as $c): $url = base_url('admin/customers.php?code=' . urlencode($c['code'])); ?>
      <tr onclick="location='<?= h($url) ?>'">
        <td><a href="<?= h($url) ?>"><b><?= h($c['code']) ?></b></a></td>
        <td><?= h($c['name']) ?></td>
        <td><?= h($c['phone']) ?></td>
        <td><?= h($c['pincode']) ?></td>
        <td class="num"><?= (int)$c['n_orders'] ?></td>
        <td class="num"><?= (int)$c['pcs'] ?></td>
        <td><?= h(fmt_date($c['last_order'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>
<?php require __DIR__ . '/../inc/footer.php'; ?>
