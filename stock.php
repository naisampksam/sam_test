<?php
// Stock: blank T-shirts (by GSM / product / colour / size) and any other material. Stock goes down
// automatically when a tax invoice is made (see inc/billing.php); here it is added, counted and corrected.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/orders.php';
require __DIR__ . '/inc/billing.php';

require_login();
if (!cap('stock')) {
    http_response_code(403);
    exit('You are not allowed to manage stock.');
}
$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
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
                $row = $common + ['name' => trim("$gsm $product $color $size"), 'category' => $cat, 'gsm' => $gsm, 'product' => $product,
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

$pageTitle = 'Stock';
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
  <div><h1>Stock</h1><p class="muted small">Goes down by itself when a tax invoice is made. Add new stock, returns and stock counts here.</p></div>
  <div class="actions"><a class="btn" href="?low=1">⚠ Low stock</a><?php if (is_admin()): ?> <a class="btn" href="admin/import.php">⬆ Import from Vyapar</a><?php endif; ?></div>
</div>

<div class="stats">
  <div class="stat"><span class="stat-label">Stock items</span><span class="stat-num"><?= (int)$tot['n'] ?></span></div>
  <div class="stat"><span class="stat-label">Blank T-shirts</span><span class="stat-num"><?= qty_fmt($tot['blanks']) ?></span><span class="stat-sub">pcs in stock</span></div>
  <div class="stat"><span class="stat-label">Stock value</span><span class="stat-num"><?= h(money((float)$tot['value'], 0)) ?></span><span class="stat-sub">at cost price</span></div>
  <a class="stat <?= $tot['low'] ? 'stat-warn' : '' ?>" href="?low=1"><span class="stat-label">Low / out of stock</span><span class="stat-num"><?= (int)$tot['low'] ?></span><span class="stat-sub">tap to see</span></a>
</div>

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
<script>
(function () {
  var GP = <?= json_encode($gp, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
  var cat = document.getElementById('nsCat'), gp = document.getElementById('nsGp');
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
