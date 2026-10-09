<?php
// Products & stock: the catalog's T-shirt products (price, colours, sizes) with the stock of every colour × size,
// plus other stock and printing services. Stock goes down by itself when a tax invoice is made (inc/billing.php).
// Product changes are saved by admin/catalog.php ("catalog" permission); stock changes here ("stock" permission).
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/orders.php';
require __DIR__ . '/inc/billing.php';

require_login();
$canStock = cap('stock');
$canCat = cap('catalog');
if (!$canStock && !$canCat) {
    http_response_code(403);
    exit('You are not allowed to see products & stock.');
}
$id = (int)($_GET['id'] ?? 0);
if ($id && !$canStock) {
    redirect('stock.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$canStock) {
        http_response_code(403);
        exit('You are not allowed to change stock.');
    }
    $do = $_POST['do'] ?? '';
    $num = fn(string $k, float $d = 0) => isset($_POST[$k]) && $_POST[$k] !== '' ? round((float)str_replace(',', '.', (string)$_POST[$k]), 2) : $d;
    $common = [
        'unit' => in_array($_POST['unit'] ?? '', ['pcs', 'm', 'kg', 'roll', 'box', 'set'], true) ? $_POST['unit'] : 'pcs',
        'low_level' => max(0, $num('low_level')), 'cost_price' => max(0, $num('cost_price')), 'sale_price' => max(0, $num('sale_price')),
        'hsn' => mb_substr(trim((string)($_POST['hsn'] ?? '')), 0, 20), 'gst_rate' => max(0, $num('gst_rate', 5)),
        'sku' => mb_substr(trim((string)($_POST['sku'] ?? '')), 0, 60),
    ];
    if ($do === 'create') {
        $cat = in_array($_POST['category'] ?? '', STOCK_CATEGORIES, true) ? $_POST['category'] : 'Other';
        $opening = max(0, $num('opening'));
        $made = 0;
        if ($cat === 'Blank T-shirt') {
            [$gsm, $product] = array_pad(explode('|', (string)($_POST['gp'] ?? ''), 2), 2, '');
            $color = trim((string)($_POST['color'] ?? ''));
            $sizes = array_values(array_filter(array_map('trim', (array)($_POST['sizes'] ?? [])), 'strlen')) ?: [''];
            foreach ($sizes as $size) {
                if (q('SELECT id FROM stock_items WHERE gsm = ? AND product = ? AND color = ? AND size = ?', [$gsm, $product, $color, $size])->fetch()) {
                    continue; // already in stock list
                }
                $row = ['sale_price' => catalog_price($gsm, $product) ?? $common['sale_price']] + $common + ['name' => trim("$gsm $product $color $size"), 'category' => $cat, 'gsm' => $gsm, 'product' => $product,
                    'color' => $color, 'size' => $size, 'created_at' => now()];
                q('INSERT INTO stock_items (' . implode(',', array_keys($row)) . ') VALUES (' . rtrim(str_repeat('?,', count($row)), ',') . ')', array_values($row));
                $sid = (int)db()->lastInsertId();
                stock_move($sid, $opening, 'opening', null, '', $common['cost_price'] ?: null);
                $made++;
            }
        } else {
            $name = mb_substr(trim((string)($_POST['name'] ?? '')), 0, 200);
            if ($name !== '') {
                $service = $cat === SERVICE_CATEGORY;
                $row = $common + ['name' => $name, 'category' => $cat, 'track' => $service ? 0 : 1, 'created_at' => now()];
                q('INSERT INTO stock_items (' . implode(',', array_keys($row)) . ') VALUES (' . rtrim(str_repeat('?,', count($row)), ',') . ')', array_values($row));
                if (!$service) {
                    stock_move((int)db()->lastInsertId(), $opening, 'opening', null, '', $common['cost_price'] ?: null);
                }
                $made = 1;
            }
        }
        flash($made ? "Added $made stock item" . ($made === 1 ? '' : 's') . '.' : 'Nothing added — those items are already in the stock list, or the name is empty.', $made ? 'ok' : 'err');
        redirect('stock.php?view=list');
    }
    if ($do === 'add_bulk') {
        // Simple "Add stock": one product + colour, a quantity per size (stock items are made the first time).
        $p = q('SELECT p.*, g.label AS gsm FROM products p JOIN gsm_options g ON g.id = p.gsm_id WHERE p.id = ?', [(int)($_POST['pid'] ?? 0)])->fetch();
        $color = trim((string)($_POST['color'] ?? ''));
        $count = ($_POST['kind'] ?? '') === 'count';
        $note = mb_substr(trim((string)($_POST['note'] ?? '')), 0, 200);
        $done = [];
        foreach ($p ? (array)($_POST['qty'] ?? []) : [] as $size => $v) {
            $size = trim((string)$size);
            if (trim((string)$v) === '' || $size === '') {
                continue;
            }
            $qty = max(0, round((float)str_replace(',', '.', (string)$v), 2));
            if (!$count && $qty <= 0) {
                continue;
            }
            $sid = (int)q("SELECT id FROM stock_items WHERE category = 'Blank T-shirt' AND gsm = ? AND product = ? AND color = ? AND size = ? ORDER BY active DESC, id LIMIT 1",
                [$p['gsm'], $p['name'], $color, $size])->fetchColumn();
            if (!$sid) {
                $row = ['name' => trim("{$p['gsm']} {$p['name']} $color $size"), 'category' => 'Blank T-shirt', 'gsm' => $p['gsm'], 'product' => $p['name'], 'color' => $color,
                    'size' => $size, 'unit' => 'pcs', 'sale_price' => $p['price'] ?? 0, 'hsn' => setting('inv_default_hsn', '6109'),
                    'gst_rate' => (float)setting('inv_default_gst', '5'), 'low_level' => 5, 'created_at' => now()];
                q('INSERT INTO stock_items (' . implode(',', array_keys($row)) . ') VALUES (' . rtrim(str_repeat('?,', count($row)), ',') . ')', array_values($row));
                $sid = (int)db()->lastInsertId();
            }
            q('UPDATE stock_items SET active = 1 WHERE id = ?', [$sid]);
            $cur = (float)stock_get($sid)['qty'];
            if ($count) {
                stock_move($sid, round($qty - $cur, 2), 'adjust', null, $note !== '' ? $note : 'Stock count');
            } else {
                stock_move($sid, $qty, 'purchase', null, $note, $num('unit_cost') > 0 ? $num('unit_cost') : null);
            }
            $done[] = $size . ($count ? ' = ' : ' +') . qty_fmt($qty);
        }
        flash($done ? "✓ {$p['name']} · $color: " . implode(', ', $done) . '.' : 'Type a quantity under at least one size.', $done ? 'ok' : 'err');
        redirect('stock.php' . ($p ? '?add=' . (int)$p['id'] . '&color=' . urlencode($color) . '#p' . (int)$p['id'] : ''));
    }
    if ($do === 'hide_loose' && is_admin()) {
        // T-shirt stock that is not one of the catalog products: hide it (history is kept).
        $n = 0;
        foreach (q("SELECT s.id FROM stock_items s WHERE s.category = 'Blank T-shirt' AND s.active = 1 AND NOT EXISTS
                    (SELECT 1 FROM products p JOIN gsm_options g ON g.id = p.gsm_id WHERE g.label = s.gsm AND p.name = s.product)")->fetchAll(PDO::FETCH_COLUMN) as $sid) {
            q('UPDATE stock_items SET active = 0 WHERE id = ?', [$sid]);
            $n++;
        }
        flash("Hidden $n T-shirt stock item(s) that are not catalog products.");
        redirect('stock.php');
    }
    if ($do === 'move' && ($s = stock_get((int)($_POST['stock_id'] ?? 0)))) {
        $kind = $_POST['kind'] ?? 'purchase';
        $note = trim((string)($_POST['note'] ?? ''));
        if ($kind === 'count') {
            // Stock count: set to what is actually on the shelf.
            $change = round($num('qty') - (float)$s['qty'], 2);
            stock_move((int)$s['id'], $change, 'adjust', null, $note !== '' ? $note : 'Stock count');
            flash(stock_name($s) . ': set to ' . qty_fmt($num('qty')) . ' ' . $s['unit'] . '.');
        } else {
            $qty = $num('qty');
            $reason = in_array($kind, ['purchase', 'return', 'adjust'], true) ? $kind : 'purchase';
            $change = $kind === 'remove' ? -abs($qty) : abs($qty);
            stock_move((int)$s['id'], $change, $kind === 'remove' ? 'adjust' : $reason, null, $note, $kind === 'purchase' && $num('unit_cost') > 0 ? $num('unit_cost') : null);
            if ($kind === 'purchase' && $num('unit_cost') > 0) {
                q('UPDATE stock_items SET cost_price = ? WHERE id = ?', [$num('unit_cost'), $s['id']]);
            }
            flash(stock_name($s) . ': ' . ($change >= 0 ? '+' : '') . qty_fmt($change) . ' ' . $s['unit'] . '.');
        }
        redirect(!empty($_POST['back']) ? 'stock.php?id=' . (int)$s['id'] : 'stock.php' . (isset($_POST['q']) ? '?q=' . urlencode((string)$_POST['q']) : ''));
    }
    if ($do === 'save' && $id && ($s = stock_get($id))) {
        $row = $common + ['name' => mb_substr(trim((string)($_POST['name'] ?? '')), 0, 200) ?: $s['name'], 'active' => !empty($_POST['active']) ? 1 : 0,
            'track' => !empty($_POST['track']) ? 1 : 0, 'updated_at' => now()];
        if ($s['category'] === 'Blank T-shirt' && ($cp = catalog_price($s['gsm'], $s['product'])) !== null) {
            $row['sale_price'] = $cp; // T-shirt price comes from the catalog
        }
        if (!$row['track'] && $s['category'] === 'Blank T-shirt') {
            $row['category'] = SERVICE_CATEGORY;
        } elseif ($row['track'] && $s['category'] === SERVICE_CATEGORY) {
            $row['category'] = 'Other';
        }
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($row)));
        q("UPDATE stock_items SET $sets WHERE id = ?", array_merge(array_values($row), [$id]));
        flash('Saved.');
        redirect('stock.php?id=' . $id);
    }
    if ($do === 'delete' && $id) {
        $used = (int)q('SELECT COUNT(*) FROM bill_items WHERE stock_id = ?', [$id])->fetchColumn();
        if ($used) {
            q('UPDATE stock_items SET active = 0 WHERE id = ?', [$id]);
            flash('This item is on bills, so it was hidden instead of deleted.');
        } else {
            q('DELETE FROM stock_moves WHERE stock_id = ?', [$id]);
            q('DELETE FROM stock_items WHERE id = ?', [$id]);
            flash('Stock item deleted.');
        }
        redirect('stock.php');
    }
}

