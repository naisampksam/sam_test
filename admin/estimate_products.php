<?php
// Estimate products (admin): making costs, margin and starting fabric for each product, and new products.
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/estimates.php';

require_admin();
$products = est_products();
$key = (string)($_GET['p'] ?? '');
$isNew = !empty($_GET['new']);

$money = ['c_cmt' => 'Cutting & stitching', 'c_acc' => 'Product accessories', 'c_print' => 'Printing (DTF / screen)', 'c_embroidery' => 'Embroidery',
    'c_labels' => 'Neck & size labels', 'c_trims' => 'Thread & trims', 'c_finishing' => 'Ironing & finishing', 'c_packing' => 'Poly bag & packing',
    'c_other' => 'Transport / other'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? 'save';
    if ($do === 'delete' && isset($products[$key])) {
        if (count($products) < 2) {
            flash('Keep at least one product.', 'err');
            redirect('admin/estimate_products.php?p=' . urlencode($key));
        }
        $name = $products[$key]['name'];
        unset($products[$key]);
        est_save_products($products);
        flash("Product “{$name}” deleted. Saved estimates that used it keep their numbers.");
        redirect('admin/estimate_products.php');
    }
    $p = $isNew ? ['key' => 'p' . base_convert((string)time(), 10, 36) . bin2hex(random_bytes(2)), 'defaults' => est_product_template()] : ($products[$key] ?? null);
    if (!$p) {
        redirect('admin/estimate_products.php');
    }
    $p['name'] = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 80);
    $p['base'] = isset(est_bases()[$_POST['base'] ?? '']) ? $_POST['base'] : 'regular';
    $p['active'] = !empty($_POST['active']) ? 1 : 0;
    $num = fn(string $k) => max(0, round((float)str_replace(',', '.', (string)($_POST[$k] ?? 0)), 2));
    foreach (array_merge(array_keys($money), ['fixed', 'rib_g', 'buffer', 'profit', 'gst', 'gsm', 'roll_width', 'fabric_price', 'rib_price', 'wastage']) as $k) {
        $p['defaults'][$k] = $num($k);
    }
    $p['defaults']['acc_label'] = mb_substr(trim((string)($_POST['acc_label'] ?? '')), 0, 60) ?: 'Other accessories';
    $p['defaults']['fabric_form'] = ($_POST['fabric_form'] ?? '') === 'tube' ? 'tube' : 'open';
    $extra = [];
    foreach ((array)($_POST['extra_name'] ?? []) as $i => $nm) {
        $nm = mb_substr(trim((string)$nm), 0, 60);
        $amt = max(0, round((float)($_POST['extra_amt'][$i] ?? 0), 2));
        if ($nm !== '' || $amt > 0) {
            $extra[] = ['name' => $nm ?: 'Other cost', 'amt' => $amt];
        }
    }
    $p['defaults']['extra'] = $extra;
    if ($p['name'] === '') {
        flash('Give the product a name.', 'err');
        redirect('admin/estimate_products.php?' . ($isNew ? 'new=1' : 'p=' . urlencode($key)));
    }
    $products[$p['key']] = $p;
    est_save_products($products);
    flash("Saved “{$p['name']}”. New estimates use these costs.");
    redirect('admin/estimate_products.php');
}

$edit = $isNew ? ['key' => '', 'name' => '', 'base' => 'regular', 'active' => 1, 'defaults' => est_product_template()] : ($products[$key] ?? null);

$pageTitle = 'Estimate products';
$active = 'estimate';
require __DIR__ . '/../inc/header.php';

function pnum(array $d, string $k, string $label, string $suffix, string $hint = ''): void
{
    echo '<label class="field"><span class="lbl">' . h($label) . '</span><div class="est-input"><input type="number" inputmode="decimal" step="any" min="0" name="' . h($k) . '" value="' . h((string)($d[$k] ?? 0)) . '">'
        . '<span class="est-suffix">' . h($suffix) . '</span></div>' . ($hint !== '' ? '<small class="hint">' . h($hint) . '</small>' : '') . '</label>';
}
?>
<div class="page-head">
  <div><a class="back" href="<?= h(base_url('estimate.php')) ?>">← Estimate</a><h1>Products &amp; making costs</h1>
    <p class="muted small">Staff pick these products on the Estimate page and enter the fabric; the making costs and margin come from here.</p></div>
  <?php if (!$edit): ?><div class="actions"><a class="btn primary" href="?new=1">+ New product</a></div><?php endif; ?>
</div>

