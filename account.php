<?php
require __DIR__ . '/inc/bootstrap.php';

$me = require_login();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $new = (string)($_POST['new_password'] ?? '');
    if (!password_verify((string)($_POST['current_password'] ?? ''), $me['password_hash'])) {
        $error = 'Current password is wrong.';
    } elseif (strlen($new) < 6) {
        $error = 'New password must be at least 6 characters.';
    } elseif ($new !== ($_POST['confirm'] ?? '')) {
        $error = 'The two new passwords don’t match.';
    } else {
        q('UPDATE users SET password_hash = ?, session_version = session_version + 1 WHERE id = ?', [password_hash($new, PASSWORD_DEFAULT), $me['id']]);
        $_SESSION['sv'] = (int)$me['session_version'] + 1;
        flash('Password changed. Other devices have been logged out.');
        redirect('account.php');
    }
}

$pageTitle = 'My account';
require __DIR__ . '/inc/header.php';
?>
<div class="page-head"><h1>My account</h1></div>
<section class="panel narrow">
  <p><b><?= h($me['name']) ?></b> · @<?= h($me['username']) ?> · <?= $me['role'] === 'admin' ? 'Admin' : 'Staff' ?></p>
  <h2>Change password</h2>
  <?php if ($error): ?><p class="alert err"><?= h($error) ?></p><?php endif; ?>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <label>Current password <input type="password" name="current_password" required autocomplete="current-password"></label>
    <label>New password <input type="password" name="new_password" required minlength="6" autocomplete="new-password"></label>
    <label>Repeat new password <input type="password" name="confirm" required minlength="6" autocomplete="new-password"></label>
    <button class="btn primary">Change password</button>
  </form>
</section>
<?php require __DIR__ . '/inc/footer.php'; ?>
