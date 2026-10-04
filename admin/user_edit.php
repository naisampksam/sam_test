<?php
require __DIR__ . '/../inc/bootstrap.php';

$me = require_admin();

$id = (int)($_GET['id'] ?? 0);
$u = q('SELECT * FROM users WHERE id = ?', [$id])->fetch();
if (!$u) {
    redirect('admin/users.php');
}
$errors = [];
$fields = all_fields();
$levels = ['none' => 'Hidden', 'view' => 'View', 'edit' => 'Edit'];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'delete') {
    csrf_check();
    $otherAdmins = (int)q("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1 AND id <> ?", [$id])->fetchColumn();
    if ($id === (int)$me['id']) {
        flash('You cannot delete your own account.', 'err');
    } elseif ($u['role'] === 'admin' && $otherAdmins === 0) {
        flash('You need at least one admin.', 'err');
    } else {
        q('DELETE FROM users WHERE id = ?', [$id]);
        flash('Account "' . $u['username'] . '" deleted. Their past ticks and changes stay in the history.');
        redirect('admin/users.php');
    }
    redirect('admin/user_edit.php?id=' . $id);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'staff';
    $activeFlag = !empty($_POST['active']) ? 1 : 0;
    $username = strtolower(trim($_POST['username'] ?? ''));
    $otherAdmins = (int)q("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1 AND id <> ?", [$id])->fetchColumn();

    if (!preg_match('/^[a-z0-9._-]{3,60}$/', $username)) {
        $errors[] = 'Username: 3-60 characters, letters/numbers/._- only.';
    } elseif (q('SELECT id FROM users WHERE username = ? AND id <> ?', [$username, $id])->fetch()) {
        $errors[] = 'That username is already taken.';
    }
    if ($u['role'] === 'admin' && ($role !== 'admin' || !$activeFlag) && $otherAdmins === 0) {
        $errors[] = 'You need at least one active admin.';
    }
    $newPass = (string)($_POST['new_password'] ?? '');
    if ($newPass !== '' && strlen($newPass) < 6) {
        $errors[] = 'New password must be at least 6 characters.';
    }

    if (!$errors) {
        $perms = [];
        foreach ($fields as $k => $f) {
            $v = $_POST['perm'][$k] ?? 'none';
            $perms[$k] = isset($levels[$v]) ? $v : 'none';
        }
        foreach (estimate_fields() as $k => $l) {
            $v = $_POST['perm'][$k] ?? 'edit';
            $perms[$k] = isset($levels[$v]) ? $v : 'edit';
        }
        $caps = [];
        foreach (capability_labels() as $k => $l) {
            if (!empty($_POST['cap'][$k])) {
                $caps[$k] = 1;
            }
        }
        // Logging out other sessions when access is cut or password is reset.
        $bump = ($newPass !== '' || !$activeFlag) ? 1 : 0;
        q('UPDATE users SET name = ?, username = ?, role = ?, active = ?, perms = ?, caps = ?, session_version = session_version + ? WHERE id = ?', [
            trim($_POST['name'] ?? '') ?: $username, $username, $role, $activeFlag, json_encode($perms), json_encode($caps), $bump, $id,
        ]);
        if ($newPass !== '') {
            q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($newPass, PASSWORD_DEFAULT), $id]);
        }
        if ($id === (int)$me['id'] && $bump) {
            $_SESSION['sv'] = (int)q('SELECT session_version FROM users WHERE id = ?', [$id])->fetchColumn();
        }
        flash('Saved ' . ($u['name'] ?: $username) . '.' . ($newPass !== '' ? ' New password is active.' : ''));
        redirect('admin/user_edit.php?id=' . $id);
    }
}

$perms = json_decode($u['perms'] ?: '{}', true) ?: [];
$caps = json_decode($u['caps'] ?: '{}', true) ?: [];
$groups = [];
foreach ($fields as $k => $f) {
    $groups[$f['group']][$k] = $f;
}

$pageTitle = 'Edit ' . $u['name'];
$active = 'users';
require __DIR__ . '/../inc/header.php';
?>
<div class="page-head">
  <div><a class="back" href="<?= h(base_url('admin/users.php')) ?>">← Staff</a><h1><?= h($u['name']) ?></h1></div>
</div>
<?php if ($errors): ?><div class="alert err"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>