$pageTitle = 'Products & stock';
$active = 'stock';

// ---------------------------------------------------------------- one item
if ($id && ($s = stock_get($id))) {
    $moves = q('SELECT m.*, b.number FROM stock_moves m LEFT JOIN bills b ON b.id = m.bill_id WHERE m.stock_id = ? ORDER BY m.id DESC LIMIT 200', [$id])->fetchAll();
    require __DIR__ . '/inc/header.php';
    ?>
    <div class="page-head"><div><a class="back" href="stock.php">← Stock</a><h1><?= h(stock_name($s)) ?></h1>
      <p class="muted small"><?= h($s['category']) ?> · <?php if ($s['track']): ?><b class="<?= (float)$s['qty'] <= (float)$s['low_level'] ? 'err-text' : '' ?>"><?= qty_fmt($s['qty']) ?> <?= h($s['unit']) ?> in stock</b><?php else: ?><b>Service — not counted as stock</b><?php endif; ?></p></div></div>
    <?php if ($s['track']): ?>
    <section class="panel">
      <h2>Add / remove stock</h2>
      <?php stock_move_form($s, true); ?>
    </section>
    <?php endif; ?>
    <section class="panel">
      <h2>Details</h2>
      <form method="post" class="grid">
        <?= csrf_field() ?><input type="hidden" name="do" value="save">
        <label class="field"><span class="lbl">Name</span><input name="name" value="<?= h($s['name']) ?>"></label>
        <?php stock_common_fields($s); ?>
        <label class="field check"><input type="checkbox" name="active" value="1" <?= $s['active'] ? 'checked' : '' ?>> In use (shows on bills)</label>
        <label class="field check"><input type="checkbox" name="track" value="1" <?= $s['track'] ? 'checked' : '' ?>> Count stock (untick for printing &amp; other services — bills never change their count)</label>
        <div class="field"><button class="btn primary">Save</button></div>
      </form>
    </section>
    <section class="panel">
      <h2>History</h2>
      <div class="table-wrap"><table class="table compact">
        <thead><tr><th>When</th><th>What</th><th class="num">Change</th><th>Note</th><th>By</th></tr></thead>
        <tbody>
        <?php foreach ($moves as $m): ?>
          <tr><td><?= h(fmt_date($m['created_at'], true)) ?></td><td><?= h(STOCK_REASONS[$m['reason']] ?? $m['reason']) ?>
            <?php if ($m['bill_id']): ?> · <a href="bill.php?id=<?= (int)$m['bill_id'] ?>"><?= h($m['number'] ?: '#' . $m['bill_id']) ?></a><?php endif; ?></td>
            <td class="num <?= $m['qty_change'] < 0 ? 'err-text' : '' ?>"><b><?= ($m['qty_change'] > 0 ? '+' : '') . qty_fmt($m['qty_change']) ?></b></td>
            <td class="wrap-cell"><?= h($m['note']) ?><?= $m['unit_cost'] ? ' · ' . h(money((float)$m['unit_cost'])) . '/' . h($s['unit']) : '' ?></td><td><?= h(user_name($m['created_by'])) ?></td></tr>
        <?php endforeach; ?>
        <?php if (!$moves): ?><tr><td colspan="5" class="muted">No movements yet.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </section>
    <form method="post" class="danger-zone" onsubmit="return confirm('Delete this stock item? (If it is on bills it will be hidden instead.)');">
      <?= csrf_field() ?><input type="hidden" name="do" value="delete"><button class="btn danger">Delete item</button>
    </form>
    <?php
    require __DIR__ . '/inc/footer.php';
    exit;
}

