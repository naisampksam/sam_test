<?php
// The payment QR code this partner pays to (GST / non-GST / their own, chosen by Looma). Only for the logged-in partner.
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/partners.php';
require __DIR__ . '/../inc/partner_money.php';

$pu = current_partner_user();
$f = $pu ? partner_qr_file($pu['partner']) : '';
$path = $f !== '' ? upload_dir() . '/' . $f : '';
if ($path === '' || !is_file($path)) {
    http_response_code(404);
    exit;
}
session_write_close();
header('Content-Type: image/png');
header('Content-Length: ' . filesize($path));
header_remove('Pragma');
header('X-LiteSpeed-Cache-Control: no-cache');
header('Cache-Control: private, no-cache');
readfile($path);
