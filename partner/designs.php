<?php
// Partner: their saved designs (in Looma's Saved designs, linked to them). A new design is saved here first,
// then picked when sending an order.
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/partners.php';
require __DIR__ . '/../inc/partner_ui.php';

$pu = require_partner();
$p = $pu['partner'];
$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    [$newId, $errors] = partner_create_design($pu, $_POST, $_FILES['photos'] ?? null);
    if ($newId && !$errors) {
        flash('Design “' . trim((string)$_POST['name']) . '” saved. You can now pick it in a new order.');
        redirect('partner/designs.php');
    }
}
$designs = partner_designs($p);
$showForm = !empty($_GET['new']) || $errors || !$designs;
partner_head('Designs', 'designs', $pu);
?>
<div class="pt-head">
  <div><h1 class="pt-title">Designs</h1><p class="pt-sub">Save a design once — then pick its picture when you order.</p></div>
  <?php if (!$showForm): ?><a class="pt-btn filled" href="?new=1">＋ New design</a><?php endif; ?>
</div>
<?php if ($showForm): ?>
<form method="post" enctype="multipart/form-data" class="pt-newdesign" id="newDesign">
  <?= csrf_field() ?>
  <?php if ($errors): ?><div class="pt-note err" role="alert"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>
  <label class="pt-drop" id="ptDrop">
    <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required>
    <span class="pt-drop-empty"><span class="pt-empty-ico">🖼</span><b>Add mock-up photos</b><small>Front and back · JPG, PNG or WEBP · up to 8</small></span>
    <span class="pt-drop-prev" hidden></span>
  </label>
  <section class="pt-card pt-fields">
    <label><span>Name</span><input name="name" required maxlength="150" value="<?= h($_POST['name'] ?? '') ?>" placeholder="As you call it, e.g. Kanye West"></label>
    <label class="tall"><span>Note</span><textarea name="note" rows="2" maxlength="2000" placeholder="Optional: where it prints, size, anything special"><?= h($_POST['note'] ?? '') ?></textarea></label>
  </section>
  <div class="pt-actions"><button class="pt-btn filled">Save design</button><?php if ($designs): ?><a class="pt-btn plain" href="designs.php">Cancel</a><?php endif; ?></div>
</form>
<script>
(function () { // photo previews before saving
  var drop = document.getElementById('ptDrop'), inp = drop.querySelector('input'), prev = drop.querySelector('.pt-drop-prev');
  inp.addEventListener('change', function () {
    prev.innerHTML = ''; [].slice.call(inp.files, 0, 8).forEach(function (f) { var i = new Image(); i.src = URL.createObjectURL(f); i.alt = ''; prev.appendChild(i); });
    prev.hidden = !inp.files.length; drop.querySelector('.pt-drop-empty').hidden = !!inp.files.length;
  });
  ['dragenter', 'dragover'].forEach(function (t) { drop.addEventListener(t, function () { drop.classList.add('over'); }); });
  ['dragleave', 'drop'].forEach(function (t) { drop.addEventListener(t, function () { drop.classList.remove('over'); }); });
})();
</script>
<?php endif; ?>
<?php if ($designs): ?>
<?php if ($showForm): ?><h2 class="pt-h2">Your designs</h2><?php endif; ?>
<div class="pt-grid">
  <?php foreach ($designs as $d): ?>
    <a class="pt-dcard" href="<?= h(base_url('partner/order.php?new=1&design=' . (int)$d['id'])) ?>" title="Order this design">
      <?php if ($d['images']): ?><img src="<?= h(partner_img($d['images'][0])) ?>" alt="" loading="lazy"><?php else: ?><span class="pt-tile-ph">👕</span><?php endif; ?>
      <span><b><?= h($d['name']) ?></b><small><?= !empty($d['extra']['partner_new']) ? 'New · Looma checks it' : count($d['images']) . ' photo' . (count($d['images']) === 1 ? '' : 's') ?></small></span>
      <span class="pt-dcard-go">Order ›</span>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php partner_foot(); ?>
