<?php
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/orders.php';

require_login();

$id = (int)($_GET['id'] ?? 0);
$isNew = !$id;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'delete') {
        if (cap('delete') && $id && get_order($id)) {
            q('UPDATE orders SET deleted_at = ?, updated_by = ? WHERE id = ?', [now(), current_user()['id'], $id]);
            log_change($id, 'deleted', null, 'deleted');
            flash('Order ' . order_no($id) . ' deleted.');
            redirect('orders.php');
        }
        flash('You are not allowed to delete orders.', 'err');
        redirect('order.php?id=' . $id);
    }
    [$savedId, $errors] = save_order($isNew ? null : $id, $_POST, $_FILES);
    if ($savedId && !$errors) {
        flash($isNew ? 'Order ' . order_no($savedId) . ' created.' : 'Saved.');
        if ($isNew && !empty($_POST['save_and_new'])) {
            redirect('order.php?new=1&copy=' . $savedId);
        }
        redirect('order.php?id=' . $savedId);
    }
    if ($savedId && $isNew) {
        // Saved, but an image failed: show the order with the error.
        flash(implode(' ', $errors), 'err');
        redirect('order.php?id=' . $savedId . '&edit=1');
    }
}

if ($isNew) {
    if (!cap('create')) {
        http_response_code(403);
        exit('You are not allowed to create orders.');
    }
    $o = ['id' => 0, 'customer_id' => '', 'gsm' => '', 'product' => '', 'color' => '', 'size' => '', 'quantity' => 1,
        'front_print' => '', 'back_print' => '', 'chest_print' => '', 'neck_label' => '', 'custom_print' => '', 'notes' => '',
        'due_date' => compute_due_date(now()), 'printed' => 0, 'packed' => 0, 'shipped' => 0, 'courier' => '', 'tracking_no' => '', 'extra' => []];
    // "Duplicate" / "Save & add another": copy the order details but not progress or images.
    if (!empty($_GET['copy']) && ($src = get_order((int)$_GET['copy']))) {
        foreach (['customer_id', 'gsm', 'product', 'color', 'size', 'quantity', 'front_print', 'back_print', 'chest_print', 'neck_label', 'custom_print', 'notes', 'extra'] as $f) {
            if (can_view($f) || $f === 'extra') {
                $o[$f] = $src[$f];
            }
        }
    }
    $images = [];
    $editing = true;
} else {
    $o = get_order($id);
    if (!$o) {
        http_response_code(404);
        exit('Order not found. <a href="orders.php">Back to orders</a>');
    }
    $images = order_images($id);
    $editing = !empty($_GET['edit']) || $errors;
}
if ($errors && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Keep what was typed.
    foreach (TEXT_FIELDS as $f) {
        if (isset($_POST[$f])) {
            $o[$f] = $_POST[$f];
        }
    }
    if (isset($_POST['quantity'])) {
        $o['quantity'] = $_POST['quantity'];
    }
}

$fields = all_fields();
$editable = array_filter(array_keys($fields), 'can_edit');
$canEditAny = (bool)$editable;
$st = $isNew ? null : order_status($o);

function val(array $o, string $k): string
{
    if (str_starts_with($k, 'cf_')) {
        return (string)($o['extra'][$k] ?? '');
    }
    return (string)($o[$k] ?? '');
}

function show_value(array $o, string $k, array $f): string
{
    $v = val($o, $k);
    if ($f['type'] === 'checkbox') {
        return $v ? '✓ Yes' : '—';
    }
    if ($f['type'] === 'date') {
        return $v ? h(fmt_date($v)) : '—';
    }
    return $v === '' ? '<span class="muted">—</span>' : nl2br(h($v));
}

/** Form control for one field. */
function field_input(array $o, string $k, array $f): string
{
    $v = val($o, $k);
    $name = h($k);
    switch ($f['type']) {
        case 'gsm':
        case 'product':
        case 'color':
        case 'size':
            return '<select name="' . $name . '" data-cat="' . $name . '" data-value="' . h($v) . '"><option value="' . h($v) . '">' . h($v ?: 'Select…') . '</option></select>';
        case 'courier':
        case 'select':
            $opts = $f['type'] === 'courier' ? couriers() : $f['options'];
            $html = '<select name="' . $name . '" data-other="1"><option value="">Select…</option>';
            $found = $v === '';
            foreach ($opts as $opt) {
                $sel = $opt === $v ? ' selected' : '';
                $found = $found || $sel;
                $html .= '<option' . $sel . '>' . h($opt) . '</option>';
            }
            if (!$found) {
                $html .= '<option selected>' . h($v) . '</option>';
            }
            return $html . '<option value="__other">Other… (type)</option></select>';
        case 'number':
            return '<input type="number" inputmode="numeric" min="1" name="' . $name . '" value="' . h($v) . '" required>';
        case 'date':
            return '<input type="date" name="' . $name . '" value="' . h($v) . '">';
        case 'textarea':
            return '<textarea name="' . $name . '" rows="2">' . h($v) . '</textarea>';
        case 'checkbox':
            return '<label class="check"><input type="checkbox" name="' . $name . '" value="1"' . ($v ? ' checked' : '') . '> Yes</label>';
        default:
            $req = $k === 'customer_id' ? ' required' : '';
            return '<input type="text" name="' . $name . '" value="' . h($v) . '"' . $req . '>';
    }
}

