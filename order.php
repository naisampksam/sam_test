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
    if (($_POST['action'] ?? '') === 'clear_images') {
        $n = clear_order_images($id);
        flash($n ? "Removed $n mock-up image(s). Order details are kept." : 'Nothing removed (only shipped orders can be cleared).', $n ? 'ok' : 'err');
        redirect('order.php?id=' . $id);
    }
    [$savedId, $errors] = save_order($isNew ? null : $id, $_POST, $_FILES);
    if ($savedId && !$errors) {
        flash($isNew ? 'Order ' . order_no($savedId) . ' created.' : 'Saved.');
        redirect('order.php?id=' . $savedId);
    }
    if ($savedId && $isNew) {
        // Saved, but an image failed: show the order with the error.
        flash(implode(' ', $errors), 'err');
        redirect('order.php?id=' . $savedId . '&edit=1');
    }
}

$blankItem = ['id' => 0, 'gsm' => '', 'product' => '', 'color' => '', 'size' => '', 'quantity' => 1, 'plain' => 0, 'front_print' => '', 'back_print' => '',
    'chest_print' => '', 'neck_label_on' => 0, 'neck_label' => '', 'custom_print' => '', 'extra' => [], 'printed' => 0, 'printed_at' => null, 'printed_by' => null];

if ($isNew) {
    if (!cap('create')) {
        http_response_code(403);
        exit('You are not allowed to create orders.');
    }
    $o = ['id' => 0, 'customer_id' => '', 'ship_name' => '', 'ship_phone' => '', 'ship_address' => '', 'ship_pincode' => '', 'notes' => '', 'due_date' => compute_due_date(now()), 'printed' => 0, 'packed' => 0, 'shipped' => 0,
        'courier' => '', 'tracking_no' => '', 'extra' => []];
    $items = [$blankItem];
    // "Duplicate": copy the order and its items, but not progress or images.
    if (!empty($_GET['copy']) && ($src = get_order((int)$_GET['copy']))) {
        foreach (['customer_id', 'ship_name', 'ship_phone', 'ship_address', 'ship_pincode', 'notes', 'extra'] as $f) {
            $o[$f] = $src[$f];
        }
        $items = array_map(fn($it) => array_merge($it, ['id' => 0, 'printed' => 0, 'printed_at' => null, 'printed_by' => null]), order_items((int)$src['id'])) ?: [$blankItem];
    }
    // "New order for this customer" (from the Customers page): fill the saved address.
    if (!empty($_GET['customer']) && ($c = q('SELECT * FROM customers WHERE code = ?', [(string)$_GET['customer']])->fetch())) {
        $o = array_merge($o, ['customer_id' => $c['code'], 'ship_name' => $c['name'], 'ship_phone' => $c['phone'],
            'ship_address' => (string)$c['address'], 'ship_pincode' => $c['pincode']]);
    }
    $images = [];
    $editing = true;
} else {
    $o = get_order($id);
    if (!$o) {
        http_response_code(404);
        exit('Order not found. <a href="orders.php">Back to orders</a>');
    }
    $items = order_items($id);
    $images = order_images_by_item($id);
    $editing = !empty($_GET['edit']) || $errors;
}
if ($errors && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Keep what was typed.
    foreach (ORDER_TEXT_FIELDS as $f) {
        if (isset($_POST[$f])) {
            $o[$f] = $_POST[$f];
        }
    }
    $byKey = [];
    foreach ($items as $it) {
        $byKey['e' . $it['id']] = $it;
    }
    $posted = [];
    foreach ((array)($_POST['items'] ?? []) as $k => $ip) {
        if (!is_array($ip) || !empty($ip['delete'])) {
            continue;
        }
        $base = $byKey[$k] ?? $blankItem;
        foreach ($ip as $f => $v) {
            if (str_starts_with((string)$f, 'cf_')) {
                $base['extra'][$f] = $v;
            } elseif (array_key_exists($f, $base) && $f !== 'id') {
                $base[$f] = $v;
            }
        }
        $posted[] = $base;
    }
    $items = $posted ?: $items;
}

