<?php
// Production estimate: works out fabric use and per-piece cost for custom production from the
// fabric (GSM, roll width, open / tube), the size chart and the making costs, then adds a buffer and profit.
// The sums run live in assets/estimate.js; saved estimates keep their inputs as JSON.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/estimates.php';

require_login();
if (!cap('estimate')) {
    http_response_code(403);
    exit('You are not allowed to make estimates.');
}

// The estimates table arrives with this update; create it here too, so the page works even before install.php is run.
try {
    db()->query('SELECT 1 FROM estimates LIMIT 1');
} catch (PDOException $e) {
    require_once __DIR__ . '/inc/schema.php';
    foreach (schema_sql() as $sql) {
        if (str_contains($sql, 'EXISTS estimates')) {
            db()->exec($sql);
        }
    }
}

$id = (int)($_GET['id'] ?? 0);
// What this person may see / change: none, view or edit per cost group.
$ep = [];
foreach (['fabric', 'making', 'breakdown', 'cost', 'margin', 'price'] as $g) {
    $ep[$g] = est_perm($g);
}
/** Saved values each cost group owns (top-level keys, and per-size override keys). */
const EST_GROUP_KEYS = [
    'fabric' => ['gsm', 'fabric_form', 'roll_width', 'edge_waste', 'fabric_price', 'wastage', 'rib_price'],
    'making' => ['c_cmt', 'c_acc', 'acc_label', 'c_print', 'c_embroidery', 'c_labels', 'c_trims', 'c_finishing', 'c_packing', 'c_other', 'fixed', 'extra', 'rib_g'],
    'breakdown' => [],
    'cost' => [],
    'margin' => ['buffer', 'profit'],
    'price' => ['gst'],
];
const EST_ROW_KEYS = ['fabric' => 'g_ov', 'cost' => 'cost_ov', 'price' => 'price_ov'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    if ($do === 'delete' && $id) {
        q('DELETE FROM estimates WHERE id = ?', [$id]);
        flash('Estimate deleted.');
        redirect('estimate.php');
    }
    if ($do === 'save') {
        $data = json_decode((string)($_POST['data'] ?? ''), true);
        if (!is_array($data)) {
            flash('Could not read the estimate. Please try again.', 'err');
            redirect('estimate.php' . ($id ? '?id=' . $id : ''));
        }
        // Values this person may not change keep what was saved before, else the product's current values (admin rates).
        $old = $id ? json_decode((string)q('SELECT data FROM estimates WHERE id = ?', [$id])->fetchColumn(), true) : null;
        $old = is_array($old) ? $old : [];
        $prod = est_product_for($data);
        if ($prod) {
            $data['product'] = $prod['key'];
            $data['style'] = $prod['base'];
        }
        foreach ($ep as $g => $lv) {
            if ($lv === 'edit') {
                continue;
            }
            foreach (EST_GROUP_KEYS[$g] as $k) {
                if (array_key_exists($k, $old) && ($old['product'] ?? $old['style'] ?? '') === ($data['product'] ?? '')) {
                    $data[$k] = $old[$k];
                } elseif ($prod && array_key_exists($k, $prod['defaults'])) {
                    $data[$k] = $prod['defaults'][$k];
                } else {
                    unset($data[$k]);
                }
            }
            if (isset(EST_ROW_KEYS[$g]) && is_array($data['sizes'] ?? null)) {
                foreach ($data['sizes'] as $i => &$sz) {
                    $sz[EST_ROW_KEYS[$g]] = $old['sizes'][$i][EST_ROW_KEYS[$g]] ?? '';
                }
                unset($sz);
            }
        }
        $row = [
            'name' => mb_substr(trim((string)($_POST['name'] ?? '')), 0, 150) ?: 'Estimate ' . date('d M'),
            'customer' => mb_substr(trim((string)($_POST['customer'] ?? '')), 0, 150),
            'data' => json_encode($data, JSON_UNESCAPED_UNICODE),
            'total_qty' => max(0, (int)($_POST['total_qty'] ?? 0)),
            'price_per_pc' => round((float)($_POST['price_per_pc'] ?? 0), 2),
            'total_amount' => round((float)($_POST['total_amount'] ?? 0), 2),
        ];
        if (!empty($_POST['as_new'])) {
            $id = 0;
        }
        if ($id && q('SELECT id FROM estimates WHERE id = ?', [$id])->fetch()) {
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($row)));
            q("UPDATE estimates SET $sets, updated_at = ? WHERE id = ?", array_merge(array_values($row), [now(), $id]));
        } else {
            $row += ['created_by' => current_user()['id'], 'created_at' => now()];
            q('INSERT INTO estimates (' . implode(',', array_keys($row)) . ') VALUES (' . rtrim(str_repeat('?,', count($row)), ',') . ')', array_values($row));
            $id = (int)db()->lastInsertId();
        }
        flash('Estimate saved.');
        redirect('estimate.php?id=' . $id);
    }
}

