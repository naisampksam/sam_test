<?php
// Partner: their products (Designs). A product = T-shirt + colour + mock-up (made here on a T-shirt picture,
// or ready photos uploaded) + print file link. Saved once, then added to an order in one tap; can be edited.
// Lives in Looma's Saved designs, linked to them. Mock-ups are stored small (1200px WebP).
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/partners.php';
require __DIR__ . '/../inc/partner_ui.php';
require __DIR__ . '/../inc/partner_studio.php';

$pu = require_partner();
$p = $pu['partner'];
$errors = [];
$editId = (int)($_GET['edit'] ?? 0);
$ed = $editId ? partner_design($p, $editId) : null;
if ($editId && !$ed) {
    http_response_code(404);
    exit('Product not found.');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'link') {
        $err = partner_design_link($pu, (int)($_POST['design_id'] ?? 0), (string)($_POST['print_link'] ?? ''));
        flash($err ?? 'Print file link saved.', $err ? 'err' : 'ok');
        redirect('partner/designs.php');
    }
    [$newId, $errors] = partner_save_product($pu, $_POST, $_FILES['photos'] ?? null, $ed ? $editId : null);
    if ($newId && !$errors) {
        flash('Product “' . trim((string)$_POST['name']) . '” saved.' . ($ed ? '' : ' Add it to a new order in one tap.'));
        redirect('partner/designs.php');
    }
}
$designs = partner_designs($p);
$showForm = $ed || !empty($_GET['new']) || $errors || !$designs;
// form values: what was typed, else the product being edited
$v = [
    'name' => $_POST['name'] ?? ($ed['name'] ?? ''),
    'tshirt' => $_POST['tshirt'] ?? ($ed && $ed['product'] !== '' ? $ed['gsm'] . '|' . $ed['product'] : ''),
    'color' => $_POST['color'] ?? ($ed['color'] ?? ''),
    'print_link' => $_POST['print_link'] ?? (string)($ed['extra']['print_link'] ?? ''),
    'note' => $_POST['note'] ?? (string)($ed['extra']['partner_note'] ?? ''),
];
$edImgs = $ed ? q('SELECT id, filename FROM design_images WHERE design_id = ? ORDER BY id', [$editId])->fetchAll() : [];
$removed = array_map('intval', (array)($_POST['remove_img'] ?? []));
$cat = catalog();
partner_head($ed ? 'Edit ' . $ed['name'] : 'Designs', 'designs', $pu);
?>
<?php if ($ed): ?><a class="pt-back" href="<?= h(base_url('partner/designs.php')) ?>">‹ Designs</a><?php endif; ?>
<div class="pt-head">
  <div><h1 class="pt-title"><?= $ed ? 'Edit product' : 'Designs' ?></h1>
    <p class="pt-sub"><?= $ed ? h($ed['name']) : 'Your products — T-shirt, colour and mock-up saved once. Add one to an order in a tap.' ?></p></div>
  <?php if (!$showForm): ?><a class="pt-btn filled" href="?new=1">＋ New product</a><?php endif; ?>