$canAddItems = cap('create');
$designs = $editing && can_edit('mockups') ? designs_for_picker() : [];
$hasDesigns = (bool)$designs;
$orderFields = array_filter(scoped_fields('order'), fn($f, $k) => $f['type'] !== 'stage' && can_view($k), ARRAY_FILTER_USE_BOTH);
$itemFields = array_filter(scoped_fields('item'), fn($f, $k) => !in_array($k, ['printed', 'mockups'], true) && can_view($k), ARRAY_FILTER_USE_BOTH);
$canEditAny = (bool)array_filter(array_keys(all_fields()), 'can_edit');
$st = $isNew ? null : order_status($o);
$always = ['customer_id', 'gsm', 'product', 'color', 'size', 'quantity'];

function val(array $row, string $k): string
{
    if (str_starts_with($k, 'cf_')) {
        return (string)($row['extra'][$k] ?? '');
    }
    return (string)($row[$k] ?? '');
}

function show_value(array $row, string $k, array $f): string
{
    $v = val($row, $k);
    if ($f['type'] === 'checkbox') {
        return $v ? '✓ Yes' : '—';
    }
    if ($f['type'] === 'date') {
        return $v ? h(fmt_date($v)) : '—';
    }
    if ($f['type'] === 'tel' && $v !== '') {
        return '<a href="tel:' . h(preg_replace('/[^\d+]/', '', $v)) . '">' . h($v) . '</a>';
    }
    return $v === '' ? '<span class="muted">—</span>' : nl2br(h($v));
}

/** Form control for one field; $name is the full input name. */
function field_input(string $name, string $v, string $k, array $f): string
{
    $name = h($name);
    switch ($f['type']) {
        case 'gsm':
        case 'product':
        case 'color':
        case 'size':
            return '<select name="' . $name . '" data-cat="' . h($f['type']) . '" data-value="' . h($v) . '"><option value="' . h($v) . '">' . h($v ?: 'Select…') . '</option></select>';
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
            return '<div class="stepper"><button type="button" data-step="-1" aria-label="Less">−</button>'
                . '<input type="number" inputmode="numeric" min="1" name="' . $name . '" value="' . h($v) . '" required data-qty>'
                . '<button type="button" data-step="1" aria-label="More">+</button></div>';
        case 'tel':
            return '<input type="tel" inputmode="tel" autocomplete="off" name="' . $name . '" value="' . h($v) . '" required placeholder="10-digit mobile" data-customer-suggest>';
        case 'pincode':
            return '<input type="text" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" autocomplete="postal-code" name="' . $name . '" value="' . h($v) . '" required placeholder="6 digits">';
        case 'date':
            return '<input type="date" name="' . $name . '" value="' . h($v) . '">';
        case 'textarea':
            $req = $k === 'ship_address' ? ' required placeholder="House / street, area, city, state"' : '';
            return '<textarea name="' . $name . '" rows="' . ($k === 'ship_address' ? 3 : 2) . '"' . $req . '>' . h($v) . '</textarea>';
        case 'checkbox':
            return '<label class="check"><input type="checkbox" name="' . $name . '" value="1"' . ($v ? ' checked' : '') . '> Yes</label>';
        default:
            $req = in_array($k, ['customer_id', 'ship_name'], true) ? ' required' : '';
            // Customer ID and name look up saved customers as you type.
            $suggest = in_array($k, ['customer_id', 'ship_name'], true) ? ' data-customer-suggest autocomplete="off"' : '';
            $ph = $k === 'customer_id' ? ' placeholder="Type ID, phone or name to find a customer"' : '';
            return '<input type="text" name="' . $name . '" value="' . h($v) . '"' . $req . $suggest . $ph . '>';
    }
}