$groups = [];
foreach ($fields as $k => $f) {
    if ($k === 'mockups' || $f['type'] === 'stage' || !can_view($k)) {
        continue;
    }
    $groups[$f['group'] === 'Extra' ? 'Print' : $f['group']][$k] = $f;
}
$groupOrder = ['Order', 'Blank T-shirt', 'Print', 'Shipping'];

$history = (!$isNew && is_admin())
    ? q('SELECT * FROM order_log WHERE order_id = ? ORDER BY id DESC LIMIT 100', [$id])->fetchAll()
    : [];

$pageTitle = $isNew ? 'New order' : order_no($id);
$active = $isNew ? 'new' : 'orders';
require __DIR__ . '/inc/header.php';
?>
<div class="page-head">
  <div>
    <a class="back" href="orders.php">← Orders</a>
    <h1><?= $isNew ? 'New order' : h(order_no($id)) ?>
      <?php if ($st): ?><span class="badge <?= h($st['key']) ?>"><?= h($st['label']) ?></span><?php endif; ?>
      <?php if ($st && $st['delayed']): ?><span class="badge delayed">⚠ Delayed</span><?php endif; ?>
    </h1>
    <?php if (!$isNew): ?>
      <p class="muted small">Created <?= h(fmt_date($o['created_at'], true)) ?> by <?= h(user_name($o['created_by'])) ?>
        <?php if ($o['updated_at']): ?> · Updated <?= h(fmt_date($o['updated_at'], true)) ?> by <?= h(user_name($o['updated_by'])) ?><?php endif; ?></p>
    <?php endif; ?>
  </div>
  <?php if (!$isNew && !$editing): ?>
  <div class="actions">
    <?php if ($canEditAny): ?><a class="btn primary" href="order.php?id=<?= $id ?>&edit=1">✎ Edit</a><?php endif; ?>
    <?php if (cap('create')): ?><a class="btn" href="order.php?new=1&copy=<?= $id ?>">Duplicate</a><?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php if ($errors): ?><div class="alert err"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>

<?php if (!$isNew): ?>
<!-- Progress ticks: one tap, from any phone -->
<section class="panel ticks-panel" data-id="<?= $id ?>">
  <?php foreach (STAGES as $s): if (!can_view($s)) continue; ?>
    <button type="button" class="tick big <?= $o[$s] ? 'done' : '' ?>" data-stage="<?= $s ?>" <?= can_edit($s) ? '' : 'disabled' ?>>
      <span class="box"><?= $o[$s] ? '✓' : '' ?></span>
      <span><b><?= ucfirst($s) ?></b><small class="by"><?= $o[$s] ? h(user_name($o[$s . '_by']) . ' · ' . fmt_date($o[$s . '_at'], true)) : (can_edit($s) ? 'Tap to mark' : 'Not yet') ?></small></span>
    </button>
  <?php endforeach; ?>
</section>
<?php endif; ?>

<?php if (can_view('mockups') && (!$editing || !can_edit('mockups'))): ?>
<section class="panel">
  <h2>Mock-up</h2>
  <?php if ($images): ?>
    <div class="gallery">
      <?php foreach ($images as $img): ?>
        <a href="image.php?f=<?= h(urlencode($img['filename'])) ?>" class="gal-item" data-full="image.php?f=<?= h(urlencode($img['filename'])) ?>">
          <img loading="lazy" src="image.php?f=<?= h(urlencode(thumb_path($img['filename']))) ?>" alt="<?= h($img['original_name']) ?>">
        </a>
      <?php endforeach; ?>
    </div>
    <p class="muted small">Tap an image to view full size.</p>
  <?php else: ?>
    <p class="muted">No mock-up uploaded — see print details below.</p>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($editing): ?>
<form method="post" enctype="multipart/form-data" class="order-form" id="orderForm"
      data-catalog="<?= h(json_encode(catalog(), JSON_UNESCAPED_UNICODE)) ?>">
  <?= csrf_field() ?>
<?php endif; ?>

