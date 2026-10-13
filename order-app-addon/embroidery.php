<?php
// Embroidery → DST: convert artwork (PNG/JPG…) into a Tajima .DST machine file. Engine: inc/digitizer.php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/digitizer.php';

require_login();
if (!cap('embroidery')) {
    http_response_code(403);
    exit('Not allowed. Ask the admin to turn on "Embroidery" for you (Staff → permissions).');
}
@set_time_limit(600);
@ini_set('memory_limit', '1024M');

$d = Digitizer::defaults();
$checkboxes = ['remove_bg', 'underlay', 'lock', 'run_triple', 'auto_angle', 'details_last'];
$v = $d;
$error = null;
$result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $opts = array_intersect_key($_POST, $d);
    foreach ($checkboxes as $cb) {
        $opts[$cb] = isset($_POST[$cb]) ? 1 : 0;
    }
    $v = array_merge($d, $opts);
    try {
        $f = $_FILES['design'] ?? null;
        if (!$f || $f['error'] === UPLOAD_ERR_NO_FILE) {
            throw new RuntimeException('Please choose an image file.');
        }
        if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
            throw new RuntimeException('The image is too big to upload. Save it smaller (under 20 MB) and try again.');
        }
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            throw new RuntimeException('Upload failed – please try again.');
        }
        $opts['label'] = pathinfo((string)$f['name'], PATHINFO_FILENAME);
        $dz = new Digitizer($opts);
        $dz->load($f['tmp_name']);
        $dz->digitize();
        $dst = $dz->toDst();
        $result = [
            'dst' => base64_encode($dst),
            'name' => (preg_replace('/[^A-Za-z0-9_-]+/', '_', $opts['label']) ?: 'design') . '.dst',
            'svg' => $dz->previewSvg(),
            'stats' => $dz->stats,
            'sheet' => $dz->colorSheet(),
        ];
    } catch (Throwable $e) {
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not convert this image: ' . $e->getMessage();
    }
}

$chk = fn($k) => !empty($v[$k]) ? 'checked' : '';
$num = fn($name, $label, $step = '0.1', $extra = '') =>
    '<label class="emb-row"><span>' . $label . '</span><input type="number" step="' . $step . '" name="' . $name . '" value="' . h($v[$name]) . '" ' . $extra . '></label>';
$box = fn($name, $label) =>
    '<label class="emb-row"><span>' . $label . '</span><input type="checkbox" name="' . $name . '" value="1" ' . $chk($name) . '></label>';