<?php if ($edit): $d = $edit['defaults']; ?>
<form method="post" class="est-form">
  <?= csrf_field() ?>
  <section class="panel">
    <h2><?= $isNew ? 'New product' : h($edit['name']) ?></h2>
    <div class="grid">
      <label class="field"><span class="lbl">Product name</span><input name="name" value="<?= h($edit['name']) ?>" required placeholder="e.g. Kids T-shirt, Henley, Crop top"></label>
      <label class="field"><span class="lbl">Cut like</span>
        <select name="base"><?php foreach (est_bases() as $k => $l): ?><option value="<?= h($k) ?>" <?= $edit['base'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select>
        <small class="hint">Which pieces & size chart the fabric calculation uses</small></label>
      <label class="field check"><input type="checkbox" name="active" value="1" <?= $edit['active'] ? 'checked' : '' ?>> Staff can pick this product</label>
    </div>
  </section>

  <section class="panel">
    <h2>Making costs <small class="muted">per piece</small></h2>
    <div class="grid">
      <?php foreach ($money as $k => $l): pnum($d, $k, $l, '₹'); if ($k === 'c_acc'): ?>
        <label class="field"><span class="lbl">Accessories name</span><input name="acc_label" value="<?= h($d['acc_label']) ?>" placeholder="e.g. Collar, cuffs & buttons"></label>
      <?php endif; endforeach; ?>
      <?php pnum($d, 'rib_g', 'Rib fabric', 'g/pc', 'Neck / cuff / waistband rib'); ?>
      <?php pnum($d, 'fixed', 'One-time costs (per order)', '₹', 'Sampling, screens… shared across the pieces'); ?>
    </div>
    <h3 class="est-h3">Other costs</h3>
    <div class="extra-costs">
      <?php foreach (array_merge($d['extra'], [['name' => '', 'amt' => ''], ['name' => '', 'amt' => '']]) as $x): ?>
        <div class="extra-row"><input name="extra_name[]" value="<?= h($x['name']) ?>" placeholder="Cost name, e.g. Hang tag">
          <div class="est-input"><input type="number" inputmode="decimal" step="any" min="0" name="extra_amt[]" value="<?= h((string)$x['amt']) ?>"><span class="est-suffix">₹/pc</span></div><span></span></div>
      <?php endforeach; ?>
    </div>
    <p class="hint">Leave a row empty to skip it. Save to get more empty rows.</p>
  </section>

  <section class="panel">
    <h2>Margin <small class="muted">admin only</small></h2>
    <div class="grid">
      <?php pnum($d, 'buffer', 'Buffer', '%', 'Added to the cost'); ?>
      <?php pnum($d, 'profit', 'Profit', '₹/pc'); ?>
      <?php pnum($d, 'gst', 'GST', '%'); ?>
    </div>
  </section>

  <section class="panel">
    <h2>Starting fabric <small class="muted">staff change it on each estimate</small></h2>
    <div class="grid">
      <?php pnum($d, 'gsm', 'Fabric GSM', 'gsm'); ?>
      <label class="field"><span class="lbl">Fabric comes as</span>
        <select name="fabric_form"><option value="open" <?= $d['fabric_form'] !== 'tube' ? 'selected' : '' ?>>Open width</option><option value="tube" <?= $d['fabric_form'] === 'tube' ? 'selected' : '' ?>>Tube / roll</option></select></label>
      <?php pnum($d, 'roll_width', 'Roll width', 'in'); ?>
      <?php pnum($d, 'fabric_price', 'Fabric price', '₹/kg'); ?>
      <?php pnum($d, 'rib_price', 'Rib price', '₹/kg'); ?>
      <?php pnum($d, 'wastage', 'Cutting wastage', '%'); ?>
    </div>
  </section>

  <div class="sticky-actions">
    <button class="btn primary">💾 Save product</button>
    <a class="btn ghost" href="estimate_products.php">Cancel</a>
  </div>
</form>
<?php if (!$isNew): ?>
<form method="post" class="danger-zone" onsubmit="return confirm('Delete this product? Saved estimates keep their numbers.');">
  <?= csrf_field() ?><input type="hidden" name="do" value="delete">
  <button class="btn danger">Delete product</button>
</form>
<?php endif; ?>

<?php else: ?>
<section class="panel">
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Product</th><th>Cut like</th><th class="num">Making ₹/pc</th><th class="num">Buffer</th><th class="num">Profit</th><th>Staff</th></tr></thead>
    <tbody>
    <?php foreach ($products as $p): $d = $p['defaults'];
        $making = array_sum(array_map(fn($k) => (float)$d[$k], array_keys($money))) + array_sum(array_map(fn($x) => (float)$x['amt'], $d['extra']));
        $url = 'estimate_products.php?p=' . urlencode($p['key']); ?>
      <tr onclick="location='<?= h($url) ?>'">
        <td><a href="<?= h($url) ?>"><b><?= h($p['name']) ?></b></a></td>
        <td><?= h(est_bases()[$p['base']]) ?></td>
        <td class="num">₹<?= h(number_format($making, 2)) ?></td>
        <td class="num"><?= h((string)(float)$d['buffer']) ?>%</td>
        <td class="num">₹<?= h(number_format((float)$d['profit'], 0)) ?></td>
        <td><?= $p['active'] ? 'Can pick' : '<span class="muted">Hidden</span>' ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="hint">Making ₹/pc = stitching + accessories + printing + labels + trims + ironing + packing + other (rib fabric and one-time costs are added on the estimate).</p>
</section>
<?php endif; ?>
<?php require __DIR__ . '/../inc/footer.php'; ?>
