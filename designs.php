<?php
// Saved designs: a product + print + mock-up images that staff can drop into any order in one tap.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/orders.php';

require_login();
$canManage = cap('designs');
if (!$canManage && !cap('create')) {
    http_response_code(403);
    exit('Not allowed.');
}

$id = (int)($_GET['id'] ?? 0);
$isNew = !empty($_GET['new']);
$errors = [];
$printFields = ['front_print' => 'Front print', 'back_print' => 'Back print', 'chest_print' => 'Chest print', 'custom_print' => 'Custom print'];
$itemCustom = array_filter(custom_fields(), fn($f) => $f['scope'] === 'item');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage && ($_POST['do'] ?? '') === 'delete' && $id) {
    csrf_check();
    $name = (string)q('SELECT name FROM designs WHERE id = ?', [$id])->fetchColumn();
    $files = array_column(q('SELECT filename FROM design_images WHERE design_id = ?', [$id])->fetchAll(), 'filename');
    q('DELETE FROM design_images WHERE design_id = ?', [$id]);
    q('DELETE FROM designs WHERE id = ?', [$id]);
    foreach ($files as $file) {
        unlink_if_unused($file); // images already attached to orders are kept
    }
    flash('Design "' . $name . '" deleted. Orders that used it keep their mock-ups.');
    redirect('designs.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $canManage) {
    csrf_check();
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') {
        $errors[] = 'Give the design a name.';
    }
    if (!$errors) {
        $row = [
            'name' => $name,
            'code' => trim((string)($_POST['code'] ?? '')),
            'gsm' => trim((string)($_POST['gsm'] ?? '')),
            'product' => trim((string)($_POST['product'] ?? '')),
            'color' => trim((string)($_POST['color'] ?? '')),
            'neck_label_on' => !empty($_POST['neck_label_on']) ? 1 : 0,
            'neck_label' => trim((string)($_POST['neck_label'] ?? '')),
            'active' => !empty($_POST['active']) || !$id ? 1 : 0,
            'updated_at' => now(),
        ];
        foreach ($printFields as $k => $l) {
            $row[$k] = trim((string)($_POST[$k] ?? ''));
        }
        $extra = [];
        foreach ($itemCustom as $k => $f) {
            $extra[$k] = $f['type'] === 'checkbox' ? (!empty($_POST[$k]) ? '1' : '') : trim((string)($_POST[$k] ?? ''));
        }
        $row['extra'] = json_encode($extra, JSON_UNESCAPED_UNICODE);
        if ($id) {
            update_row('designs', $id, $row);
        } else {
            $row += ['created_by' => current_user()['id'], 'created_at' => now()];
            $cols = array_keys($row);
            q('INSERT INTO designs (' . implode(',', $cols) . ') VALUES (' . rtrim(str_repeat('?,', count($cols)), ',') . ')', array_values($row));
            $id = (int)db()->lastInsertId();
        }
        foreach ((array)($_POST['delete_images'] ?? []) as $imgId) {
            $img = q('SELECT * FROM design_images WHERE id = ? AND design_id = ?', [(int)$imgId, $id])->fetch();
            if ($img) {
                q('DELETE FROM design_images WHERE id = ?', [$img['id']]);
                unlink_if_unused($img['filename']);
            }
        }
        foreach (normalise_files($_FILES['images'] ?? null) as $f) {
            [$path, $err] = store_upload($f);
            if ($err) {
                $errors[] = $err;
            }
            if ($path) {
                q('INSERT INTO design_images (design_id, filename, original_name, created_at) VALUES (?, ?, ?, ?)', [$id, $path, mb_substr($f['name'], 0, 250), now()]);
            }
        }
        flash('Design "' . $name . '" saved.' . ($errors ? ' ' . implode(' ', $errors) : ''), $errors ? 'err' : 'ok');
        redirect('designs.php' . ($errors ? '?id=' . $id : ''));
    }
}

