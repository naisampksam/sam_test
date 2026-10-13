<?php
// Embroidery → DST: convert artwork (PNG/JPG…) into a Tajima .DST machine file. Engine: inc/digitizer.php
// Nothing is saved on the server: no database rows, no files. The DST goes straight to the browser as a download.
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
$checkboxes = ['remove_bg', 'underlay', 'lock', 'run_triple', 'auto_angle', 'details_last'];   // 'skip' = colors left out
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
        if (file_get_contents($f['tmp_name'], false, null, 0, 5) === '%PDF-') {
            @unlink($f['tmp_name']);
            throw new RuntimeException('PDF files are turned into an image in your browser – please reload this page and choose the PDF again.');
        }
        $opts['label'] = pathinfo((string)$f['name'], PATHINFO_FILENAME);
        $dz = new Digitizer($opts);
        $dz->load($f['tmp_name']);
        @unlink($f['tmp_name']);   // nothing is kept on the server: the image is deleted right after reading
        $dz->digitize();
        $dst = $dz->toDst();
        $result = [
            'ok' => true,
            'dst' => base64_encode($dst),
            'name' => (preg_replace('/[^A-Za-z0-9_-]+/', '_', $opts['label']) ?: 'design') . '.dst',
            'svg' => $dz->previewSvg(),
            'stats' => $dz->stats,
            'sheet' => $dz->colorSheet(),
        ];
    } catch (Throwable $e) {
        $error = $e instanceof RuntimeException ? $e->getMessage() : 'Could not convert this image: ' . $e->getMessage();
    }
    // live updates from the page: answer with JSON only (still nothing is saved on the server)
    if (($_POST['ajax'] ?? '') === '1') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($error ? ['ok' => false, 'error' => $error] : $result, JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
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
  .emb-result { position: relative; }
  .emb-result.busy::after { content: 'Updating…'; position: absolute; inset: 0; display: grid; place-items: center; font-weight: 600;
    background: color-mix(in srgb, var(--surface) 70%, transparent); border-radius: var(--radius); }
  .emb-table input[type=color] { width: 36px; height: 28px; padding: 0; border: 1px solid var(--border-strong); border-radius: 6px; background: none; cursor: pointer; }
  .emb-table tr.off td { opacity: .45; }
  .emb-removed { display: flex; flex-wrap: wrap; gap: 6px; margin: 8px 0; }
  .emb-removed button { display: inline-flex; align-items: center; gap: 6px; border: 1px dashed var(--border-strong); background: var(--surface-2);
    border-radius: 999px; padding: 4px 10px; font: inherit; font-size: 13px; cursor: pointer; }
</style>

<div class="page-head"><h1>Embroidery → DST</h1></div>

<div class="emb-grid">
<section class="panel">
  <form method="post" enctype="multipart/form-data" class="emb-form" id="embForm">
    <?= csrf_field() ?>
    <input type="hidden" name="skip" value="<?= h($v['skip']) ?>">
    <label class="emb-row" style="display:block">
      <b>Artwork</b>
      <input type="file" name="design" accept="image/png,image/jpeg,image/gif,image/webp,image/bmp,application/pdf,.pdf" required style="width:100%;margin-top:6px">
    </label>
    <label class="emb-row" id="embPageRow" hidden><span>PDF page</span><input type="number" id="embPage" min="1" value="1" step="1"></label>
    <p class="emb-note" id="embPdfNote" hidden></p>
    <p class="emb-note">PNG (transparent background is best), JPG or <b>PDF</b> (artwork from Illustrator, CorelDRAW, Canva…). Clean artwork with flat colors gives the best result; photos give many trims.</p>

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
    <p class="hint">After the first convert, every change you make here updates the result by itself – no need to upload again.</p>
  </form>
</section>

<section class="panel emb-result" id="embResult">
<?php if ($error): ?>
  <p class="alert err"><?= h($error) ?></p>
<?php else: ?>
  <p>Upload artwork, set the width and number of thread colors, then press <b>Convert to DST</b>.</p>
  <p class="muted">You get a Download button for the .dst file, a stitch preview and the thread list.
     Afterwards you can keep changing settings, thread colors or leave colors out – the result updates live.
     Nothing is saved on the server.</p>
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

  // ---------- live conversion: the image stays in the browser; nothing is stored on the server
  var box = document.getElementById('embResult');
  var fileInput = form.querySelector('input[name=design]');
  var skipInput = form.querySelector('input[name=skip]');
  var btn = form.querySelector('button[type=submit]');
  var colorMap = {};          // design color -> thread color picked by the user (preview & list only)
  var dlUrl = null, ctrl = null, timer = null, converted = false;

  function esc(t) { return String(t).replace(/[&<>"']/g, function (c) { return '&#' + c.charCodeAt(0) + ';'; }); }
  function fmt(n) { return Number(n).toLocaleString('en-IN'); }
  function skipped() { return skipInput.value ? skipInput.value.split(',') : []; }

  // ---------- PDF: turned into a PNG in the browser with PDF.js (bundled in assets/vendor/pdfjs)
  var PDFJS = <?= json_encode(['lib' => asset('assets/vendor/pdfjs/pdf.min.js'), 'worker' => asset('assets/vendor/pdfjs/pdf.worker.min.js')]) ?>;
  var pageRow = document.getElementById('embPageRow'), pageInput = document.getElementById('embPage'), pdfNote = document.getElementById('embPdfNote');
  var pdfDoc = null, pdfFile = null, pdfCache = {};
  function isPdf(f) { return f && (f.type === 'application/pdf' || /\.pdf$/i.test(f.name)); }
  function loadPdfJs() {
    if (window.pdfjsLib) return Promise.resolve(window.pdfjsLib);
    return new Promise(function (ok, fail) {
      var sc = document.createElement('script'); sc.src = PDFJS.lib;
      sc.onload = function () { window.pdfjsLib.GlobalWorkerOptions.workerSrc = PDFJS.worker; ok(window.pdfjsLib); };
      sc.onerror = function () { fail(new Error('Could not load the PDF reader.')); };
      document.head.appendChild(sc);
    });
  }
  function pdfToPng(file, pageNo) {
    var key = pageNo;
    if (pdfFile === file && pdfCache[key]) return Promise.resolve(pdfCache[key]);
    return loadPdfJs().then(function (lib) {
      if (pdfFile !== file || !pdfDoc) {
        pdfCache = {}; pdfFile = file;
        return file.arrayBuffer().then(function (buf) { return lib.getDocument({ data: buf }).promise; }).then(function (d) { pdfDoc = d; return d; });
      }
      return pdfDoc;
    }).then(function (doc) {
      pageRow.hidden = doc.numPages < 2; pageInput.max = doc.numPages;
      pdfNote.hidden = false; pdfNote.textContent = 'PDF: page ' + Math.min(pageNo, doc.numPages) + ' of ' + doc.numPages + ' is used.';
      return doc.getPage(Math.min(Math.max(1, pageNo), doc.numPages));
    }).then(function (page) {
      var v1 = page.getViewport({ scale: 1 });
      var scale = Math.min(8, 2400 / Math.max(v1.width, v1.height));     // sharp: about 2400 px on the long side
      var vp = page.getViewport({ scale: scale });
      var c = document.createElement('canvas'); c.width = Math.ceil(vp.width); c.height = Math.ceil(vp.height);
      return page.render({ canvasContext: c.getContext('2d'), viewport: vp, background: 'rgba(0,0,0,0)' }).promise
        .then(function () { return new Promise(function (ok) { c.toBlob(ok, 'image/png'); }); });
    }).then(function (blob) { pdfCache[key] = blob; return blob; });
  }
  function artwork() {
    var f = fileInput.files[0];
    if (!isPdf(f)) { pageRow.hidden = true; pdfNote.hidden = true; return Promise.resolve(null); }
    return pdfToPng(f, parseInt(pageInput.value, 10) || 1).then(function (blob) { return [blob, f.name.replace(/\.pdf$/i, '') + '.png']; });
  }

  function convert() {
    if (!fileInput.files.length) return;
    if (ctrl) ctrl.abort();
    var my = ctrl = new AbortController();
    box.classList.add('busy'); btn.disabled = true; btn.textContent = 'Converting…';
    artwork().then(function (art) {
      if (my.signal.aborted) throw new DOMException('aborted', 'AbortError');
      var fd = new FormData(form); fd.append('ajax', '1');
      if (art) fd.set('design', art[0], art[1]);
      return fetch(location.pathname, { method: 'POST', body: fd, signal: my.signal, credentials: 'same-origin' });
    })
      .then(function (r) { return r.json(); })
      .then(function (r) { converted = true; render(r); })
      .catch(function (e) {
        if (e.name === 'AbortError') return;
        box.innerHTML = '<p class="alert err">' + esc(/PDF|password|Invalid/i.test(e.message || '') ? 'Could not read this PDF: ' + e.message : 'Could not convert – please check your internet connection and try again.') + '</p>';
      })
      .finally(function () { if (my !== ctrl) return;   // a newer update is still running
        box.classList.remove('busy'); btn.disabled = false; btn.textContent = 'Convert to DST'; });
  }
  function later() { clearTimeout(timer); if (converted) timer = setTimeout(convert, 900); }

  function render(r) {
    if (!r.ok) { box.innerHTML = '<p class="alert err">' + esc(r.error) + '</p>' + removedHtml(); bindRemoved(); return; }
    var bin = atob(r.dst), bytes = new Uint8Array(bin.length);
    for (var i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
    if (dlUrl) URL.revokeObjectURL(dlUrl);
    dlUrl = URL.createObjectURL(new Blob([bytes], { type: 'application/octet-stream' }));
    var s = r.stats, h = '';
    h += '<p class="alert ok">✓ Ready. Change anything on the left and this updates by itself. Press Download when it looks right.</p>';
    h += '<p><a class="btn primary" id="embDl" download="' + esc(r.name) + '" href="' + dlUrl + '">⬇ Download ' + esc(r.name) + '</a></p>';
    h += '<div class="emb-stats"><span><b>' + fmt(s.stitches) + '</b> stitches</span><span><b>' + s.colors + '</b> color stops</span>'
       + '<span><b>' + s.width + ' × ' + s.height + '</b> mm</span><span><b>' + s.trims + '</b> trims</span></div>';
    h += '<p class="muted" style="font-size:13px">Objects: ' + s.objects.fill + ' fill · ' + s.objects.satin + ' satin · ' + s.objects.run + ' running stitch</p>';
    (s.warnings || []).forEach(function (w) { h += '<p class="alert warn">⚠ ' + esc(w) + '</p>'; });
    h += '<div class="emb-preview">' + r.svg + '</div>';
    h += '<h2>Thread list</h2><p class="emb-note">Click a color to change the thread color. Untick “Sew” to leave that color out of the file.</p>';
    h += '<table class="emb-table"><tr><th>#</th><th>Thread</th><th>Hex</th><th>Stitches</th><th>Sew</th></tr>';
    r.sheet.forEach(function (row, i) {
      var orig = row[0], col = colorMap[orig] || orig;
      h += '<tr data-orig="' + orig + '"><td>' + (i + 1) + '</td><td><input type="color" value="' + col + '" aria-label="Thread color ' + (i + 1) + '"></td>'
         + '<td class="hex">' + col + '</td><td>' + fmt(row[1]) + '</td><td><input type="checkbox" checked aria-label="Sew this color"></td></tr>';
    });
    h += '</table>' + removedHtml();
    h += '<p class="emb-note" style="margin-top:8px">A DST file stores stitches and color stops only – thread colors are for your preview and thread list. Always do a test sew first.</p>';
    box.innerHTML = h;
    Object.keys(colorMap).forEach(function (o) { recolor(o, colorMap[o]); });
    box.querySelectorAll('tr[data-orig]').forEach(function (tr) {
      var orig = tr.dataset.orig;
      tr.querySelector('input[type=color]').addEventListener('input', function () { colorMap[orig] = this.value; recolor(orig, this.value); });
      tr.querySelector('input[type=checkbox]').addEventListener('change', function () {
        var list = skipped(); if (list.indexOf(orig) < 0) list.push(orig);
        skipInput.value = list.join(','); tr.classList.add('off'); convert();
      });
    });
    bindRemoved();
  }
  // thread color change: preview and list update instantly (no new conversion needed)
  function recolor(orig, col) {
    box.querySelectorAll('path[data-hex="' + orig + '"]').forEach(function (p) { p.setAttribute('stroke', col); });
    box.querySelectorAll('tr[data-orig="' + orig + '"]').forEach(function (tr) { tr.querySelector('.hex').textContent = col; tr.querySelector('input[type=color]').value = col; });
  }
  function removedHtml() {
    var list = skipped(); if (!list.length) return '';
    return '<p class="emb-note" style="margin-top:12px">Left out (tap to put back):</p><div class="emb-removed">' + list.map(function (c) {
      return '<button type="button" data-c="' + c + '"><span class="emb-sw" style="background:' + (colorMap[c] || c) + '"></span>' + (colorMap[c] || c) + ' ↺</button>';
    }).join('') + '</div>';
  }
  function bindRemoved() {
    box.querySelectorAll('.emb-removed button').forEach(function (b) {
      b.addEventListener('click', function () { skipInput.value = skipped().filter(function (c) { return c !== b.dataset.c; }).join(','); convert(); });
    });
  }

  form.addEventListener('submit', function (e) { e.preventDefault(); clearTimeout(timer); convert(); });
  fileInput.addEventListener('change', function () { skipInput.value = ''; colorMap = {}; pageInput.value = 1; pdfDoc = null; pdfFile = null; if (converted) convert(); });
  form.addEventListener('input', function (e) { if (e.target !== fileInput) later(); });
  form.addEventListener('change', function (e) { if (e.target !== fileInput) later(); });
  ['embCover', 'embFabric', 'embThread'].forEach(function (id) { document.getElementById(id).addEventListener('change', later); });
  // the number of colors changes the design colors, so earlier choices no longer apply
  form.querySelector('[name=colors]').addEventListener('change', function () { skipInput.value = ''; colorMap = {}; });
})();
</script>
<?php require __DIR__ . '/inc/footer.php'; ?>