$est = $id ? q('SELECT * FROM estimates WHERE id = ?', [$id])->fetch() : null;
if ($id && !$est) {
    flash('Estimate not found.', 'err');
    redirect('estimate.php');
}
$saved = q('SELECT id, name, customer, total_qty, price_per_pc, total_amount, created_at, updated_at FROM estimates ORDER BY COALESCE(updated_at, created_at) DESC LIMIT 100')->fetchAll();
$company = setting('company_name', 'Looma Apparels');
// Products staff can pick (plus the one this estimate uses, even if the admin hid it since).
$estProducts = est_products(true);
if ($est && ($cur = est_product_for(json_decode($est['data'], true) ?: []))) {
    $estProducts[$cur['key']] ??= $cur;
}
if (!$estProducts) {
    $estProducts = est_products();
}

$pageTitle = $est ? $est['name'] : 'Estimate';
$active = 'estimate';
require __DIR__ . '/inc/header.php';

/** One number input bound to the estimate data. */
function num_field(string $key, string $label, string $hint = '', string $step = 'any', string $suffix = ''): void
{
    echo '<label class="field"><span class="lbl">' . h($label) . '</span><div class="est-input">'
        . '<input type="number" inputmode="decimal" step="' . h($step) . '" min="0" data-k="' . h($key) . '">'
        . ($suffix !== '' ? '<span class="est-suffix">' . h($suffix) . '</span>' : '') . '</div>'
        . ($hint !== '' ? '<small class="hint">' . h($hint) . '</small>' : '') . '</label>';
}
?>
<div class="page-head">
  <div>
    <h1><?= $est ? h($est['name']) : 'Production estimate' ?></h1>
    <p class="muted small">Per-piece cost for custom production from the fabric, size chart and making costs<?= $est ? ' · saved ' . h(fmt_date($est['updated_at'] ?: $est['created_at'], true)) : '' ?></p>
  </div>
  <div class="actions">
    <?php if ($est): ?><a class="btn" href="estimate.php">+ New estimate</a><?php endif; ?>
    <?php if (is_admin()): ?><a class="btn" href="admin/estimate_products.php">⚙ Products &amp; making costs</a><?php endif; ?>
    <?php if ($ep['price'] !== 'none'): ?><button type="button" class="btn" data-print-quote>🖨 Print quote</button><?php endif; ?>
  </div>
</div>

