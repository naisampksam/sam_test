<?php
// How to (SOP): every feature step by step, searchable, in English and Malayalam (content: inc/sop.php).
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/tasks.php';
require __DIR__ . '/inc/sop.php';

$me = require_login();
$topics = sop_visible($me);
$lang = ($_COOKIE['sop_lang'] ?? 'en') === 'ml' ? 'ml' : 'en';
$pageTitle = 'How to';
$active = 'help';
require __DIR__ . '/inc/header.php';
$byCat = [];
foreach ($topics as $t) {
    $byCat[$t[1]][] = $t;
}
?>
<div class="sop-page lang-<?= $lang ?>" id="sop" data-lang="<?= $lang ?>">
  <div class="page-head sop-head">
    <div>
      <h1><span class="en">How to · SOP</span><span class="ml">എങ്ങനെ ചെയ്യാം · SOP</span></h1>
      <p class="muted small"><span class="en">Every part of the app, step by step. Search what you want to do.</span><span class="ml">ആപ്പിലെ എല്ലാ കാര്യവും, ഓരോ സ്റ്റെപ്പായി. ചെയ്യേണ്ടത് സെർച്ച് ചെയ്യുക.</span></p>
    </div>
    <div class="seg-toggle sop-lang" role="group" aria-label="Language">
      <label><input type="radio" name="sop_lang" value="en" <?= $lang === 'en' ? 'checked' : '' ?>><span>English</span></label>
      <label><input type="radio" name="sop_lang" value="ml" <?= $lang === 'ml' ? 'checked' : '' ?>><span>മലയാളം</span></label>
    </div>
  </div>

  <div class="sop-search">
    <input type="search" id="sopQ" autocomplete="off" value="<?= h((string)($_GET['q'] ?? '')) ?>"
           placeholder="Search: how to create an order, add stock, COD, label…" data-ph-en="Search: how to create an order, add stock, COD, label…" data-ph-ml="സെർച്ച്: ഓർഡർ ഉണ്ടാക്കാൻ, സ്റ്റോക്ക്, COD, ലേബൽ…" aria-label="Search">
    <p class="small muted" id="sopCount" aria-live="polite"></p>
    <p class="small muted sop-hint"><span class="en">Tap a step (or point at it) to see more.</span><span class="ml">കൂടുതൽ അറിയാൻ സ്റ്റെപ്പിൽ തൊടുക.</span></p>
  </div>

  <?php $seeIds = array_flip(array_column($topics, 0)); $opts = array_filter(sop_options(), fn($o) => isset($seeIds[$o[5]])); ?>
  <section class="sop-options" id="sopOptions">
    <h2 class="section-title"><span class="en">What’s in the app</span><span class="ml">ആപ്പിൽ എന്തൊക്കെ</span></h2>
    <div class="sop-opt-grid">
      <?php foreach ($opts as [$ic, $nen, $nml, $den, $dml, $tid]): ?>
        <a class="sop-opt" href="#<?= h($tid) ?>"><span class="sop-opt-ic" aria-hidden="true"><?= $ic ?></span>
          <span><b><span class="en"><?= h($nen) ?></span><span class="ml"><?= h($nml) ?></span></b>
          <small><span class="en"><?= h($den) ?></span><span class="ml"><?= h($dml) ?></span></small></span></a>
      <?php endforeach; ?>
    </div>
  </section>

  <nav class="date-chips sop-cats" aria-label="Sections">
    <a href="#" data-cat="" class="on"><span class="en">All</span><span class="ml">എല്ലാം</span></a>
    <?php foreach (SOP_CATS as $ck => [$cen, $cml]): if (empty($byCat[$ck])) continue; ?>
      <a href="#" data-cat="<?= $ck ?>"><span class="en"><?= h($cen) ?></span><span class="ml"><?= h($cml) ?></span></a>
    <?php endforeach; ?>
  </nav>

  <p class="empty sop-none" id="sopNone" hidden><span class="en">Nothing found. Try one word, e.g. “order”, “stock”, “label”, “task”.</span><span class="ml">ഒന്നും കിട്ടിയില്ല. ഒറ്റ വാക്ക് നോക്കൂ, ഉദാ: “order”, “stock”, “label”, “task”.</span></p>

  <div class="sop-list" id="sopList">
  <?php foreach (SOP_CATS as $ck => [$cen, $cml]): if (empty($byCat[$ck])) continue; ?>
    <h2 class="section-title sop-cat-title" data-cat-title="<?= $ck ?>"><span class="en"><?= h($cen) ?></span><span class="ml"><?= h($cml) ?></span></h2>
    <?php foreach ($byCat[$ck] as [$id, $cat, $who, $words, $en, $ml]): ?>
      <details class="panel sop-topic" id="<?= h($id) ?>" data-cat="<?= $cat ?>"
               data-search="<?= h(mb_strtolower($words . ' ' . $en[0] . ' ' . $ml[0])) ?>"
               data-body="<?= h(mb_strtolower(implode(' ', array_merge($en[1], $ml[1], [$en[2], $ml[2]])))) ?>">
        <summary><span class="en"><?= h($en[0]) ?></span><span class="ml"><?= h($ml[0]) ?></span>
          <?php if ($who === 'admin'): ?><span class="badge plain">Admin</span><?php endif; ?></summary>
        <?php foreach (['en' => $en, 'ml' => $ml] as $lk => [$title, $steps, $tip]): ?>
          <div class="<?= $lk ?>">
            <ol class="sop-steps"><?php foreach ($steps as $st): [$short, $why] = sop_step($st); ?>
              <li tabindex="0"><span class="sop-do"><?= h($short) ?><?php if ($why !== ''): ?> <i class="sop-i" aria-hidden="true">i</i><?php endif; ?></span><?php if ($why !== ''): ?><span class="sop-why"><?= h($why) ?></span><?php endif; ?></li>
            <?php endforeach; ?></ol>
            <?php if ($tip !== ''): ?><p class="sop-tip">💡 <?= h($tip) ?></p><?php endif; ?>
          </div>
        <?php endforeach; ?>
        <a class="sop-link small" href="#<?= h($id) ?>" data-copy-link><span class="en">🔗 Link to this</span><span class="ml">🔗 ഇതിന്റെ ലിങ്ക്</span></a>
      </details>
    <?php endforeach; ?>
  <?php endforeach; ?>
  </div>
