<?php
require __DIR__ . '/../inc/bootstrap.php';

require_admin();

$types = ['text' => 'Short text', 'textarea' => 'Long text', 'select' => 'Dropdown', 'checkbox' => 'Tick box', 'number' => 'Number', 'date' => 'Date'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    switch ($_POST['do'] ?? '') {
        case 'general':
            set_setting('company_name', trim($_POST['company_name'] ?? '') ?: 'Looma Apparels');
            set_setting('dispatch_days', (string)max(0, min(30, (int)($_POST['dispatch_days'] ?? 2))));
            set_setting('skip_sundays', !empty($_POST['skip_sundays']) ? '1' : '0');
            if (!empty($_POST['recalc'])) {
                // Re-apply the dispatch rule to all unshipped orders.
                foreach (q('SELECT id, created_at FROM orders WHERE shipped = 0 AND deleted_at IS NULL')->fetchAll() as $o) {
                    q('UPDATE orders SET due_date = ? WHERE id = ?', [compute_due_date($o['created_at']), $o['id']]);
                }
            }
            flash('Settings saved.');
            break;
        case 'field_add':
            $label = trim($_POST['label'] ?? '');
            $type = isset($types[$_POST['type'] ?? '']) ? $_POST['type'] : 'text';
            if ($label !== '') {
                q('INSERT INTO custom_fields (label, type, options, sort) VALUES (?, ?, ?, ?)', [$label, $type, trim($_POST['options'] ?? ''), 50]);
                $key = 'cf_' . db()->lastInsertId();
                // Existing staff get "view" on the new field; admin can change it per person.
                foreach (q("SELECT id, perms FROM users WHERE role = 'staff'")->fetchAll() as $u) {
                    $p = json_decode($u['perms'] ?: '{}', true) ?: [];
                    $p[$key] = 'view';
                    q('UPDATE users SET perms = ? WHERE id = ?', [json_encode($p), $u['id']]);
                }
                flash("Field \"$label\" added. Give edit access to the right staff in Staff.");
            }
            break;
        case 'field_save':
            q('UPDATE custom_fields SET label = ?, options = ?, sort = ?, active = ? WHERE id = ?', [
                trim($_POST['label'] ?? '') ?: 'Field', trim($_POST['options'] ?? ''), (int)($_POST['sort'] ?? 0), !empty($_POST['active']) ? 1 : 0, (int)$_POST['id'],
            ]);
            flash('Field saved.');
            break;
    }
    redirect('admin/settings.php');
}

$fields = q('SELECT * FROM custom_fields ORDER BY active DESC, sort, id')->fetchAll();
$pageTitle = 'Settings';
$active = 'settings';
require __DIR__ . '/../inc/header.php';
?>
<div class="page-head"><h1>Settings</h1></div>

<section class="panel">
  <h2>General</h2>
  <form method="post" class="grid">
    <?= csrf_field() ?><input type="hidden" name="do" value="general">
    <label class="field"><span class="lbl">Company name</span><input name="company_name" value="<?= h(setting('company_name')) ?>"></label>
    <label class="field"><span class="lbl">Dispatch within (days)</span><input type="number" min="0" max="30" name="dispatch_days" value="<?= h(setting('dispatch_days', '2')) ?>"></label>
    <label class="field check"><input type="checkbox" name="skip_sundays" value="1" <?= setting('skip_sundays', '1') === '1' ? 'checked' : '' ?>> Don’t count Sundays</label>
    <label class="field check"><input type="checkbox" name="recalc" value="1"> Also update “dispatch by” on all open orders</label>
    <div class="field"><button class="btn primary">Save</button></div>
  </form>
  <p class="hint">An order becomes <b>Delayed</b> when it is not shipped by its “dispatch by” date.</p>
</section>

<section class="panel">
  <h2>Custom fields</h2>
  <p class="muted small">Add any extra field to orders (e.g. Order source, Payment status). Each one gets its own Hidden/View/Edit permission per staff.</p>
  <?php foreach ($fields as $f): ?>
    <form method="post" class="grid field-row <?= $f['active'] ? '' : 'inactive' ?>">
      <?= csrf_field() ?><input type="hidden" name="do" value="field_save"><input type="hidden" name="id" value="<?= (int)$f['id'] ?>">
      <label class="field"><span class="lbl">Label · <?= h($types[$f['type']] ?? $f['type']) ?></span><input name="label" value="<?= h($f['label']) ?>"></label>
      <?php if ($f['type'] === 'select'): ?>
        <label class="field"><span class="lbl">Options (comma separated)</span><input name="options" value="<?= h($f['options']) ?>"></label>
      <?php else: ?><input type="hidden" name="options" value="<?= h($f['options']) ?>"><?php endif; ?>
      <label class="field"><span class="lbl">Order</span><input type="number" name="sort" value="<?= (int)$f['sort'] ?>"></label>
      <label class="field check"><input type="checkbox" name="active" value="1" <?= $f['active'] ? 'checked' : '' ?>> In use</label>
      <div class="field"><button class="btn small">Save</button></div>
    </form>
  <?php endforeach; ?>
  <h3 class="perm-group">Add a field</h3>
  <form method="post" class="grid">
    <?= csrf_field() ?><input type="hidden" name="do" value="field_add">
    <label class="field"><span class="lbl">Label</span><input name="label" required placeholder="e.g. Payment status"></label>
    <label class="field"><span class="lbl">Type</span>
      <select name="type"><?php foreach ($types as $k => $l): ?><option value="<?= $k ?>"><?= $l ?></option><?php endforeach; ?></select></label>
    <label class="field"><span class="lbl">Options (for dropdown)</span><input name="options" placeholder="Paid, COD, Pending"></label>
    <div class="field"><span class="lbl">&nbsp;</span><button class="btn primary">+ Add field</button></div>
  </form>
</section>
<?php require __DIR__ . '/../inc/footer.php'; ?>
