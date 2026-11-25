<?php
// Partner order form: a returning customer's details by mobile number (only from this partner's own orders).
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/partners.php';

$pu = current_partner_user();
header('Content-Type: application/json');
header('Cache-Control: no-store');
if (!$pu) {
    http_response_code(403);
    exit('{}');
}
echo json_encode(partner_customer_lookup($pu['partner'], (string)($_GET['phone'] ?? '')) ?: (object)[], JSON_UNESCAPED_UNICODE);
