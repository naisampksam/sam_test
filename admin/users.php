<?php
require __DIR__ . '/../inc/bootstrap.php';

require_admin();

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = strtolower(trim($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $role = ($_POST['role'] ?? '') === 'admin' ? 'admin' : 'staff';
    $preset = permission_presets()[$_POST['preset'] ?? ''] ?? permission_presets()['viewer'];
    if (!preg_match('/^[a-z0-9._-]{3,60}$/', $username)) {
        $errors[] = 'Username: 3-60 characters, letters/numbers/._- only (no spaces).';
    } elseif (q('SELECT id FROM users WHERE username = ?', [$username])->fetch()) {
        $errors[] = 'That username is already taken.';
    }
    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }
    if (!$errors) {
        q('INSERT INTO users (username, name, password_hash, role, perms, caps, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [
            $username, trim($_POST['name'] ?? '') ?: $username, password_hash($password, PASSWORD_DEFAULT), $role,
            json_encode($preset['perms']), json_encode($preset['caps']), now(),
        ]);
        flash('Staff account created. Adjust their permissions below if needed.');
        redirect('admin/user_edit.php?id=' . db()->lastInsertId());
    }
}

$users = q('SELECT * FROM users ORDER BY active DESC, role, name')->fetchAll();
$pageTitle = 'Staff';
$active = 'users';
require __DIR__ . '/../inc/header.php';
?>
<div class="page-head"><h1>Staff &amp; permissions</h1></div>

<div class="cards">
<?php foreach ($users as $u):
    $perms = json_decode($u['perms'] ?: '{}', true) ?: [];
    $caps = json_decode($u['caps'] ?: '{}', true) ?: [];
    $editF = array_keys(array_filter($perms, fn($p) => $p === 'edit'));
?>
  <a class="card user-card <?= $u['active'] ? '' : 'inactive' ?>" href="<?= h(base_url('admin/user_edit.php?id=' . $u['id'])) ?>">
    <div class="row-between">
      <strong><?= h($u['name']) ?></strong>
      <span class="badge <?= $u['role'] === 'admin' ? 'shipped' : 'pending' ?>"><?= $u['role'] === 'admin' ? 'Admin' : 'Staff' ?></span>
    </div>
    <div class="muted small">@<?= h($u['username']) ?> · <?= $u['active'] ? ($u['last_login'] ? 'last login ' . h(fmt_date($u['last_login'], true)) : 'never logged in') : 'DISABLED' ?></div>
    <?php if ($u['role'] !== 'admin'): ?>
      <div class="small">Can edit: <?= $editF ? h(implode(', ', array_map(fn($f) => strip_tags(all_fields()[$f]['label'] ?? $f), $editF))) : '<span class="muted">nothing</span>' ?></div>
      <?php if (array_filter($caps)): ?><div class="small muted">Also: <?= h(implode(', ', array_map(fn($c) => strtolower(explode(' (', capability_labels()[$c] ?? $c)[0]), array_keys(array_filter($caps))))) ?></div><?php endif; ?>
    <?php else: ?>
      <div class="small muted">Full access to everything</div>
    <?php endif; ?>
  </a>
<?php endforeach; ?>
</div>

<section class="panel" id="add">
  <h2>Add staff</h2>
  <?php if ($errors): ?><div class="alert err"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>
  <form method="post" class="grid">
    <?= csrf_field() ?>
    <label class="field"><span class="lbl">Name</span><input name="name" value="<?= h($_POST['name'] ?? '') ?>" placeholder="e.g. Rahul"></label>
    <label class="field"><span class="lbl">Username (for login)</span><input name="username" value="<?= h($_POST['username'] ?? '') ?>" required autocapitalize="none" placeholder="e.g. rahul"></label>
    <label class="field"><span class="lbl">Password</span><input name="password" type="text" required minlength="6" autocomplete="new-password"></label>
    <label class="field"><span class="lbl">Role</span>
      <select name="role"><option value="staff">Staff</option><option value="admin">Admin (full access)</option></select>
    </label>
    <label class="field"><span class="lbl">Starting permissions</span>
      <select name="preset">
        <?php foreach (permission_presets() as $k => $p): ?><option value="<?= $k ?>"><?= h($p['label']) ?></option><?php endforeach; ?>
      </select>
    </label>
    <div class="field"><span class="lbl">&nbsp;</span><button class="btn primary">Add staff</button></div>
  </form>
  <p class="hint">You can fine-tune exactly which fields each person can see and edit after creating the account.</p>
</section>
<?php require __DIR__ . '/../inc/footer.php'; ?>
