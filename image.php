<?php
// Serves mock-up images to logged-in staff only (uploads/ is not public).
require __DIR__ . '/inc/bootstrap.php';

require_login();
if (!can_view('mockups')) {
    http_response_code(403);
    exit;
}
$f = (string)($_GET['f'] ?? '');
if (!preg_match('~^\d{4}/\d{2}/[a-f0-9]{24}(_t)?\.(jpg|png|webp|gif)$~', $f)) {
    http_response_code(404);
    exit;
}
$path = APP_ROOT . '/uploads/' . $f;
if (!is_file($path)) {
    http_response_code(404);
    exit;
}
$types = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp', 'gif' => 'image/gif'];
session_write_close();
header('Content-Type: ' . $types[pathinfo($path, PATHINFO_EXTENSION)]);
header('Content-Length: ' . filesize($path));
header('Cache-Control: private, max-age=2592000, immutable');
header('X-Content-Type-Options: nosniff');
if (!empty($_GET['dl'])) {
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
}
readfile($path);