<form method="post" id="estForm" class="est-form" data-company="<?= h($company) ?>" data-perm="<?= h(json_encode($ep)) ?>" data-products="<?= h(json_encode(array_values(array_map(fn($p) => ['key' => $p['key'], 'name' => $p['name'], 'base' => $p['base'], 'defaults' => $p['defaults']], $estProducts)), JSON_UNESCAPED_UNICODE)) ?>">
  <?= csrf_field() ?>
  <input type="hidden" name="do" value="save">
  <input type="hidden" name="data" id="estData">
  <input type="hidden" name="total_qty" id="estQty">
  <input type="hidden" name="price_per_pc" id="estPrice">
  <input type="hidden" name="total_amount" id="estTotal">
  <script type="application/json" id="estSaved"><?= $est ? json_encode(json_decode($est['data'], true), JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE) : 'null' ?></script>

  <section class="panel">
    <h2>Estimate</h2>
    <div class="grid">
      <label class="field"><span class="lbl">Name</span><input name="name" value="<?= h($est['name'] ?? '') ?>" placeholder="e.g. College fest tees · 180 GSM"></label>
      <label class="field"><span class="lbl">Customer</span><input name="customer" value="<?= h($est['customer'] ?? '') ?>" placeholder="Customer ID or name"></label>
      <label class="field"><span class="lbl">Product</span>
        <select data-k="product">
          <?php foreach ($estProducts as $pk => $pp): ?><option value="<?= h($pk) ?>"><?= h($pp['name']) ?><?= $pp['active'] ? '' : ' (hidden)' ?></option><?php endforeach; ?>
        </select>
        <small class="hint" id="productHint"></small>
      </label>
    </div>
  </section>

  <section class="panel" data-pgroup="fabric" <?= $ep['fabric'] === 'none' ? 'hidden' : '' ?>>
    <h2>Fabric</h2>
    <div class="grid">
      <?php num_field('gsm', 'Fabric GSM', 'Weight of 1 m² of fabric', '1', 'gsm'); ?>
      <label class="field"><span class="lbl">Fabric comes as</span>
        <div class="seg-toggle est-seg" role="radiogroup">
          <label><input type="radio" name="fabric_form" value="open" data-k="fabric_form"><span>Open width</span></label>
          <label><input type="radio" name="fabric_form" value="tube" data-k="fabric_form"><span>Tube / roll</span></label>
        </div>
        <small class="hint" id="formHint"></small>
      </label>
      <?php num_field('roll_width', 'Roll width', 'As measured on the roll (tube: folded width)', 'any', 'in'); ?>
      <?php num_field('edge_waste', 'Edge waste', 'Selvedge / uneven edge not usable', 'any', 'in'); ?>
      <?php num_field('fabric_price', 'Fabric price', '', 'any', '₹/kg'); ?>
      <?php num_field('wastage', 'Cutting wastage', 'End bits, marker loss, damages', 'any', '%'); ?>
      <?php num_field('rib_price', 'Rib price', '', 'any', '₹/kg'); ?>
    </div>
  </section>

  <section class="panel">
    <div class="item-head"><h2>Size chart &amp; quantity</h2>
      <div class="est-presets"><button type="button" class="btn small" data-preset>↺ Standard sizes</button></div>
    </div>
    <p class="muted small" id="sizeHelp"></p>
    <div class="table-wrap">
      <table class="table compact est-sizes">
        <thead id="sizeHead"></thead>
        <tbody id="sizeRows"></tbody>
        <tfoot><tr><th>Total</th><th class="num" id="footQty">0</th><th id="footGap"></th><th class="num col-fabric" id="footG"></th><th class="col-fabric"></th><th class="col-cost"></th><th class="col-price"></th><th class="num col-price" id="footAmt"></th><th></th></tr></tfoot>
      </table>
    </div>
    <button type="button" class="btn small" data-add-size>+ Add size</button>
    <details class="est-allow">
      <summary class="muted small">Cutting allowances (inches)</summary>
      <div class="grid">
        <?php num_field('seam_w', 'Width allowance per panel', 'Side seams (added to each body / leg panel)', 'any', 'in'); ?>
        <?php num_field('len_allow', 'Length allowance per panel', 'Shoulder seam + hem, or waist + hem', 'any', 'in'); ?>
        <?php num_field('slv_len_allow', 'Sleeve length allowance', 'Sleeve cap + hem', 'any', 'in'); ?>
        <?php num_field('slv_w_allow', 'Sleeve width allowance', 'Underarm seam', 'any', 'in'); ?>
      </div>
    </details>
  </section>

  <section class="panel" data-pgroup="making" <?= $ep['making'] === 'none' ? 'hidden' : '' ?>>
    <h2>Making costs <small class="muted">per piece<?= is_admin() ? ' · defaults from Products &amp; making costs' : ' · set by admin' ?></small></h2>
    <div class="grid">
      <?php num_field('c_cmt', 'Cutting & stitching', 'Changes with the product', 'any', '₹'); ?>
      <?php num_field('c_acc', 'Product accessories', '', 'any', '₹'); ?>
      <?php num_field('rib_g', 'Rib fabric', 'Neck / cuff / waistband rib (priced at the rib ₹/kg)', 'any', 'g/pc'); ?>
      <?php num_field('c_print', 'Printing (DTF / screen)', '', 'any', '₹'); ?>
      <?php num_field('c_embroidery', 'Embroidery', '', 'any', '₹'); ?>
      <?php num_field('c_labels', 'Neck & size labels', '', 'any', '₹'); ?>
      <?php num_field('c_trims', 'Thread & trims', '', 'any', '₹'); ?>
      <?php num_field('c_finishing', 'Ironing & finishing', '', 'any', '₹'); ?>
      <?php num_field('c_packing', 'Poly bag & packing', '', 'any', '₹'); ?>
      <?php num_field('c_other', 'Transport / other', '', 'any', '₹'); ?>
      <?php num_field('fixed', 'One-time costs (whole order)', 'Sampling, screens, pattern… shared across all pieces', 'any', '₹'); ?>
    </div>
    <div id="extraCosts" class="extra-costs"></div>
    <button type="button" class="btn small" data-add-cost>+ Add another cost</button>
  </section>

  <section class="panel" <?= $ep['margin'] === 'none' && $ep['price'] === 'none' ? 'hidden' : '' ?>>
    <h2><?= $ep['margin'] === 'none' ? 'GST' : 'Margin' ?></h2>
    <div class="grid">
      <div class="pg-contents" data-pgroup="margin" <?= $ep['margin'] === 'none' ? 'hidden' : '' ?>>
        <?php num_field('buffer', 'Buffer', 'Added to the cost for price changes & mistakes', 'any', '%'); ?>
        <?php num_field('profit', 'Profit', '', 'any', '₹/pc'); ?>
      </div>
      <div class="pg-contents" data-pgroup="price" <?= $ep['price'] === 'none' ? 'hidden' : '' ?>>
        <?php num_field('gst', 'GST', 'Shown separately on the quote', 'any', '%'); ?>
      </div>
    </div>
  </section>

  <section class="panel est-result" id="estResult">
    <h2>Result</h2>
    <div class="stats">
      <div class="stat" data-pgroup="price" <?= $ep['price'] === 'none' ? 'hidden' : '' ?>><span class="stat-label">Quote price</span><span class="stat-num" id="rPrice">–</span><span class="stat-sub" id="rPriceSub">per piece (average)</span></div>
      <div class="stat" data-pgroup="price" <?= $ep['price'] === 'none' ? 'hidden' : '' ?>><span class="stat-label">Order total</span><span class="stat-num" id="rTotal">–</span><span class="stat-sub" id="rTotalSub"></span></div>
      <div class="stat" data-pgroup="cost" <?= $ep['cost'] === 'none' ? 'hidden' : '' ?>><span class="stat-label">Cost per piece</span><span class="stat-num" id="rCost">–</span><span class="stat-sub" id="rCostSub">before buffer &amp; profit</span></div>
      <div class="stat" data-pgroup="fabric" <?= $ep['fabric'] === 'none' ? 'hidden' : '' ?>><span class="stat-label">Fabric needed</span><span class="stat-num" id="rFabric">–</span><span class="stat-sub" id="rFabricSub"></span></div>
      <div class="stat" data-pgroup="margin" data-needs="cost"><span class="stat-label">Your profit</span><span class="stat-num" id="rProfit">–</span><span class="stat-sub" id="rProfitSub"></span></div>
    </div>
    <div data-pgroup="fabric" <?= $ep['fabric'] === 'none' ? 'hidden' : '' ?>>
      <h3 class="est-h3">Cut pieces &amp; fabric use <small class="muted" id="pcSize"></small></h3>
      <div class="table-wrap"><table class="table compact est-pieces"><thead><tr><th>Piece</th><th class="num">Pcs</th><th class="num">Cut size (W × L)</th><th class="num">Across roll</th><th class="num">Fabric length</th></tr></thead><tbody id="pieces"></tbody></table></div>
    </div>
    <div data-pgroup="breakdown" <?= $ep['breakdown'] === 'none' ? 'hidden' : '' ?>>
      <h3 class="est-h3">Cost of one piece <small class="muted" id="bdSize"></small></h3>
      <table class="table compact est-breakdown"><tbody id="breakdown"></tbody></table>
    </div>
    <p class="hint" id="estWarn"></p>
  </section>

  <div class="sticky-actions">
    <span class="total-pcs" id="estBar"></span>
    <button class="btn primary">💾 <?= $est ? 'Save changes' : 'Save estimate' ?></button>
    <?php if ($est): ?><button class="btn" name="as_new" value="1">Save as new</button><?php endif; ?>
  </div>
