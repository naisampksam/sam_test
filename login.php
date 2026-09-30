<?php
require __DIR__ . '/inc/bootstrap.php';

start_session();
$error = '';
$ip = $_SERVER['REMOTE_ADDR'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    q('DELETE FROM login_attempts WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 900)]);
    $recent = (int)q('SELECT COUNT(*) FROM login_attempts WHERE ip = ?', [$ip])->fetchColumn();
    if ($recent >= 10) {
        $error = 'Too many wrong attempts. Please wait 15 minutes.';
    } else {
        $u = q('SELECT * FROM users WHERE username = ? AND active = 1', [strtolower(trim($_POST['username'] ?? ''))])->fetch();
        if ($u && password_verify((string)($_POST['password'] ?? ''), $u['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['uid'] = (int)$u['id'];
            $_SESSION['sv'] = (int)$u['session_version'];
            if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
                q('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash($_POST['password'], PASSWORD_DEFAULT), $u['id']]);
            }
            q('UPDATE users SET last_login = ? WHERE id = ?', [now(), $u['id']]);
            q('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
            $next = (string)($_GET['next'] ?? '');
            // Only allow local paths as the post-login destination.
            redirect(preg_match('~^/(?!/)~', $next) ? $next : 'index.php');
        }
        q('INSERT INTO login_attempts (ip, created_at) VALUES (?, ?)', [$ip, now()]);
        $error = 'Wrong username or password.';
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#1f2a44">
<title>Login · <?= h(setting('company_name', 'Looma Apparels')) ?></title>
<link rel="stylesheet" href="<?= h(base_url('assets/style.css')) ?>">
</head>
<body class="auth">
<main class="auth-card">
  <div class="brand-lg"><?= h(setting('company_name', 'Looma Apparels')) ?></div>
  <p class="muted">Order management — staff login</p>
  <?php if ($error): ?><p class="alert err"><?= h($error) ?></p><?php endif; ?>
  <form method="post" class="stack">
    <?= csrf_field() ?>
    <label>Username <input name="username" required autocapitalize="none" autocomplete="username" autofocus></label>
    <label>Password <input name="password" type="password" required autocomplete="current-password"></label>
    <button class="btn primary">Log in</button>
  </form>
</main>
</body>
</html>