</div>

<script>
// Search (both languages, any word order, small spelling differences), sections, language switch, links.
(function () {
  var root = document.getElementById('sop'), q = document.getElementById('sopQ'), list = document.getElementById('sopList');
  var topics = [].slice.call(list.querySelectorAll('.sop-topic')), cat = '';
  var STOP = ('how to i can do a an the my me we what is are in on for of and or with from it this that please want need ' +
    'എങ്ങനെ ചെയ്യാം ചെയ്യാൻ ചെയ്യണം ഒരു ആണ് എന്ത് എനിക്ക് ഞാൻ എന്റെ').split(' ');
  function words(s) {
    return s.toLowerCase().replace(/[^\p{L}\p{M}\p{N}\s-]/gu, ' ').split(/\s+/).filter(function (w) { return w.length > 1 && STOP.indexOf(w) < 0; });
  }
  // "orders" finds "order", "creating" finds "create", "tshirt" finds "t-shirt".
  function stem(w) { return w.replace(/-/g, '').replace(/(ing|ed|es|s)$/, ''); }
  function has(text, w) {
    if (text.indexOf(w) >= 0) return true;
    var s = stem(w);
    return s.length >= 3 && text.replace(/-/g, '').indexOf(s) >= 0;
  }
  function run() {
    var ws = words(q.value), shown = 0, scored = [];
    topics.forEach(function (t) {
      var okCat = !cat || t.dataset.cat === cat, score = 0;
      if (ws.length) {
        ws.forEach(function (w) {
          if (has(t.dataset.search, w)) score += 5; else if (has(t.dataset.body, w)) score += 1;
        });
        // most words must match somewhere
        var hits = ws.filter(function (w) { return has(t.dataset.search, w) || has(t.dataset.body, w); }).length;
        if (hits < Math.max(1, Math.ceil(ws.length * 0.6))) score = 0;
      } else score = 1;
      t.hidden = !(okCat && score > 0);
      if (!t.hidden) { shown++; scored.push([score, t]); }
    });
    // best answers first while searching; the normal order (by section) otherwise
    list.querySelectorAll('.sop-cat-title').forEach(function (h) { h.hidden = ws.length > 0 || (cat && h.dataset.catTitle !== cat); });
    if (ws.length) {
      scored.sort(function (a, b) { return b[0] - a[0]; }).forEach(function (x) { list.appendChild(x[1]); });
      topics.forEach(function (t) { t.open = false; });
      if (scored[0]) scored[0][1].open = true;
    } else {
      list.querySelectorAll('.sop-cat-title').forEach(function (h) {
        var after = h; topics.filter(function (t) { return t.dataset.cat === h.dataset.catTitle; }).forEach(function (t) { after.after(t); after = t; });
      });
    }
    document.getElementById('sopNone').hidden = shown > 0;
    document.getElementById('sopOptions').hidden = ws.length > 0 || cat !== '';
    var ml = root.dataset.lang === 'ml';
    document.getElementById('sopCount').textContent = ws.length ? (ml ? shown + ' ഫലങ്ങൾ' : shown + ' result' + (shown === 1 ? '' : 's')) : '';
  }
  function setLang(l) {
    root.dataset.lang = l; root.className = root.className.replace(/lang-\w+/, 'lang-' + l);
    q.placeholder = q.dataset['ph' + (l === 'ml' ? 'Ml' : 'En')];
    document.cookie = 'sop_lang=' + l + ';path=/;max-age=31536000;samesite=lax';
    run();
  }
  root.addEventListener('change', function (e) { if (e.target.name === 'sop_lang') setLang(e.target.value); });
  q.addEventListener('input', run);
  root.querySelector('.sop-cats').addEventListener('click', function (e) {
    var a = e.target.closest('[data-cat]'); if (!a) return;
    e.preventDefault(); cat = a.dataset.cat;
    root.querySelectorAll('.sop-cats a').forEach(function (x) { x.classList.toggle('on', x === a); });
    run();
  });
  // A step's explanation: shows on hover (computer); a tap opens / closes it (phone).
  root.addEventListener('click', function (e) {
    var li = e.target.closest('.sop-steps li');
    if (li && li.querySelector('.sop-why')) li.classList.toggle('show');
  });
  root.addEventListener('click', function (e) {
    var l = e.target.closest('[data-copy-link]'); if (!l) return;
    e.preventDefault();
    var url = location.href.split('#')[0].split('?')[0] + l.getAttribute('href');
    if (navigator.clipboard) navigator.clipboard.writeText(url).then(function () { l.classList.add('copied'); setTimeout(function () { l.classList.remove('copied'); }, 1500); });
    history.replaceState(null, '', l.getAttribute('href'));
  });
  setLang(root.dataset.lang);
  // help.php#create-order opens that topic (also when only the # part changes)
  function openHash() {
    var t = location.hash.length > 1 && document.getElementById(location.hash.slice(1));
    if (!t || !t.classList.contains('sop-topic')) return false;
    if (t.hidden) { q.value = ''; cat = ''; run(); }
    t.open = true; setTimeout(function () { t.scrollIntoView({ block: 'start' }); }, 50);
    return true;
  }
  window.addEventListener('hashchange', openHash);
  if (!openHash() && !('ontouchstart' in window)) q.focus();
})();
</script>
<?php require __DIR__ . '/inc/footer.php'; ?>