$pageTitle = 'Embroidery → DST';
$active = 'embroidery';
require __DIR__ . '/inc/header.php';
?>
<style>
  .emb-grid { display: grid; gap: 16px; grid-template-columns: minmax(300px, 380px) 1fr; align-items: start; }
  @media (max-width: 860px) { .emb-grid { grid-template-columns: 1fr; } }
  .emb-row { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin: 6px 0; font-size: 14px; }
  .emb-row input[type=number], .emb-row input[type=text], .emb-row select { width: 130px; flex: none; }
  .emb-row input[type=checkbox] { width: 20px; height: 20px; flex: none; }
  .emb-h { font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); margin: 16px 0 4px; }
  .emb-adv summary { cursor: pointer; font-weight: 600; margin: 14px 0 4px; }
  .emb-note { font-size: 12px; color: var(--muted); margin: 0 0 6px; }
  .emb-stats { display: flex; flex-wrap: wrap; gap: 6px 16px; margin: 10px 0; }
  .emb-preview { background: #fff; border: 1px solid var(--border); border-radius: 10px; overflow: hidden; margin: 10px 0; }
  .emb-preview svg { display: block; width: 100%; height: auto; }
  .emb-table { width: 100%; border-collapse: collapse; font-size: 14px; }
  .emb-table td, .emb-table th { padding: 6px; border-bottom: 1px solid var(--border); text-align: left; }
  .emb-sw { width: 22px; height: 22px; border-radius: 6px; border: 1px solid var(--border-strong); display: inline-block; vertical-align: middle; }
  .alert.warn { background: var(--warn-bg); color: var(--warn); }
  .emb-busy { display: none; }
  .emb-form.busy .emb-busy { display: block; }
</style>

<div class="page-head"><h1>Embroidery → DST</h1></div>

<div class="emb-grid">
<section class="panel">
  <form method="post" enctype="multipart/form-data" class="emb-form" id="embForm">
    <?= csrf_field() ?>
    <label class="emb-row" style="display:block">
      <b>Artwork</b>
      <input type="file" name="design" accept="image/png,image/jpeg,image/gif,image/webp,image/bmp" required style="width:100%;margin-top:6px">
    </label>
    <p class="emb-note">PNG with transparent background is best. Clean artwork with flat colors gives the best result; photos give many trims.</p>

    <div class="emb-h">Size & colors</div>
    <?= $num('width_mm', 'Width (mm)', '1') ?>
    <?= $num('colors', 'Thread colors', '1', 'min="1" max="30"') ?>
    <label class="emb-row"><span>Own thread colors (hex)</span><input type="text" name="palette" placeholder="#000000,#ff0000" value="<?= h($v['palette']) ?>"></label>
    <p class="emb-note">Optional: your real thread colors; the design is mapped to them.</p>
    <?= $box('remove_bg', 'Remove background') ?>
    <?= $num('detail', 'Detail level (mm²)', '0.1', 'min="0"') ?>
    <p class="emb-note">Higher (3–4) = cleaner, fewer trims. Lower (1) = more detail. Thin lines are always kept.</p>

    <div class="emb-h">Quick presets</div>
    <label class="emb-row"><span>Coverage</span>
      <select id="embCover">
        <option value="">— keep values —</option>
        <option value="safe">Safe – no thread breaks</option>
        <option value="normal">Normal (professional)</option>
        <option value="heavy">Heavy (fuller look)</option>
        <option value="max">Maximum coverage</option>
      </select>
    </label>
    <label class="emb-row"><span>Fabric</span>
      <select id="embFabric">
        <option value="">— keep values —</option>
        <option value="woven">Woven (cotton, denim, twill)</option>
        <option value="knit">Knit (T-shirt, polo, jersey)</option>
        <option value="cap">Cap / hat</option>
        <option value="towel">Towel / fleece / terry</option>
        <option value="leather">Leather / vinyl</option>
      </select>
    </label>
    <label class="emb-row"><span>Thread weight</span>
      <select id="embThread">
        <option value="">— keep values —</option>
        <option value="40">40 wt (standard)</option>
        <option value="60">60 wt (fine – small text)</option>
        <option value="30">30 wt (thick)</option>
      </select>
    </label>

    <details class="emb-adv">
      <summary>Advanced stitch settings</summary>
      <div class="emb-h">Fill & satin</div>
      <?= $num('density', 'Fill row spacing (mm)', '0.01') ?>
      <?= $num('satin_density', 'Satin density (mm)', '0.01') ?>
      <p class="emb-note">Defaults match professional Wilcom files (fill 0.25 mm, satin 0.40 mm per edge).</p>
      <?= $num('coverage', 'Stitch volume (%)', '10', 'min="50" max="300"') ?>
      <?= $num('layers', 'Fill layers', '1', 'min="1" max="3"') ?>
      <?= $num('overlap', 'Color overlap (mm)') ?>
      <?= $num('stitch_len', 'Fill stitch length (mm)') ?>
      <?= $num('satin_max', 'Satin up to width (mm)', '0.5') ?>
      <?= $box('auto_angle', 'Fill angle follows each shape') ?>
      <?= $num('angle', 'Fill angle (°)', '1') ?>
      <?= $num('pull_comp', 'Pull compensation (mm)', '0.05') ?>
      <div class="emb-h">Lines</div>
      <?= $num('run_max', 'Running stitch for lines under (mm)') ?>
      <?= $num('run_len', 'Running stitch length (mm)') ?>
      <?= $box('run_triple', 'Bold triple running stitch') ?>
      <div class="emb-h">Clean-up & safety</div>
      <?= $num('cleanup', 'Noise cleanup passes', '1', 'min="0" max="6"') ?>
      <?= $num('min_stitch', 'Min stitch length (mm)', '0.05') ?>
      <?= $num('min_run', 'Skip areas thinner than (mm)') ?>
      <div class="emb-h">Underlay, trims & order</div>
      <?= $box('underlay', 'Underlay') ?>
      <?= $num('underlay_gap', 'Underlay spacing (mm)') ?>
      <?= $num('inset', 'Underlay inset (mm)') ?>
      <?= $num('trim_at', 'Trim jumps longer than (mm)', '0.5') ?>
      <?= $box('details_last', 'Details & outlines sewn last') ?>
      <?= $box('lock', 'Lock (tie-in/off) stitches') ?>
    </details>

    <p style="margin-top:16px"><button class="btn primary" type="submit">Convert to DST</button></p>
    <p class="emb-busy hint">Converting… big designs (20 cm+) can take up to a minute. Keep this page open.</p>
  </form>
</section>

<section class="panel">
<?php if ($error): ?>
  <p class="alert err"><?= h($error) ?></p>
<?php elseif ($result): $s = $result['stats']; ?>
  <p><a class="btn primary" download="<?= h($result['name']) ?>" href="data:application/octet-stream;base64,<?= $result['dst'] ?>">⬇ Download <?= h($result['name']) ?></a></p>
  <div class="emb-stats">
    <span><b><?= inr_number((float)$s['stitches']) ?></b> stitches</span>
    <span><b><?= (int)$s['colors'] ?></b> color stops</span>
    <span><b><?= h($s['width']) ?> × <?= h($s['height']) ?></b> mm</span>
    <span><b><?= (int)$s['trims'] ?></b> trims</span>
  </div>
  <p class="muted" style="font-size:13px">Objects: <?= (int)$s['objects']['fill'] ?> fill · <?= (int)$s['objects']['satin'] ?> satin · <?= (int)$s['objects']['run'] ?> running stitch</p>
  <?php foreach ($s['warnings'] as $w): ?><p class="alert warn">⚠ <?= h($w) ?></p><?php endforeach; ?>
  <div class="emb-preview"><?= $result['svg'] ?></div>
  <h2>Thread sequence</h2>
  <table class="emb-table">
    <tr><th>#</th><th>Color</th><th>Hex</th><th>Stitches</th></tr>
    <?php foreach ($result['sheet'] as $i => [$hex, $n]): ?>
      <tr><td><?= $i + 1 ?></td><td><span class="emb-sw" style="background:<?= h($hex) ?>"></span></td><td><?= h($hex) ?></td><td><?= inr_number((float)$n) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <p class="emb-note" style="margin-top:8px">The DST format stores stitches and color stops only – not thread colors. Use this list to load threads. Always do a test sew first.</p>
<?php else: ?>
  <p>Upload artwork, set the width and number of thread colors, then press <b>Convert to DST</b>.</p>
  <p class="muted">You get a stitch preview, the thread sequence and the .dst file for the embroidery machine.
     Fills, satin columns, running lines, underlay, lock stitches and trims are added automatically.</p>
<?php endif; ?>
</section>
</div>

<script>
(function () {
  // Presets only fill in the form fields – everything can still be changed by hand.
  var form = document.getElementById('embForm');
  function set(o) {
    Object.keys(o).forEach(function (k) {
      var el = form.querySelector('[name="' + k + '"]');
      if (!el) return;
      if (el.type === 'checkbox') el.checked = !!o[k]; else el.value = o[k];
    });
  }
  var cover = {
    safe:   { coverage: 100, layers: 1, overlap: 0.3, underlay: 1, underlay_gap: 2.0, stitch_len: 4.2, satin_max: 6, min_stitch: 0.4, min_run: 0.8, lock: 1 },
    normal: { coverage: 100, layers: 1, overlap: 0.3, underlay: 1, underlay_gap: 2.0 },
    heavy:  { coverage: 120, layers: 1, overlap: 0.4, underlay: 1, underlay_gap: 1.2, pull_comp: 0.35 },
    max:    { coverage: 130, layers: 2, overlap: 0.5, underlay: 1, underlay_gap: 0.8, pull_comp: 0.4, min_run: 0.4 }
  };
  var fabric = {
    woven:   { density: 0.25, pull_comp: 0.15, underlay: 1, underlay_gap: 2.5, inset: 0.4, stitch_len: 4.2 },
    knit:    { density: 0.27, pull_comp: 0.30, underlay: 1, underlay_gap: 1.5, inset: 0.4, stitch_len: 3.5 },
    cap:     { density: 0.24, pull_comp: 0.35, underlay: 1, underlay_gap: 1.5, inset: 0.5, stitch_len: 3.5, auto_angle: 0, angle: 90 },
    towel:   { density: 0.22, pull_comp: 0.30, underlay: 1, underlay_gap: 1.0, inset: 0.3, stitch_len: 3.5 },
    leather: { density: 0.32, satin_density: 0.5, pull_comp: 0.10, underlay: 0, stitch_len: 4.5, satin_max: 5 }
  };
  var thread = { 40: { density: 0.25, satin_density: 0.40 }, 60: { density: 0.20, satin_density: 0.32, min_run: 0.4 }, 30: { density: 0.32, satin_density: 0.50 } };
  document.getElementById('embCover').onchange = function () { if (this.value) set(cover[this.value]); };
  document.getElementById('embFabric').onchange = function () { if (this.value) set(fabric[this.value]); };
  document.getElementById('embThread').onchange = function () { if (this.value) set(thread[this.value]); };
  form.addEventListener('submit', function () {
    form.classList.add('busy');
    var b = form.querySelector('button[type=submit]'); b.disabled = true; b.textContent = 'Converting…';
  });
})();
</script>
<?php require __DIR__ . '/inc/footer.php'; ?>
