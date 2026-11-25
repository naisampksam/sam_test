<?php
// Partner portal home: log in, then the partner's orders with where each one is (and courier + tracking once shipped).
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/partner_money.php';
require __DIR__ . '/../inc/partner_ui.php';

start_session();
$pu = current_partner_user();
if (!$pu) {
    $error = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        csrf_check();
        ensure_schema();
        $error = partner_login((string)($_POST['username'] ?? ''), (string)($_POST['password'] ?? '')) ?? '';
        if ($error === '') {
            redirect('partner/index.php');
        }
    }
    partner_head('Log in');
    ?>
    <div class="pt-login">
      <img class="pt-login-logo" src="<?= h(asset('assets/icons/icon-192.png')) ?>" alt="" width="72" height="72">
      <h1><?= h(setting('company_name', 'Looma Apparels')) ?></h1>
      <p>Partner login</p>
      <?php if ($error): ?><p class="pt-note err" role="alert"><?= h($error) ?></p><?php endif; ?>
      <form method="post" class="pt-login-form">
        <?= csrf_field() ?>
        <div class="pt-card pt-login-fields">
          <label><span class="pt-vh">Username</span><input name="username" required autocapitalize="none" autocorrect="off" autocomplete="username" placeholder="Username" value="<?= h($_POST['username'] ?? '') ?>"></label>
          <label><span class="pt-vh">Password</span><input name="password" type="password" required autocomplete="current-password" placeholder="Password"></label>
        </div>
        <button class="pt-btn filled wide">Log in</button>
      </form>
      <div class="pt-login-or"><span>New to Looma?</span></div>
      <a class="pt-btn tinted wide" href="<?= h(base_url('partner/register.php')) ?>">Create a partner account</a>
    </div>
    <?php
    partner_foot();
    exit;
}
ensure_schema();
$p = $pu['partner'];

$tabs = ['all' => 'All', 'new' => 'Waiting', 'progress' => 'In progress', 'shipped' => 'Shipped', 'closed' => 'Not accepted'];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'all';
$qtext = trim((string)($_GET['q'] ?? ''));
$where = ['po.partner_id = ?'];
$args = [$p['id']];
$tabSql = [
    'new' => "po.status = 'new'",
    'progress' => "po.status = 'accepted' AND IFNULL(o.shipped, 0) = 0",
    'shipped' => "po.status = 'accepted' AND o.shipped = 1",
    'closed' => "po.status IN ('rejected', 'cancelled')",
];
$counts = [];
foreach ($tabs as $k => $l) {
    $counts[$k] = (int)q('SELECT COUNT(*) FROM partner_orders po LEFT JOIN orders o ON o.id = po.order_id WHERE po.partner_id = ?' . (isset($tabSql[$k]) ? ' AND ' . $tabSql[$k] : ''), [$p['id']])->fetchColumn();
}
if (isset($tabSql[$tab])) {
    $where[] = $tabSql[$tab];
}
if ($qtext !== '') {
    $where[] = '(po.ref LIKE ? OR po.ship_name LIKE ? OR po.ship_phone LIKE ? OR po.ship_pincode = ? OR o.tracking_no = ?)';
    array_push($args, "%$qtext%", "%$qtext%", "%$qtext%", $qtext, $qtext);
}
$page = max(1, (int)($_GET['page'] ?? 1));
$per = 50;
$total = (int)q('SELECT COUNT(*) FROM partner_orders po LEFT JOIN orders o ON o.id = po.order_id WHERE ' . implode(' AND ', $where), $args)->fetchColumn();
$rows = q('SELECT po.*, o.shipped, o.packed, o.printed, o.print_processed, o.courier AS o_courier, o.tracking_no, o.due_date, o.deleted_at
           FROM partner_orders po LEFT JOIN orders o ON o.id = po.order_id WHERE ' . implode(' AND ', $where) . '
           ORDER BY po.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $args)->fetchAll();

// first photo of each design on the page
$thumbs = [];
foreach (partner_designs($p) as $d) {
    if ($d['images']) {
        $thumbs[(int)$d['id']] = partner_img($d['images'][0]);
    }
}
partner_head('Orders', 'orders', $pu);
?>
<div class="pt-head">
  <h1 class="pt-title">Orders</h1>
  <a class="pt-btn filled pt-only-wide" href="<?= h(base_url('partner/order.php?new=1')) ?>">＋ New order</a>
</div>
<details class="pt-more pt-export"><summary>⬇ Download orders (Excel)</summary>
  <form method="get" action="<?= h(base_url('partner/export.php')) ?>" class="pt-card pt-fields">
    <label><span>From</span><input type="date" name="from" value="<?= h(date('Y-m-d', strtotime('-30 days'))) ?>"></label>
    <label><span>To</span><input type="date" name="to" value="<?= h(today()) ?>"></label>
    <div style="padding:8px 16px 14px"><button class="pt-btn tinted wide">Download</button></div></form></details>
<?php if ($counts['all']):
    $acct = partner_account($p);
    $shippedMonth = (int)q("SELECT COUNT(*) FROM partner_orders po JOIN orders o ON o.id = po.order_id WHERE po.partner_id = ? AND o.shipped = 1 AND o.shipped_at >= ?", [$p['id'], date('Y-m-01')])->fetchColumn(); ?>