/** Grid of fields; editable ones become inputs named via $nameFn. Empty optional fields are skipped when read-only. */
function render_fields(array $row, array $fields, bool $editing, callable $nameFn, array $always): void
{
    $out = '';
    foreach ($fields as $k => $f) {
        $edit = $editing && can_edit($k);
        if (!$edit && val($row, $k) === '' && !in_array($k, $always, true)) {
            continue;
        }
        $req = $edit && in_array($k, ['customer_id', 'ship_name', 'ship_phone', 'ship_address', 'ship_pincode'], true) ? ' <i class="req" title="Required">*</i>' : '';
        $out .= '<div class="field' . ($f['type'] === 'textarea' ? ' full' : '') . '"><span class="lbl">' . h($f['label']) . $req . '</span>';
        if ($edit) {
            $out .= field_input($nameFn($k), val($row, $k), $k, $f);
        } else {
            $out .= '<div class="val' . ($k === 'customer_id' ? ' strong' : '') . '">';
            if ($k === 'color' && ($row['color'] ?? '') !== '') {
                $out .= '<span class="dot" data-color="' . h($row['product'] . '|' . $row['color']) . '"></span>';
            }
            $out .= show_value($row, $k, $f) . '</div>';
        }
        $out .= '</div>';
    }
    if ($out !== '') {
        echo '<div class="grid">' . $out . '</div>';
    }
}

function blank_title(array $it): string
{
    $s = implode(' · ', array_filter([$it['gsm'], $it['product'], $it['color'], $it['size']], 'strlen'));
    return $s ?: 'Item';
}

/** Neck label switch + text (shown for printed and plain T-shirts). */
function neck_label_edit(string $key, array $it): void
{
    if (!can_view('neck_label')) {
        return;
    }
    $on = !empty($it['neck_label_on']);
    if (!can_edit('neck_label')) {
        echo '<div class="neck-row"><span class="lbl">Neck label</span><div class="val">' . ($on ? '🏷 ' . h($it['neck_label'] ?: 'Yes') : '<span class="muted">No neck label</span>') . '</div></div>';
        return;
    }
    ?>
    <div class="neck-row" data-neck>
      <input type="hidden" name="items[<?= h($key) ?>][neck_label_on]" value="0">
      <label class="switch">
        <input type="checkbox" name="items[<?= h($key) ?>][neck_label_on]" value="1" <?= $on ? 'checked' : '' ?> data-neck-toggle>
        <span class="switch-ui" aria-hidden="true"></span>
        <span class="switch-label">Neck label</span>
      </label>
      <input type="text" class="neck-text" name="items[<?= h($key) ?>][neck_label]" value="<?= h($it['neck_label']) ?>" placeholder="Label text / brand name (optional)" <?= $on ? '' : 'hidden' ?>>
    </div>
    <?php
}

