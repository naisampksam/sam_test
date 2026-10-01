<?php
// Catalog & dropdown options. Open to admins and staff with the "catalog" permission.
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/catalog_import.php';

require_login();
if (!cap('catalog')) {
    http_response_code(403);
    exit('You are not allowed to manage the catalog.');
}

function clean_hex(string $h): string
{
    $h = trim($h);
    return preg_match('/^#[0-9a-fA-F]{6}$/', $h) ? strtoupper($h) : '';
}

function clean_list(string $s): string
{
    return implode(', ', array_unique(array_filter(array_map('trim', explode(',', $s)), 'strlen')));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    $id = (int)($_POST['id'] ?? 0);
    $name = trim((string)($_POST['name'] ?? ''));
    $anchor = '';
    switch ($do) {
        case 'gsm_add':
            if ($name !== '') {
                $sort = (int)q('SELECT IFNULL(MAX(sort),0)+1 FROM gsm_options')->fetchColumn();
                q('INSERT INTO gsm_options (label, sort) VALUES (?, ?)', [$name, $sort]);
                flash("GSM \"$name\" added.");
            }
            break;
        case 'gsm_save':
            if ($name !== '') {
                q('UPDATE gsm_options SET label = ?, sort = ? WHERE id = ?', [$name, (int)($_POST['sort'] ?? 0), $id]);
                flash('GSM saved.');
            }
            break;
        case 'gsm_delete':
            if ((int)q('SELECT COUNT(*) FROM products WHERE gsm_id = ?', [$id])->fetchColumn() > 0) {
                flash('Remove or move the products in this GSM first.', 'err');
            } else {
                q('DELETE FROM gsm_options WHERE id = ?', [$id]);
                flash('GSM removed.');
            }
            break;
        case 'product_add':
            if ($name !== '') {
                q('INSERT INTO products (gsm_id, name, sizes) VALUES (?, ?, ?)', [(int)$_POST['gsm_id'], $name, clean_list((string)($_POST['sizes'] ?? '')) ?: 'XS, S, M, L, XL, XXL']);
                $anchor = '#p' . db()->lastInsertId();
                flash("Product \"$name\" added. Now add its colors.");
            }
            break;
        case 'product_save':
            if ($name !== '') {
                q('UPDATE products SET name = ?, gsm_id = ?, sizes = ?, sort = ?, active = ? WHERE id = ?', [
                    $name, (int)$_POST['gsm_id'], clean_list((string)($_POST['sizes'] ?? '')), (int)($_POST['sort'] ?? 0), !empty($_POST['active']) ? 1 : 0, $id,
                ]);
                flash('Product saved.');
            }
            $anchor = "#p$id";
            break;
        case 'color_add':
            $pid = (int)$_POST['product_id'];
            foreach (array_filter(array_map('trim', explode(',', $name)), 'strlen') as $c) {
                $hex = clean_hex((string)($_POST['hex'] ?? ''));
                q('INSERT INTO product_colors (product_id, name, hex, sort) VALUES (?, ?, ?, ?)', [$pid, $c, $hex ?: guess_color_hex($c), 99]);
            }
            flash('Color added.');
            $anchor = "#p$pid";
            break;
        case 'color_save':
        case 'color_delete':
            $c = q('SELECT product_id FROM product_colors WHERE id = ?', [$id])->fetch();
            if ($do === 'color_delete') {
                q('DELETE FROM product_colors WHERE id = ?', [$id]);
                flash('Color removed.');
            } elseif ($name !== '') {
                q('UPDATE product_colors SET name = ?, hex = ?, active = ? WHERE id = ?', [$name, clean_hex((string)($_POST['hex'] ?? '')), !empty($_POST['active']) ? 1 : 0, $id]);
                flash('Color saved.');
            }
            $anchor = $c ? '#p' . $c['product_id'] : '';
            break;
        case 'product_delete':
            $pname = (string)q('SELECT name FROM products WHERE id = ?', [$id])->fetchColumn();
            q('DELETE FROM product_colors WHERE product_id = ?', [$id]);
            q('DELETE FROM products WHERE id = ?', [$id]);
            flash("Product \"$pname\" deleted. Existing orders keep their product name.");
            $anchor = '#products';
            break;
        case 'courier_delete':
            q('DELETE FROM couriers WHERE id = ?', [$id]);
            flash('Courier deleted. Existing orders keep their courier name.');
            $anchor = '#couriers';
            break;
        case 'courier_add':
            if ($name !== '') {
                q('INSERT INTO couriers (name, sort) VALUES (?, 99)', [$name]);
                flash('Courier added.');
            }
            $anchor = '#couriers';
            break;
        case 'courier_save':
            if ($name !== '') {
                q('UPDATE couriers SET name = ?, active = ? WHERE id = ?', [$name, !empty($_POST['active']) ? 1 : 0, $id]);
                flash('Courier saved.');
            }
            $anchor = '#couriers';
            break;
        case 'print_sizes_save':
            set_setting('print_sizes', clean_list((string)($_POST['options'] ?? '')));
            flash('Print sizes saved.');
            $anchor = '#options';
            break;
        case 'options_save':
            q("UPDATE custom_fields SET options = ? WHERE id = ? AND type = 'select'", [clean_list((string)($_POST['options'] ?? '')), $id]);
            flash('Options saved.');
            $anchor = '#options';
            break;
        case 'import':
            [$n, $errs] = import_catalog_text(db(), (string)($_POST['text'] ?? ''));
            flash("Imported $n product line(s)." . ($errs ? ' Problems: ' . implode('; ', $errs) : ''), $errs ? 'err' : 'ok');
            break;
    }
    redirect('admin/catalog.php' . $anchor);
}