</form>

<?php if ($est): ?>
<form method="post" class="danger-zone" onsubmit="return confirm('Delete this estimate?');">
  <?= csrf_field() ?><input type="hidden" name="do" value="delete">
  <button class="btn ghost danger">🗑 Delete estimate</button>
</form>
<?php endif; ?>

<?php if ($saved): ?>
<section class="panel est-saved">
  <h2>Saved estimates</h2>
  <div class="table-wrap"><table class="table compact">
    <thead><tr><th>Name</th><th>Customer</th><th class="num">Pcs</th><?php if ($ep['price'] !== 'none'): ?><th class="num">₹/pc</th><th class="num">Total</th><?php endif; ?><th>Saved</th></tr></thead>
    <tbody>
    <?php foreach ($saved as $s): $url = 'estimate.php?id=' . (int)$s['id']; ?>
      <tr onclick="location='<?= h($url) ?>'" class="<?= $est && (int)$est['id'] === (int)$s['id'] ? 'on' : '' ?>">
        <td><a href="<?= h($url) ?>"><b><?= h($s['name']) ?></b></a></td>
        <td><?= h($s['customer']) ?></td>
        <td class="num"><?= (int)$s['total_qty'] ?></td>
        <?php if ($ep['price'] !== 'none'): ?>
        <td class="num">₹<?= h(inr_number((float)$s['price_per_pc'], 2)) ?></td>
        <td class="num">₹<?= h(inr_number((float)$s['total_amount'], 0)) ?></td>
        <?php endif; ?>
        <td><?= h(fmt_date($s['updated_at'] ?: $s['created_at'])) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>

<div id="quoteSheet" class="quote-sheet" hidden></div>
<script src="<?= h(asset('assets/estimate.js')) ?>"></script>
<?php require __DIR__ . '/inc/footer.php'; ?>
