<?php
// Free up hosting space: delete mock-up images of shipped or deleted orders, and leftover image files.
// Order details are never touched, and images of orders that are not shipped yet are always kept.
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
              WHERE o.deleted_at IS NULL AND o.shipped = 1 AND o.shipped_at <= ? AND ' . NOT_DESIGN_IMAGE . ' GROUP BY o.id', [$cutoff])->fetchAll();
}

/** Bytes used by one stored image (full size + thumbnail). */
function image_bytes(string $filename): int
{
    $n = 0;
    foreach (array_unique([$filename, thumb_path($filename)]) as $f) {
        $p = upload_dir() . '/' . $f;
        $n += is_file($p) ? (int)filesize($p) : 0;
    }
    return $n;
}

/** Image files on disk that no order or saved design uses (relative paths, main files and thumbnails). */
function unused_files(): array
{
    $used = [];
    foreach (q('SELECT filename FROM order_images UNION SELECT filename FROM design_images')->fetchAll() as $r) {
        $used[$r['filename']] = true;
        $used[thumb_path($r['filename'])] = true;
        $used[preg_replace('/(\.\w+)$/', '_t$1', $r['filename'])] = true;
    }
    $out = [];
    $dir = upload_dir();
    if (!is_dir($dir)) {
        return [];
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        $rel = ltrim(str_replace('\\', '/', substr($f->getPathname(), strlen($dir))), '/');
        // Only our image files; leave anything uploaded in the last hour alone (could be mid-save).
        if (!preg_match('~^\d{4}/\d{2}/[a-f0-9]{24}(_t)?\.(jpg|png|webp|gif)$~', $rel) || isset($used[$rel]) || $f->getMTime() > time() - 3600) {
            continue;
        }
        $out[$rel] = $f->getSize();
    }
    return $out;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $before = dir_size(upload_dir());
    $do = $_POST['do'] ?? 'shipped';
    if ($do === 'deleted') {
        // Orders that were deleted: their images are no longer needed.
        $n = 0;
        foreach (q('SELECT i.id, i.order_id FROM order_images i JOIN orders o ON o.id = i.order_id WHERE o.deleted_at IS NOT NULL AND ' . NOT_DESIGN_IMAGE)->fetchAll() as $img) {
            delete_image((int)$img['id'], (int)$img['order_id'], false);
            $n++;
        }
        $msg = "Removed $n image(s) of deleted orders";
    } elseif ($do === 'unused') {
        $n = 0;
        foreach (unused_files() as $rel => $size) {
            if (@unlink(upload_dir() . '/' . $rel)) {
                $n++;
            }
        }
        $msg = "Removed $n leftover file(s)";
    } else {
        $orders = 0;
        $n = 0;
        foreach (candidates($cutoff) as $c) {
            $k = clear_order_images((int)$c['id']);
            $n += $k;
            $orders += $k ? 1 : 0;
        }
        $msg = "Removed $n mock-up image(s) from $orders shipped order(s)";
    }
    $freed = max(0, $before - dir_size(upload_dir()));
    flash($msg . ', freed ' . human_size($freed) . '. All order details are kept.');
    redirect('admin/storage.php?days=' . $days);
}

