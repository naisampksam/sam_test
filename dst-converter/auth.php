<?php
/**
 * Simple internal login: one or more users in data/users.php (password hashes only),
 * session cookie, CSRF tokens, and lock-out after repeated wrong passwords.
 */
if (basename($_SERVER['SCRIPT_FILENAME']) === basename(__FILE__)) { http_response_code(403); exit; }

header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
session_name('embr_session');
session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
session_start();

const AUTH_DATA = __DIR__ . '/data';
const MAX_FAILS = 5;          // wrong passwords before lock-out
const LOCK_MINUTES = 15;

function auth_users(): array
{
    $f = AUTH_DATA . '/users.php';
    return is_file($f) ? (array)(include $f) : [];
}

function auth_csrf(): string
{
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['csrf'];
}

function auth_check_csrf(): bool
{
    return isset($_POST['csrf'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['csrf']);
}

/** Failed-login counter per IP address, stored in data/throttle.json. */
function auth_throttle(string $ip, ?bool $failed = null): int
{
    $f = AUTH_DATA . '/throttle.json';
    $t = is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : [];
    $now = time();
    foreach ($t as $k => $r) if ($r['until'] < $now && $r['last'] < $now - 3600) unset($t[$k]);
    $r = $t[$ip] ?? ['fails' => 0, 'until' => 0, 'last' => 0];
    if ($failed === true) {
        $r['fails']++; $r['last'] = $now;
        if ($r['fails'] >= MAX_FAILS) { $r['until'] = $now + LOCK_MINUTES * 60; $r['fails'] = 0; }
        $t[$ip] = $r;
    } elseif ($failed === false) {
        unset($t[$ip]);
    }
    @file_put_contents($f, json_encode($t), LOCK_EX);
    return max(0, ($r['until'] ?? 0) - $now);
}

function auth_save_password(string $user, string $password): bool
{
    $users = auth_users();
    $users[$user] = password_hash($password, PASSWORD_DEFAULT);
    $php = "<?php\n// Internal users: username => password hash (never store plain passwords here)\nreturn " . var_export($users, true) . ";\n";
    $ok = @file_put_contents(AUTH_DATA . '/users.php', $php, LOCK_EX) !== false;
    // PHP's code cache would otherwise keep serving the old password file for a while
    if ($ok && function_exists('opcache_invalidate')) @opcache_invalidate(AUTH_DATA . '/users.php', true);
    return $ok;
}

function auth_page(string $title, string $body): void
{
    $t = htmlspecialchars($title);
    echo <<<HTML
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow"><title>{$t}</title>
<style>
body{font-family:system-ui,sans-serif;background:#f4f5f7;margin:0;display:grid;place-items:center;min-height:100vh;color:#222}
form{background:#fff;padding:28px;border-radius:12px;box-shadow:0 2px 8px #0002;width:min(340px,90vw)}
h1{font-size:20px;margin:0 0 16px} label{display:block;font-size:14px;margin:10px 0 4px}
input{width:100%;box-sizing:border-box;padding:9px;font-size:15px;border:1px solid #ccc;border-radius:6px}
button{margin-top:16px;width:100%;background:#2563eb;color:#fff;border:0;border-radius:6px;padding:10px;font-size:15px;cursor:pointer}
.err{background:#fee;color:#a00;padding:8px;border-radius:6px;font-size:14px}.ok{background:#e8f7ec;color:#126b2d;padding:8px;border-radius:6px;font-size:14px}
a{color:#2563eb;font-size:14px}
</style></head><body>{$body}</body></html>
HTML;
}

// ---- logout ----
if (isset($_GET['logout'])) {
    $_SESSION = [];
    session_destroy();
    header('Location: ./');
    exit;
}

// ---- login ----
if (empty($_SESSION['user'])) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $msg = '';
    $wait = auth_throttle($ip);
    if ($wait > 0) {
        $msg = 'Too many wrong attempts. Try again in ' . ceil($wait / 60) . ' minutes.';
    } elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['username'])) {
        $users = auth_users();
        $u = (string)$_POST['username']; $p = (string)($_POST['password'] ?? '');
        if (auth_check_csrf() && isset($users[$u]) && password_verify($p, $users[$u])) {
            auth_throttle($ip, false);
            session_regenerate_id(true);
            $_SESSION['user'] = $u;
            header('Location: ./');
            exit;
        }
        usleep(800000);
        auth_throttle($ip, true);
        $msg = 'Wrong username or password.';
    }
    $c = auth_csrf();
    $err = $msg ? '<p class="err">' . htmlspecialchars($msg) . '</p>' : '';
    auth_page('Login – Embroidery DST', <<<HTML
<form method="post" autocomplete="on">
  <h1>🧵 Embroidery DST – Login</h1>{$err}
  <input type="hidden" name="csrf" value="{$c}">
  <label>Username</label><input name="username" required autofocus autocomplete="username">
  <label>Password</label><input name="password" type="password" required autocomplete="current-password">
  <button type="submit">Log in</button>
</form>
HTML);
    exit;
}

// ---- change password (logged in) ----
if (isset($_GET['password'])) {
    $msg = '';
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $users = auth_users(); $u = $_SESSION['user'];
        $new = (string)($_POST['new'] ?? '');
        if (!auth_check_csrf()) $msg = '<p class="err">Session expired, try again.</p>';
        elseif (!password_verify((string)($_POST['current'] ?? ''), $users[$u] ?? '')) $msg = '<p class="err">Current password is wrong.</p>';
        elseif (strlen($new) < 10) $msg = '<p class="err">New password must be at least 10 characters.</p>';
        elseif ($new !== ($_POST['repeat'] ?? '')) $msg = '<p class="err">The new passwords do not match.</p>';
        elseif (!auth_save_password($u, $new)) $msg = '<p class="err">Could not save – make the "data" folder writable (permission 755).</p>';
        else $msg = '<p class="ok">Password changed.</p>';
    }
    $c = auth_csrf();
    auth_page('Change password', <<<HTML
<form method="post">
  <h1>Change password</h1>{$msg}
  <input type="hidden" name="csrf" value="{$c}">
  <label>Current password</label><input name="current" type="password" required autocomplete="current-password">
  <label>New password (min. 10 characters)</label><input name="new" type="password" required minlength="10" autocomplete="new-password">
  <label>Repeat new password</label><input name="repeat" type="password" required minlength="10" autocomplete="new-password">
  <button type="submit">Save</button>
  <p><a href="./">← Back to converter</a></p>
</form>
HTML);
    exit;
}
