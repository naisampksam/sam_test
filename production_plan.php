<?php
// Plan a production: minimum stock to keep per size (and colour), what is below it, and how many pieces of each size
// to cut from the fabric kg per colour — in the same ratio as the minimum stock. Download the plan as a PDF.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/orders.php';
require __DIR__ . '/inc/plan.php';

require_login();
if (!cap('stock')) {
    http_response_code(403);
    exit('You are not allowed to plan production (needs the Stock permission).');
}
$pid = (int)($_GET['pid'] ?? 0);
$p = $pid ? plan_product($pid) : null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $p) {
    csrf_check();
    $sizes = $p['size_list'];
    foreach (array_keys((array)($_POST['all'] ?? [])) as $sz) {
        if (!in_array(strtoupper($sz), $sizes, true)) {
            $sizes[] = strtoupper($sz);
        }
    }
    save_stock_mins($pid, (array)($_POST['all'] ?? []), (array)($_POST['col'] ?? []));
    flash('Minimum stock saved for ' . $p['gsm'] . ' · ' . $p['name'] . '.');
    redirect('production_plan.php?pid=' . $pid . '#plan');
}

$pageTitle = 'Plan production';
$active = 'stock';
require __DIR__ . '/inc/header.php';

if (!$p):
    // Every product, those below their minimum first.
    $rows = [];
    foreach (q('SELECT p.id FROM products p JOIN gsm_options g ON g.id = p.gsm_id WHERE p.active = 1 ORDER BY g.sort, g.id, p.sort, p.id')->fetchAll(PDO::FETCH_COLUMN) as $id) {
        $pp = plan_product((int)$id);
        $mins = stock_mins((int)$id);
        $rows[] = [$pp, count(below_min($pp, $mins)), (bool)$mins];
    }
    usort($rows, fn($a, $b) => $b[1] <=> $a[1]);
?>
<div class="page-head"><div><a class="back" href="stock.php">← Products &amp; stock</a><h1>🧵 Plan production</h1>
  <p class="muted small">Set the minimum stock to keep for each product; plan a production when it goes below.</p></div></div>
<section class="panel">
  <div class="table-wrap"><table class="table">
    <thead><tr><th>Product</th><th>Minimum stock</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as [$pp, $below, $hasMin]): ?>
      <tr class="<?= $below ? 'plan-below' : '' ?>"><td><b><?= h($pp['name']) ?></b><br><small class="muted"><?= h($pp['gsm']) ?></small></td>
        <td><?= !$hasMin ? '<span class="muted">Not set</span>' : ($below ? '<b class="err-text">⚠ ' . $below . ' colour / size' . ($below === 1 ? '' : 's') . ' below minimum</b>' : '<span class="ok-text">✓ All above minimum</span>') ?></td>
        <td><a class="btn small <?= $below ? 'primary' : '' ?>" href="production_plan.php?pid=<?= (int)$pp['id'] ?>"><?= $hasMin ? '🧵 Plan production' : '📏 Set minimum stock' ?></a></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php
else:
    $mins = stock_mins($pid);
    $sizes = $p['size_list'];
    foreach ($mins as $byS) {
        foreach (array_keys($byS) as $sz) {
            if (!in_array($sz, $sizes, true)) {
                $sizes[] = $sz;
            }
        }
    }
    $below = below_min(['colors' => $p['colors'], 'size_list' => $sizes, 'stock' => $p['stock']], $mins);
    $weights = array_change_key_case(piece_weights($p['gsm'], $p['name']), CASE_UPPER);
    $data = ['product' => $p['gsm'] . ' · ' . $p['name'], 'sizes' => $sizes, 'waste' => plan_waste_pct($p['gsm'], $p['name']), 'weights' => (object)$weights,
        'company' => setting('company_name', 'Looma Apparels'), 'date' => fmt_date(today()), 'colors' => array_map(fn($c) => [
            'name' => $c['name'], 'hex' => $c['hex'],
            'min' => (object)array_combine($sizes, array_map(fn($sz) => min_for($mins, $c['name'], $sz), $sizes)),
            'stock' => (object)array_combine($sizes, array_map(fn($sz) => (float)($p['stock'][strtolower($c['name'])][$sz] ?? 0), $sizes)),
        ], $p['colors'])];
    $belowBy = [];
    foreach ($below as $b) {
        $belowBy[$b['color']][$b['size']] = true;
    }
