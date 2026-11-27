<?php
// Partner: import their Shopify products as products. 1) upload Shopify's products export (CSV); 2) check the
// match — our T-shirt and, for each Shopify colour, our colour (guessed; Chocolate → Brown); 3) import. Their Shopify
// pictures are then downloaded one by one on the Products page (fetch).
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/shop_import.php';
require __DIR__ . '/../inc/partner_ui.php';

$pu = require_partner();
$p = $pu['partner'];
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'fetch') { // one product's pictures (called by the Products page)
        header('Content-Type: application/json');
        $err = shop_import_fetch_images($pu, (int)($_POST['id'] ?? 0));
        echo json_encode(['ok' => $err === null, 'msg' => $err]);
        exit;
    }
    if ($act === 'cancel') {
        unset($_SESSION['shop_import']);
        redirect('partner/designs.php');
    }
    if ($act === 'upload') {
        $f = $_FILES['csv'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name']) || $f['size'] > 20 * 1024 * 1024) {
            $errors[] = 'Choose the products file exported from Shopify (CSV, up to 20 MB).';
        } else {
            [$list, $err] = shop_csv_products($f['tmp_name']);
            if ($err) {
                $errors[] = $err;
            } else {
                $_SESSION['shop_import'] = $list;
                redirect('partner/shop_import.php');
            }
        }
    }
    if ($act === 'import' && !empty($_SESSION['shop_import'])) {
        [$made, $skipped, $errors] = shop_import_save($pu, $_SESSION['shop_import'], (array)($_POST['rows'] ?? []));
        if (!$errors) {
            unset($_SESSION['shop_import']);
            flash($made . ' product' . ($made === 1 ? '' : 's') . ' imported from Shopify.' . ($skipped ? ' Already there (skipped): ' . implode(', ', array_slice($skipped, 0, 5)) . (count($skipped) > 5 ? '…' : '') . '.' : '')
                . ($made ? ' Their pictures are loading now.' : ''));
            redirect('partner/designs.php');
        }
    }
}
$list = $_SESSION['shop_import'] ?? [];
$cat = catalog();
$ts = [];
foreach ($cat as $g) {
    foreach ($g['products'] as $pr) {
        $ts[$g['gsm'] . '|' . $pr['name']] = ['label' => $pr['name'] . ' · ' . $g['gsm'], 'colours' => array_column($pr['colors'], 'name')];
    }
}
$existing = array_map(fn($d) => mb_strtolower($d['name']), partner_designs($p));
partner_head('Import from Shopify', 'designs', $pu);
?>
<a class="pt-back" href="<?= h(base_url('partner/designs.php')) ?>">‹ Products</a>
<h1 class="pt-title">Import from Shopify</h1>
<?php if ($errors): ?><div class="pt-note err" role="alert"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>

<?php if (!$list): ?>
  <p class="pt-sub">Bring your Shopify products here in one go — no need to create them one by one. Your Shopify orders then find them by their name.</p>
  <ol class="pt-stepper pt-imp-how">
    <li><span class="pt-stepn">1</span><div class="pt-stepb"><b>Export from Shopify</b><p class="pt-hint">Shopify admin → <b>Products</b> → <b>Export</b> → “All products” → “CSV for Excel” or “Plain CSV” → Export. Shopify emails you the file.</p></div></li>
    <li><span class="pt-stepn">2</span><div class="pt-stepb"><b>Upload it here</b>
      <form method="post" enctype="multipart/form-data" class="pt-form-block" id="ptImpUp"><?= csrf_field() ?><input type="hidden" name="action" value="upload">
        <div class="pt-fields"><label><span>Shopify products file (.csv)</span><input type="file" name="csv" accept=".csv,text/csv" required></label></div>
        <button class="pt-btn filled wide">Upload and check</button></form></div></li>
    <li><span class="pt-stepn">3</span><div class="pt-stepb"><b>Check the match</b><p class="pt-hint">We match each product to our T-shirt and colours (e.g. your “Chocolate” = our Brown). You fix anything we could not match, then import.</p></div></li>
  </ol>