$gsms = q('SELECT g.*, (SELECT COUNT(*) FROM products p WHERE p.gsm_id = g.id) AS n FROM gsm_options g ORDER BY sort, id')->fetchAll();
$products = q('SELECT * FROM products ORDER BY active DESC, sort, name')->fetchAll();
$colors = [];
foreach (q('SELECT * FROM product_colors ORDER BY sort, id')->fetchAll() as $c) {
    $colors[$c['product_id']][] = $c;
}
$couriers = q('SELECT * FROM couriers ORDER BY active DESC, sort, name')->fetchAll();
$selectFields = q("SELECT * FROM custom_fields WHERE type = 'select' AND active = 1 ORDER BY sort, id")->fetchAll();

$pageTitle = 'Catalog';
$active = 'catalog';
require __DIR__ . '/../inc/header.php';
?>
<div class="page-head"><h1>Catalog &amp; options</h1></div>
<p class="muted">Everything here shows up in the order form dropdowns. Colors are listed per product, so each GSM only shows its own products and colors.</p>

<nav class="tabs">
  <a href="#products">Products &amp; colors</a><a href="#options">Print options</a><a href="#couriers">Couriers</a><a href="#import">Bulk import</a>
</nav>

<section class="panel" id="gsm">
  <h2>GSM options</h2>
  <div class="chip-list">
  <?php foreach ($gsms as $g): ?>
    <form method="post" class="chip-form">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$g['id'] ?>">
      <input name="name" value="<?= h($g['label']) ?>" aria-label="GSM label" class="w-sm">
      <input name="sort" type="number" value="<?= (int)$g['sort'] ?>" aria-label="Order" class="w-xs" title="Display order">
      <button class="btn small" name="do" value="gsm_save">Save</button>
      <?php if (!$g['n']): ?><button class="btn small ghost" name="do" value="gsm_delete" onclick="return confirm('Remove this GSM?')">✕</button><?php endif; ?>
    </form>
  <?php endforeach; ?>
  </div>
  <form method="post" class="inline-add">
    <?= csrf_field() ?><input name="name" placeholder="New GSM e.g. 220 GSM" required>
    <button class="btn" name="do" value="gsm_add">+ Add GSM</button>
  </form>
</section>

