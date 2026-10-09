<?php
// Catalog & dropdown options. Open to admins and staff with the "catalog" permission.
require __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/catalog_import.php';

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

/** Price typed in the catalog: empty = not set. */
function clean_price($v): ?string
{
    $v = trim(str_replace(',', '.', (string)$v));
    return $v === '' ? null : number_format(max(0, (float)$v), 2, '.', '');
}

/** T-shirts in stock follow their catalog product's price. */
function push_price_to_stock(int $productId): void
{
    $p = q('SELECT p.name, p.price, g.label FROM products p JOIN gsm_options g ON g.id = p.gsm_id WHERE p.id = ?', [$productId])->fetch();
    if ($p && $p['price'] !== null) {
        q("UPDATE stock_items SET sale_price = ?, updated_at = ? WHERE category = 'Blank T-shirt' AND gsm = ? AND product = ?", [$p['price'], now(), $p['label'], $p['name']]);
    }
}

/** Quantity prices typed as "10:265, 25:260" → tidy text (bad parts dropped). */
function clean_tiers($v): string
{
    return implode(', ', array_map(fn($t) => $t[0] . ':' . rtrim(rtrim(number_format($t[1], 2, '.', ''), '0'), '.'), parse_tiers((string)$v)));
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
            // A new GSM can be typed right in the "add product" form.
            if ($name !== '' && ($newGsm = trim((string)($_POST['gsm_new'] ?? ''))) !== '') {
                $gid = q('SELECT id FROM gsm_options WHERE label = ?', [$newGsm])->fetchColumn();
                if (!$gid) {
                    q('INSERT INTO gsm_options (label, sort) VALUES (?, ?)', [$newGsm, (int)q('SELECT IFNULL(MAX(sort),0)+1 FROM gsm_options')->fetchColumn()]);
                    $gid = db()->lastInsertId();
                }
                $_POST['gsm_id'] = $gid;
            }
            if ($name !== '') {
                q('INSERT INTO products (gsm_id, name, sizes, price, price_tiers) VALUES (?, ?, ?, ?, ?)', [(int)$_POST['gsm_id'], $name, clean_list((string)($_POST['sizes'] ?? '')) ?: 'XS, S, M, L, XL, XXL', clean_price($_POST['price'] ?? ''), clean_tiers($_POST['price_tiers'] ?? '')]);
                $pid = (int)db()->lastInsertId();
                $anchor = '#p' . $pid;
                $cols = array_values(array_filter(array_map('trim', explode(',', (string)($_POST['colors'] ?? ''))), 'strlen'));
                foreach ($cols as $i => $c) {
                    q('INSERT INTO product_colors (product_id, name, hex, sort) VALUES (?, ?, ?, ?)', [$pid, $c, guess_color_hex($c), $i]);
                }
                flash("Product \"$name\" added." . ($cols ? '' : ' Now add its colours.'));
            }
            break;
        case 'product_save':
            if ($name !== '') {
                q('UPDATE products SET name = ?, gsm_id = ?, sizes = ?, price = ?, price_tiers = ?, sort = ?, active = ? WHERE id = ?', [
                    $name, (int)$_POST['gsm_id'], clean_list((string)($_POST['sizes'] ?? '')), clean_price($_POST['price'] ?? ''), clean_tiers($_POST['price_tiers'] ?? ''),
                    (int)($_POST['sort'] ?? 0), !empty($_POST['active']) ? 1 : 0, $id,
                ]);
                push_price_to_stock($id);
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
        case 'print_prices_save':
            $pp = [];
            foreach ((array)($_POST['pp'] ?? []) as $row) {
                $k = trim((string)($row['size'] ?? ''));
                if ($k !== '' && trim((string)($row['one'] ?? '')) !== '') {
                    $one = max(0, (float)$row['one']);
                    $pp[mb_substr($k, 0, 20)] = [$one, trim((string)($row['ten'] ?? '')) === '' ? $one : max(0, (float)$row['ten'])];
                }
            }
            set_setting('print_prices', json_encode($pp, JSON_UNESCAPED_UNICODE));
            flash('Printing prices saved.');
            $anchor = '#printing';
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
    // Products are edited on the "Products & stock" page; it sends its changes here and gets them back.
    if (($_POST['back'] ?? '') === 'stock') {
        redirect('stock.php' . (str_starts_with($anchor, '#p') ? $anchor : ''));
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

$pageTitle = 'Order form options';
$active = 'catalog';
require __DIR__ . '/../inc/header.php';
?>
<div class="page-head"><div><h1>Order form options</h1>
  <p class="muted small">GSM list, print options, couriers and bulk import. Products, colours, sizes, prices and stock are on <a href="<?= h(base_url('stock.php')) ?>">Products &amp; stock</a>.</p></div>
  <div class="actions"><a class="btn primary" href="<?= h(base_url('stock.php')) ?>">👕 Products &amp; stock</a></div></div>
<nav class="tabs">
  <a href="#gsm">GSM</a><a href="#printing">Printing prices</a><a href="#options">Print options</a><a href="#couriers">Couriers</a><a href="#import">Bulk import</a>
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

<section class="panel" id="printing">
  <h2>DTF printing prices <small class="muted">(per print, before GST — used on bills)</small></h2>
  <p class="hint">The size name matches the start of the print size on orders (A2, A3, A4, Logo). <b>Roll</b> is the DTF roll price per metre. A neck label is free with an A2/A3/A4 print, otherwise it is charged as a Logo. Custom sizes have no automatic price.</p>
  <form method="post">
    <?= csrf_field() ?>
    <div class="table-wrap"><table class="table compact pp-table">
      <thead><tr><th>Size</th><th class="num">1–9 pieces ₹</th><th class="num">10+ pieces ₹</th></tr></thead>
      <tbody>
      <?php $ppi = 0; foreach (print_prices() + ['' => ['', '']] as $k => [$one, $ten]): ?>
        <tr><td><input name="pp[<?= $ppi ?>][size]" value="<?= h((string)$k) ?>" placeholder="New size"></td>
          <td class="num"><input type="number" step="any" min="0" name="pp[<?= $ppi ?>][one]" value="<?= h((string)$one) ?>"></td>
          <td class="num"><input type="number" step="any" min="0" name="pp[<?= $ppi ?>][ten]" value="<?= h((string)$ten) ?>"></td></tr>
      <?php $ppi++; endforeach; ?>
      </tbody>
    </table></div>
    <button class="btn primary" name="do" value="print_prices_save">Save printing prices</button>
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