<?php else:
    $guess = [];
    foreach ($list as $sp) {
        $t = shop_guess_tshirt($sp['hint']);
        $c = [];
        foreach ($sp['colours'] ?: ['_'] as $sc) {
            $c[$sc] = $t !== '' ? shop_guess_colour($sc === '_' ? '' : $sc, $t) : '';
            if ($sc === '_' && $t !== '' && count($ts[$t]['colours']) === 1) {
                $c[$sc] = $ts[$t]['colours'][0];
            }
        }
        $guess[$sp['handle']] = ['t' => $t, 'c' => $c];
    }
    $posted = (array)($_POST['rows'] ?? []); ?>
  <p class="pt-sub"><?= count($list) ?> product<?= count($list) === 1 ? '' : 's' ?> found. Check our T-shirt and colour for each — <b class="pt-need">red</b> ones need your choice.</p>
  <form method="post" class="pt-form-block" id="ptImp" data-ts="<?= h(json_encode($ts, JSON_UNESCAPED_UNICODE)) ?>" data-words="<?= h(json_encode(SHOP_COLOUR_WORDS)) ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="import">
    <section class="pt-card pt-imp-all">
      <label class="pt-pick"><span>Same T-shirt for all</span><select id="ptImpAll"><option value="">Choose to set all…</option>
        <?php foreach ($ts as $k => $t): ?><option value="<?= h($k) ?>"><?= h($t['label']) ?></option><?php endforeach; ?></select></label>
    </section>
    <?php foreach ($list as $sp): $hd = $sp['handle']; $g = $guess[$hd]; $pr = $posted[$hd] ?? null; $there = in_array(mb_strtolower($sp['title']), $existing, true);
        $tSel = $pr['tshirt'] ?? $g['t']; ?>
      <section class="pt-card pt-imp<?= $there ? ' there' : '' ?>" data-h="<?= h($hd) ?>">
        <label class="pt-imp-head"><input type="checkbox" name="rows[<?= h($hd) ?>][on]" value="1" <?= ($pr ? !empty($pr['on']) : !$there) ? 'checked' : '' ?>>
          <?php if ($sp['images']): ?><img src="<?= h($sp['images'][0]) ?>" alt="" loading="lazy" referrerpolicy="no-referrer"><?php else: ?><span class="pt-tile-ph">👕</span><?php endif; ?>
          <span><b><?= h($sp['title']) ?></b><small><?= $there ? 'Already in your products — skipped' : h(($sp['sizes'] ? 'Sizes ' . implode(', ', $sp['sizes']) : 'No sizes') . ' · ' . ($sp['colours'] ? count($sp['colours']) . ' colour' . (count($sp['colours']) === 1 ? '' : 's') : 'one colour')) ?></small></span></label>
        <div class="pt-imp-body">
          <label class="pt-pick"><span>Our T-shirt</span><select name="rows[<?= h($hd) ?>][tshirt]" data-tshirt>
            <option value="">Choose…</option>
            <?php foreach ($ts as $k => $t): ?><option value="<?= h($k) ?>" <?= $tSel === $k ? 'selected' : '' ?>><?= h($t['label']) ?></option><?php endforeach; ?></select></label>
          <div class="pt-imp-cols">
            <?php foreach ($sp['colours'] ?: ['_'] as $sc): $cSel = $pr['colour'][$sc] ?? $g['c'][$sc] ?? ''; ?>
              <label class="pt-imp-col"><span><?= $sc === '_' ? 'Colour' : 'Your “' . h($sc) . '”' ?> →</span>
                <select name="rows[<?= h($hd) ?>][colour][<?= h($sc) ?>]" data-col data-from="<?= h($sc === '_' ? '' : $sc) ?>" data-sel="<?= h($cSel) ?>">
                  <option value="">Our colour…</option>
                  <?php foreach ($tSel !== '' && isset($ts[$tSel]) ? $ts[$tSel]['colours'] : [] as $cn): ?><option <?= $cSel === $cn ? 'selected' : '' ?>><?= h($cn) ?></option><?php endforeach; ?></select></label>
            <?php endforeach; ?>
          </div>
        </div>
      </section>
    <?php endforeach; ?>
    <div class="pt-dock pt-imp-dock"><span id="ptImpCount"></span>
      <button class="pt-btn filled" id="ptImpGo">Import</button></div>
  </form>
  <form method="post" style="text-align:center;margin-top:8px"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="pt-btn plain">Cancel</button></form>
  <script>
  (function () {
    var f = document.getElementById('ptImp'), TS = JSON.parse(f.dataset.ts), W = JSON.parse(f.dataset.words);
    function guess(v, names) {
      var l = v.toLowerCase().trim(), i, k;
      for (i = 0; i < names.length; i++) if (names[i].toLowerCase() === l) return names[i];
      for (k in W) if (l === k || l.indexOf(k) >= 0) { for (i = 0; i < names.length; i++) if (names[i].toLowerCase() === W[k]) return names[i]; for (i = 0; i < names.length; i++) if (names[i].toLowerCase().indexOf(W[k]) >= 0) return names[i]; }
      for (i = 0; i < names.length; i++) if (new RegExp('\\b' + names[i].toLowerCase().replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + '\\b').test(l)) return names[i];
      return names.length === 1 ? names[0] : '';
    }
    function fill(card) {
      var t = TS[card.querySelector('[data-tshirt]').value], names = t ? t.colours : [];
      [].forEach.call(card.querySelectorAll('[data-col]'), function (s) {
        var keep = s.value || s.dataset.sel, pick = names.indexOf(keep) >= 0 ? keep : guess(s.dataset.from, names);
        s.innerHTML = '<option value="">Our colour…</option>' + names.map(function (n) { return '<option' + (n === pick ? ' selected' : '') + '>' + n.replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }) + '</option>'; }).join('');
      });
      mark(card);
    }
    function mark(card) {
      var on = card.querySelector('.pt-imp-head input').checked, bad = !card.querySelector('[data-tshirt]').value;
      [].forEach.call(card.querySelectorAll('[data-col]'), function (s) { s.classList.toggle('bad', on && !s.value); if (!s.value) bad = true; });
      card.querySelector('[data-tshirt]').classList.toggle('bad', on && !card.querySelector('[data-tshirt]').value);
      card.classList.toggle('off', !on); card.classList.toggle('needs', on && bad);
      count();
    }
    function count() {
      var cards = f.querySelectorAll('.pt-imp'), on = 0, need = 0;
      [].forEach.call(cards, function (c) { if (c.querySelector('.pt-imp-head input').checked) { on++; if (c.classList.contains('needs')) need++; } });
      document.getElementById('ptImpCount').textContent = on + ' to import' + (need ? ' · ' + need + ' need a choice' : '');
      document.getElementById('ptImpGo').textContent = 'Import ' + on;
    }
    f.addEventListener('change', function (e) {
      var card = e.target.closest('.pt-imp');
      if (e.target.id === 'ptImpAll' && e.target.value) { [].forEach.call(f.querySelectorAll('.pt-imp'), function (c) { c.querySelector('[data-tshirt]').value = e.target.value; fill(c); }); return; }
      if (!card) return;
      if (e.target.matches('[data-tshirt]')) fill(card); else mark(card);
    });
    f.addEventListener('submit', function (e) {
      var bad = f.querySelector('.pt-imp.needs:not(.off)');
      if (bad) { e.preventDefault(); bad.scrollIntoView({ block: 'center', behavior: 'smooth' }); bad.classList.add('shake'); setTimeout(function () { bad.classList.remove('shake'); }, 500); }
    });
    [].forEach.call(f.querySelectorAll('.pt-imp'), mark);
  })();
  </script>
<?php endif; ?>
<?php partner_foot(); ?>