<section class="panel" id="products">
  <h2>Products &amp; colors</h2>
  <?php foreach ($gsms as $g): ?>
    <h3 class="perm-group"><?= h($g['label']) ?></h3>
    <?php foreach ($products as $p): if ((int)$p['gsm_id'] !== (int)$g['id']) continue; ?>
      <details class="product <?= $p['active'] ? '' : 'inactive' ?>" id="p<?= (int)$p['id'] ?>">
        <summary>
          <b><?= h($p['name']) ?></b>
          <span class="swatches"><?php foreach ($colors[$p['id']] ?? [] as $c): if (!$c['active']) continue; ?><i style="background: <?= h($c['hex'] ?: '#ccc') ?>" title="<?= h($c['name']) ?>"></i><?php endforeach; ?></span>
          <span class="muted small"><?= h($p['sizes']) ?><?= $p['active'] ? '' : ' · hidden' ?></span>
        </summary>
        <form method="post" class="grid">
          <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
          <label class="field"><span class="lbl">Name</span><input name="name" value="<?= h($p['name']) ?>" required></label>
          <label class="field"><span class="lbl">GSM</span>
            <select name="gsm_id"><?php foreach ($gsms as $g2): ?><option value="<?= (int)$g2['id'] ?>" <?= $g2['id'] == $p['gsm_id'] ? 'selected' : '' ?>><?= h($g2['label']) ?></option><?php endforeach; ?></select>
          </label>
          <label class="field"><span class="lbl">Sizes (comma separated)</span><input name="sizes" value="<?= h($p['sizes']) ?>"></label>
          <label class="field"><span class="lbl">Display order</span><input name="sort" type="number" value="<?= (int)$p['sort'] ?>"></label>
          <label class="field check"><input type="checkbox" name="active" value="1" <?= $p['active'] ? 'checked' : '' ?>> Show in order form</label>
          <div class="field row-btns"><button class="btn" name="do" value="product_save">Save product</button>
            <button class="btn danger" name="do" value="product_delete" formnovalidate onclick="return confirm('Delete this product and all its colors? Existing orders are not changed.')">Delete</button></div>
        </form>
        <h4>Colors</h4>
        <div class="color-rows">
        <?php foreach ($colors[$p['id']] ?? [] as $c): ?>
          <form method="post" class="color-row <?= $c['active'] ? '' : 'inactive' ?>">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
            <input type="color" name="hex" value="<?= h($c['hex'] ?: '#cccccc') ?>" aria-label="Color swatch">
            <input name="name" value="<?= h($c['name']) ?>" aria-label="Color name">
            <label class="check small"><input type="checkbox" name="active" value="1" <?= $c['active'] ? 'checked' : '' ?>> In stock</label>
            <button class="btn small" name="do" value="color_save">Save</button>
            <button class="btn small ghost" name="do" value="color_delete" formnovalidate onclick="return confirm('Remove this color? Old orders keep their color name.')">✕</button>
          </form>
        <?php endforeach; ?>
        </div>
        <form method="post" class="inline-add">
          <?= csrf_field() ?><input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
          <input type="color" name="hex" value="#cccccc" aria-label="Swatch">
          <input name="name" placeholder="New color name" required>
          <button class="btn" name="do" value="color_add">+ Add color</button>
        </form>
      </details>
    <?php endforeach; ?>
  <?php endforeach; ?>

  <h3 class="perm-group">Add a product</h3>
  <form method="post" class="grid">
    <?= csrf_field() ?>
    <label class="field"><span class="lbl">GSM</span>
      <select name="gsm_id" required><?php foreach ($gsms as $g): ?><option value="<?= (int)$g['id'] ?>"><?= h($g['label']) ?></option><?php endforeach; ?></select>
    </label>
    <label class="field"><span class="lbl">Product name</span><input name="name" required placeholder="e.g. Oversized Hoodie"></label>
    <label class="field"><span class="lbl">Sizes</span><input name="sizes" value="XS, S, M, L, XL, XXL"></label>
    <div class="field"><span class="lbl">&nbsp;</span><button class="btn primary" name="do" value="product_add">+ Add product</button></div>
  </form>
</section>

<section class="panel" id="options">
  <h2>Print options &amp; other dropdowns</h2>
  <form method="post" class="grid">
    <?= csrf_field() ?>
    <label class="field full"><span class="lbl">Print sizes <small class="muted">(shown next to Front / Back / Chest / Custom print · comma separated)</small></span>
      <textarea name="options" rows="2"><?= h(setting('print_sizes', 'A2 (16×22), A3 (11×16), A4 (8×11), Logo (2.5×2.5), Custom')) ?></textarea></label>
    <div class="field"><button class="btn" name="do" value="print_sizes_save">Save print sizes</button></div>
  </form>
  <?php foreach ($selectFields as $f): ?>
    <form method="post" class="grid">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
      <label class="field full"><span class="lbl"><?= h($f['label']) ?> <small class="muted">(comma separated)</small></span>
        <textarea name="options" rows="2"><?= h($f['options']) ?></textarea></label>
      <div class="field"><button class="btn" name="do" value="options_save">Save <?= h($f['label']) ?></button></div>
    </form>
  <?php endforeach; ?>
</section>

<section class="panel" id="couriers">
  <h2>Couriers</h2>
  <div class="color-rows">
  <?php foreach ($couriers as $c): ?>
    <form method="post" class="color-row <?= $c['active'] ? '' : 'inactive' ?>">
      <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
      <input name="name" value="<?= h($c['name']) ?>" aria-label="Courier name">
      <label class="check small"><input type="checkbox" name="active" value="1" <?= $c['active'] ? 'checked' : '' ?>> Active</label>
      <button class="btn small" name="do" value="courier_save">Save</button>
      <button class="btn small ghost" name="do" value="courier_delete" formnovalidate onclick="return confirm('Delete this courier?')" aria-label="Delete courier">✕</button>
    </form>
  <?php endforeach; ?>
  </div>
  <form method="post" class="inline-add">
    <?= csrf_field() ?><input name="name" placeholder="New courier" required>
    <button class="btn" name="do" value="courier_add">+ Add courier</button>
  </form>
</section>

<section class="panel" id="import">
  <h2>Bulk import</h2>
  <p class="muted small">One product per line: <code>GSM | Product | Colors | Sizes</code>. Add a hex code after a color if you like. Existing products are updated; colors are added, never deleted.</p>
  <form method="post">
    <?= csrf_field() ?>
    <textarea name="text" rows="5" placeholder="250 GSM | Oversized Hoodie | Black #131313, Grey Melange | S, M, L, XL"></textarea>
    <button class="btn" name="do" value="import">Import</button>
  </form>
</section>
<?php require __DIR__ . '/../inc/footer.php'; ?>