/** One item card in the edit form. $key is "e<ID>", "n<N>", or "__KEY__" for the add-item template. */
function item_card_edit(string $key, array $it, array $imgs, int $num, array $itemFields, array $always, bool $canAddItems): void
{
    $p = fn($k) => "items[$key][$k]";
    $vals = json_encode(['gsm' => $it['gsm'], 'product' => $it['product'], 'color' => $it['color'], 'size' => $it['size']], JSON_UNESCAPED_UNICODE);
    $blank = array_filter($itemFields, fn($f) => $f['group'] === 'Blank T-shirt');
    $print = array_filter($itemFields, fn($f, $k) => $f['group'] !== 'Blank T-shirt' && $k !== 'neck_label', ARRAY_FILTER_USE_BOTH);
    $plain = !empty($it['plain']);
    ?>
    <section class="panel item-card <?= $plain ? 'is-plain' : '' ?>" data-key="<?= h($key) ?>" data-values="<?= h($vals) ?>">
      <div class="item-head">
        <h2><span class="item-num"><?= $num ?></span> Item</h2>
        <?php if ($canAddItems): ?>
          <div class="item-tools">
            <button type="button" class="icon-btn" data-dup-item title="Copy this item (images are not copied)" aria-label="Copy item">⧉</button>
            <?php if ($it['id']): ?>
              <label class="icon-btn remove-toggle" title="Remove this item"><input type="checkbox" name="<?= h($p('delete')) ?>" value="1"> 🗑</label>
            <?php else: ?>
              <button type="button" class="icon-btn" data-remove-item title="Remove this item" aria-label="Remove item">✕</button>
            <?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
      <input type="hidden" name="<?= h($p('keep')) ?>" value="1">
      <?php if (can_edit('product')): ?>
        <div class="seg-toggle" role="radiogroup" aria-label="Type">
          <label><input type="radio" name="<?= h($p('plain')) ?>" value="0" <?= $plain ? '' : 'checked' ?> data-plain-toggle><span>🎨 With print</span></label>
          <label><input type="radio" name="<?= h($p('plain')) ?>" value="1" <?= $plain ? 'checked' : '' ?> data-plain-toggle><span>👕 Plain T-shirt</span></label>
        </div>
      <?php elseif ($plain): ?>
        <p><span class="badge plain">Plain T-shirt · no print</span></p>
      <?php endif; ?>
      <?php if (can_edit('mockups') && !empty($GLOBALS['hasDesigns'])): ?>
        <div class="design-pick design-only">
          <input type="hidden" name="<?= h($p('design_id')) ?>" value="" data-design-id>
          <button type="button" class="btn design-btn" data-pick-design>⭐ <span>Pick a saved design</span></button>
          <div class="design-chosen" hidden></div>
        </div>
      <?php endif; ?>
      <?php if ($it['printed'] && !$plain): ?><p class="small printed-note">✓ Printed by <?= h(user_name($it['printed_by'])) ?> · <?= h(fmt_date($it['printed_at'], true)) ?></p><?php endif; ?>
      <?php render_fields($it, $blank, true, $p, $always); ?>
      <?php neck_label_edit($key, $it); ?>
      <div class="design-only">
      <?php if (can_view('mockups')): ?>
      <div class="field full mockup-field">
        <span class="lbl">Mock-up images</span>
        <?php if ($imgs): ?>
          <div class="gallery edit">
            <?php foreach ($imgs as $img): ?>
              <label class="gal-item">
                <img src="image.php?f=<?= h(urlencode(thumb_path($img['filename']))) ?>" alt="">
                <?php if (can_edit('mockups')): ?><span class="del"><input type="checkbox" name="delete_images[]" value="<?= (int)$img['id'] ?>"> Remove</span><?php endif; ?>
              </label>
            <?php endforeach; ?>
          </div>
        <?php elseif (!can_edit('mockups')): ?>
          <p class="muted small">No mock-up.</p>
        <?php endif; ?>
        <?php if (can_edit('mockups')): ?>
          <label class="upload-box">
            <input type="file" name="item_mockups[<?= h($key) ?>][]" accept="image/*" multiple data-preview>
            <span class="upload-icon" aria-hidden="true">📷</span>
            <span><b>Add mock-up photos</b><small>Front, back… take a photo or choose from gallery</small></span>
          </label>
          <div class="preview"></div>
        <?php endif; ?>
      </div>
      <?php endif; ?>
      <?php if ($print): $filled = array_filter(array_keys($print), fn($k) => val($it, $k) !== ''); ?>
        <details class="print-details" <?= $filled ? 'open' : '' ?>>
          <summary>✍️ Print details <span class="muted small"><?= $filled ? count($filled) . ' filled' : 'if there is no mock-up' ?></span></summary>
          <?php render_fields($it, $print, true, $p, $always); ?>
        </details>
      <?php endif; ?>
      </div>
    </section>
    <?php
}

$history = (!$isNew && is_admin())
    ? q('SELECT * FROM order_log WHERE order_id = ? ORDER BY id DESC LIMIT 150', [$id])->fetchAll()
    : [];
$itemNo = [];
foreach ($items as $i => $it) {
    $itemNo[(int)$it['id']] = $i + 1;
}
$totalQty = array_sum(array_map(fn($it) => (int)$it['quantity'], $items));

$pageTitle = $isNew ? 'New order' : order_no($id);
$active = $isNew ? 'new' : 'orders';
require __DIR__ . '/inc/header.php';
?>
<div class="page-head">
  <div>
    <a class="back" href="orders.php">← Orders</a>
    <h1><?= $isNew ? 'New order' : h(order_no($id)) ?>
      <?php if ($st): ?><span class="badge <?= h($st['key']) ?>" id="statusBadge"><?= h($st['label']) ?></span><?php endif; ?>
      <?php if ($st && $st['delayed']): ?><span class="badge delayed">⚠ Delayed</span><?php endif; ?>
    </h1>
    <?php if (!$isNew): ?>
      <p class="muted small"><?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?> · <?= $totalQty ?> pcs ·
        created <?= h(fmt_date($o['created_at'], true)) ?> by <?= h(user_name($o['created_by'])) ?>
        <?php if ($o['updated_at']): ?> · updated <?= h(fmt_date($o['updated_at'], true)) ?> by <?= h(user_name($o['updated_by'])) ?><?php endif; ?></p>
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

