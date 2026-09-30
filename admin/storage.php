<?php
// Free up hosting space by deleting mock-up images of shipped orders. Order details are never touched.
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/orders.php';

require_login();
if (!cap('cleanup')) {
    http_response_code(403);
    exit('You are not allowed to do this.');
}

$days = max(0, (int)($_POST['days'] ?? $_GET['days'] ?? 7));
$cutoff = date('Y-m-d H:i:s', strtotime("-$days days"));

function candidates(string $cutoff): array
{
    return q('SELECT o.id, COUNT(i.id) n FROM orders o JOIN order_images i ON i.order_id = o.id
              WHERE o.shipped = 1 AND o.shipped_at <= ? GROUP BY o.id', [$cutoff])->fetchAll();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $before = dir_size(upload_dir());
    $orders = 0;
    $imgs = 0;
    foreach (candidates($cutoff) as $c) {
        $n = clear_order_images((int)$c['id']);
        $imgs += $n;
        $orders += $n ? 1 : 0;
    }
    $freed = max(0, $before - dir_size(upload_dir()));
    flash("Removed $imgs mock-up image(s) from $orders shipped order(s), freed " . human_size($freed) . '. All order details are kept.');
    redirect('admin/storage.php?days=' . $days);
}

$used = dir_size(upload_dir());
$totalImgs = (int)q('SELECT COUNT(*) FROM order_images')->fetchColumn();
$cands = candidates($cutoff);
$candImgs = array_sum(array_column($cands, 'n'));
$openImgs = (int)q('SELECT COUNT(*) FROM order_images i JOIN orders o ON o.id = i.order_id WHERE o.shipped = 0')->fetchColumn();

$pageTitle = 'Storage';
$active = 'storage';
require __DIR__ . '/../inc/header.php';
?>
<div class="page-head"><h1>Storage</h1></div>

<div class="stats">
  <div class="stat"><span class="stat-label">Mock-up images use</span><span class="stat-num"><?= h(human_size($used)) ?></span><span class="stat-sub"><?= $totalImgs ?> images</span></div>
  <div class="stat"><span class="stat-label">On open orders (kept)</span><span class="stat-num"><?= $openImgs ?></span><span class="stat-sub">not shipped yet</span></div>
  <div class="stat"><span class="stat-label">Can be removed</span><span class="stat-num"><?= $candImgs ?></span><span class="stat-sub"><?= count($cands) ?> shipped order<?= count($cands) === 1 ? '' : 's' ?></span></div>
</div>

<section class="panel" style="margin-top:14px">
  <h2>Remove mock-up images of shipped orders</h2>
  <p class="muted">Only the <b>image files</b> are deleted. Customer ID, address, items, print details, ticks, courier and history all stay.
    Orders that are not shipped are never touched.</p>
  <form method="get" class="inline-add">
    <label class="check">Shipped more than <input type="number" name="days" min="0" value="<?= $days ?>" class="w-xs"> days ago</label>
    <button class="btn">Check</button>
  </form>
  <form method="post" onsubmit="return confirm('Delete <?= $candImgs ?> mock-up image(s) from <?= count($cands) ?> shipped order(s)? This cannot be undone. Order details are kept.');">
    <?= csrf_field() ?><input type="hidden" name="days" value="<?= $days ?>">
    <button class="btn primary" <?= $candImgs ? '' : 'disabled' ?>>🗑 Remove <?= $candImgs ?> image<?= $candImgs === 1 ? '' : 's' ?> now</button>
  </form>
  <p class="hint">You can also remove images of a single shipped order from its page.</p>
</section>
<?php require __DIR__ . '/../inc/footer.php'; ?>
