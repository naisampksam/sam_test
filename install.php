<?php
// One-time setup: saves database details, creates tables and the first admin account.
// It locks itself once an admin exists. You can delete this file after setup.
declare(strict_types=1);

require __DIR__ . '/inc/schema.php';

$configFile = __DIR__ . '/config.php';
$error = '';
$done = false;

function install_pdo(array $c): PDO
{
    return new PDO('mysql:host=' . $c['db_host'] . ';dbname=' . $c['db_name'] . ';charset=utf8mb4', $c['db_user'], $c['db_pass'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ]);
}

$config = is_file($configFile) ? require $configFile : null;

// Refuse to run again once an admin exists.
if ($config) {
    try {
        $pdo = install_pdo($config);
        $exists = $pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn();
        if ($exists && (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn() > 0) {
            // Still apply any new tables from updates, then stop.
            foreach (schema_sql() as $sql) {
                $pdo->exec($sql);
            }
            migrate($pdo);
            seed_data($pdo);
            exit('Already installed. Database is up to date. <a href="login.php">Go to login</a>');
        }
    } catch (Throwable $e) {
        $error = 'Could not connect with the saved config.php: ' . $e->getMessage();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!$config) {
            $config = [
                'db_host' => trim($_POST['db_host'] ?? 'localhost'),
                'db_name' => trim($_POST['db_name'] ?? ''),
                'db_user' => trim($_POST['db_user'] ?? ''),
                'db_pass' => (string)($_POST['db_pass'] ?? ''),
                'timezone' => 'Asia/Kolkata',
                'max_upload_mb' => 15,
            ];
            install_pdo($config); // test the connection first
            $php = "<?php\nreturn " . var_export($config, true) . ";\n";
            if (@file_put_contents($configFile, $php) === false) {
                throw new RuntimeException('Could not write config.php. Copy config.sample.php to config.php in File Manager and fill it in, then reload this page.');
            }
        }
        $username = strtolower(trim($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        if (!preg_match('/^[a-z0-9._-]{3,60}$/', $username)) {
            throw new RuntimeException('Username: 3-60 characters, letters/numbers/._- only.');
        }
        if (strlen($password) < 8) {
            throw new RuntimeException('Password must be at least 8 characters.');
        }
        $pdo = install_pdo($config);
        foreach (schema_sql() as $sql) {
            $pdo->exec($sql);
        }
        seed_data($pdo);
        $pdo->prepare("INSERT INTO users (username, name, password_hash, role, perms, caps, created_at) VALUES (?, ?, ?, 'admin', '{}', '{}', ?)")
            ->execute([$username, trim($_POST['name'] ?? '') ?: $username, password_hash($password, PASSWORD_DEFAULT), date('Y-m-d H:i:s')]);
        $done = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Install · Looma Orders</title>
<link rel="stylesheet" href="assets/style.css?v=<?= @filemtime(__DIR__ . '/assets/style.css') ?>">
</head>
<body class="auth">
<main class="auth-card">
  <h1>Looma Orders setup</h1>
  <?php if ($done): ?>
    <p class="alert ok">All set. Your admin account is ready.</p>
    <p>For safety, delete <code>install.php</code> from File Manager (it is locked now either way).</p>
    <p><a class="btn primary" href="login.php">Go to login</a></p>
  <?php else: ?>
    <?php if ($error): ?><p class="alert err"><?= htmlspecialchars($error) ?></p><?php endif; ?>
    <form method="post" class="stack">
      <?php if (!$config): ?>
        <h2>1. Database</h2>
        <p class="muted">From Hostinger hPanel → Databases → MySQL Databases.</p>
        <label>Host <input name="db_host" value="localhost" required></label>
        <label>Database name <input name="db_name" required></label>
        <label>Database user <input name="db_user" required></label>
        <label>Database password <input name="db_pass" type="password"></label>
      <?php endif; ?>
      <h2><?= $config ? '' : '2. ' ?>Admin account</h2>
      <label>Your name <input name="name" placeholder="Founder"></label>
      <label>Username <input name="username" required autocapitalize="none"></label>
      <label>Password (min 8) <input name="password" type="password" required minlength="8"></label>
      <button class="btn primary">Install</button>
    </form>
  <?php endif; ?>
</main>
</body>
</html>