// ---------------------------------------------------------------- list
$view = ($_GET['view'] ?? '') === 'list' || isset($_GET['q']) || isset($_GET['cat']) || !empty($_GET['low']) ? 'list' : 'shirts';
$s = trim((string)($_GET['q'] ?? ''));
$cat = in_array($_GET['cat'] ?? '', STOCK_CATEGORIES, true) ? $_GET['cat'] : '';
$low = !empty($_GET['low']);
$where = ['1'];
$params = [];
if ($s !== '') {
    $where[] = "CONCAT_WS(' ', name, sku, gsm, product, color, size) LIKE ?";
    $params[] = '%' . $s . '%';
}
if ($cat !== '') {
    $where[] = 'category = ?';
    $params[] = $cat;
}
if ($low) {
    $where[] = 'qty <= low_level AND track = 1';
}
if (empty($_GET['all'])) {
    $where[] = 'active = 1';
}
$items = q('SELECT * FROM stock_items WHERE ' . implode(' AND ', $where) . ' ORDER BY track DESC, category, gsm, product, color, FIELD(size, "XS","S","M","L","XL","XXL","2XL","3XL","4XL"), size, name', $params)->fetchAll();
$tot = q('SELECT COUNT(*) n, IFNULL(SUM(qty * cost_price), 0) value, IFNULL(SUM(qty <= low_level), 0) low,
                 IFNULL(SUM(CASE WHEN category = "Blank T-shirt" THEN qty END), 0) blanks FROM stock_items WHERE active = 1 AND track = 1')->fetch();

function stock_common_fields(array $s): void
{
    ?>
    <label class="field"><span class="lbl">Unit</span><select name="unit"><?php foreach (['pcs', 'm', 'kg', 'roll', 'box', 'set'] as $u): ?><option <?= $s['unit'] === $u ? 'selected' : '' ?>><?= $u ?></option><?php endforeach; ?></select></label>
    <label class="field"><span class="lbl">Selling price (₹ per unit, before GST)</span><input type="number" step="any" min="0" name="sale_price" value="<?= h((string)(float)$s['sale_price']) ?>"></label>
    <label class="field"><span class="lbl">Cost price (₹ per unit)</span><input type="number" step="any" min="0" name="cost_price" value="<?= h((string)(float)$s['cost_price']) ?>"></label>
    <label class="field"><span class="lbl">Warn when stock is at or below</span><input type="number" step="any" min="0" name="low_level" value="<?= h((string)(float)$s['low_level']) ?>"></label>
    <label class="field"><span class="lbl">HSN code</span><input name="hsn" value="<?= h($s['hsn']) ?>"></label>
    <label class="field"><span class="lbl">GST %</span><input type="number" step="any" min="0" name="gst_rate" value="<?= h((string)(float)$s['gst_rate']) ?>"></label>
    <label class="field"><span class="lbl">SKU / code (optional)</span><input name="sku" value="<?= h($s['sku']) ?>"></label>
    <?php
}

function stock_move_form(array $s, bool $back): void
{
    ?>
    <form method="post" class="grid stock-move">
      <?= csrf_field() ?><input type="hidden" name="do" value="move"><input type="hidden" name="stock_id" value="<?= (int)$s['id'] ?>">
      <?php if ($back): ?><input type="hidden" name="back" value="1"><?php endif; ?>
      <label class="field"><span class="lbl">What</span>
        <select name="kind">
          <option value="purchase">➕ Add (purchase)</option>
          <option value="return">↩ Returned by customer</option>
          <option value="remove">➖ Remove (damaged / used)</option>
          <option value="count">🔢 Stock count: set to</option>
        </select></label>
      <label class="field"><span class="lbl">Quantity (<?= h($s['unit']) ?>)</span><input type="number" step="any" min="0" name="qty" required></label>
      <label class="field"><span class="lbl">Cost per <?= h($s['unit']) ?> (purchase)</span><input type="number" step="any" min="0" name="unit_cost" placeholder="<?= h((string)(float)$s['cost_price']) ?>"></label>
      <label class="field"><span class="lbl">Note</span><input name="note" placeholder="Supplier, bill no…"></label>
      <div class="field"><span class="lbl">&nbsp;</span><button class="btn primary">Save</button></div>
    </form>
    <?php
}

$catalog = catalog();
$gp = [];
foreach ($catalog as $g) {
    foreach ($g['products'] as $p) {
        $gp[$g['gsm'] . '|' . $p['name']] = ['label' => trim($g['gsm'] . ' · ' . $p['name']), 'sizes' => $p['sizes'], 'colors' => array_column($p['colors'], 'name')];
    }
}
$defaults = ['unit' => 'pcs', 'sale_price' => 0, 'cost_price' => 0, 'low_level' => 10, 'hsn' => setting('inv_default_hsn', '6109'), 'gst_rate' => setting('inv_default_gst', '5'), 'sku' => ''];
require __DIR__ . '/inc/header.php';
?>
<div class="page-head">
  <div><h1>Products &amp; stock</h1><p class="muted small">Your T-shirt products with price, colours and sizes, and how many of each are in stock. Stock goes down by itself when a tax invoice is made.</p></div>
  <div class="actions">
    <?php if ($canStock): ?><a class="btn primary" href="stock.php#add-stock">＋ Add stock</a><?php endif; ?>
    <?php if ($canCat): ?><a class="btn" href="stock.php#add-product">＋ New product</a><?php endif; ?>
  </div>
</div>

<div class="stats">
  <div class="stat"><span class="stat-label">Products</span><span class="stat-num"><?= (int)q('SELECT COUNT(*) FROM products WHERE active = 1')->fetchColumn() ?></span><span class="stat-sub"><?= (int)$tot['n'] ?> stock items</span></div>
  <div class="stat"><span class="stat-label">Blank T-shirts</span><span class="stat-num"><?= qty_fmt($tot['blanks']) ?></span><span class="stat-sub">pcs in stock</span></div>
  <div class="stat"><span class="stat-label">Stock value</span><span class="stat-num"><?= h(money((float)$tot['value'], 0)) ?></span><span class="stat-sub">at cost price</span></div>
  <a class="stat <?= $tot['low'] ? 'stat-warn' : '' ?>" href="?low=1"><span class="stat-label">Low / out of stock</span><span class="stat-num"><?= (int)$tot['low'] ?></span><span class="stat-sub">tap to see</span></a>
</div>

<nav class="tabs" style="margin:14px 0">
  <a class="<?= $view === 'shirts' ? 'on' : '' ?>" href="stock.php">👕 T-shirt products</a>
  <a class="<?= $view === 'list' && $cat !== SERVICE_CATEGORY ? 'on' : '' ?>" href="stock.php?view=list">📦 All stock items</a>
  <a class="<?= $cat === SERVICE_CATEGORY ? 'on' : '' ?>" href="stock.php?cat=<?= urlencode(SERVICE_CATEGORY) ?>">🖨 Printing &amp; services</a>
</nav>

<?php if ($view === 'shirts'): ?>
<?php
    $pGsms = q('SELECT * FROM gsm_options ORDER BY sort, id')->fetchAll();
    $pProducts = q('SELECT * FROM products ORDER BY active DESC, sort, id')->fetchAll();
    $pColors = [];
    foreach (q('SELECT * FROM product_colors ORDER BY sort, id')->fetchAll() as $c) {
        $pColors[$c['product_id']][] = $c;
    }
    $pStock = [];
    foreach (q("SELECT * FROM stock_items WHERE category = 'Blank T-shirt' AND track = 1 AND active = 1")->fetchAll() as $it) {
        $pStock[$it['gsm'] . '|' . $it['product']][strtolower($it['color'])][strtoupper($it['size'])] = $it;
    }
    $sizeOrder = ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'];
    $seen = [];
    // For the "Add stock" form: each product's colours, sizes and current stock.
    $addData = [];
    foreach ($pProducts as $p) {
        if (!$p['active']) {
            continue;
        }
        $gl = '';
        foreach ($pGsms as $g) { if ((int)$g['id'] === (int)$p['gsm_id']) { $gl = $g['label']; } }
        $st = [];
        foreach ($pStock[$gl . '|' . $p['name']] ?? [] as $col => $bySize) {
            foreach ($bySize as $sz => $it) { $st[$col . '|' . $sz] = (float)$it['qty']; }
        }
        $addData[] = ['id' => (int)$p['id'], 'gsm' => $gl, 'name' => $p['name'],
            'colors' => array_map(fn($c) => ['name' => $c['name'], 'hex' => $c['hex']], array_values(array_filter($pColors[$p['id']] ?? [], fn($c) => $c['active']))),
            'sizes' => array_values(array_filter(array_map('trim', explode(',', (string)$p['sizes'])), 'strlen')), 'stock' => $st];
    }
    $pick = (int)($_GET['add'] ?? 0);
?>
  <?php if ($canStock): ?>
  <section class="panel add-stock" id="add-stock">
    <h2>＋ Add stock</h2>
    <?php if (!$addData): ?>
      <p class="muted">Add a product first (below), then its stock here.</p>
    <?php else: ?>
    <form method="post" id="addStockForm" data-products="<?= h(json_encode($addData, JSON_UNESCAPED_UNICODE)) ?>" data-color="<?= h((string)($_GET['color'] ?? '')) ?>">
      <?= csrf_field() ?><input type="hidden" name="do" value="add_bulk">
      <label class="field"><span class="lbl">1 · Product</span>
        <select name="pid" id="asProduct">
          <?php $lastG = null; foreach ($addData as $d): if ($d['gsm'] !== $lastG): ?><?= $lastG !== null ? '</optgroup>' : '' ?><optgroup label="<?= h($d['gsm']) ?>"><?php $lastG = $d['gsm']; endif; ?>
            <option value="<?= $d['id'] ?>" <?= $pick === $d['id'] ? 'selected' : '' ?>><?= h($d['gsm'] . ' · ' . $d['name']) ?></option>
          <?php endforeach; ?></optgroup>
        </select></label>
      <div class="field"><span class="lbl">2 · Colour</span><div class="chips" id="asColors"></div><input type="hidden" name="color" id="asColor"></div>
      <div class="field"><span class="lbl">3 · Quantity for each size <small class="muted">(leave empty to skip)</small></span><div class="size-qty" id="asSizes"></div></div>
      <div class="seg-toggle as-kind">
        <label><input type="radio" name="kind" value="purchase" checked><span>➕ Add to stock (received)</span></label>
        <label><input type="radio" name="kind" value="count"><span>🔢 Set count (counted)</span></label>
      </div>
      <details class="as-more"><summary class="muted small">Note / cost (optional)</summary>
        <div class="grid" style="margin-top:8px">
          <label class="field"><span class="lbl">Note</span><input name="note" placeholder="Supplier, bill no…"></label>
          <label class="field"><span class="lbl">Cost per pc ₹</span><input type="number" step="any" min="0" name="unit_cost"></label>
        </div>
      </details>
      <button class="btn primary as-save">💾 Save stock</button>
    </form>
    <?php endif; ?>
  </section>
  <?php endif; ?>
  <?php foreach ($pGsms as $g): $inG = array_filter($pProducts, fn($p) => (int)$p['gsm_id'] === (int)$g['id']); if (!$inG) continue; ?>
    <h3 class="perm-group"><?= h($g['label']) ?></h3>
    <?php foreach ($inG as $p):
        $key = $g['label'] . '|' . $p['name']; $seen[$key] = true; $stockOf = $pStock[$key] ?? [];
        $sizes = array_values(array_filter(array_map('trim', explode(',', (string)$p['sizes'])), 'strlen'));
        foreach ($stockOf as $byColor) { foreach (array_keys($byColor) as $sz) { if (!in_array($sz, array_map('strtoupper', $sizes), true)) $sizes[] = $sz; } }
        $cols = $pColors[$p['id']] ?? [];
        $total = 0; foreach ($stockOf as $byColor) { foreach ($byColor as $it) { $total += (float)$it['qty']; } } ?>
      <section class="panel prod-card <?= $p['active'] ? '' : 'inactive' ?>" id="p<?= (int)$p['id'] ?>">
        <div class="prod-head">
          <div><h2><?= h($p['name']) ?><?= $p['active'] ? '' : ' <span class="badge plain">Hidden</span>' ?></h2>
            <span class="muted small"><?= h($g['label']) ?> · <?= qty_fmt($total) ?> pcs in stock</span></div>
          <span class="price-tag <?= $p['price'] === null ? 'unset' : '' ?>"><?= $p['price'] === null ? 'No price set' : h(money((float)$p['price'], 0)) . ' / pc' ?></span>
        </div>
        <?php if ($cols && $sizes): ?>
        <div class="table-wrap"><table class="table compact stock-grid">
          <thead><tr><th>Colour</th><?php foreach ($sizes as $sz): ?><th class="num"><?= h($sz) ?></th><?php endforeach; ?><th class="num">Total</th></tr></thead>
          <tbody>
          <?php foreach ($cols as $c): $row = $stockOf[strtolower($c['name'])] ?? []; $rt = 0; ?>
            <tr class="<?= $c['active'] ? '' : 'inactive' ?>"><td><span class="dot" style="background: <?= h($c['hex'] ?: '#ccc') ?>"></span> <?= h($c['name']) ?></td>
              <?php foreach ($sizes as $sz): $it = $row[strtoupper($sz)] ?? null; $rt += $it ? (float)$it['qty'] : 0; ?>
                <td class="num"><?php if ($it): $lo = (float)$it['qty'] <= (float)$it['low_level']; ?><a href="stock.php?id=<?= (int)$it['id'] ?>" class="<?= $lo ? 'err-text' : '' ?>" title="<?= h($it['name']) ?>"><b><?= qty_fmt($it['qty']) ?></b></a><?php else: ?><span class="muted">—</span><?php endif; ?></td>
              <?php endforeach; ?>
              <td class="num"><b><?= qty_fmt($rt) ?></b></td></tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
        <?php else: ?><p class="muted small">Add colours and sizes to this product to keep its stock.</p><?php endif; ?>
        <div class="prod-tools">
          <?php if ($canStock && $cols && $sizes): ?><a class="btn small" href="stock.php?add=<?= (int)$p['id'] ?>#add-stock">＋ Add stock</a><?php endif; ?>
          <?php if ($canCat): ?>
          <details class="edit-box"><summary class="btn small">✎ Edit product, price &amp; colours</summary>
            <form method="post" action="admin/catalog.php" class="grid" style="margin-top:10px">
              <?= csrf_field() ?><input type="hidden" name="back" value="stock"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <label class="field"><span class="lbl">Name</span><input name="name" value="<?= h($p['name']) ?>" required></label>
              <label class="field"><span class="lbl">GSM</span><select name="gsm_id"><?php foreach ($pGsms as $g2): ?><option value="<?= (int)$g2['id'] ?>" <?= $g2['id'] == $p['gsm_id'] ? 'selected' : '' ?>><?= h($g2['label']) ?></option><?php endforeach; ?></select></label>
              <label class="field"><span class="lbl">Selling price ₹ per piece <small class="muted">(before GST)</small></span><input name="price" type="number" step="any" min="0" value="<?= h($p['price'] === null ? '' : rtrim(rtrim($p['price'], '0'), '.')) ?>" placeholder="Not set"></label>
              <label class="field"><span class="lbl">Sizes (comma separated)</span><input name="sizes" value="<?= h($p['sizes']) ?>"></label>
              <label class="field"><span class="lbl">Display order</span><input name="sort" type="number" value="<?= (int)$p['sort'] ?>"></label>
              <label class="field check"><input type="checkbox" name="active" value="1" <?= $p['active'] ? 'checked' : '' ?>> Show in the order form</label>
              <div class="field row-btns"><button class="btn primary" name="do" value="product_save">Save product</button>
                <button class="btn danger" name="do" value="product_delete" formnovalidate onclick="return confirm('Delete this product and its colours? Orders and stock items are kept.')">Delete</button></div>
            </form>
            <h4>Colours</h4>
            <div class="color-rows">
            <?php foreach ($cols as $c): ?>
              <form method="post" action="admin/catalog.php" class="color-row <?= $c['active'] ? '' : 'inactive' ?>">
                <?= csrf_field() ?><input type="hidden" name="back" value="stock"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
                <input type="color" name="hex" value="<?= h($c['hex'] ?: '#cccccc') ?>" aria-label="Colour swatch">
                <input name="name" value="<?= h($c['name']) ?>" aria-label="Colour name">
                <label class="check small"><input type="checkbox" name="active" value="1" <?= $c['active'] ? 'checked' : '' ?>> Available</label>
                <button class="btn small" name="do" value="color_save">Save</button>
                <button class="btn small ghost" name="do" value="color_delete" formnovalidate onclick="return confirm('Remove this colour? Old orders keep their colour name.')">✕</button>
              </form>
            <?php endforeach; ?>
            </div>
            <form method="post" action="admin/catalog.php" class="inline-add">
              <?= csrf_field() ?><input type="hidden" name="back" value="stock"><input type="hidden" name="product_id" value="<?= (int)$p['id'] ?>">
              <input type="color" name="hex" value="#cccccc" aria-label="Swatch">
              <input name="name" placeholder="New colour (or several: Black, White)" required>
              <button class="btn" name="do" value="color_add">+ Add colour</button>
            </form>
          </details>
          <?php endif; ?>
        </div>
      </section>
    <?php endforeach; ?>
  <?php endforeach; ?>

  <?php $loose = array_filter(q("SELECT * FROM stock_items WHERE category = 'Blank T-shirt' AND track = 1 AND active = 1 ORDER BY name")->fetchAll(), fn($it) => !isset($seen[$it['gsm'] . '|' . $it['product']])); ?>
  <?php if ($loose): ?>
  <section class="panel">
    <h2>T-shirt stock that is not a catalog product <small class="muted">(<?= count($loose) ?>)</small></h2>
    <p class="hint">Only your catalog products are used on orders and bills. These old stock items can be hidden.</p>
    <?php if (is_admin()): ?><form method="post" onsubmit="return confirm('Hide these <?= count($loose) ?> stock items? Their history is kept.');"><?= csrf_field() ?><input type="hidden" name="do" value="hide_loose"><button class="btn small danger">Hide all <?= count($loose) ?></button></form><?php endif; ?>
    <div class="table-wrap"><table class="table compact"><tbody>
      <?php foreach ($loose as $it): ?><tr onclick="location='stock.php?id=<?= (int)$it['id'] ?>'"><td><a href="stock.php?id=<?= (int)$it['id'] ?>"><?= h($it['name']) ?></a></td><td class="num"><b><?= qty_fmt($it['qty']) ?></b> pcs</td></tr><?php endforeach; ?>
    </tbody></table></div>
  </section>
  <?php endif; ?>

  <?php if ($canCat): ?>
  <section class="panel" id="add-product">
    <h2>＋ Add a new product</h2>
    <form method="post" action="admin/catalog.php" class="grid">
      <?= csrf_field() ?><input type="hidden" name="back" value="stock">
      <label class="field"><span class="lbl">GSM</span><select name="gsm_id"><?php foreach ($pGsms as $g): ?><option value="<?= (int)$g['id'] ?>"><?= h($g['label']) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span class="lbl">…or a new GSM</span><input name="gsm_new" placeholder="e.g. 220 GSM"></label>
      <label class="field"><span class="lbl">Product name</span><input name="name" required placeholder="e.g. Oversized Fit - French Terry"></label>
      <label class="field"><span class="lbl">Selling price ₹ per piece <small class="muted">(before GST)</small></span><input name="price" type="number" step="any" min="0" placeholder="e.g. 250"></label>
      <label class="field"><span class="lbl">Sizes</span><input name="sizes" value="XS, S, M, L, XL, XXL"></label>
      <label class="field"><span class="lbl">Colours</span><input name="colors" placeholder="Black, White, Navy Blue"></label>
      <div class="field"><span class="lbl">&nbsp;</span><button class="btn primary" name="do" value="product_add">＋ Add product</button></div>
    </form>
  </section>
  <?php endif; ?>
<?php else: ?>
<details class="panel edit-box" style="margin-top:14px" <?= !$tot['n'] ? 'open' : '' ?>>
  <summary><b>+ New stock item</b></summary>
  <form method="post" class="grid" style="margin-top:12px" id="newStock">
    <?= csrf_field() ?><input type="hidden" name="do" value="create">
    <label class="field"><span class="lbl">Type</span><select name="category" id="nsCat"><?php foreach (STOCK_CATEGORIES as $c): ?><option><?= h($c) ?></option><?php endforeach; ?></select></label>
    <label class="field ns-blank"><span class="lbl">GSM · product</span><select name="gp" id="nsGp"><?php foreach ($gp as $k => $p): ?><option value="<?= h($k) ?>"><?= h($p['label']) ?></option><?php endforeach; ?></select></label>
    <label class="field ns-blank"><span class="lbl">Colour</span><input name="color" id="nsColor" list="nsColors" autocomplete="off"><datalist id="nsColors"></datalist></label>
    <div class="field full ns-blank"><span class="lbl">Sizes (one stock item per size)</span><div class="chips" id="nsSizes"></div></div>
    <label class="field ns-other" hidden><span class="lbl">Name</span><input name="name" placeholder="e.g. DTF film 24&quot; roll, Poly bags 10×12"></label>
    <label class="field"><span class="lbl">Opening stock (each)</span><input type="number" step="any" min="0" name="opening" value="0"></label>
    <?php stock_common_fields($defaults); ?>
    <div class="field"><span class="lbl">&nbsp;</span><button class="btn primary">Add to stock</button></div>
  </form>
</details>

<form class="filters" method="get">
  <input type="search" name="q" value="<?= h($s) ?>" placeholder="Search colour, size, product, name…">
  <select name="cat"><option value="">All types</option><?php foreach (STOCK_CATEGORIES as $c): ?><option <?= $cat === $c ? 'selected' : '' ?>><?= h($c) ?></option><?php endforeach; ?></select>
  <button class="btn">Search</button>
  <?php if ($s !== '' || $cat !== '' || $low): ?><a class="btn ghost" href="stock.php">Clear</a><?php endif; ?>
</form>

<?php if (!$items): ?>
  <p class="empty"><?= $low ? '👍 Nothing is low on stock.' : 'No stock items yet — add your blank T-shirts above.' ?></p>
<?php else: ?>
<section class="panel">
  <div class="table-wrap"><table class="table compact stock-table">
    <thead><tr><th>Item</th><th class="num">In stock</th><th class="num">Price</th><th>Quick add</th></tr></thead>
    <tbody>
    <?php foreach ($items as $it): $isLow = $it['track'] && (float)$it['qty'] <= (float)$it['low_level']; ?>
      <tr class="<?= $it['active'] ? '' : 'inactive' ?>">
        <td><a href="stock.php?id=<?= (int)$it['id'] ?>"><b><?= h($it['name'] !== '' ? $it['name'] : stock_name($it)) ?></b></a><br><small class="muted"><?= h($it['category']) ?><?= $it['sku'] !== '' ? ' · ' . h($it['sku']) : '' ?></small></td>
        <td class="num"><?php if (!$it['track']): ?><small class="muted">Service<br>no stock</small><?php else: ?><b class="<?= $isLow ? 'err-text' : '' ?>"><?= qty_fmt($it['qty']) ?></b> <small class="muted"><?= h($it['unit']) ?></small><?php if ($isLow): ?><br><small class="err-text"><?= (float)$it['qty'] <= 0 ? 'Out of stock' : 'Low' ?></small><?php endif; ?><?php endif; ?></td>
        <td class="num"><?= $it['sale_price'] > 0 ? h(money((float)$it['sale_price'])) : '<span class="muted">—</span>' ?></td>
        <td><?php if ($it['track']): ?>
          <form method="post" class="quick-add">
            <?= csrf_field() ?><input type="hidden" name="do" value="move"><input type="hidden" name="kind" value="purchase"><input type="hidden" name="stock_id" value="<?= (int)$it['id'] ?>"><input type="hidden" name="q" value="<?= h($s) ?>">
            <input type="number" step="any" min="0" name="qty" placeholder="+ qty" required><button class="btn small">Add</button>
          </form><?php endif; ?>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>
<?php endif; /* list view */ ?>
<script>
// Add stock: product → colour chips → a quantity box per size (with what is in stock now).
(function () {
  var form = document.getElementById('addStockForm');
  if (!form) return;
  var data = JSON.parse(form.dataset.products || '[]'), sel = document.getElementById('asProduct');
  var colorsBox = document.getElementById('asColors'), sizesBox = document.getElementById('asSizes'), colorIn = document.getElementById('asColor');
  var esc = function (v) { return String(v).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
  function product() { return data.find(function (d) { return String(d.id) === sel.value; }) || data[0]; }
  function drawSizes() {
    var p = product(), col = colorIn.value.toLowerCase();
    sizesBox.innerHTML = p.sizes.map(function (sz) {
      var have = p.stock[col + '|' + sz.toUpperCase()];
      return '<label class="sq"><b>' + esc(sz) + '</b><input type="number" inputmode="numeric" min="0" step="any" name="qty[' + esc(sz) + ']" placeholder="0">'
        + '<small class="' + (have !== undefined && have <= 0 ? 'err-text' : 'muted') + '">' + (have === undefined ? 'none yet' : have + ' now') + '</small></label>';
    }).join('') || '<span class="muted small">This product has no sizes — add them with “Edit product”.</span>';
  }
  function pickColor(name) {
    colorIn.value = name;
    colorsBox.querySelectorAll('.chip').forEach(function (c) { c.classList.toggle('on', c.dataset.v === name); });
    drawSizes();
  }
  function drawColors(want) {
    var p = product();
    colorsBox.innerHTML = p.colors.map(function (c) {
      return '<button type="button" class="chip" data-v="' + esc(c.name) + '"><span class="dot" style="background:' + esc(c.hex || '#ccc') + '"></span>' + esc(c.name) + '</button>';
    }).join('') || '<span class="muted small">No colours — add them with “Edit product”.</span>';
    var first = p.colors.find(function (c) { return c.name === want; }) || p.colors[0];
    pickColor(first ? first.name : '');
  }
  colorsBox.addEventListener('click', function (e) { var c = e.target.closest('.chip'); if (c) pickColor(c.dataset.v); });
  sel.addEventListener('change', function () { drawColors(''); });
  form.addEventListener('submit', function (e) {
    if (![].some.call(sizesBox.querySelectorAll('input'), function (i) { return i.value.trim() !== ''; })) { e.preventDefault(); alert('Type a quantity under at least one size.'); }
  });
  drawColors(form.dataset.color);
})();
(function () {
  var GP = <?= json_encode($gp, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  var cat = document.getElementById('nsCat'), gp = document.getElementById('nsGp');
  if (!cat) return;
  function sync() {
    var blank = cat.value === 'Blank T-shirt';
    document.querySelectorAll('.ns-blank').forEach(function (e) { e.hidden = !blank; });
    document.querySelectorAll('.ns-other').forEach(function (e) { e.hidden = blank; });
    var p = GP[gp.value] || { sizes: [], colors: [] };
    document.getElementById('nsColors').innerHTML = p.colors.map(function (c) { return '<option value="' + c.replace(/"/g, '&quot;') + '">'; }).join('');
    document.getElementById('nsSizes').innerHTML = p.sizes.map(function (s) {
      return '<label class="chip-check"><input type="checkbox" name="sizes[]" value="' + s.replace(/"/g, '&quot;') + '" checked> ' + s + '</label>';
    }).join('') || '<span class="muted small">No sizes set for this product in Catalog.</span>';
  }
  cat.addEventListener('change', sync); gp.addEventListener('change', sync); sync();
})();
</script>
<?php require __DIR__ . '/inc/footer.php'; ?>
