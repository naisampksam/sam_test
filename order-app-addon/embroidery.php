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
        $head = (string)file_get_contents($f['tmp_name'], false, null, 0, 1024);
        if (strncmp($head, '%PDF-', 5) === 0 || stripos($head, '<svg') !== false) {
            @unlink($f['tmp_name']);
            throw new RuntimeException('PDF and SVG files are turned into an image in your browser – please reload this page and choose the file again.');
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
            'shapes' => $dz->shapes,      // what was made where – used for the "fix an area" suggestions
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
  .emb-preview { background: #fff; border: 1px solid var(--border); border-radius: 10px; overflow: hidden; margin: 10px 0; position: relative; }
  .emb-zones { position: absolute; inset: 0; width: 100%; height: 100%; touch-action: none; }
  .emb-preview.drawing { cursor: crosshair; outline: 3px solid var(--brand); }
  .emb-tools { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 8px 0; }
  .emb-tools .btn.on { background: var(--brand); color: #fff; }
  .emb-fix { border: 2px solid var(--brand); border-radius: 12px; padding: 14px; margin: 10px 0; background: var(--accent-soft); }
  .emb-fix h3 { margin: 0 0 8px; font-size: 16px; }
  .emb-fix textarea { width: 100%; min-height: 64px; box-sizing: border-box; font: inherit; padding: 8px; border-radius: 8px; border: 1px solid var(--border-strong); }
  .emb-chips { display: flex; flex-wrap: wrap; gap: 6px; margin: 8px 0; }
  .emb-chips label { display: inline-flex; align-items: center; gap: 6px; background: var(--surface); border: 1px solid var(--border-strong);
    border-radius: 999px; padding: 6px 12px; font-size: 13px; cursor: pointer; }
  .emb-chips label:has(input:checked) { background: var(--brand); color: #fff; border-color: var(--brand); }
  .emb-chips input { margin: 0; }
  .emb-understood { font-size: 13px; color: var(--ok); min-height: 1em; }
  .emb-sugg { font-size: 13px; margin: 8px 0; }
  .emb-sugg button { margin: 4px 6px 0 0; font-size: 12px; padding: 4px 10px; border-radius: 999px; border: 1px dashed var(--brand); background: var(--surface); color: var(--brand); cursor: pointer; }
  .emb-zlist { display: flex; flex-wrap: wrap; gap: 6px; margin: 6px 0; }
  .emb-zlist span { display: inline-flex; align-items: center; gap: 6px; font-size: 13px; background: var(--warn-bg); color: var(--warn);
    border-radius: 999px; padding: 4px 6px 4px 10px; }
  .emb-zlist button { border: 0; background: none; cursor: pointer; font-size: 15px; color: inherit; padding: 0 4px; }
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
    <input type="hidden" name="zones" value="<?= h($v['zones']) ?>">
    <label class="emb-row" style="display:block">
      <b>Artwork</b>
      <input type="file" name="design" accept="image/png,image/jpeg,image/gif,image/webp,image/bmp,image/svg+xml,.svg,application/pdf,.pdf" required style="width:100%;margin-top:6px">
    </label>
    <label class="emb-row" id="embPageRow" hidden><span>PDF page</span><input type="number" id="embPage" min="1" value="1" step="1"></label>
    <p class="emb-note" id="embPdfNote" hidden></p>
    <p class="emb-note">PNG (transparent background is best), JPG, <b>SVG</b> or <b>PDF</b> (artwork from Illustrator, CorelDRAW, Inkscape, Canva…). Clean artwork with flat colors gives the best result; photos give many trims.</p>

    <div class="emb-h">Size & colors</div>
    <?= $num('width_mm', 'Width (mm)', '1') ?>
    <label class="emb-row"><span>Height (mm)</span><span style="display:flex;gap:6px;align-items:center">
      <button type="button" id="embLock" class="btn small" title="Width and height keep the artwork's proportions" style="padding:4px 8px">🔗</button>
      <input type="number" step="0.1" min="0" name="height_mm" value="<?= $v['height_mm'] ? h($v['height_mm']) : '' ?>" placeholder="auto" style="width:96px"></span></label>
    <p class="emb-note" id="embLockNote">🔗 Linked: change the width or the height and the other follows the original design's proportions.</p>
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
      <?= $num('satin_split', 'Split satin longer than (mm)', '0.5') ?>
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
    var key = pageNo + '@' + (parseFloat(form.querySelector('[name=width_mm]').value) || 100);
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
      // render sharp enough for small details: ~20 px per mm of the chosen width (3000–6000 px on the long side)
      var mm = parseFloat(form.querySelector('[name=width_mm]').value) || 100;
      var target = Math.min(6000, Math.max(3000, mm * 20));
      var scale = Math.min(16, target / Math.max(v1.width, v1.height));
      var vp = page.getViewport({ scale: scale });
      var c = document.createElement('canvas'); c.width = Math.ceil(vp.width); c.height = Math.ceil(vp.height);
      return page.render({ canvasContext: c.getContext('2d'), viewport: vp, background: 'rgba(0,0,0,0)' }).promise
        .then(function () { return new Promise(function (ok) { c.toBlob(ok, 'image/png'); }); });
    }).then(function (blob) { pdfCache[key] = blob; return blob; });
  }
  // ---------- SVG: drawn into a sharp PNG in the browser (the SVG never runs anything on the server)
  var svgCache = {};
  function isSvg(f) { return f && (f.type === 'image/svg+xml' || /\.svg$/i.test(f.name)); }
  function svgToPng(file) {
    var mm = parseFloat(form.querySelector('[name=width_mm]').value) || 100;
    var target = Math.min(6000, Math.max(3000, mm * 20)), key = file.name + file.size + '@' + target;
    if (svgCache[key]) return Promise.resolve(svgCache[key]);
    return file.text().then(function (txt) {
      var doc = new DOMParser().parseFromString(txt, 'image/svg+xml'), svg = doc.documentElement;
      if (!svg || svg.nodeName.toLowerCase() !== 'svg') throw new Error('This SVG file could not be read (Invalid SVG).');
      var num = function (v) { var n = parseFloat(v); return isFinite(n) && n > 0 ? n : 0; };
      var vb = (svg.getAttribute('viewBox') || '').trim().split(/[\s,]+/).map(Number);
      var w = vb.length === 4 && vb[2] > 0 ? vb[2] : num(svg.getAttribute('width')) || 1000;
      var h = vb.length === 4 && vb[3] > 0 ? vb[3] : num(svg.getAttribute('height')) || 1000;
      if (!(vb.length === 4 && vb[2] > 0)) svg.setAttribute('viewBox', '0 0 ' + w + ' ' + h);
      var scale = target / Math.max(w, h), W = Math.round(w * scale), H = Math.round(h * scale);
      svg.setAttribute('width', W); svg.setAttribute('height', H);
      svg.setAttribute('preserveAspectRatio', 'xMidYMid meet');
      var url = URL.createObjectURL(new Blob([new XMLSerializer().serializeToString(svg)], { type: 'image/svg+xml' }));
      return new Promise(function (ok, fail) {
        var img = new Image();
        img.onload = function () {
          var c = document.createElement('canvas'); c.width = W; c.height = H;
          c.getContext('2d').drawImage(img, 0, 0, W, H); URL.revokeObjectURL(url);
          c.toBlob(function (b) { svgCache = {}; svgCache[key] = b; ok(b); }, 'image/png');
        };
        img.onerror = function () { URL.revokeObjectURL(url); fail(new Error('This SVG file could not be drawn (Invalid SVG).')); };
        img.src = url;
      });
    });
  }

  function artwork() {
    var f = fileInput.files[0];
    if (isSvg(f)) { pageRow.hidden = true; pdfNote.hidden = true;
      return svgToPng(f).then(function (blob) { return [blob, f.name.replace(/\.svg$/i, '') + '.png']; }); }
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
        box.innerHTML = '<p class="alert err">' + esc(/PDF|SVG|password|Invalid/i.test(e.message || '') ? 'Could not read this file: ' + e.message : 'Could not convert – please check your internet connection and try again.') + '</p>';
      })
      .finally(function () { if (my !== ctrl) return;   // a newer update is still running
        box.classList.remove('busy'); btn.disabled = false; btn.textContent = 'Convert to DST'; });
  }
  function later() { clearTimeout(timer); if (converted) timer = setTimeout(convert, 900); }

  // ---------- width & height linked to the artwork's own proportions (🔗), or free to stretch
  var widthIn = form.querySelector('[name=width_mm]'), heightIn = form.querySelector('[name=height_mm]');
  var lockBtn = document.getElementById('embLock'), lockNote = document.getElementById('embLockNote');
  var linked = true, aspect = null, typed = 'w';     // the size the user typed last is kept exactly
  function r1(n) { return Math.round(n * 10) / 10; }
  function syncHeight() {
    if (!linked || !aspect) return;
    if (typed === 'h' && +heightIn.value > 0) widthIn.value = r1(heightIn.value * aspect);
    else if (+widthIn.value > 0) heightIn.value = r1(widthIn.value / aspect);
  }
  widthIn.addEventListener('input', function () { typed = 'w'; syncHeight(); });
  heightIn.addEventListener('input', function () { typed = 'h'; syncHeight(); });
  lockBtn.onclick = function () {
    linked = !linked; lockBtn.textContent = linked ? '🔗' : '⛓️‍💥'; lockBtn.classList.toggle('on', !linked);
    lockNote.textContent = linked ? '🔗 Linked: change the width or the height and the other follows the original design\'s proportions.'
                                  : 'Unlinked: width and height are set separately – the design will be stretched to exactly this size.';
    if (linked) { syncHeight(); later(); }
  };

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
    h += '<div class="emb-tools"><button type="button" class="btn" id="embPen">✏️ Mark a problem area</button>'
       + '<span class="emb-note" style="margin:0">Draw around a part that came out badly – then say what is wrong and only that part is redone.</span></div>';
    h += '<div class="emb-preview" id="embPrev">' + r.svg + '<svg class="emb-zones" id="embZones"></svg></div>';
    h += '<p class="emb-note" style="margin:0">⊕ <span style="color:#16a34a">green cross</span> = start &amp; end point (design centre, the machine starts and finishes here) · ● first stitch. Cuts are written so the machine trims automatically (3+ jumps).</p>';
    h += '<div class="emb-zlist" id="embZList"></div><div id="embFix"></div>';
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
    lastShapes = r.shapes || [];
    if (r.stats.aspect) { aspect = r.stats.aspect; syncHeight(); }   // show the real height for the chosen width
    setupZones();
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

  // ---------- "fix this area": draw around a bad part, say what is wrong, only that part is redone
  var zonesInput = form.querySelector('input[name=zones]');
  var zones = [], lastShapes = [], drawing = false, pts = null, vb = null;
  var ISSUES = [
    ['messy', 'Messy / scribbled stitches'], ['details', 'Small details lost or merged'], ['rough', 'Rough or hairy edges'],
    ['thick', 'Too thick / bulky'], ['gaps', 'Gaps – fabric shows'], ['remove', 'Remove this part']
  ];
  var TYPES = [['', 'Stitch type: automatic'], ['straight', 'Straight satin (all stitches parallel)'], ['satin', 'Satin that follows the shape'], ['fill', 'Tatami fill'], ['run', 'Outline only (running stitch)']];
  function saveZones() { zonesInput.value = zones.length ? JSON.stringify(zones.map(function (z) { return { pts: z.pts, fix: z.fix }; })) : ''; }
  function zoneLabel(z) {
    var f = z.fix, t = [];
    ISSUES.forEach(function (i) { if (f[i[0]]) t.push(i[1].split(' /')[0].split(' –')[0].toLowerCase()); });
    if (f.dir !== undefined) t.push('direction ' + f.dir + '°');
    if (f.type) t.push(TYPES.filter(function (x) { return x[0] === f.type; })[0][1].split(' (')[0].toLowerCase());
    return t.join(', ') || 'no change';
  }
  function setupZones() {
    var prev = document.getElementById('embPrev'), svgEl = prev.querySelector('svg:not(.emb-zones)'), ov = document.getElementById('embZones');
    var v = (svgEl.getAttribute('viewBox') || '0 0 100 100').split(/\s+/).map(Number);
    vb = { x: v[0], y: v[1], w: v[2], h: v[3], W: v[2] - 2, H: v[3] - 2 };    // preview has a 1 mm frame
    ov.setAttribute('viewBox', v.join(' '));
    drawZones();
    var pen = document.getElementById('embPen');
    pen.onclick = function () { drawing = !drawing; pen.classList.toggle('on', drawing); prev.classList.toggle('drawing', drawing);
      pen.textContent = drawing ? '✏️ Drawing… circle the bad part' : '✏️ Mark a problem area'; };
    function pt(e) {
      var r = ov.getBoundingClientRect();
      return [vb.x + (e.clientX - r.left) / r.width * vb.w, vb.y + (e.clientY - r.top) / r.height * vb.h];
    }
    ov.onpointerdown = function (e) { if (!drawing) return; e.preventDefault(); ov.setPointerCapture(e.pointerId); pts = [pt(e)]; drawZones(); };
    ov.onpointermove = function (e) { if (!drawing || !pts) return; var p = pt(e), l = pts[pts.length - 1];
      if (Math.hypot(p[0] - l[0], p[1] - l[1]) > vb.W / 300) { pts.push(p); drawZones(); } };
    ov.onpointerup = function () {
      if (!drawing || !pts) return;
      var done = pts; pts = null; drawing = false; pen.classList.remove('on'); prev.classList.remove('drawing'); pen.textContent = '✏️ Mark a problem area';
      if (done.length < 3) { drawZones(); return; }
      openFix({ pts: done.map(function (p) { return [+(p[0] / vb.W).toFixed(4), +(p[1] / vb.H).toFixed(4)]; }), fix: {}, note: '' }, -1);
    };
    var list = document.getElementById('embZList');
    list.innerHTML = zones.map(function (z, i) {
      var mine = lastShapes.filter(function (sh) { return sh[6] === i; });
      var rem = mine.filter(function (sh) { return sh[0] === 'removed'; }).length, done = mine.length - rem;
      var res = rem ? ' – ' + rem + ' part' + (rem > 1 ? 's' : '') + ' removed'
              : done ? ' – ✓ ' + done + ' part' + (done > 1 ? 's' : '') + ' redone'
              : ' – ⚠ nothing stitched inside this area – draw over the stitches you want changed';
      return '<span' + (!mine.length ? ' style="background:var(--err-bg);color:var(--err)"' : '') + '>Area ' + (i + 1) + ': ' + esc(zoneLabel(z)) + esc(res)
        + ' <button type="button" data-e="' + i + '" title="Change">✎</button><button type="button" data-d="' + i + '" title="Remove this fix">✕</button></span>';
    }).join('');
    list.querySelectorAll('[data-d]').forEach(function (b) { b.onclick = function () { zones.splice(+b.dataset.d, 1); saveZones(); convert(); }; });
    list.querySelectorAll('[data-e]').forEach(function (b) { b.onclick = function () { openFix(zones[+b.dataset.e], +b.dataset.e); }; });
  }
  function drawZones(edit) {
    var ov = document.getElementById('embZones'); if (!ov || !vb) return;
    var h = '', sw = vb.W / 250;
    zones.concat(edit ? [edit] : []).forEach(function (z, i) {
      var p = z.pts.map(function (q) { return (q[0] * vb.W).toFixed(2) + ',' + (q[1] * vb.H).toFixed(2); }).join(' ');
      var c = z.pts[0];
      h += '<polygon points="' + p + '" fill="rgba(245,158,11,.18)" stroke="#f59e0b" stroke-width="' + sw + '" stroke-dasharray="' + sw * 3 + ' ' + sw * 2 + '"/>';
      h += '<text x="' + (c[0] * vb.W) + '" y="' + (c[1] * vb.H) + '" font-size="' + sw * 9 + '" font-weight="700" fill="#b45309">' + (i + 1) + '</text>';
    });
    if (pts) h += '<polyline points="' + pts.map(function (q) { return q.join(','); }).join(' ') + '" fill="rgba(37,99,235,.12)" stroke="#2563eb" stroke-width="' + sw + '"/>';
    ov.innerHTML = h;
  }
  // what is inside the marked area -> suggestions
  function suggest(z) {
    var xs = z.pts.map(function (p) { return p[0]; }), ys = z.pts.map(function (p) { return p[1]; });
    var bx = [Math.min.apply(0, xs), Math.min.apply(0, ys), Math.max.apply(0, xs), Math.max.apply(0, ys)];
    var inside = lastShapes.filter(function (s) { return s[1] < bx[2] && s[3] > bx[0] && s[2] < bx[3] && s[4] > bx[1]; });
    var small = inside.filter(function (s) { return s[5] < 8; }), fills = inside.filter(function (s) { return s[0] === 'fill'; });
    var out = [];
    if (!inside.length) return [['details', 'Nothing is stitched here. If something is missing, it may be too small – try “Small details lost”.']];
    out.push(['', 'In this area: ' + inside.length + ' shape' + (inside.length > 1 ? 's' : '') + ' (' + ['fill', 'satin', 'run'].map(function (k) {
      var n = inside.filter(function (s) { return s[0] === k; }).length; return n ? n + ' ' + (k === 'run' ? 'line' : k) : ''; }).filter(Boolean).join(', ') + ').']);
    if (small.length) out.push(['messy', small.length + ' small part' + (small.length > 1 ? 's' : '') + ' (under 8 mm): if they look scribbled, use straight satin.']);
    if (small.length) out.push(['details', 'Small parts merged or missing? Keep every detail here.']);
    if (fills.length) out.push(['dir', 'Filled area: try another stitch direction for a cleaner look.']);
    if (inside.some(function (s) { return s[0] === 'satin' && s[5] >= 8; })) out.push(['rough', 'Satin edges look hairy? Smooth them.']);
    if (small.length && inside.every(function (s) { return s[5] < 4; })) out.push(['', 'Parts here are under 4 mm – making the whole design wider helps most.']);
    return out;
  }
  // understand what the user typed
  function understand(text) {
    var t = text.toLowerCase(), f = {}, said = [];
    var rules = [
      ['messy', /mess|scribbl|zig.?zag|star|burst|tangl|ugly|crazy|wrong stitch|bad stitch|criss|cross/],
      ['details', /detail|small|tiny|dot|point|lost|missing|merg|join|touch|disappear|gone|stuck together/],
      ['rough', /rough|hair|jagged|spik|edge|uneven|wavy|fuzz|not smooth|not clean/],
      ['thick', /thick|bulk|heavy|hard|stiff|too dense|too much|puffy/],
      ['gaps', /gap|fabric show|see.?through|sparse|not cover|holes?|thin cover|light/],
      ['remove', /remove|delete|erase|skip|don.?t (sew|stitch)|not need|no need/]
    ];
    rules.forEach(function (r) { if (r[1].test(t)) { f[r[0]] = 1; said.push(ISSUES.filter(function (i) { return i[0] === r[0]; })[0][1]); } });
    var deg = t.match(/(\d{1,3})\s*(°|deg)/);
    if (deg) f.dir = +deg[1] % 180;
    else if (/horizontal|left.?to.?right|flat|level/.test(t)) f.dir = 0;
    else if (/vertical|up.?down|top.?to.?bottom|standing/.test(t)) f.dir = 90;
    else if (/diagonal|slant|tilt|angle/.test(t)) f.dir = 45;
    if (f.dir !== undefined) said.push('stitch direction ' + f.dir + '°');
    if (/tatami|fill/.test(t)) f.type = 'fill'; else if (/outline|running|line only/.test(t)) f.type = 'run';
    else if (/straight|parallel/.test(t)) f.type = 'straight'; else if (/satin/.test(t)) f.type = 'satin';
    if (f.type) said.push(TYPES.filter(function (x) { return x[0] === f.type; })[0][1]);
    return [f, said];
  }
  function openFix(z, idx) {
    var el = document.getElementById('embFix'), f = z.fix;
    drawZones(idx < 0 ? z : null);
    var h = '<div class="emb-fix"><h3>' + (idx < 0 ? 'What went wrong in this area?' : 'Change the fix for area ' + (idx + 1)) + '</h3>';
    h += '<textarea id="embNote" placeholder="Describe it in your own words, e.g. “the dots are messy and stuck together” or “stitches should go horizontal”">' + esc(z.note || '') + '</textarea>';
    h += '<div class="emb-understood" id="embUnd"></div>';
    h += '<div class="emb-chips">' + ISSUES.map(function (i) { return '<label><input type="checkbox" data-k="' + i[0] + '"' + (f[i[0]] ? ' checked' : '') + '> ' + i[1] + '</label>'; }).join('') + '</div>';
    h += '<div class="emb-chips"><label><input type="checkbox" id="embDirOn"' + (f.dir !== undefined ? ' checked' : '') + '> Stitch direction</label>'
       + '<input type="number" id="embDir" min="0" max="179" step="5" value="' + (f.dir !== undefined ? f.dir : 45) + '" style="width:70px"> °'
       + ' <button type="button" class="btn small" data-a="0">↔</button><button type="button" class="btn small" data-a="90">↕</button>'
       + '<button type="button" class="btn small" data-a="45">⤢</button><button type="button" class="btn small" data-a="135">⤡</button>'
       + ' <select id="embType">' + TYPES.map(function (t) { return '<option value="' + t[0] + '"' + ((f.type || '') === t[0] ? ' selected' : '') + '>' + t[1] + '</option>'; }).join('') + '</select></div>';
    h += '<div class="emb-sugg" id="embSugg"></div>';
    h += '<p><button type="button" class="btn primary" id="embApply">Redo this area</button> <button type="button" class="btn" id="embCancel">Cancel</button>'
       + (idx >= 0 ? ' <button type="button" class="btn" id="embDel">Remove this fix</button>' : '') + '</p></div>';
    el.innerHTML = h;
    el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    var sugg = suggest(z);
    document.getElementById('embSugg').innerHTML = '<b>Suggestions:</b> ' + sugg.map(function (s) {
      return s[0] ? '<button type="button" data-s="' + s[0] + '">' + esc(s[1]) + '</button>' : '<div>' + esc(s[1]) + '</div>';
    }).join('');
    el.querySelectorAll('[data-s]').forEach(function (b) { b.onclick = function () {
      var k = b.dataset.s;
      if (k === 'dir') document.getElementById('embDirOn').checked = true;
      else { var c = el.querySelector('[data-k="' + k + '"]'); if (c) c.checked = true; }
    }; });
    el.querySelectorAll('[data-a]').forEach(function (b) { b.onclick = function () { document.getElementById('embDir').value = b.dataset.a; document.getElementById('embDirOn').checked = true; }; });
    var note = document.getElementById('embNote'), und = document.getElementById('embUnd');
    note.oninput = function () {
      var u = understand(note.value), f2 = u[0];
      el.querySelectorAll('[data-k]').forEach(function (c) { if (f2[c.dataset.k]) c.checked = true; });
      if (f2.dir !== undefined) { document.getElementById('embDirOn').checked = true; document.getElementById('embDir').value = f2.dir; }
      if (f2.type) document.getElementById('embType').value = f2.type;
      und.textContent = u[1].length ? '✓ Understood: ' + u[1].join(' · ') : '';
    };
    if (z.note) note.oninput();
    document.getElementById('embCancel').onclick = function () { el.innerHTML = ''; drawZones(); };
    if (idx >= 0) document.getElementById('embDel').onclick = function () { zones.splice(idx, 1); el.innerHTML = ''; saveZones(); convert(); };
    document.getElementById('embApply').onclick = function () {
      var fix = {};
      el.querySelectorAll('[data-k]').forEach(function (c) { if (c.checked) fix[c.dataset.k] = 1; });
      if (document.getElementById('embDirOn').checked) fix.dir = (+document.getElementById('embDir').value || 0) % 180;
      var ty = document.getElementById('embType').value; if (ty) fix.type = ty;
      if (!Object.keys(fix).length) { und.style.color = 'var(--err)'; und.textContent = 'Tick at least one problem (or describe it above) so the app knows what to change.'; return; }
      var zz = { pts: z.pts, fix: fix, note: note.value };
      if (idx < 0) zones.push(zz); else zones[idx] = zz;
      el.innerHTML = ''; saveZones(); convert();
    };
  }

  form.addEventListener('submit', function (e) { e.preventDefault(); clearTimeout(timer); convert(); });
  fileInput.addEventListener('change', function () { skipInput.value = ''; colorMap = {}; zones = []; saveZones(); aspect = null; if (linked) heightIn.value = ''; pageInput.value = 1; pdfDoc = null; pdfFile = null; if (converted) convert(); });
  form.addEventListener('input', function (e) { if (e.target !== fileInput) later(); });
  form.addEventListener('change', function (e) { if (e.target !== fileInput) later(); });
  ['embCover', 'embFabric', 'embThread'].forEach(function (id) { document.getElementById(id).addEventListener('change', later); });
  // the number of colors changes the design colors, so earlier choices no longer apply
  form.querySelector('[name=colors]').addEventListener('change', function () { skipInput.value = ''; colorMap = {}; });
})();
</script>
<?php require __DIR__ . '/inc/footer.php'; ?>