<div class="pt-stats">
  <a class="pt-stat" href="?tab=new"><small>Waiting for Looma</small><b><?= $counts['new'] ?></b></a>
  <a class="pt-stat" href="?tab=progress"><small>In progress</small><b><?= $counts['progress'] ?></b></a>
  <a class="pt-stat" href="?tab=shipped"><small>Shipped this month</small><b><?= $shippedMonth ?></b></a>
  <a class="pt-stat <?= $acct['balance'] < 0 ? 'neg' : '' ?>" href="<?= h(base_url('partner/money.php')) ?>"><small><?= $acct['balance'] < 0 ? 'You owe' : 'Balance' ?></small><b>₹<?= h(inr_number(abs($acct['balance']))) ?></b></a>
</div>
<?php endif; ?>
<form class="pt-search" method="get" role="search">
  <input type="hidden" name="tab" value="<?= h($tab) ?>">
  <input type="search" name="q" value="<?= h($qtext) ?>" placeholder="Search name, mobile, order no., tracking" aria-label="Search orders">
</form>
<nav class="pt-chips" aria-label="Show">
  <?php foreach ($tabs as $k => $l): if ($k === 'closed' && !$counts[$k]) continue; ?>
    <a href="?tab=<?= $k ?><?= $qtext !== '' ? '&q=' . urlencode($qtext) : '' ?>" class="<?= $tab === $k ? 'on' : '' ?>"><?= h($l) ?><?= $counts[$k] ? ' <b>' . $counts[$k] . '</b>' : '' ?></a>
  <?php endforeach; ?>
</nav>
<?php if (!$rows): ?>
  <div class="pt-empty"><div class="pt-empty-ico"><?= $counts['all'] ? '🔍' : '📦' ?></div>
    <?php if ($counts['all']): ?><b>Nothing here</b><p>No orders match.</p>
    <?php else: ?><b>Your first order</b><p>Save your design once, then send orders in a few taps. You'll see each order get accepted, printed and shipped here.</p>
      <a class="pt-btn filled" href="<?= h(base_url(partner_designs($p) ? 'partner/order.php?new=1' : 'partner/designs.php?new=1')) ?>"><?= partner_designs($p) ? '＋ New order' : '＋ Save your first design' ?></a><?php endif; ?></div>
<?php else: ?>
<section class="pt-card pt-list pt-orders">
<?php foreach ($rows as $r):
    $o = $r['order_id'] && !$r['deleted_at'] ? ['shipped' => $r['shipped'], 'packed' => $r['packed'], 'printed' => $r['printed'], 'print_processed' => $r['print_processed'],
        'courier' => $r['o_courier'], 'tracking_no' => $r['tracking_no'], 'due_date' => $r['due_date']] : null;
    [$label, $cls, $detail] = partner_order_stage($r, $o);
    $items = json_decode($r['items'] ?: '[]', true) ?: [];
    $pcs = array_sum(array_column($items, 'qty'));
    $th = $thumbs[(int)($items[0]['design_id'] ?? 0)] ?? ''; ?>
  <a class="pt-row pt-order" data-po="<?= (int)$r['id'] ?>" href="<?= h(base_url('partner/order.php?id=' . (int)$r['id'])) ?>">
    <?php if ($th): ?><img class="pt-thumb" src="<?= h($th) ?>" alt="" loading="lazy"><?php else: ?><span class="pt-thumb">👕</span><?php endif; ?>
    <span class="pt-row-main"><b><?= !empty($r['bulk']) ? '📦 ' : '' ?><?= h($r['ship_name']) ?><?= $r['urgent'] ? ' <span class="pt-urg">⚡</span>' : '' ?></b>
      <small><?= h($r['ref']) ?> · <?= plural((int)$pcs, 'T-shirt') ?> · <?= h(implode(', ', array_unique(array_column($items, 'design')))) ?></small>
      <?php if ($detail !== ''): ?><small class="pt-detail <?= $cls === 'rejected' ? 'err' : '' ?>"><?= h($detail) ?></small><?php endif; ?></span>
    <span class="pt-row-end"><span class="pt-pill s-<?= h($cls) ?>"><?= h($label) ?></span><small><?= h(fmt_date($r['created_at'])) ?></small></span>
  </a>
<?php endforeach; ?>
</section>
<?php endif; ?>
<?php if ($total > $per): $pages = (int)ceil($total / $per); ?>
<nav class="pt-pager">
  <?php if ($page > 1): ?><a class="pt-btn tinted" href="?<?= h(http_build_query(['tab' => $tab, 'q' => $qtext, 'page' => $page - 1])) ?>">‹ Newer</a><?php endif; ?>
  <span><?= $page ?> / <?= $pages ?></span>
  <?php if ($page < $pages): ?><a class="pt-btn tinted" href="?<?= h(http_build_query(['tab' => $tab, 'q' => $qtext, 'page' => $page + 1])) ?>">Older ›</a><?php endif; ?>
</nav>
<?php endif; ?>
<?php partner_foot(); ?>
