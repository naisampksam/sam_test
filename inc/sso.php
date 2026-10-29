<?php
// Who is logged into the orders app, for the other apps on this site (attendance): an admin there is admin everywhere.
// Reads the orders login (session file + users table) without starting the orders app; include from PHP only.
declare(strict_types=1);

/** The orders-app admin logged in on this browser: ['id' =>, 'name' =>], or null. */
function looma_orders_admin(): ?array
{
    $sid = (string)($_COOKIE['LOOMASESS'] ?? '');
    if (!preg_match('/^[A-Za-z0-9,-]{22,128}$/', $sid)) {
        return null;
    }
    $file = dirname(__DIR__) . '/sessions/sess_' . $sid;
    if (!is_file($file) || filemtime($file) < time() - 60 * 60 * 24 * 14) {
        return null;
    }
    $data = (string)@file_get_contents($file);
    if (!preg_match('/(?:^|;|\})uid\|i:(\d+);/', $data, $u) || !preg_match('/(?:^|;|\})sv\|i:(-?\d+);/', $data, $v)) {
        return null;
    }
    try {
        $c = include dirname(__DIR__) . '/config.php';
        if (!is_array($c) || empty($c['db_name'])) {
            return null;
        }
        $pdo = new PDO('mysql:host=' . ($c['db_host'] ?? 'localhost') . ';dbname=' . $c['db_name'] . ';charset=utf8mb4', (string)($c['db_user'] ?? ''), (string)($c['db_pass'] ?? ''),
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $st = $pdo->prepare("SELECT id, name, username, session_version FROM users WHERE id = ? AND active = 1 AND role = 'admin'");
        $st->execute([(int)$u[1]]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)$row['session_version'] !== (int)$v[1]) {
            return null; // logged out everywhere since, or no longer an admin
        }
        return ['id' => (int)$row['id'], 'name' => (string)($row['name'] ?: $row['username'])];
    } catch (Throwable $e) {
        error_log('Looma SSO: ' . $e->getMessage());
        return null;
    }
}