// ---- Where the space goes. A file shared by an order and a saved design is counted once, under the first group it falls in.
$groups = [
    'open' => ['On orders not shipped yet', 'Kept — the printer still needs them', 0, 0],
    'design' => ['Saved designs', 'Kept — reused in new orders (delete a design in Designs to free it)', 0, 0],
    'shipped' => ['On shipped orders', 'Can be removed below', 0, 0],
    'deleted' => ['On deleted orders', 'Can be removed below', 0, 0],
];
$seen = [];
$rows = q("SELECT i.filename, CASE WHEN o.deleted_at IS NOT NULL THEN 'deleted' WHEN o.shipped = 1 THEN 'shipped' ELSE 'open' END AS grp
           FROM order_images i JOIN orders o ON o.id = i.order_id")->fetchAll();
$designFiles = array_column(q('SELECT filename FROM design_images')->fetchAll(), 'filename');
$order = ['open' => 0, 'design' => 1, 'shipped' => 2, 'deleted' => 3];
$best = [];
foreach ($rows as $r) {
    if (!isset($best[$r['filename']]) || $order[$r['grp']] < $order[$best[$r['filename']]]) {
        $best[$r['filename']] = $r['grp'];
    }
}
foreach ($designFiles as $f) {
    if (!isset($best[$f]) || $order['design'] < $order[$best[$f]]) {
        $best[$f] = 'design';
    }
}
foreach ($best as $file => $g) {
    $groups[$g][2]++;
    $groups[$g][3] += image_bytes($file);
}
$unused = unused_files();
$used = dir_size(upload_dir());
$cands = candidates($cutoff);
$candImgs = array_sum(array_column($cands, 'n'));
$deletedImgs = (int)q('SELECT COUNT(*) FROM order_images i JOIN orders o ON o.id = i.order_id WHERE o.deleted_at IS NOT NULL AND ' . NOT_DESIGN_IMAGE)->fetchColumn();
$fileCount = count($best);
$avg = $fileCount ? intdiv(array_sum(array_column($groups, 3)), $fileCount) : 0;

$pageTitle = 'Storage';
$active = 'storage';
require __DIR__ . '/../inc/header.php';
?>
<div class="page-head"><h1>Storage</h1></div>

<div class="stats">
  <div class="stat"><span class="stat-label">Mock-up images use</span><span class="stat-num"><?= h(human_size($used)) ?></span><span class="stat-sub"><?= plural($fileCount, 'image') ?><?= $avg ? ' · about ' . h(human_size($avg)) . ' each' : '' ?></span></div>
  <div class="stat"><span class="stat-label">Kept (still needed)</span><span class="stat-num"><?= $groups['open'][2] + $groups['design'][2] ?></span><span class="stat-sub">open orders &amp; saved designs</span></div>
  <div class="stat"><span class="stat-label">Can be removed now</span><span class="stat-num"><?= $candImgs + $deletedImgs + count($unused) ?></span><span class="stat-sub"><?= h(human_size($groups['shipped'][3] + $groups['deleted'][3] + array_sum($unused))) ?></span></div>
</div>

<section class="panel" style="margin-top:14px">
  <h2>Where the space goes</h2>
  <div class="table-wrap"><table class="table compact">
    <thead><tr><th>Images</th><th class="num">Count</th><th class="num">Size</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($groups as [$label, $note, $n, $bytes]): ?>
      <tr><td><b><?= h($label) ?></b></td><td class="num"><?= $n ?></td><td class="num"><?= h(human_size($bytes)) ?></td><td class="wrap-cell muted small"><?= h($note) ?></td></tr>
    <?php endforeach; ?>
    <tr><td><b>Leftover files</b></td><td class="num"><?= count($unused) ?></td><td class="num"><?= h(human_size(array_sum($unused))) ?></td><td class="wrap-cell muted small">Not used by any order or design · can be removed below</td></tr>
    </tbody>
  </table></div>
  <p class="hint">Sizes include the small preview copy of each image. Phone photos are shrunk when uploaded; PNG mock-ups with transparency stay larger.</p>
</section>

<section class="panel">
  <h2>1 · Images of shipped orders</h2>
  <p class="muted">Only the <b>image files</b> are deleted. Customer ID, address, items, print details, ticks, courier and history all stay.
    Orders that are not shipped yet are never touched, and <b>saved design images are always kept</b>.</p>
  <form method="get" class="inline-add">
    <label class="check">Shipped more than <input type="number" name="days" min="0" value="<?= $days ?>" class="w-xs"> days ago</label>
    <button class="btn">Check</button>
  </form>
  <form method="post" onsubmit="return confirm('Delete <?= $candImgs ?> mock-up image(s) from <?= count($cands) ?> shipped order(s)? This cannot be undone. Order details are kept.');">
    <?= csrf_field() ?><input type="hidden" name="days" value="<?= $days ?>"><input type="hidden" name="do" value="shipped">
    <button class="btn primary" <?= $candImgs ? '' : 'disabled' ?>>🗑 Remove <?= plural($candImgs, 'image') ?> from <?= plural(count($cands), 'shipped order') ?></button>
  </form>
  <?php if (!$candImgs): ?><p class="hint">Nothing yet — images become removable once their order is marked <b>Shipped</b><?= $days ? ' and is older than ' . plural($days, 'day') : '' ?>.</p><?php endif; ?>
</section>

<section class="panel">
  <h2>2 · Images of deleted orders</h2>
  <form method="post" onsubmit="return confirm('Delete the images of all deleted orders?');">
    <?= csrf_field() ?><input type="hidden" name="do" value="deleted">
    <button class="btn" <?= $deletedImgs ? '' : 'disabled' ?>>🗑 Remove <?= plural($deletedImgs, 'image') ?> of deleted orders</button>
  </form>
</section>

<section class="panel">
  <h2>3 · Leftover files</h2>
  <p class="muted small">Image files that no order or saved design uses any more (for example from a cancelled upload).</p>
  <form method="post" onsubmit="return confirm('Delete <?= count($unused) ?> leftover file(s)?');">
    <?= csrf_field() ?><input type="hidden" name="do" value="unused">
    <button class="btn" <?= $unused ? '' : 'disabled' ?>>🗑 Remove <?= plural(count($unused), 'leftover file') ?> (<?= h(human_size(array_sum($unused))) ?>)</button>
  </form>
</section>
<p class="hint">You can also remove the images of a single shipped order from that order’s page.</p>
<?php require __DIR__ . '/../inc/footer.php'; ?>