?>
<div class="page-head"><div><a class="back" href="production_plan.php">← Plan production</a><h1>🧵 <?= h($p['name']) ?></h1>
  <p class="muted small"><?= h($p['gsm']) ?> · <?= $below ? '<b class="err-text">⚠ ' . count($below) . ' colour / size' . (count($below) === 1 ? '' : 's') . ' below minimum stock</b>' : ($mins ? '✓ All above minimum stock' : 'Set the minimum stock first (1)') ?></p></div></div>

<form method="post" class="panel" id="mins">
  <?= csrf_field() ?>
  <h2>1 · Minimum stock to keep <small class="muted">pieces per size — the plan follows this ratio</small></h2>
  <div class="table-wrap"><table class="table compact plan-grid">
    <thead><tr><th>Colour</th><?php foreach ($sizes as $sz): ?><th class="num"><?= h($sz) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
      <tr class="plan-all"><td><b>All colours</b></td><?php foreach ($sizes as $sz): ?><td><input type="number" min="0" step="1" inputmode="numeric" name="all[<?= h($sz) ?>]" value="<?= isset($mins[''][$sz]) ? (int)$mins[''][$sz] : '' ?>" placeholder="0" aria-label="Minimum <?= h($sz) ?>, all colours"></td><?php endforeach; ?></tr>
      <?php foreach ($p['colors'] as $c): ?>
        <tr><td><span class="dot" style="background: <?= h($c['hex'] ?: '#ccc') ?>"></span> <?= h($c['name']) ?></td>
          <?php foreach ($sizes as $sz): $own = $mins[$c['name']][$sz] ?? null; ?><td><input type="number" min="0" step="1" inputmode="numeric" name="col[<?= h($c['name']) ?>][<?= h($sz) ?>]" value="<?= $own !== null ? (int)$own : '' ?>" placeholder="same" aria-label="Minimum <?= h($sz) ?>, <?= h($c['name']) ?>"></td><?php endforeach; ?></tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="hint">Fill “All colours”. A colour that sells more or less? Type its own numbers; empty = same as all colours.</p>
  <button class="btn primary">💾 Save minimum stock</button>
</form>

<section class="panel">
  <h2>2 · Stock now <small class="muted">red = below the minimum</small></h2>
  <div class="table-wrap"><table class="table compact stock-grid">
    <thead><tr><th>Colour</th><?php foreach ($sizes as $sz): ?><th class="num"><?= h($sz) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
    <?php foreach ($p['colors'] as $c): ?>
      <tr><td><span class="dot" style="background: <?= h($c['hex'] ?: '#ccc') ?>"></span> <?= h($c['name']) ?></td>
        <?php foreach ($sizes as $sz): $have = (float)($p['stock'][strtolower($c['name'])][$sz] ?? 0); $mn = min_for($mins, $c['name'], $sz); ?>
          <td class="num <?= isset($belowBy[$c['name']][$sz]) ? 'below-min' : '' ?>"><b><?= qty_fmt($have) ?></b><?php if ($mn): ?><small class="muted"> / <?= $mn ?></small><?php endif; ?></td>
        <?php endforeach; ?></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <p class="hint">Stock now / minimum.</p>
</section>