<form method="post" id="permForm">
  <?= csrf_field() ?>
  <section class="panel">
    <h2>Account</h2>
    <div class="grid">
      <label class="field"><span class="lbl">Name</span><input name="name" value="<?= h($u['name']) ?>"></label>
      <label class="field"><span class="lbl">Username</span><input name="username" value="<?= h($u['username']) ?>" required autocapitalize="none"></label>
      <label class="field"><span class="lbl">Reset password <small class="muted">(leave empty to keep)</small></span><input name="new_password" type="text" autocomplete="new-password" minlength="6"></label>
      <label class="field"><span class="lbl">Role</span>
        <select name="role" id="roleSel">
          <option value="staff" <?= $u['role'] === 'staff' ? 'selected' : '' ?>>Staff</option>
          <option value="admin" <?= $u['role'] === 'admin' ? 'selected' : '' ?>>Admin (full access)</option>
        </select>
      </label>
      <label class="field check"><input type="checkbox" name="active" value="1" <?= $u['active'] ? 'checked' : '' ?>> Account active (untick to block login)</label>
    </div>
  </section>

  <div id="staffPerms" <?= $u['role'] === 'admin' ? 'hidden' : '' ?>>
  <section class="panel">
    <h2>What can they do?</h2>
    <?php foreach (capability_labels() as $k => $label): ?>
      <label class="check"><input type="checkbox" name="cap[<?= $k ?>]" value="1" <?= !empty($caps[$k]) ? 'checked' : '' ?>> <?= h($label) ?></label>
    <?php endforeach; ?>
  </section>

  <section class="panel" id="orderPerms">
    <h2>Which fields can they see / edit?</h2>
    <div class="preset-row">
      <span class="muted small">Quick fill:</span>
      <?php foreach (permission_presets() as $k => $p): ?>
        <button type="button" class="btn small" data-preset='<?= h(json_encode($p)) ?>'><?= h($p['label']) ?></button>
      <?php endforeach; ?>
      <button type="button" class="btn small" data-all="edit">All edit</button>
    </div>
    <?php foreach ($groups as $gName => $gFields): ?>
      <h3 class="perm-group"><?= h($gName === 'Extra' ? 'Extra fields' : $gName) ?></h3>
      <div class="perm-table">
        <?php foreach ($gFields as $k => $f): $cur = $perms[$k] ?? 'none'; ?>
          <div class="perm-row">
            <span class="perm-name"><?= h($f['label']) ?></span>
            <div class="seg" role="radiogroup" aria-label="<?= h($f['label']) ?>">
              <?php foreach ($levels as $lv => $ll): ?>
                <label class="seg-<?= $lv ?>"><input type="radio" name="perm[<?= h($k) ?>]" value="<?= $lv ?>" <?= $cur === $lv ? 'checked' : '' ?>><span><?= $ll ?></span></label>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
    <p class="hint">“Printed ✓ / Packed ✓ / Shipped ✓ — Edit” means that person can tick it. Every tick records who did it and when.</p>
  </section>

  <section class="panel" id="estPerms">
    <h2>Estimate: which costs can they see?</h2>
    <p class="muted small">Only used when “Production estimates” is ticked above. Staff can always pick the product, size chart and quantities to check an estimate. Hidden parts are left off their screen; with View they can see but not change them. Hidden rates come from the defaults an admin saved on the Estimate page.</p>
    <div class="perm-table">
      <?php foreach (estimate_fields() as $k => $label): $cur = $perms[$k] ?? 'edit'; ?>
        <div class="perm-row">
          <span class="perm-name"><?= h($label) ?></span>
          <div class="seg" role="radiogroup" aria-label="<?= h($label) ?>">
            <?php foreach ($levels as $lv => $ll): ?>
              <label class="seg-<?= $lv ?>"><input type="radio" name="perm[<?= h($k) ?>]" value="<?= $lv ?>" <?= $cur === $lv ? 'checked' : '' ?>><span><?= $ll ?></span></label>
            <?php endforeach; ?>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="preset-row" style="margin-top:10px">
      <span class="muted small">Quick fill:</span>
      <button type="button" class="btn small" data-est='{"est_fabric":"none","est_making":"none","est_breakdown":"none","est_cost":"view","est_margin":"none","est_price":"view"}'>Final cost &amp; price only</button>
      <button type="button" class="btn small" data-est='{"est_fabric":"view","est_making":"view","est_breakdown":"view","est_cost":"view","est_margin":"none","est_price":"view"}'>With breakdown, no profit</button>
      <button type="button" class="btn small" data-est='{"est_fabric":"edit","est_making":"edit","est_breakdown":"edit","est_cost":"edit","est_margin":"edit","est_price":"edit"}'>Everything</button>
    </div>
  </section>
  </div>
  <p id="adminNote" class="alert ok" <?= $u['role'] === 'admin' ? '' : 'hidden' ?>>Admins can see and edit everything.</p>

  <div class="sticky-actions"><button class="btn primary">Save</button></div>
</form>
<?php if ($id !== (int)$me['id']): ?>
<form method="post" class="danger-zone" onsubmit="return confirm('Delete this account permanently? (To only stop someone logging in, untick “Account active” instead.)');">
  <?= csrf_field() ?><input type="hidden" name="do" value="delete">
  <button class="btn danger">Delete account</button>
</form>
<?php endif; ?>

<script>
(function () {
  var form = document.getElementById('permForm');
  function setPerm(k, v) {
    var r = form.querySelector('input[name="perm[' + k + ']"][value="' + v + '"]');
    if (r) r.checked = true;
  }
  form.querySelectorAll('[data-preset]').forEach(function (b) {
    b.addEventListener('click', function () {
      var p = JSON.parse(b.dataset.preset);
      form.querySelectorAll('#orderPerms input[type=radio][value=none]').forEach(function (r) { r.checked = true; });
      Object.keys(p.perms).forEach(function (k) { setPerm(k, p.perms[k]); });
      form.querySelectorAll('input[name^="cap["]').forEach(function (c) {
        var key = c.name.slice(4, -1);
        c.checked = !!p.caps[key];
      });
    });
  });
  form.querySelector('[data-all]').addEventListener('click', function () {
    form.querySelectorAll('#orderPerms input[type=radio][value=edit]').forEach(function (r) { r.checked = true; });
  });
  form.querySelectorAll('[data-est]').forEach(function (b) {
    b.addEventListener('click', function () {
      var p = JSON.parse(b.dataset.est);
      Object.keys(p).forEach(function (k) { setPerm(k, p[k]); });
    });
  });
  document.getElementById('roleSel').addEventListener('change', function () {
    var admin = this.value === 'admin';
    document.getElementById('staffPerms').hidden = admin;
    document.getElementById('adminNote').hidden = !admin;
  });
})();
</script>
<?php require __DIR__ . '/../inc/footer.php'; ?>