</div>
<?php if ($showForm): ?>
<form method="post" enctype="multipart/form-data" class="pt-newdesign pt-form-block" id="newDesign" data-catalog="<?= h(json_encode($cat, JSON_UNESCAPED_UNICODE)) ?>">
  <?= csrf_field() ?>
  <?php if ($errors): ?><div class="pt-note err" role="alert"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>

  <h2 class="pt-h2">T-shirt</h2>
  <section class="pt-card pt-line pt-prod-ts">
    <label class="pt-pick"><span>T-shirt</span><select name="tshirt" id="pdTshirt" required>
      <option value="">Choose…</option>
      <?php foreach ($cat as $g): foreach ($g['products'] as $pr): $k = $g['gsm'] . '|' . $pr['name']; ?>
        <option value="<?= h($k) ?>" <?= $v['tshirt'] === $k ? 'selected' : '' ?>><?= h($pr['name'] . ' · ' . $g['gsm']) ?></option>
      <?php endforeach; endforeach; ?>
    </select></label>
    <div class="pt-sub2">Colour</div>
    <div class="pt-sws" role="radiogroup" id="pdColors" data-color="<?= h($v['color']) ?>"><p class="pt-hint">Choose the T-shirt to see its colours.</p></div>
    <p class="pt-hint" id="pdSizes" hidden></p>
  </section>

  <h2 class="pt-h2">Mock-up</h2>
  <div class="pt-seg pt-mk-mode" role="radiogroup" aria-label="Mock-up">
    <label><input type="radio" name="mk_mode" value="make" checked><span>Make it here</span></label>
    <label><input type="radio" name="mk_mode" value="upload"><span>Upload photos</span></label>
  </div>
  <div id="pdMake">
    <p class="pt-hint" id="pdPick">Choose the T-shirt and colour above — then add your design or text on it.</p>
    <?php require __DIR__ . '/../inc/partner_studio_markup.php'; ?>
    <p class="pt-hint">Only the finished mock-up is saved (small, to keep space). Send the print file by Google Drive link below.</p>
  </div>
  <label class="pt-drop" id="ptDrop" hidden>
    <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple>
    <span class="pt-drop-empty"><span class="pt-empty-ico">🖼</span><b>Add mock-up photos</b><small>Front and back · JPG, PNG or WEBP · up to 8</small></span>
    <span class="pt-drop-prev" hidden></span>
  </label>
  <?php if ($edImgs): ?>
    <div class="pt-sub2">Saved pictures <small>— tap ✕ to remove</small></div>
    <p class="pt-hint">Changed the colour? Make a new mock-up above and remove the old picture.</p>
    <div class="pt-mk-saved">
      <?php foreach ($edImgs as $im): ?>
        <label class="pt-mk-old"><img src="<?= h(partner_img($im['filename'])) ?>" alt="" loading="lazy">
          <input type="checkbox" name="remove_img[]" value="<?= (int)$im['id'] ?>" <?= in_array((int)$im['id'], $removed, true) ? 'checked' : '' ?>><i aria-label="Remove">✕</i></label>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <div id="pdMade"></div>

  <h2 class="pt-h2">Details</h2>
  <section class="pt-card pt-fields">
    <label><span>Product name</span><input name="name" required maxlength="150" value="<?= h($v['name']) ?>" placeholder="As you call it, e.g. Kanye Black Oversized"></label>
    <label><span>Print file</span><input name="print_link" type="url" inputmode="url" value="<?= h($v['print_link']) ?>" placeholder="Google Drive link (optional)"></label>
    <label class="tall"><span>Note</span><textarea name="note" rows="2" maxlength="2000" placeholder="Optional: where it prints, size, anything special"><?= h($v['note']) ?></textarea></label>
  </section>
  <p class="pt-hint">Print file: upload the original (full size PNG / PDF) to Google Drive, set sharing to “Anyone with the link”, and paste the link above — or WhatsApp the original file to Looma.</p>
  <div class="pt-actions"><button class="pt-btn filled"><?= $ed ? 'Save changes' : 'Save product' ?></button><?php if ($designs): ?><a class="pt-btn plain" href="designs.php">Cancel</a><?php endif; ?></div>
</form>
<link rel="stylesheet" href="<?= h(asset('assets/studio/fonts.css')) ?>">
<link rel="stylesheet" href="<?= h(asset('assets/studio/studio.css')) ?>">
<script src="<?= h(asset('assets/studio/studio.js')) ?>"></script>
<script src="<?= h(asset('assets/partner_product.js')) ?>"></script>
<?php endif; ?>
<?php if ($designs && !$ed): ?>
<?php if ($showForm): ?><h2 class="pt-h2">Your products</h2><?php endif; ?>
<div class="pt-grid">
  <?php foreach ($designs as $d): ?>
    <div class="pt-dwrap"><a class="pt-dcard" href="<?= h(base_url('partner/order.php?new=1&design=' . (int)$d['id'])) ?>" title="Order this product">
      <?php if ($d['images']): ?><img src="<?= h(partner_img($d['images'][0])) ?>" alt="" loading="lazy"><?php else: ?><span class="pt-tile-ph">👕</span><?php endif; ?>
      <span><b><?= h($d['name']) ?></b><small><?= $d['product'] !== '' ? h($d['product'] . ' · ' . $d['gsm'] . ($d['color'] !== '' ? ' · ' . $d['color'] : '')) : (!empty($d['extra']['partner_new']) ? 'New · Looma checks it' : count($d['images']) . ' photo' . (count($d['images']) === 1 ? '' : 's')) ?></small></span>
      <span class="pt-dcard-go">Order ›</span>
    </a>
    <div class="pt-dacts"><a class="pt-btn tinted pt-small" href="?edit=<?= (int)$d['id'] ?>">Edit</a>
    <details class="pt-dlink"><summary><?= !empty($d['extra']['print_link']) ? '✓ Print file link' : '＋ Print file link' ?></summary>
      <form method="post" class="pt-dlink-f"><?= csrf_field() ?><input type="hidden" name="action" value="link"><input type="hidden" name="design_id" value="<?= (int)$d['id'] ?>">
        <input name="print_link" type="url" value="<?= h((string)($d['extra']['print_link'] ?? '')) ?>" placeholder="https://drive.google.com/…"><button class="pt-btn tinted pt-small">Save</button></form></details></div></div>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php partner_foot(); ?>