<section class="panel" id="plan">
  <h2>3 · Plan production <small class="muted">fabric for each colour → pieces of each size</small></h2>
  <?php if (!$mins): ?><p class="muted">Set the minimum stock (1) first — the plan uses its ratio.</p><?php else: ?>
  <form id="planForm" data-plan="<?= h(json_encode($data, JSON_UNESCAPED_UNICODE)) ?>" onsubmit="return false">
    <div class="seg-toggle plan-method">
      <label><input type="radio" name="method" value="count" checked><span>Pieces per kg (simple)</span></label>
      <label><input type="radio" name="method" value="grams"><span>Grams per size (exact)</span></label>
    </div>
    <label class="field plan-count" style="max-width:260px"><span class="lbl">Pieces from 1 kg of fabric</span><input type="number" min="0.1" step="0.1" inputmode="decimal" id="planPerKg" value="<?= h(setting('plan_pcs_per_kg', '3')) ?>">
      <small class="hint">3 = 20 kg gives about 60 T-shirts.</small></label>
    <div class="plan-grams" hidden>
      <div class="field"><span class="lbl">Fabric per piece (grams) <small class="muted">finished weight of one piece — from your production batches</small></span>
        <div class="size-qty"><?php foreach ($sizes as $sz): ?><label class="sq"><b><?= h($sz) ?></b><input type="number" min="0" step="any" inputmode="decimal" data-g="<?= h($sz) ?>" value="<?= isset($weights[$sz]) ? h((string)$weights[$sz]) : '' ?>" placeholder="g"><small class="muted">g</small></label><?php endforeach; ?></div></div>
      <label class="field" style="max-width:220px"><span class="lbl">Fabric waste %</span><input type="number" min="0" max="80" step="0.1" id="planWaste" value="<?= h((string)$data['waste']) ?>"></label>
    </div>
    <div class="table-wrap"><table class="table compact plan-kg">
      <thead><tr><th>Colour</th><th>Fabric (kg)</th><th>To reach minimum</th></tr></thead>
      <tbody>
      <?php foreach ($data['colors'] as $i => $c): ?>
        <tr><td><span class="dot" style="background: <?= h($c['hex'] ?: '#ccc') ?>"></span> <?= h($c['name']) ?></td>
          <td><input type="number" min="0" step="0.1" inputmode="decimal" data-kg="<?= $i ?>" placeholder="0" aria-label="Fabric kg, <?= h($c['name']) ?>"></td>
          <td><span class="muted small" data-need="<?= $i ?>"></span></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div id="planOut"></div>
    <div class="status-btns"><button type="button" class="btn primary" id="planPdf" disabled>⬇ Download PDF</button></div>
  </form>
  <?php endif; ?>
