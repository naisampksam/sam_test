<?php
// Production estimate: works out fabric use and per-piece cost for custom production from the
// fabric (GSM, roll width, open / tube), the size chart and the making costs, then adds a buffer and profit.
// The sums run live in assets/estimate.js; saved estimates keep their inputs as JSON.
require __DIR__ . '/inc/bootstrap.php';

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
    <button type="button" class="btn" data-print-quote>🖨 Print quote</button>
  </div>
</div>

<form method="post" id="estForm" class="est-form" data-company="<?= h($company) ?>">
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
        <select data-k="style">
          <option value="regular">Round-neck T-shirt · regular fit</option>
          <option value="oversized">Round-neck T-shirt · oversized</option>
          <option value="polo">Polo T-shirt</option>
          <option value="other">Other</option>
        </select>
      </label>
    </div>
  </section>

  <section class="panel">
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
      <?php num_field('rib_g', 'Neck rib / collar', 'Fabric weight per piece', 'any', 'g/pc'); ?>
      <?php num_field('rib_price', 'Rib price', '', 'any', '₹/kg'); ?>
    </div>
  </section>

  <section class="panel">
    <div class="item-head"><h2>Size chart &amp; quantity</h2>
      <div class="est-presets"><span class="muted small">Fill sizes:</span>
        <button type="button" class="btn small" data-preset="regular">Regular</button>
        <button type="button" class="btn small" data-preset="oversized">Oversized</button>
      </div>
    </div>
    <p class="muted small">Measurements in inches. Chest = full chest round (body width × 2). Sleeve width = flat width at the armhole.</p>
    <div class="table-wrap">
      <table class="table compact est-sizes">
        <thead><tr><th>Size</th><th class="num">Qty</th><th class="num">Chest</th><th class="num">Length</th><th class="num">Sleeve</th><th class="num">Sleeve width</th>
          <th class="num">Fabric g/pc</th><th class="num">Cost/pc</th><th class="num">Price/pc</th><th class="num">Amount</th><th></th></tr></thead>
        <tbody id="sizeRows"></tbody>
        <tfoot><tr><th>Total</th><th class="num" id="footQty">0</th><th colspan="4"></th><th class="num" id="footG"></th><th></th><th></th><th class="num" id="footAmt"></th><th></th></tr></tfoot>
      </table>
    </div>
    <button type="button" class="btn small" data-add-size>+ Add size</button>
    <details class="est-allow">
      <summary class="muted small">Cutting allowances (inches)</summary>
      <div class="grid">
        <?php num_field('seam_w', 'Body width allowance', 'Side seams, added to half chest', 'any', 'in'); ?>
        <?php num_field('len_allow', 'Body length allowance', 'Shoulder seam + bottom hem', 'any', 'in'); ?>
        <?php num_field('slv_len_allow', 'Sleeve length allowance', 'Sleeve cap + hem', 'any', 'in'); ?>
        <?php num_field('slv_w_allow', 'Sleeve width allowance', 'Underarm seam', 'any', 'in'); ?>
      </div>
    </details>
  </section>

  <section class="panel">
    <h2>Making costs <small class="muted">per piece</small></h2>
    <div class="grid">
      <?php num_field('c_cmt', 'Cutting & stitching', '', 'any', '₹'); ?>
      <?php num_field('c_print', 'Printing (DTF / screen)', '', 'any', '₹'); ?>
      <?php num_field('c_embroidery', 'Embroidery', '', 'any', '₹'); ?>
      <?php num_field('c_labels', 'Neck & size labels', '', 'any', '₹'); ?>
      <?php num_field('c_trims', 'Thread & trims', '', 'any', '₹'); ?>
      <?php num_field('c_finishing', 'Ironing & finishing', '', 'any', '₹'); ?>
      <?php num_field('c_packing', 'Poly bag & packing', '', 'any', '₹'); ?>
      <?php num_field('c_other', 'Transport / other', '', 'any', '₹'); ?>
      <?php num_field('fixed', 'One-time costs (whole order)', 'Sampling, screens, pattern… shared across all pieces', 'any', '₹'); ?>
    </div>
  </section>

  <section class="panel">
    <h2>Margin</h2>
    <div class="grid">
      <?php num_field('buffer', 'Buffer', 'Added to the cost for price changes & mistakes', 'any', '%'); ?>
      <?php num_field('profit', 'Profit', '', 'any', '₹/pc'); ?>
      <?php num_field('gst', 'GST', 'Shown separately on the quote', 'any', '%'); ?>
    </div>
  </section>

  <section class="panel est-result" id="estResult">
    <h2>Result</h2>
    <div class="stats">
      <div class="stat"><span class="stat-label">Quote price</span><span class="stat-num" id="rPrice">–</span><span class="stat-sub" id="rPriceSub">per piece (average)</span></div>
      <div class="stat"><span class="stat-label">Order total</span><span class="stat-num" id="rTotal">–</span><span class="stat-sub" id="rTotalSub"></span></div>
      <div class="stat"><span class="stat-label">Fabric needed</span><span class="stat-num" id="rFabric">–</span><span class="stat-sub" id="rFabricSub"></span></div>
      <div class="stat"><span class="stat-label">Your profit</span><span class="stat-num" id="rProfit">–</span><span class="stat-sub" id="rProfitSub"></span></div>
    </div>
    <h3 class="est-h3">Cost of one piece <small class="muted" id="bdSize"></small></h3>
    <table class="table compact est-breakdown"><tbody id="breakdown"></tbody></table>
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
    <thead><tr><th>Name</th><th>Customer</th><th class="num">Pcs</th><th class="num">₹/pc</th><th class="num">Total</th><th>Saved</th></tr></thead>
    <tbody>
    <?php foreach ($saved as $s): $url = 'estimate.php?id=' . (int)$s['id']; ?>
      <tr onclick="location='<?= h($url) ?>'" class="<?= $est && (int)$est['id'] === (int)$s['id'] ? 'on' : '' ?>">
        <td><a href="<?= h($url) ?>"><b><?= h($s['name']) ?></b></a></td>
        <td><?= h($s['customer']) ?></td>
        <td class="num"><?= (int)$s['total_qty'] ?></td>
        <td class="num">₹<?= h(number_format((float)$s['price_per_pc'], 2)) ?></td>
        <td class="num">₹<?= h(number_format((float)$s['total_amount'], 0)) ?></td>
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