function normalise_files(?array $f): array
{
    if (!$f || !isset($f['name']) || !is_array($f['name'])) {
        return [];
    }
    $out = [];
    foreach ($f['name'] as $i => $n) {
        $out[] = ['name' => $n, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $out;
}

$editing = ($id || $isNew) && $canManage;
$pageTitle = 'Saved designs';
$active = 'designs';
require __DIR__ . '/inc/header.php';

if ($editing):
    $d = $id ? q('SELECT * FROM designs WHERE id = ?', [$id])->fetch() : null;
    if ($id && !$d) {
        echo '<p class="empty">Design not found.</p>';
        require __DIR__ . '/inc/footer.php';
        exit;
    }
    $d = $d ?: ['name' => '', 'code' => '', 'gsm' => '', 'product' => '', 'color' => '', 'front_print' => '', 'back_print' => '', 'chest_print' => '',
        'custom_print' => '', 'neck_label_on' => 0, 'neck_label' => '', 'extra' => '{}', 'active' => 1];
    foreach (['name', 'code', 'gsm', 'product', 'color', 'neck_label', ...array_keys($printFields)] as $k) {
        if (isset($_POST[$k])) {
            $d[$k] = $_POST[$k];
        }
    }
    $dExtra = json_decode($d['extra'] ?: '{}', true) ?: [];
    $imgs = $id ? q('SELECT * FROM design_images WHERE design_id = ? ORDER BY id', [$id])->fetchAll() : [];
    $vals = json_encode(['gsm' => $d['gsm'], 'product' => $d['product'], 'color' => $d['color']], JSON_UNESCAPED_UNICODE);
?>
<div class="page-head">
  <div><a class="back" href="designs.php">← Saved designs</a><h1><?= $id ? h($d['name']) : 'New design' ?></h1></div>
</div>
<?php if ($errors): ?><div class="alert err"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>
<form method="post" enctype="multipart/form-data" id="orderForm" data-catalog="<?= h(json_encode(catalog(), JSON_UNESCAPED_UNICODE)) ?>">
  <?= csrf_field() ?>
  <section class="panel">
    <div class="grid">
      <label class="field"><span class="lbl">Design name <i class="req">*</i></span><input name="name" value="<?= h($d['name']) ?>" required placeholder="e.g. Better Days – mountain back print"></label>
      <label class="field"><span class="lbl">Code (optional)</span><input name="code" value="<?= h($d['code']) ?>" placeholder="e.g. BD-01"></label>
    </div>
  </section>
  <section class="panel design-card" data-values="<?= h($vals) ?>">
    <h2>Blank T-shirt <small class="muted">(optional — filled in when picked; size is chosen per order)</small></h2>
    <div class="grid">
      <div class="field"><span class="lbl">GSM</span><select name="gsm" data-cat="gsm" data-value="<?= h($d['gsm']) ?>"><option value="<?= h($d['gsm']) ?>"><?= h($d['gsm'] ?: 'Select…') ?></option></select></div>
      <div class="field"><span class="lbl">Product</span><select name="product" data-cat="product" data-value="<?= h($d['product']) ?>"><option value="<?= h($d['product']) ?>"><?= h($d['product'] ?: 'Select…') ?></option></select></div>
      <div class="field"><span class="lbl">Color</span><select name="color" data-cat="color" data-value="<?= h($d['color']) ?>"><option value="<?= h($d['color']) ?>"><?= h($d['color'] ?: 'Select…') ?></option></select></div>
    </div>
  </section>
  <section class="panel">
    <h2>Mock-up images</h2>
    <?php if ($imgs): ?>
      <div class="gallery edit">
        <?php foreach ($imgs as $img): ?>
          <label class="gal-item"><img src="image.php?f=<?= h(urlencode(thumb_path($img['filename']))) ?>" alt="">
            <span class="del"><input type="checkbox" name="delete_images[]" value="<?= (int)$img['id'] ?>"> Remove</span></label>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
    <label class="upload-box" style="margin-top:10px">
      <input type="file" name="images[]" accept="image/*" multiple data-preview>
      <span class="upload-icon" aria-hidden="true">📷</span>
      <span><b>Add mock-up photos</b><small>Front, back… these are attached to every order that uses this design</small></span>
    </label>
    <div class="preview"></div>
  </section>
  <section class="panel">
    <h2>Print details</h2>
    <div class="grid">
      <?php foreach ($printFields as $k => $l): ?>
        <label class="field full"><span class="lbl"><?= h($l) ?></span><textarea name="<?= $k ?>" rows="2"><?= h($d[$k]) ?></textarea></label>
      <?php endforeach; ?>
      <?php foreach ($itemCustom as $k => $f): $v = (string)($dExtra[$k] ?? ''); ?>
        <label class="field"><span class="lbl"><?= h($f['label']) ?></span>
          <?php if ($f['type'] === 'select'): ?>
            <select name="<?= h($k) ?>" data-other="1"><option value="">Select…</option>
              <?php foreach ($f['options'] as $opt): ?><option <?= $opt === $v ? 'selected' : '' ?>><?= h($opt) ?></option><?php endforeach; ?>
              <?php if ($v !== '' && !in_array($v, $f['options'], true)): ?><option selected><?= h($v) ?></option><?php endif; ?>
            </select>
          <?php elseif ($f['type'] === 'checkbox'): ?>
            <span class="check"><input type="checkbox" name="<?= h($k) ?>" value="1" <?= $v ? 'checked' : '' ?>> Yes</span>
          <?php else: ?>
            <input name="<?= h($k) ?>" value="<?= h($v) ?>">
          <?php endif; ?>
        </label>
      <?php endforeach; ?>
    </div>
    <div class="neck-row" data-neck>
      <label class="switch"><input type="checkbox" name="neck_label_on" value="1" <?= $d['neck_label_on'] ? 'checked' : '' ?> data-neck-toggle><span class="switch-ui"></span><span class="switch-label">Neck label</span></label>
      <input type="text" class="neck-text" name="neck_label" value="<?= h($d['neck_label']) ?>" placeholder="Label text / brand name (optional)" <?= $d['neck_label_on'] ? '' : 'hidden' ?>>
    </div>
  </section>
  <?php if ($id): ?>
    <section class="panel"><label class="check"><input type="checkbox" name="active" value="1" <?= $d['active'] ? 'checked' : '' ?>> Show this design when creating orders (untick to archive)</label></section>
  <?php endif; ?>
  <div class="sticky-actions">
    <button class="btn primary">Save design</button>
    <a class="btn ghost" href="designs.php">Cancel</a>
  </div>
</form>
<?php if ($id): ?>
<form method="post" class="danger-zone" onsubmit="return confirm('Delete this design? Orders that already used it keep their mock-ups.');">
  <?= csrf_field() ?><input type="hidden" name="do" value="delete">
  <button class="btn danger">Delete design</button>
</form>
<?php endif; ?>
<?php
else:
    $qStr = trim((string)($_GET['q'] ?? ''));
    $showArchived = !empty($_GET['archived']);
    $where = ['d.active = ' . ($showArchived ? 0 : 1)];
    $params = [];
    if ($qStr !== '') {
        $where[] = '(d.name LIKE ? OR d.code LIKE ? OR d.product LIKE ?)';
        array_push($params, "%$qStr%", "%$qStr%", "%$qStr%");
    }
    $list = q('SELECT d.*, (SELECT filename FROM design_images i WHERE i.design_id = d.id ORDER BY i.id LIMIT 1) img,
                      (SELECT COUNT(*) FROM design_images i WHERE i.design_id = d.id) img_count,
                      (SELECT COUNT(*) FROM order_items it JOIN orders o ON o.id = it.order_id WHERE it.design_id = d.id AND o.deleted_at IS NULL) used
               FROM designs d WHERE ' . implode(' AND ', $where) . ' ORDER BY d.name', $params)->fetchAll();
?>
<div class="page-head">
  <div><h1>Saved designs</h1><p class="muted small">Products with mock-ups that can be added to an order in one tap.</p></div>
  <?php if ($canManage): ?><div class="actions"><a class="btn primary" href="designs.php?new=1">+ New design</a></div><?php endif; ?>
</div>
<form class="filters" method="get">
  <input type="search" name="q" value="<?= h($qStr) ?>" placeholder="Search design name, code, product…">
  <button class="btn">Search</button>
  <a class="btn ghost" href="?<?= $showArchived ? '' : 'archived=1' ?>"><?= $showArchived ? 'Show active' : 'Archived' ?></a>
</form>
<?php if (!$list): ?><p class="empty">No saved designs yet.<?= $canManage ? ' Tap “+ New design” to add your first one.' : '' ?></p><?php endif; ?>
<div class="design-grid">
  <?php foreach ($list as $d): ?>
    <a class="design-tile" href="<?= $canManage ? 'designs.php?id=' . (int)$d['id'] : '#' ?>">
      <?php if ($d['img']): ?><img loading="lazy" src="image.php?f=<?= h(urlencode(thumb_path($d['img']))) ?>" alt=""><?php else: ?><div class="design-noimg">👕</div><?php endif; ?>
      <div class="design-info">
        <b><?= h($d['name']) ?></b>
        <small><?= h(implode(' · ', array_filter([$d['code'], $d['gsm'], $d['product'], $d['color']], 'strlen')) ?: 'Any T-shirt') ?></small>
        <small class="muted">🖼 <?= (int)$d['img_count'] ?> · used in <?= (int)$d['used'] ?> order item<?= $d['used'] == 1 ? '' : 's' ?></small>
      </div>
    </a>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php'; ?>