<?php if (!$isNew && !$editing): ?>
<!-- ============================== VIEW ============================== -->
<section class="panel ticks-panel" data-id="<?= $id ?>">
  <?php if (can_view('printed')): ?>
    <div class="tick big info <?= $o['printed'] ? 'done' : '' ?>" id="printSummary">
      <span class="box"><?= $o['printed'] ? '✓' : '' ?></span>
      <span><b>Printed</b><small class="by"><?php if ((int)$o['printable_count'] === 0): ?>Plain only · no printing<?php else: ?><span data-printed-count><?= (int)$o['printed_count'] ?></span> of <?= (int)$o['printable_count'] ?> items<?php endif; ?></small></span>
    </div>
  <?php endif; ?>
  <?php foreach (ORDER_STAGES as $s): if (!can_view($s)) continue; ?>
    <button type="button" class="tick big <?= $o[$s] ? 'done' : '' ?>" data-stage="<?= $s ?>" <?= can_edit($s) ? '' : 'disabled' ?>>
      <span class="box"><?= $o[$s] ? '✓' : '' ?></span>
      <span><b><?= ucfirst($s) ?></b><small class="by"><?= $o[$s] ? h(user_name($o[$s . '_by']) . ' · ' . fmt_date($o[$s . '_at'], true)) : (can_edit($s) ? 'Tap to mark' : 'Not yet') ?></small></span>
    </button>
  <?php endforeach; ?>
</section>

<?php $top = array_filter($orderFields, fn($f) => $f['group'] === 'Order'); if ($top): ?>
<section class="panel">
  <h2>Order</h2>
  <?php render_fields($o, $top, false, fn($k) => $k, $always); ?>
</section>
<?php endif; ?>
<?php $addr = array_filter($orderFields, fn($f) => $f['group'] === 'Shipping address'); if ($addr && array_filter(array_keys($addr), fn($k) => val($o, $k) !== '')): ?>
<section class="panel address">
  <div class="item-head"><h2>Shipping address</h2><button type="button" class="btn small" data-copy-address>Copy</button></div>
  <?php render_fields($o, $addr, false, fn($k) => $k, []); ?>
</section>
<?php endif; ?>

<div class="items-head">
  <h2><?= count($items) ?> item<?= count($items) === 1 ? '' : 's' ?> · <?= $totalQty ?> pcs</h2>
  <?php if (can_edit('printed') && (int)$o['printable_count'] > 1): ?>
    <button type="button" class="btn small" data-print-all data-id="<?= $id ?>" data-on="<?= $o['printed'] ? '0' : '1' ?>"><?= $o['printed'] ? 'Untick all printed' : '✓ Mark all printed' ?></button>
  <?php endif; ?>
