<?php
// Images for the partner portal: only that partner's own design photos.
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/partners.php';

$pu = current_partner_user();
$f = (string)($_GET['f'] ?? '');
if (!$pu || !preg_match('~^\d{4}/\d{2}/[a-f0-9]{24}(_t)?\.(jpg|png|webp)$~', $f) || !partner_owns_image($pu['partner'], $f)) {
    http_response_code(404);
    exit;
}
$path = APP_ROOT . '/uploads/' . $f;
if (!is_file($path)) {
    http_response_code(404);
    exit;
}
session_write_close();
header('Content-Type: ' . ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][pathinfo($path, PATHINFO_EXTENSION)]);
header('Content-Length: ' . filesize($path));
header_remove('Pragma');
header('X-LiteSpeed-Cache-Control: no-cache');
header('Cache-Control: private, max-age=2592000, immutable');
readfile($path);
