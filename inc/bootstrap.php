<?php
// Shared setup: config, database, session, auth, permissions and helpers.
declare(strict_types=1);

define('APP_ROOT', dirname(__DIR__));
define('APP_NAME', 'Looma Orders');

if (!is_file(APP_ROOT . '/config.php')) {
    header('Location: ' . base_url('install.php'));
    exit;
}
$CONFIG = require APP_ROOT . '/config.php';
date_default_timezone_set($CONFIG['timezone'] ?? 'Asia/Kolkata');

require_once __DIR__ . '/fields.php';

// ---------------------------------------------------------------- database

function db(): PDO
{
    static $pdo = null;
    global $CONFIG;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . $CONFIG['db_host'] . ';dbname=' . $CONFIG['db_name'] . ';charset=utf8mb4';
        $pdo = new PDO($dsn, $CONFIG['db_user'], $CONFIG['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec("SET time_zone = '" . date('P') . "'");
    }
    return $pdo;
}

function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}

function now(): string
{
    return date('Y-m-d H:i:s');
}

function today(): string
{
    return date('Y-m-d');
}

// ---------------------------------------------------------------- settings

function setting(string $key, ?string $default = null): ?string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (q('SELECT k, v FROM settings')->fetchAll() as $r) {
            $cache[$r['k']] = $r['v'];
        }
    }
    return $cache[$key] ?? $default;
}

function set_setting(string $key, string $value): void
{
    q('INSERT INTO settings (k, v) VALUES (?, ?) ON DUPLICATE KEY UPDATE v = VALUES(v)', [$key, $value]);
}

/** Dispatch deadline: order date + N days, optionally skipping Sundays. */
function compute_due_date(string $orderDate): string
{
    $days = max(0, (int)setting('dispatch_days', '2'));
    $skipSundays = setting('skip_sundays', '1') === '1';
    $d = new DateTime(substr($orderDate, 0, 10));
    while ($days > 0) {
        $d->modify('+1 day');
        if ($skipSundays && $d->format('w') === '0') {
            continue;
        }
        $days--;
    }
    return $d->format('Y-m-d');
}

// ---------------------------------------------------------------- session & auth

function start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    // Keep sessions in a private folder so shared-host cleanup doesn't log staff out every 24 min.
    $dir = APP_ROOT . '/sessions';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
        @file_put_contents($dir . '/.htaccess', "Require all denied\n");
    }
    if (is_dir($dir) && is_writable($dir)) {
        session_save_path($dir);
    }
    $lifetime = 60 * 60 * 24 * 14;
    ini_set('session.gc_maxlifetime', (string)$lifetime);
    ini_set('session.use_strict_mode', '1');
    session_name('LOOMASESS');
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function current_user(): ?array
{
    static $user = false;
    if ($user !== false) {
        return $user;
    }
    start_session();
    $user = null;
    if (!empty($_SESSION['uid'])) {
        $u = q('SELECT * FROM users WHERE id = ? AND active = 1', [$_SESSION['uid']])->fetch();
        if ($u && ($u['session_version'] ?? 0) == ($_SESSION['sv'] ?? -1)) {
            $u['perms'] = json_decode($u['perms'] ?: '{}', true) ?: [];
            $u['caps'] = json_decode($u['caps'] ?: '{}', true) ?: [];
            $user = $u;
        } else {
            $_SESSION = [];
        }
    }
    return $user;
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        redirect('login.php?next=' . urlencode($_SERVER['REQUEST_URI'] ?? ''));
    }
    return $u;
}

function is_admin(?array $u = null): bool
{
    $u = $u ?? current_user();
    return $u && $u['role'] === 'admin';
}

function require_admin(): array
{
    $u = require_login();
    if (!is_admin($u)) {
        http_response_code(403);
        exit('Only admins can open this page.');
    }
    return $u;
}

/** Field permission for the current user: 'none', 'view' or 'edit'. */
function perm(string $field, ?array $u = null): string
{
    $u = $u ?? current_user();
    if (!$u) {
        return 'none';
    }
    if (is_admin($u)) {
        return 'edit';
    }
    return $u['perms'][$field] ?? 'none';
}

function can_view(string $field): bool
{
    return perm($field) !== 'none';
}

function can_edit(string $field): bool
{
    return perm($field) === 'edit';
}

