<?php
// Serves mock-up images to logged-in staff only (uploads/ is not public).
require __DIR__ . '/inc/bootstrap.php';

require_login();
$f = (string)($_GET['f'] ?? '');
if (!preg_match('~^\d{4}/\d{2}/[a-f0-9]{24}(_t)?\.(jpg|png|webp|gif)$~', $f)) {
    http_response_code(404);
    exit;
}
// Mock-ups: staff who may see them. Reference images on task pages: whoever has the Tasks page.
// Partner payment screenshots: whoever handles bills.
$base = preg_replace('/_t(\.\w+)$/', '$1', $f);
$proofOk = (cap('billing') || cap('partners')) && q('SELECT 1 FROM partner_ledger WHERE proof = ? LIMIT 1', [$base])->fetchColumn();
// People who manage partners: partners' product pictures and the payment QR codes.
if (!$proofOk && cap('partners')) {
    $proofOk = in_array($base, [(string)setting('partner_qr_gst', ''), (string)setting('partner_qr_nogst', '')], true)
        || (bool)q('SELECT 1 FROM design_images di JOIN design_customers dc ON dc.design_id = di.design_id JOIN partners p ON p.code = dc.customer_code WHERE di.filename = ? LIMIT 1', [$base])->fetchColumn()
        || (bool)q('SELECT 1 FROM partners WHERE settings LIKE ? LIMIT 1', ['%' . $base . '%'])->fetchColumn();
}
if (!$proofOk && !can_view('mockups')) {
    require_once __DIR__ . '/inc/tasks.php';
    if (!has_task_page() || !q('SELECT 1 FROM task_files WHERE filename = ? LIMIT 1', [preg_replace('/_t(\.\w+)$/', '$1', $f)])->fetchColumn()) {
        http_response_code(403);
        exit;
    }
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
header_remove('Pragma');
header('X-LiteSpeed-Cache-Control: no-cache');
header('Cache-Control: private, max-age=2592000, immutable');
header('X-Content-Type-Options: nosniff');
if (!empty($_GET['dl'])) {
    header('Content-Disposition: attachment; filename="' . basename($path) . '"');
}
readfile($path);
