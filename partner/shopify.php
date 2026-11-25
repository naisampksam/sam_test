<?php
// Webhook for a partner's Shopify: Shopify Admin → Settings → Notifications → Webhooks → "Order creation" (and
// "Order cancellation"), format JSON, URL = this page with the partner's ?k=… code. Looma gets no access to their
// store: their shop only sends orders here. Every message must carry Shopify's signature made with their key.
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/shopify.php';

header('Content-Type: application/json');
header('Cache-Control: no-store');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('{"ok":false}');
}
ensure_schema();
$k = (string)($_GET['k'] ?? '');
$p = preg_match('/^[a-f0-9]{32}$/', $k) ? (q('SELECT * FROM partners WHERE shop_token = ? AND shop_on = 1 AND active = 1', [$k])->fetch() ?: null) : null;
$body = (string)file_get_contents('php://input', false, null, 0, 2 * 1024 * 1024);
if (!$p || !shop_verify($body, (string)($_SERVER['HTTP_X_SHOPIFY_HMAC_SHA256'] ?? ''), (string)$p['shop_secret'])) {
    http_response_code(401); // Shopify retries; a wrong key shows up as failed deliveries in their Shopify
    exit('{"ok":false}');
}
$j = json_decode($body, true);
if (!is_array($j)) {
    http_response_code(400);
    exit('{"ok":false}');
}
try {
    $res = shop_receive($p, strtolower((string)($_SERVER['HTTP_X_SHOPIFY_TOPIC'] ?? 'orders/create')), $j);
} catch (Throwable $e) {
    error_log('Looma Shopify webhook: ' . $e->getMessage());
    http_response_code(500); // Shopify sends it again later
    exit('{"ok":false}');
}
echo json_encode(['ok' => true, 'result' => $res]);