/** Action permission: create, delete, dashboard, export. */
function cap(string $name, ?array $u = null): bool
{
    $u = $u ?? current_user();
    if (!$u) {
        return false;
    }
    return is_admin($u) || !empty($u['caps'][$name]);
}

// ---------------------------------------------------------------- csrf

function csrf_token(): string
{
    start_session();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check(): void
{
    start_session();
    $sent = $_POST['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], (string)$sent)) {
        http_response_code(400);
        exit('Your session expired. Please go back, refresh the page and try again.');
    }
}

// ---------------------------------------------------------------- misc helpers

function h($s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

function base_url(string $path = ''): string
{
    // Works whether the app sits at the subdomain root or in a sub-folder.
    $script = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '/');
    $dir = rtrim(dirname($script), '/');
    if (basename($dir) === 'admin') {
        $dir = rtrim(dirname($dir), '/');
    }
    return $dir . '/' . ltrim($path, '/');
}

function redirect(string $path): void
{
    header('Location: ' . (preg_match('~^(https?:)?/~', $path) ? $path : base_url($path)));
    exit;
}

function flash(?string $msg = null, string $type = 'ok'): ?array
{
    start_session();
    if ($msg !== null) {
        $_SESSION['flash'] = ['msg' => $msg, 'type' => $type];
        return null;
    }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}

function order_no($id): string
{
    return 'LA' . str_pad((string)$id, 5, '0', STR_PAD_LEFT);
}

function fmt_date(?string $d, bool $withTime = false): string
{
    if (!$d) {
        return '';
    }
    $t = strtotime($d);
    return $withTime ? date('d M, h:i A', $t) : date('d M Y', $t);
}

function user_names(): array
{
    static $names = null;
    if ($names === null) {
        $names = [];
        foreach (q('SELECT id, name, username FROM users')->fetchAll() as $r) {
            $names[$r['id']] = $r['name'] ?: $r['username'];
        }
    }
    return $names;
}

function user_name($id): string
{
    return $id ? (user_names()[$id] ?? 'Unknown') : '';
}

/** Order stage used for badges and filters. Uses item_count / printed_count when present. */
function order_status(array $o): array
{
    $delayed = !$o['shipped'] && $o['due_date'] && $o['due_date'] < today();
    $n = (int)($o['printable_count'] ?? $o['item_count'] ?? 0);
    $p = (int)($o['printed_count'] ?? 0);
    if ($o['shipped']) {
        $s = ['shipped', 'Shipped'];
    } elseif ($o['packed']) {
        $s = ['packed', 'Packed'];
    } elseif ($o['printed'] && isset($o['printable_count']) && !$n) {
        $s = ['printed', 'Plain · to pack'];
    } elseif ($o['printed']) {
        $s = ['printed', 'Printed'];
    } elseif ($p > 0 && $n > 1) {
        $s = ['printing', "Printing $p/$n"];
    } else {
        $s = ['pending', 'To print'];
    }
    return ['key' => $s[0], 'label' => $s[1], 'delayed' => $delayed];
}

function catalog(): array
{
    $gsms = q('SELECT * FROM gsm_options ORDER BY sort, id')->fetchAll();
    $products = q('SELECT * FROM products WHERE active = 1 ORDER BY sort, name')->fetchAll();
    $colors = q('SELECT c.* FROM product_colors c JOIN products p ON p.id = c.product_id
                 WHERE c.active = 1 AND p.active = 1 ORDER BY c.sort, c.name')->fetchAll();
    $byProduct = [];
    foreach ($colors as $c) {
        $byProduct[$c['product_id']][] = ['name' => $c['name'], 'hex' => $c['hex']];
    }
    $out = [];
    foreach ($gsms as $g) {
        $list = [];
        foreach ($products as $p) {
            if ((int)$p['gsm_id'] === (int)$g['id']) {
                $list[] = [
                    'name' => $p['name'],
                    'sizes' => array_values(array_filter(array_map('trim', explode(',', (string)$p['sizes'])), 'strlen')),
                    'colors' => $byProduct[$p['id']] ?? [],
                ];
            }
        }
        $out[] = ['gsm' => $g['label'], 'products' => $list];
    }
    return $out;
}

function couriers(): array
{
    return array_column(q('SELECT name FROM couriers WHERE active = 1 ORDER BY sort, name')->fetchAll(), 'name');
}
