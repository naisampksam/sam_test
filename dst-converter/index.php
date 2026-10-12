<?php
require __DIR__ . '/digitizer.php';
set_time_limit(120);
ini_set('memory_limit', '512M');

$d = Digitizer::defaults();
$v = array_merge($d, $_POST);   // keep form values after submit
$error = null; $result = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (empty($_FILES['design']['tmp_name']) || $_FILES['design']['error'] !== UPLOAD_ERR_OK)
            throw new RuntimeException('Please choose an image file.');
        $opts = $_POST;
        foreach (['remove_bg', 'underlay', 'lock'] as $cb) $opts[$cb] = isset($_POST[$cb]) ? 1 : 0;
        $v = array_merge($v, $opts);
        $opts['label'] = pathinfo($_FILES['design']['name'], PATHINFO_FILENAME);

        $dz = new Digitizer($opts);
        $dz->load($_FILES['design']['tmp_name']);
        $dz->digitize();
        $dst = $dz->toDst();
        $result = [
            'dst'   => base64_encode($dst),
            'name'  => preg_replace('/[^A-Za-z0-9_-]+/', '_', $opts['label']) . '.dst',
            'svg'   => $dz->previewSvg(),
            'stats' => $dz->stats,
            'sheet' => $dz->colorSheet(),
        ];
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$chk = fn($k) => !empty($v[$k]) ? 'checked' : '';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Image to DST</title>
<style>
  body { font-family: system-ui, sans-serif; margin: 0; background: #f4f5f7; color: #222; }
  .wrap { max-width: 1100px; margin: auto; padding: 16px; display: grid; gap: 16px; grid-template-columns: 380px 1fr; }
  @media (max-width: 800px) { .wrap { grid-template-columns: 1fr; } }
  .card { background: #fff; border-radius: 10px; padding: 16px; box-shadow: 0 1px 3px #0002; }
  h1 { font-size: 20px; margin: 0 0 12px; } h3 { margin: 16px 0 6px; font-size: 14px; color: #555; }
  label { display: flex; justify-content: space-between; align-items: center; gap: 8px; font-size: 14px; margin: 6px 0; }
  input[type=number], select, input[type=text] { width: 120px; padding: 4px; }
  small { color: #777; display: block; font-size: 12px; }
  button, .btn { background: #2563eb; color: #fff; border: 0; border-radius: 6px; padding: 10px 16px; font-size: 15px; cursor: pointer; text-decoration: none; display: inline-block; }
  .err { background: #fee; color: #a00; padding: 10px; border-radius: 6px; }
  table { border-collapse: collapse; width: 100%; font-size: 14px; } td, th { padding: 4px 6px; border-bottom: 1px solid #eee; text-align: left; }
  .sw { width: 20px; height: 20px; border-radius: 4px; border: 1px solid #0003; display: inline-block; vertical-align: middle; }
</style>
</head>
<body>
<div class="wrap">
<form class="card" method="post" enctype="multipart/form-data">
  <h1>🧵 Image → DST Embroidery</h1>
  <input type="file" name="design" accept="image/*" required>
  <small>PNG (transparent is best), JPG, GIF, WEBP or BMP. Clean logos with flat colors give the best result.</small>

  <h3>Quick presets</h3>
  <label>Fabric
    <select id="fabric">
      <option value="">— keep values —</option>
      <option value="woven">Woven (cotton, denim, twill)</option>
      <option value="knit">Knit (T-shirt, polo, jersey)</option>
      <option value="cap">Cap / hat</option>
      <option value="towel">Towel / fleece / terry</option>
      <option value="leather">Leather / vinyl</option>
    </select>
  </label>
  <label>Thread weight
    <select id="thread">
      <option value="">— keep values —</option>
      <option value="40">40 wt (standard polyester/rayon)</option>
      <option value="60">60 wt (fine – small text)</option>
      <option value="30">30 wt (thick – bold coverage)</option>
    </select>
  </label>

  <label>Coverage
    <select id="cover">
      <option value="">— keep values —</option>
      <option value="safe">Safe – no thread breaks (recommended)</option>
      <option value="normal">Normal</option>
      <option value="heavy">Heavy (fuller look)</option>
      <option value="max">Maximum (no fabric shows through)</option>
    </select>
  </label>

  <h3>Size & colors</h3>
  <label>Width (mm) <input type="number" step="1" name="width_mm" value="<?= $h($v['width_mm']) ?>"></label>
  <label>Thread colors <input type="number" min="1" max="30" name="colors" value="<?= $h($v['colors']) ?>"></label>
  <label>Own threads (hex) <input type="text" name="palette" placeholder="#000000,#ff0000" value="<?= $h($v['palette']) ?>"></label>
  <small>Optional: list your actual thread colors; the design is mapped to them.</small>
  <label>Remove background <input type="checkbox" name="remove_bg" value="1" <?= $chk('remove_bg') ?>></label>
  <label>Noise cleanup passes <input type="number" min="0" max="6" name="cleanup" value="<?= $h($v['cleanup']) ?>"></label>

  <h3>Stitches</h3>
  <label>Density / row spacing (mm) <input type="number" step="0.05" name="density" value="<?= $h($v['density']) ?>"></label>
  <label>Coverage / stitch volume (%) <input type="number" step="10" min="50" max="300" name="coverage" value="<?= $h($v['coverage']) ?>"></label>
  <small>100% = normal. 150% = 1.5× more stitch rows, 200% = double.</small>
  <label>Fill layers <input type="number" min="1" max="3" name="layers" value="<?= $h($v['layers']) ?>"></label>
  <small>2–3 = extra cross-hatched fills on top for full coverage.</small>
  <label>Color overlap (mm) <input type="number" step="0.1" name="overlap" value="<?= $h($v['overlap']) ?>"></label>
  <label>Fill stitch length (mm) <input type="number" step="0.1" name="stitch_len" value="<?= $h($v['stitch_len']) ?>"></label>
  <label>Satin up to width (mm) <input type="number" step="0.5" name="satin_max" value="<?= $h($v['satin_max']) ?>"></label>
  <label>Fill angle (°) <input type="number" step="1" name="angle" value="<?= $h($v['angle']) ?>"></label>
  <label>Pull compensation (mm) <input type="number" step="0.05" name="pull_comp" value="<?= $h($v['pull_comp']) ?>"></label>
  <label>Min stitch length (mm) <input type="number" step="0.05" name="min_stitch" value="<?= $h($v['min_stitch']) ?>"></label>
  <small>Shorter stitches are removed (0.3–0.4 mm prevents thread breaks).</small>
  <label>Skip areas thinner than (mm) <input type="number" step="0.1" name="min_run" value="<?= $h($v['min_run']) ?>"></label>

  <h3>Underlay, trims & locks</h3>
  <label>Underlay <input type="checkbox" name="underlay" value="1" <?= $chk('underlay') ?>></label>
  <label>Underlay spacing (mm) <input type="number" step="0.1" name="underlay_gap" value="<?= $h($v['underlay_gap']) ?>"></label>
  <label>Underlay inset (mm) <input type="number" step="0.1" name="inset" value="<?= $h($v['inset']) ?>"></label>
  <label>Trim jumps longer than (mm) <input type="number" step="0.5" name="trim_at" value="<?= $h($v['trim_at']) ?>"></label>
  <label>Lock (tie-in/off) stitches <input type="checkbox" name="lock" value="1" <?= $chk('lock') ?>></label>

  <p><button type="submit">Convert to DST</button></p>
</form>

<div class="card">
<?php if ($error): ?>
  <div class="err"><?= $h($error) ?></div>
<?php elseif ($result): $s = $result['stats']; ?>
  <p>
    <a class="btn" download="<?= $h($result['name']) ?>" href="data:application/octet-stream;base64,<?= $result['dst'] ?>">⬇ Download <?= $h($result['name']) ?></a>
  </p>
  <p><b><?= number_format($s['stitches']) ?></b> stitches ·
     <b><?= $s['colors'] ?></b> colors ·
     <b><?= $s['width'] ?> × <?= $s['height'] ?></b> mm</p>
  <?php foreach ($s['warnings'] as $w): ?><div class="err" style="background:#fff7e0;color:#8a5a00;margin:6px 0">⚠ <?= $h($w) ?></div><?php endforeach; ?>
  <?= $result['svg'] ?>
  <h3>Thread sequence (load these on your machine)</h3>
  <table>
    <tr><th>#</th><th>Color</th><th>Hex</th><th>Stitches</th></tr>
    <?php foreach ($result['sheet'] as $i => [$hex, $n]): ?>
      <tr><td><?= $i + 1 ?></td><td><span class="sw" style="background:<?= $hex ?>"></span></td><td><?= $hex ?></td><td><?= number_format($n) ?></td></tr>
    <?php endforeach; ?>
  </table>
  <small>Note: the DST format stores stitches and color stops only – not thread colors. Use this table to pick threads.</small>
<?php else: ?>
  <p>Upload a design, pick your fabric & thread, adjust anything you like, then press <b>Convert to DST</b>.
     You'll get a stitch preview, a thread color sequence and the .dst file.</p>
<?php endif; ?>
</div>
</div>

<script>
// Presets only fill in the form fields – every value can still be changed by hand.
const set = o => Object.entries(o).forEach(([k, val]) => {
  const el = document.querySelector(`[name=${k}]`);
  if (el.type === 'checkbox') el.checked = !!val; else el.value = val;
});
const fabric = {
  woven:   { density: 0.40, pull_comp: 0.15, underlay: 1, underlay_gap: 2.5, inset: 0.4, stitch_len: 3.5 },
  knit:    { density: 0.42, pull_comp: 0.30, underlay: 1, underlay_gap: 1.5, inset: 0.4, stitch_len: 3.0 },
  cap:     { density: 0.38, pull_comp: 0.35, underlay: 1, underlay_gap: 1.5, inset: 0.5, stitch_len: 3.0, angle: 90 },
  towel:   { density: 0.35, pull_comp: 0.30, underlay: 1, underlay_gap: 1.0, inset: 0.3, stitch_len: 3.0 },
  leather: { density: 0.50, pull_comp: 0.10, underlay: 0, stitch_len: 4.0, satin_max: 5 },
};
const thread = { 40: { density: 0.40 }, 60: { density: 0.30, min_run: 0.4 }, 30: { density: 0.50 } };
const cover = {
  safe:   { coverage: 100, layers: 1, overlap: 0.3, underlay: 1, underlay_gap: 2.0, stitch_len: 3.5, satin_max: 6, min_stitch: 0.4, min_run: 0.8, lock: 1 },
  normal: { coverage: 100, layers: 1, overlap: 0.3, underlay: 1, underlay_gap: 2.0 },
  heavy:  { coverage: 140, layers: 1, overlap: 0.4, underlay: 1, underlay_gap: 1.2, pull_comp: 0.35 },
  max:    { coverage: 140, layers: 2, overlap: 0.5, underlay: 1, underlay_gap: 0.8, pull_comp: 0.4, min_run: 0.4 },
};
document.getElementById('cover').onchange = e => e.target.value && set(cover[e.target.value]);
document.getElementById('fabric').onchange = e => e.target.value && set(fabric[e.target.value]);
document.getElementById('thread').onchange = e => e.target.value && set(thread[e.target.value]);
</script>
</body>
</html>