<?php foreach ($groupOrder as $gName): if (empty($groups[$gName]) && !($gName === 'Print' && $editing && can_edit('mockups'))) continue; ?>
  <section class="panel">
    <h2><?= h($gName) ?></h2>
    <?php if ($gName === 'Print' && $editing && can_edit('mockups')): ?>
      <div class="field full">
        <span class="lbl">Mock-up images <small class="muted">(one or more — front, back, etc.)</small></span>
        <?php if ($images): ?>
          <div class="gallery edit">
            <?php foreach ($images as $img): ?>
              <label class="gal-item">
                <img src="image.php?f=<?= h(urlencode(thumb_path($img['filename']))) ?>" alt="">
                <span class="del"><input type="checkbox" name="delete_images[]" value="<?= (int)$img['id'] ?>"> Remove</span>
              </label>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <input type="file" name="mockups[]" accept="image/*" multiple class="file-input" id="mockupInput">
        <div class="preview" id="mockupPreview"></div>
        <p class="hint">No mock-up? Fill the print details below instead.</p>
      </div>
    <?php endif; ?>
    <div class="grid">
    <?php foreach ($groups[$gName] ?? [] as $k => $f): $full = in_array($f['type'], ['textarea'], true);
        // In view mode skip empty optional fields so production staff only see what matters.
        if ((!$editing || !can_edit($k)) && val($o, $k) === '' && !in_array($k, ['customer_id', 'gsm', 'product', 'color', 'size', 'quantity'], true)) continue; ?>
      <div class="field <?= $full ? 'full' : '' ?>">
        <span class="lbl"><?= h($f['label']) ?></span>
        <?php if ($editing && can_edit($k)): ?>
          <?= field_input($o, $k, $f) ?>
        <?php else: ?>
          <div class="val <?= $k === 'customer_id' ? 'strong' : '' ?>">
            <?php if ($k === 'color' && $o['color'] !== ''): ?><span class="dot" data-color="<?= h($o['product'] . '|' . $o['color']) ?>"></span><?php endif; ?>
            <?= show_value($o, $k, $f) ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
    </div>
  </section>
<?php endforeach; ?>

<?php if ($editing): ?>
  <?php if ($isNew): ?>
    <?php $stagesEditable = array_filter(STAGES, 'can_edit'); if ($stagesEditable): ?>
    <section class="panel"><h2>Progress</h2>
      <?php foreach ($stagesEditable as $s): ?>
        <label class="check"><input type="checkbox" name="<?= $s ?>" value="1"> <?= ucfirst($s) ?></label>
      <?php endforeach; ?>
    </section>
    <?php endif; ?>
  <?php endif; ?>
  <div class="sticky-actions">
    <button class="btn primary"><?= $isNew ? 'Create order' : 'Save changes' ?></button>
    <?php if ($isNew): ?><button class="btn" name="save_and_new" value="1">Create &amp; add another</button><?php endif; ?>
    <a class="btn ghost" href="<?= $isNew ? 'orders.php' : 'order.php?id=' . $id ?>">Cancel</a>
  </div>
</form>
<?php endif; ?>

<?php if (!$isNew && !$editing && cap('delete')): ?>
<form method="post" class="danger-zone" onsubmit="return confirm('Delete order <?= h(order_no($id)) ?>?');">
  <?= csrf_field() ?><input type="hidden" name="action" value="delete">
  <button class="btn danger">Delete order</button>
</form>
<?php endif; ?>

<?php if ($history): ?>
<section class="panel">
  <details>
    <summary><h2 class="inline">History</h2> <span class="muted small">(admin only)</span></summary>
    <ul class="history">
      <?php foreach ($history as $hrow): ?>
        <li><span class="muted"><?= h(fmt_date($hrow['created_at'], true)) ?></span> · <b><?= h(user_name($hrow['user_id'])) ?></b>
          <?= h(field_label($hrow['field'])) ?>:
          <?php if (in_array($hrow['field'], STAGES, true)): ?>
            <?= $hrow['new_value'] ? 'ticked ✓' : 'unticked' ?>
          <?php else: ?>
            <?php if ($hrow['old_value'] !== null && $hrow['old_value'] !== ''): ?><s><?= h(mb_strimwidth($hrow['old_value'], 0, 60, '…')) ?></s> → <?php endif; ?>
            <?= h(mb_strimwidth((string)$hrow['new_value'], 0, 60, '…')) ?>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </details>
</section>
<?php endif; ?>

<div class="lightbox" id="lightbox" hidden><img alt=""><button type="button" class="lb-close" aria-label="Close">✕</button></div>
<script>window.ORDER_VALUES = <?= json_encode(['gsm' => $o['gsm'], 'product' => $o['product'], 'color' => $o['color'], 'size' => $o['size']], JSON_UNESCAPED_UNICODE) ?>;
window.COLOR_HEX = <?= json_encode((function () {
    $m = [];
    foreach (q('SELECT p.name AS product, c.name, c.hex FROM product_colors c JOIN products p ON p.id = c.product_id')->fetchAll() as $c) {
        $m[$c['product'] . '|' . $c['name']] = $c['hex'];
    }
    return $m;
})(), JSON_UNESCAPED_UNICODE) ?>;</script>
<?php require __DIR__ . '/inc/footer.php'; ?>