</div>
<?php foreach ($items as $i => $it): $imgs = $images[(int)$it['id']] ?? []; ?>
  <section class="panel item-view <?= $it['plain'] ? 'is-plain' : ($it['printed'] ? 'is-printed' : '') ?>" data-id="<?= $id ?>">
    <div class="item-head">
      <h2><span class="item-num"><?= $i + 1 ?></span> <?= h(blank_title($it)) ?></h2>
      <span class="qty-pill">× <?= (int)$it['quantity'] ?></span>
    </div>
    <div class="item-tags">
      <?php if (can_view('color') && $it['color'] !== ''): ?><span class="tag"><span class="dot" data-color="<?= h($it['product'] . '|' . $it['color']) ?>"></span><?= h($it['color']) ?></span><?php endif; ?>
      <?php if (can_view('size') && $it['size'] !== ''): ?><span class="tag">Size <b><?= h($it['size']) ?></b></span><?php endif; ?>
      <?php if ($it['plain']): ?><span class="badge plain">Plain · no print</span><?php endif; ?>
      <?php if (can_view('neck_label')): ?>
        <span class="tag <?= $it['neck_label_on'] ? 'tag-on' : 'tag-off' ?>">🏷 <?= $it['neck_label_on'] ? 'Neck label' . ($it['neck_label'] !== '' ? ': <b>' . h($it['neck_label']) . '</b>' : '') : 'No neck label' ?></span>
      <?php endif; ?>
    </div>
    <?php if (!$it['plain']): ?>
      <?php if (can_view('printed')): ?>
        <button type="button" class="tick big item-tick <?= $it['printed'] ? 'done' : '' ?>" data-stage="printed" data-item="<?= (int)$it['id'] ?>" <?= can_edit('printed') ? '' : 'disabled' ?>>
          <span class="box"><?= $it['printed'] ? '✓' : '' ?></span>
          <span><b>Printed</b><small class="by"><?= $it['printed'] ? h(user_name($it['printed_by']) . ' · ' . fmt_date($it['printed_at'], true)) : (can_edit('printed') ? 'Tap when this item is printed' : 'Not yet') ?></small></span>
        </button>
      <?php endif; ?>
      <?php if (can_view('mockups')): ?>
        <?php if ($imgs): ?>
          <div class="gallery">
            <?php foreach ($imgs as $img): ?>
              <a href="image.php?f=<?= h(urlencode($img['filename'])) ?>" class="gal-item" data-full="image.php?f=<?= h(urlencode($img['filename'])) ?>">
                <img loading="lazy" src="image.php?f=<?= h(urlencode(thumb_path($img['filename']))) ?>" alt="<?= h($img['original_name']) ?>">
              </a>
            <?php endforeach; ?>
          </div>
        <?php elseif ($o['images_cleared_at']): ?>
          <p class="muted small">🗑 Mock-ups removed to free space on <?= h(fmt_date($o['images_cleared_at'])) ?>.</p>
        <?php else: ?>
          <p class="muted small">No mock-up for this item — see print details.</p>
        <?php endif; ?>
      <?php endif; ?>
      <?php render_fields($it, array_diff_key($itemFields, array_flip(['gsm', 'product', 'color', 'size', 'quantity', 'neck_label'])), false, fn($k) => $k, []); ?>
    <?php else: ?>
      <p class="muted small">No printing needed — goes straight to packing.</p>
    <?php endif; ?>
  </section>
<?php endforeach; ?>

<?php $ship = array_filter($orderFields, fn($f) => $f['group'] === 'Shipping'); if ($ship && ($o['courier'] !== '' || $o['tracking_no'] !== '')): ?>
<section class="panel">
  <h2>Shipping</h2>
  <?php render_fields($o, $ship, false, fn($k) => $k, []); ?>
</section>
<?php endif; ?>

<?php if (cap('cleanup') && $o['shipped'] && $images): ?>
<form method="post" class="danger-zone" onsubmit="return confirm('Delete all mock-up images of this order to free space? Order details stay.');">
  <?= csrf_field() ?><input type="hidden" name="action" value="clear_images">
  <button class="btn">🗑 Remove mock-up images (free space)</button>
</form>
<?php endif; ?>
<?php if (cap('delete')): ?>
<form method="post" class="danger-zone" onsubmit="return confirm('Delete order <?= h(order_no($id)) ?>?');">
  <?= csrf_field() ?><input type="hidden" name="action" value="delete">
  <button class="btn danger">Delete order</button>
</form>
<?php endif; ?>