</section>
<script src="<?= h(asset('assets/vendor/jspdf.umd.min.js')) ?>"></script>
<script>
// Pieces per size from the fabric kg of each colour, in the ratio of the minimum stock (same as plan_pieces() in inc/plan.php).
(function () {
  var form = document.getElementById('planForm');
  if (!form) return;
  var D = JSON.parse(form.dataset.plan), out = document.getElementById('planOut'), pdfBtn = document.getElementById('planPdf');
  var esc = function (v) { return String(v).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
  function fabricG() { // grams of fabric for one finished piece, waste included
    var w = Math.min(80, Math.max(0, parseFloat(document.getElementById('planWaste').value) || 0)), g = {};
    form.querySelectorAll('[data-g]').forEach(function (i) { var v = parseFloat(i.value); if (v > 0) g[i.dataset.g] = v / (1 - w / 100); });
    return g;
  }
  function pieces(kg, ratio, f) {
    var sizes = D.sizes.filter(function (s) { return ratio[s] > 0; }), per = 0, out = {};
    for (var i = 0; i < sizes.length; i++) { if (!f[sizes[i]]) return null; per += ratio[sizes[i]] * f[sizes[i]]; }
    if (!per || kg <= 0) return {};
    var k = kg * 1000 / per, left = kg * 1000;
    sizes.forEach(function (s) { out[s] = Math.floor(k * ratio[s] + 1e-9); left -= out[s] * f[s]; });
    for (;;) {
      var best = null;
      sizes.forEach(function (s) { if (f[s] <= left + 1e-9 && (best === null || k * ratio[s] - out[s] > k * ratio[best] - out[best])) best = s; });
      if (best === null) break;
      out[best]++; left -= f[best];
    }
    return out;
  }
  // Simple way: kg × pieces per kg, split by the ratio (largest remainder) — same as plan_pieces_count() in inc/plan.php.
  function piecesCount(total, ratio) {
    var sizes = D.sizes.filter(function (s) { return ratio[s] > 0; }), sum = 0, out = {}, rest = [];
    sizes.forEach(function (s) { sum += ratio[s]; });
    if (!sum || total <= 0) return {};
    sizes.forEach(function (s, i) { var ex = total * ratio[s] / sum; out[s] = Math.floor(ex + 1e-9); rest.push([ex - out[s], i, s]); });
    rest.sort(function (a, b) { return b[0] - a[0] || a[1] - b[1]; });
    var left = total - sizes.reduce(function (a, s) { return a + out[s]; }, 0);
    for (var i = 0; i < left; i++) out[rest[i][2]]++;
    return out;
  }
  function method() { return form.querySelector('[name=method]:checked').value; }
  function perKg() { return Math.max(0, parseFloat(document.getElementById('planPerKg').value) || 0); }
  var plan = [];
  function run() {
    var byCount = method() === 'count';
    form.querySelector('.plan-count').hidden = !byCount; form.querySelector('.plan-grams').hidden = byCount;
    var f = byCount ? {} : fabricG(), html = '', any = false, missing = [];
    plan = [];
    D.colors.forEach(function (c, i) {
      var ratio = c.min, need = 0, per = 0, ok = true;
      // fabric to bring every size up to its minimum, keeping the ratio
      var rsum = 0;
      D.sizes.forEach(function (s) {
        if (!(ratio[s] > 0)) return;
        rsum += ratio[s];
        need = Math.max(need, Math.max(0, ratio[s] - (c.stock[s] || 0)) / ratio[s]);
        if (byCount) return;
        if (!f[s]) { ok = false; if (missing.indexOf(s) < 0) missing.push(s); return; }
        per += ratio[s] * f[s];
      });
      // fabric to bring every size up to its minimum, keeping the ratio
      var needKg = !ok || need <= 0 ? 0 : byCount ? (perKg() ? Math.ceil(need * rsum / perKg() * 10) / 10 : 0) : Math.ceil(need * per / 100) / 10;
      var needEl = form.querySelector('[data-need="' + i + '"]');
      var nh = !ok || (byCount && !perKg()) ? '' : need > 0 ? '<button type="button" class="linkbtn" data-use="' + i + '" data-suggest="' + needKg + '">' + needKg + ' kg</button>' : '✓ at minimum';
      if (needEl.dataset.h !== nh) { needEl.innerHTML = nh; needEl.dataset.h = nh; } // redrawn only when it changes, so a tap is not lost
      var kg = parseFloat(form.querySelector('[data-kg="' + i + '"]').value) || 0;
      if (kg <= 0) return;
      var pcs = byCount ? piecesCount(Math.round(kg * perKg()), ratio) : pieces(kg, ratio, f);
      if (!pcs) return;
      var total = 0, used = 0;
      D.sizes.forEach(function (s) { total += pcs[s] || 0; used += (pcs[s] || 0) * (f[s] || 0); });
      if (!total) return;
      any = true;
      plan.push({ color: c.name, kg: kg, pcs: pcs, total: total, used: used / 1000, stock: c.stock, min: c.min });
    });
    if (missing.length) html += '<p class="err-text small">Type the grams per piece for ' + missing.join(', ') + ' to plan those sizes.</p>';
    if (any) {
      var sz = D.sizes.filter(function (s) { return plan.some(function (p) { return p.pcs[s]; }); }), grand = 0, kgAll = 0;
      html += '<div class="table-wrap"><table class="table compact plan-result"><thead><tr><th>Colour</th><th class="num">Fabric</th>' + sz.map(function (s) { return '<th class="num">' + esc(s) + '</th>'; }).join('') + '<th class="num">Pieces</th></tr></thead><tbody>';
      plan.forEach(function (p) {
        grand += p.total; kgAll += p.kg;
        html += '<tr><td><b>' + esc(p.color) + '</b></td><td class="num">' + p.kg + ' kg</td>' + sz.map(function (s) {
          var after = (p.stock[s] || 0) + (p.pcs[s] || 0);
          return '<td class="num"><b>' + (p.pcs[s] || 0) + '</b>' + (p.min[s] ? '<small class="muted" title="Stock after / minimum"> → ' + after + '</small>' : '') + '</td>';
        }).join('') + '<td class="num"><b>' + p.total + '</b></td></tr>';
      });
      html += '</tbody><tfoot><tr><td><b>Total</b></td><td class="num"><b>' + Math.round(kgAll * 10) / 10 + ' kg</b></td>' + sz.map(function (s) {
        return '<td class="num"><b>' + plan.reduce(function (a, p) { return a + (p.pcs[s] || 0); }, 0) + '</b></td>';
      }).join('') + '<td class="num"><b>' + grand + '</b></td></tr></tfoot></table></div><p class="hint">Bold = pieces to cut; → stock after this production.</p>';
    }
    out.innerHTML = html;
    pdfBtn.disabled = !any;
  }
  form.addEventListener('input', run);
  form.addEventListener('change', run);
  form.addEventListener('click', function (e) {
    var b = e.target.closest('[data-use]'); if (!b) return;
    form.querySelector('[data-kg="' + b.dataset.use + '"]').value = b.dataset.suggest; run();
  });
  pdfBtn.addEventListener('click', function () {
    var doc = new window.jspdf.jsPDF({ unit: 'mm', format: 'a4' }), y = 18, sz = D.sizes.filter(function (s) { return plan.some(function (p) { return p.pcs[s]; }); });
    var colW = Math.min(18, 120 / Math.max(1, sz.length));
    doc.setFont('helvetica', 'bold'); doc.setFontSize(16); doc.text('Production plan', 14, y);
    doc.setFont('helvetica', 'normal'); doc.setFontSize(10); doc.text(D.company + ' · ' + D.date, 196, y, { align: 'right' });
    y += 7; doc.setFontSize(12); doc.text(D.product, 14, y);
    y += 6; doc.setFontSize(9); doc.setTextColor(100);
    doc.text('Pieces per size follow the minimum stock ratio. ' + (method() === 'count' ? perKg() + ' pieces per kg of fabric.'
      : 'Fabric waste ' + (parseFloat(document.getElementById('planWaste').value) || 0) + '%. Grams per piece: '
      + D.sizes.filter(function (s) { return form.querySelector('[data-g="' + s + '"]').value; }).map(function (s) { return s + ' ' + form.querySelector('[data-g="' + s + '"]').value; }).join(', ')), 14, y, { maxWidth: 182 });
    doc.setTextColor(0); y += 10;
    var head = function () {
      doc.setFont('helvetica', 'bold'); doc.setFontSize(10); doc.setFillColor(238, 241, 246); doc.rect(14, y - 5, 182, 7, 'F');
      doc.text('Colour', 16, y); doc.text('Fabric', 62, y);
      sz.forEach(function (s, i) { doc.text(s, 84 + i * colW + colW - 2, y, { align: 'right' }); });
      doc.text('Pieces', 194, y, { align: 'right' }); doc.setFont('helvetica', 'normal'); y += 7;
    };
    head();
    var tot = {}, grand = 0, kg = 0;
    plan.forEach(function (p) {
      if (y > 275) { doc.addPage(); y = 18; head(); }
      doc.setFont('helvetica', 'bold'); doc.text(p.color, 16, y); doc.setFont('helvetica', 'normal');
      doc.text(p.kg + ' kg', 62, y);
      sz.forEach(function (s, i) { doc.text(String(p.pcs[s] || 0), 84 + i * colW + colW - 2, y, { align: 'right' }); tot[s] = (tot[s] || 0) + (p.pcs[s] || 0); });
      doc.setFont('helvetica', 'bold'); doc.text(String(p.total), 194, y, { align: 'right' }); doc.setFont('helvetica', 'normal');
      grand += p.total; kg += p.kg;
      doc.setDrawColor(225); doc.line(14, y + 2.5, 196, y + 2.5); y += 8;
    });
    doc.setFont('helvetica', 'bold'); doc.text('Total', 16, y); doc.text((Math.round(kg * 10) / 10) + ' kg', 62, y);
    sz.forEach(function (s, i) { doc.text(String(tot[s] || 0), 84 + i * colW + colW - 2, y, { align: 'right' }); });
    doc.text(String(grand), 194, y, { align: 'right' });
    y += 12; doc.setFont('helvetica', 'normal'); doc.setFontSize(9); doc.setTextColor(100);
    doc.text('Minimum stock per size: ' + D.sizes.map(function (s) { return s + ' ' + (D.colors[0] ? (D.colors[0].min[s] || 0) : 0); }).join(', ') + ' (all colours unless set per colour).', 14, y, { maxWidth: 182 });
    doc.save('production-plan-' + D.product.replace(/[^\w]+/g, '-').toLowerCase() + '.pdf');
  });
  run();
})();
</script>
<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php'; ?>