<?php else: ?>
<!-- ============================== EDIT ============================== -->
<form method="post" enctype="multipart/form-data" class="order-form" id="orderForm"
      data-catalog="<?= h(json_encode(catalog(), JSON_UNESCAPED_UNICODE)) ?>"
      data-designs="<?= h(json_encode($designs, JSON_UNESCAPED_UNICODE)) ?>">
  <?= csrf_field() ?>
  <?php $top = array_filter($orderFields, fn($f) => $f['group'] === 'Order'); if ($top): ?>
  <section class="panel">
    <h2>Order</h2>
    <?php render_fields($o, $top, true, fn($k) => $k, $always); ?>
  </section>
  <?php endif; ?>
  <?php $addr = array_filter($orderFields, fn($f) => $f['group'] === 'Shipping address'); if ($addr): ?>
  <section class="panel">
    <h2>Shipping address</h2>
    <?php render_fields($o, $addr, true, fn($k) => $k, []); ?>
  </section>
  <?php endif; ?>

  <div id="items">
    <?php $n = 0; foreach ($items as $it): $n++;
        item_card_edit($it['id'] ? 'e' . $it['id'] : 'n' . $n, $it, $images[(int)$it['id']] ?? [], $n, $itemFields, $always, $canAddItems);
    endforeach; ?>
  </div>
  <?php if ($canAddItems): ?>
    <button type="button" class="btn add-item" id="addItem">+ Add another item</button>
    <template id="itemTemplate"><?php item_card_edit('__KEY__', $blankItem, [], 0, $itemFields, $always, $canAddItems); ?></template>
  <?php endif; ?>

  <?php $ship = array_filter($orderFields, fn($f) => $f['group'] === 'Shipping'); if ($ship && !$isNew): ?>
  <section class="panel">
    <h2>Shipping</h2>
    <?php render_fields($o, $ship, true, fn($k) => $k, ['courier', 'tracking_no']); ?>
  </section>
  <?php endif; ?>

  <div class="sticky-actions">
    <span class="total-pcs" id="totalPcs"></span>
    <button class="btn primary"><?= $isNew ? 'Create order' : 'Save changes' ?></button>
    <a class="btn ghost" href="<?= $isNew ? 'orders.php' : 'order.php?id=' . $id ?>">Cancel</a>
  </div>
</form>
<?php endif; ?>

<?php if ($history): ?>
<section class="panel">
  <details>
    <summary><h2 class="inline">History</h2> <span class="muted small">(admin only)</span></summary>
    <ul class="history">
      <?php foreach ($history as $hrow): ?>
        <li><span class="muted"><?= h(fmt_date($hrow['created_at'], true)) ?></span> · <b><?= h(user_name($hrow['user_id'])) ?></b>
          <?php if ($hrow['item_id'] && isset($itemNo[(int)$hrow['item_id']])): ?><span class="badge pending">Item <?= $itemNo[(int)$hrow['item_id']] ?></span><?php endif; ?>
          <?= h(field_label($hrow['field'])) ?>:
          <?php if (in_array($hrow['field'], STAGES, true)): ?>
            <?= $hrow['new_value'] ? 'ticked ✓' : 'unticked' ?>
          <?php else: ?>
            <?php if ($hrow['new_value'] === null): ?><?= h(mb_strimwidth((string)$hrow['old_value'], 0, 80, '…')) ?>
            <?php elseif ($hrow['old_value'] !== null && $hrow['old_value'] !== ''): ?><s><?= h(mb_strimwidth($hrow['old_value'], 0, 60, '…')) ?></s> → <?php endif; ?>
            <?php if ($hrow['new_value'] !== null): ?><?= h(mb_strimwidth((string)$hrow['new_value'], 0, 60, '…')) ?><?php endif; ?>
          <?php endif; ?>
        </li>
      <?php endforeach; ?>
    </ul>
  </details>
</section>
<?php endif; ?>

<div class="lightbox" id="lightbox" hidden><img alt=""><button type="button" class="lb-close" aria-label="Close">✕</button></div>
<?php if ($editing && $hasDesigns): ?>
<div class="modal" id="designModal" hidden>
  <div class="modal-card" role="dialog" aria-modal="true" aria-label="Pick a saved design">
    <div class="modal-head">
      <h2>Pick a saved design</h2>
      <button type="button" class="icon-btn" data-close-modal aria-label="Close">✕</button>
    </div>
    <input type="search" id="designSearch" placeholder="Search name, code, product…" autocomplete="off">
    <div class="design-grid in-modal" id="designList"></div>
  </div>
</div>
<?php endif; ?>
<script>window.COLOR_HEX = <?= json_encode((function () {
    $m = [];
    foreach (q('SELECT p.name AS product, c.name, c.hex FROM product_colors c JOIN products p ON p.id = c.product_id')->fetchAll() as $c) {
        $m[$c['product'] . '|' . $c['name']] = $c['hex'];
    }
    return $m;
})(), JSON_UNESCAPED_UNICODE) ?>;</script>
<?php require __DIR__ . '/inc/footer.php'; ?>
