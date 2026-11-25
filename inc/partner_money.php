<?php
// Partner money: each partner's price list (T-shirt + print + packing + shipping, before GST), the bill of every
// accepted order (GST invoice when they have a GSTIN; otherwise an internal bill they never see — it keeps stock and
// sales right), and their account: charges, payments (proof → team confirms), COD received for them, payouts.
// Money in is put against the oldest unpaid bills first, so "Payments due" stays right.
declare(strict_types=1);

require_once __DIR__ . '/partners.php';
require_once __DIR__ . '/billing.php';

const PARTNER_PAY_MODES = ['wallet' => 'Wallet — pays in advance', 'per_order' => 'Pay per order', 'daily' => 'Daily bill', 'credit' => 'Credit — limit and days'];
const LEDGER_KINDS = ['charge' => 'Order', 'payment' => 'Payment', 'cod' => 'COD received', 'payout' => 'Paid out to you', 'credit' => 'Credit', 'debit' => 'Charge'];
const LEDGER_IN = ['payment', 'cod', 'credit'];

// ---------------------------------------------------------------- prices

/** Price list (before GST): ['tshirt' => [gsm|product => ₹], 'print' => ₹ per piece, 'design' => [id => ₹], 'packing', 'ship_first', 'ship_extra', 'cod_fee']. */
function partner_prices(array $p): array
{
    $s = json_decode((string)($p['settings'] ?? '') ?: '{}', true) ?: [];
    $pr = (array)($s['prices'] ?? []);
    return ['tshirt' => (array)($pr['tshirt'] ?? []), 'print' => (float)($pr['print'] ?? 0), 'design' => (array)($pr['design'] ?? []),
        'packing' => (float)($pr['packing'] ?? 0), 'ship_first' => (float)($pr['ship_first'] ?? 0), 'ship_extra' => (float)($pr['ship_extra'] ?? 0),
        'cod_fee' => (float)($pr['cod_fee'] ?? 0)];
}

/** Admin: prices, GSTIN, GST on/off, how they pay. Returns errors. */
function save_partner_money(int $id, array $in): array
{
    $p = partner_by_id($id);
    if (!$p) {
        return ['Partner not found.'];
    }
    $num = fn($v) => max(0, round((float)str_replace([',', '₹', ' '], '', (string)$v), 2));
    $gstin = strtoupper(preg_replace('/\s/', '', (string)($in['gstin'] ?? '')));
    if ($gstin !== '' && !preg_match('/^\d{2}[A-Z0-9]{13}$/', $gstin)) {
        return ['GSTIN should be 15 letters and numbers (e.g. 32ABCDE1234F1Z5).'];
    }
    $prices = ['tshirt' => [], 'design' => [], 'print' => $num($in['print'] ?? 0), 'packing' => $num($in['packing'] ?? 0),
        'ship_first' => $num($in['ship_first'] ?? 0), 'ship_extra' => $num($in['ship_extra'] ?? 0), 'cod_fee' => $num($in['cod_fee'] ?? 0)];
    foreach ((array)($in['tshirt'] ?? []) as $k => $v) {
        if (trim((string)$v) !== '') {
            $prices['tshirt'][(string)$k] = $num($v);
        }
    }
    foreach ((array)($in['design'] ?? []) as $k => $v) {
        if (trim((string)$v) !== '') {
            $prices['design'][(string)(int)$k] = $num($v);
        }
    }
    $settings = json_decode((string)($p['settings'] ?? '') ?: '{}', true) ?: [];
    $settings['prices'] = $prices;
    update_row('partners', $id, ['gstin' => $gstin, 'gst_add' => !empty($in['gst_add']) ? 1 : 0,
        'pay_mode' => isset(PARTNER_PAY_MODES[$in['pay_mode'] ?? '']) ? $in['pay_mode'] : 'credit',
        'credit_limit' => $num($in['credit_limit'] ?? 0), 'credit_days' => max(1, min(120, (int)($in['credit_days'] ?? 15))),
        'settings' => json_encode($settings, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
    if ($gstin !== '') {
        q("UPDATE customers SET gstin = ? WHERE code = ?", [$gstin, $p['code']]);
    }
    return [];
}

/**
 * Bill lines for a partner order. $items: partner order items (qty) or order items (quantity).
 * T-shirt at the partner's price (else the catalog price), printing per design (else their default),
 * packing + shipping (first piece + each extra) + COD fee as lines. GST only when "Add GST" is on.
 */
function partner_price_lines(array $p, array $items, string $paymentType): array
{
    $pr = partner_prices($p);
    $gstOn = (bool)$p['gst_add'];
    $gst = $gstOn ? (float)setting('inv_default_gst', '5') : 0.0;
    $svc = $gstOn ? ship_gst_rate() : 0.0;
    $hsn = setting('inv_default_hsn', '6109');
    $lines = [];
    $pcs = 0;
    $prints = [];
    foreach ($items as $it) {
        $q = (int)($it['qty'] ?? $it['quantity'] ?? 0);
        if ($q < 1) {
            continue;
        }
        $pcs += $q;
        $key = $it['gsm'] . '|' . $it['product'];
        $rate = $pr['tshirt'][$key] ?? (catalog_price((string)$it['gsm'], (string)$it['product'], $q) ?? 0);
        $lines[] = ['stock_id' => null, 'description' => 'T-shirt – ' . implode(' · ', array_filter([$it['gsm'], $it['product'], $it['color'], $it['size'] !== '' ? 'Size ' . $it['size'] : ''], 'strlen')),
            'hsn' => $hsn, 'qty' => $q, 'unit' => 'pcs', 'rate' => (float)$rate, 'gst_rate' => $gst, '_stock' => $it];
        $did = (int)($it['design_id'] ?? 0);
        $name = (string)($it['design'] ?? $it['design_name'] ?? '');
        $prints[$did . '|' . $name] = ($prints[$did . '|' . $name] ?? 0) + $q;
    }
    foreach ($prints as $k => $q) {
        [$did, $name] = explode('|', $k, 2);
        $rate = $pr['design'][(string)(int)$did] ?? $pr['print'];
        if ($rate > 0) {
            $lines[] = ['stock_id' => null, 'description' => 'Printing' . ($name !== '' ? ' – ' . preg_replace('/ · .*$/', '', $name) : ''), 'hsn' => $hsn, 'qty' => $q, 'unit' => 'pcs', 'rate' => $rate, 'gst_rate' => $gst];
        }
    }
    if ($pcs && $pr['packing'] > 0) {
        $lines[] = ['stock_id' => null, 'description' => 'Packing', 'hsn' => '9985', 'qty' => 1, 'unit' => 'order', 'rate' => $pr['packing'], 'gst_rate' => $svc];
    }
    $ship = $pcs ? $pr['ship_first'] + max(0, $pcs - 1) * $pr['ship_extra'] : 0;
    if ($ship > 0) {
        $lines[] = ['stock_id' => null, 'description' => 'Shipping', 'hsn' => '9968', 'qty' => 1, 'unit' => 'order', 'rate' => $ship, 'gst_rate' => $svc];
    }
    if ($pcs && $paymentType === 'cod' && $pr['cod_fee'] > 0) {
        $lines[] = ['stock_id' => null, 'description' => 'COD charge', 'hsn' => '9968', 'qty' => 1, 'unit' => 'order', 'rate' => $pr['cod_fee'], 'gst_rate' => $svc];
    }
    return $lines;
}

/** What an order costs the partner: [lines, totals] (same sums as its bill). */
function partner_quote(array $p, array $items, string $paymentType): array
{
    [$lines, $t] = compute_bill(array_map(fn($l) => array_diff_key($l, ['_stock' => 1]), partner_price_lines($p, $items, $paymentType)), 0, 0, false, 0);
    return [$lines, $t];
}

/** Every line has its T-shirt (a Shopify product may still need linking). */
function partner_order_ready_safe(array $po): bool
{
    foreach ($po['items'] as $it) {
        if (($it['gsm'] ?? '') === '' || ($it['product'] ?? '') === '') {
            return false;
        }
    }
    return (bool)$po['items'];
}

// ---------------------------------------------------------------- the bill of an accepted order

/** Partner order behind a Looma order, or null. */
function partner_order_of(int $orderId): ?array
{
    try {
        $po = q('SELECT * FROM partner_orders WHERE order_id = ? LIMIT 1', [$orderId])->fetch();
    } catch (Throwable $e) {
        return null;
    }
    if (!$po) {
        return null;
    }
    $po['items'] = json_decode($po['items'] ?: '[]', true) ?: [];
    return $po;
}

/**
 * Make or refresh the bill of an accepted partner order from the order's items, and its charge on their account.
 * GST invoice (our name and GSTIN, theirs on it) when they have a GSTIN; an internal plain bill otherwise.
 */
function partner_bill_sync(int $orderId): ?int
{
    $po = partner_order_of($orderId);
    $o = get_order($orderId);
    if (!$po || !$o || $o['deleted_at']) {
        return null;
    }
    $p = partner_by_id((int)$po['partner_id']);
    $bill = $po['bill_id'] ? get_bill((int)$po['bill_id']) : null;
    if ($bill && ($bill['status'] !== 'final' || bill_lock_reason($bill) === 'old')) {
        return (int)$bill['id'];
    }
    $raw = partner_price_lines($p, order_items($orderId), (string)$o['payment_type']);
    $lines = [];
    foreach ($raw as $l) {
        if (isset($l['_stock'])) {
            $it = $l['_stock'];
            $s = stock_for_item($it) ?: (($it['size'] ?? '') !== '' && catalog_product((string)$it['gsm'], (string)$it['product'])
                ? stock_get(blank_stock_item((string)$it['gsm'], (string)$it['product'], (string)$it['color'], (string)$it['size'])) : null);
            $l['stock_id'] = $s ? (int)$s['id'] : null; // the T-shirts leave stock with the bill
            unset($l['_stock']);
        }
        $lines[] = $l;
    }
    if (!$lines) {
        return $bill ? (int)$bill['id'] : null;
    }
    $sellerState = setting('inv_state', 'Kerala');
    $hasGst = $p['gstin'] !== '';
    $state = $hasGst ? (gstin_state($p['gstin']) ?? $sellerState) : $sellerState;
    $head = ['order_id' => $orderId, 'auto' => 1, 'customer_code' => $p['code'], 'bill_name' => $p['name'], 'bill_phone' => $p['phone'],
        'bill_address' => (string)$p['ret_address'], 'bill_pincode' => $p['ret_pincode'], 'bill_gstin' => $p['gstin'], 'bill_state' => $state,
        'inter_state' => mb_strtolower($state) !== mb_strtolower($sellerState) ? 1 : 0, 'branding' => $hasGst ? 'looma' : 'plain',
        'discount' => 0, 'shipping' => 0, 'notes' => 'Partner order ' . $po['ref'] . ' · ship to ' . $o['ship_name'] . ' ' . $o['ship_pincode'],
        'bill_date' => $bill['bill_date'] ?? today(), 'no_stock' => 0];
    $head += $hasGst
        ? ['seller_name' => setting('inv_seller_name', setting('company_name', 'Looma Apparels')), 'seller_address' => setting('inv_seller_address', ''),
            'seller_phone' => setting('inv_seller_phone', ''), 'seller_gstin' => setting('inv_gstin', '')]
        : ['seller_name' => '', 'seller_address' => '', 'seller_phone' => '', 'seller_gstin' => ''];
    $billId = save_bill($bill ? (int)$bill['id'] : null, 'invoice', $head, $lines);
    $total = (float)get_bill($billId)['total'];
    q('UPDATE partner_orders SET bill_id = ? WHERE id = ?', [$billId, $po['id']]);
    // the charge on their account follows the bill
    $ch = q("SELECT id, amount FROM partner_ledger WHERE partner_order_id = ? AND kind = 'charge' LIMIT 1", [$po['id']])->fetch();
    if ($ch) {
        if (abs((float)$ch['amount'] - $total) > 0.004) {
            q('UPDATE partner_ledger SET amount = ?, note = ? WHERE id = ?', [$total, 'Order ' . $po['ref'] . ' (changed)', $ch['id']]);
        }
    } else {
        q("INSERT INTO partner_ledger (partner_id, kind, amount, partner_order_id, order_id, bill_id, ref, note, created_by, created_at)
           VALUES (?, 'charge', ?, ?, ?, ?, ?, ?, ?, ?)", [$p['id'], $total, $po['id'], $orderId, $billId, $po['ref'], 'Order ' . $po['ref'], current_user()['id'] ?? null, now()]);
    }
    partner_settle((int)$p['id']);
    return $billId;
}

/** State from the first two digits of a GSTIN (32 → Kerala), or null. */
function gstin_state(string $gstin): ?string
{
    $codes = ['01' => 'Jammu and Kashmir', '02' => 'Himachal Pradesh', '03' => 'Punjab', '04' => 'Chandigarh', '05' => 'Uttarakhand', '06' => 'Haryana', '07' => 'Delhi',
        '08' => 'Rajasthan', '09' => 'Uttar Pradesh', '10' => 'Bihar', '11' => 'Sikkim', '12' => 'Arunachal Pradesh', '13' => 'Nagaland', '14' => 'Manipur', '15' => 'Mizoram',
        '16' => 'Tripura', '17' => 'Meghalaya', '18' => 'Assam', '19' => 'West Bengal', '20' => 'Jharkhand', '21' => 'Odisha', '22' => 'Chhattisgarh', '23' => 'Madhya Pradesh',
        '24' => 'Gujarat', '26' => 'Dadra and Nagar Haveli and Daman and Diu', '27' => 'Maharashtra', '29' => 'Karnataka', '30' => 'Goa', '31' => 'Lakshadweep', '32' => 'Kerala',
        '33' => 'Tamil Nadu', '34' => 'Puducherry', '35' => 'Andaman and Nicobar Islands', '36' => 'Telangana', '37' => 'Andhra Pradesh', '38' => 'Ladakh'];
    return $codes[substr($gstin, 0, 2)] ?? null;
}

// ---------------------------------------------------------------- the account

/** Balance and what is due: ['balance' (+ = in their favour), 'due', 'overdue' (bool), 'oldest_due' date, 'pending_in', 'pending_out', 'available']. */
function partner_account(array $p): array
{
    $r = q("SELECT
              IFNULL(SUM(CASE WHEN status = 'ok' AND kind IN ('payment','cod','credit') THEN amount END), 0) cin,
              IFNULL(SUM(CASE WHEN status = 'ok' AND kind IN ('charge','payout','debit') THEN amount END), 0) cout,
              IFNULL(SUM(CASE WHEN status = 'pending' AND kind = 'payment' THEN amount END), 0) pin,
              IFNULL(SUM(CASE WHEN status = 'pending' AND kind = 'payout' THEN amount END), 0) pout
            FROM partner_ledger WHERE partner_id = ?", [$p['id']])->fetch();
    $bal = round((float)$r['cin'] - (float)$r['cout'], 2);
    $unpaid = q("SELECT MIN(b.bill_date) d, IFNULL(SUM(b.total - b.paid), 0) due FROM partner_orders po JOIN bills b ON b.id = po.bill_id
                 WHERE po.partner_id = ? AND b.status = 'final' AND b.paid < b.total - 0.005", [$p['id']])->fetch();
    $oldest = $unpaid['d'] ?: null;
    $days = ['daily' => 1, 'credit' => max(1, (int)$p['credit_days'])][$p['pay_mode']] ?? 0;
    $overdue = $oldest !== null && $oldest <= date('Y-m-d', strtotime('-' . $days . ' days'));
    return ['balance' => $bal, 'due' => round(max(0, -$bal), 2), 'overdue' => $overdue, 'oldest_due' => $oldest,
        'due_by' => $oldest ? date('Y-m-d', strtotime($oldest . ' +' . $days . ' days')) : null,
        'pending_in' => (float)$r['pin'], 'pending_out' => (float)$r['pout'], 'available' => round(max(0, $bal - (float)$r['pout']), 2)];
}

/** Put the money they have with Looma against their oldest unpaid bills (so the bills show paid). */
function partner_settle(int $partnerId): void
{
    $r = q("SELECT IFNULL(SUM(CASE WHEN kind IN ('payment','cod','credit') THEN amount WHEN kind IN ('payout','debit') THEN -amount END), 0)
            FROM partner_ledger WHERE partner_id = ? AND status = 'ok'", [$partnerId])->fetchColumn();
    $used = (float)q("SELECT IFNULL(SUM(bp.amount), 0) FROM bill_payments bp JOIN partner_orders po ON po.bill_id = bp.bill_id
                      WHERE po.partner_id = ? AND bp.note = 'Partner account'", [$partnerId])->fetchColumn();
    $avail = round((float)$r - $used, 2);
    if ($avail <= 0.004) {
        return;
    }
    foreach (q("SELECT b.id, b.total - b.paid due FROM partner_orders po JOIN bills b ON b.id = po.bill_id
                WHERE po.partner_id = ? AND b.status = 'final' AND b.paid < b.total - 0.005 ORDER BY b.bill_date, b.id", [$partnerId])->fetchAll() as $b) {
        $pay = round(min($avail, (float)$b['due']), 2);
        if ($pay <= 0) {
            break;
        }
        q('INSERT INTO bill_payments (bill_id, amount, mode, paid_on, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$b['id'], $pay, 'Other', today(), 'Partner account', current_user()['id'] ?? null, now()]);
        bill_recount_paid((int)$b['id']);
        $avail = round($avail - $pay, 2);
    }
}

/**
 * Can Looma accept an order of ₹$amount for this partner now? null = yes, else why not.
 * Wallet / per order: enough money with Looma. Daily bill: yesterday's bill paid. Credit: not overdue, within the limit.
 */
function partner_money_block(array $p, float $amount): ?string
{
    $a = partner_account($p);
    $need = round($amount - $a['balance'], 2);
    switch ($p['pay_mode']) {
        case 'credit':
            if ($a['overdue']) {
                return 'Payment overdue since ' . fmt_date($a['due_by']) . ' (₹' . inr_number($a['due']) . ' due).';
            }
            if ((float)$p['credit_limit'] > 0 && -$a['balance'] + $amount > (float)$p['credit_limit'] + 0.004) {
                return 'Over the credit limit of ₹' . inr_number((float)$p['credit_limit']) . ' (₹' . inr_number($a['due']) . ' due + this order ₹' . inr_number($amount) . ').';
            }
            return null;
        case 'daily':
            return $a['overdue'] ? 'Earlier day’s bill not paid yet (₹' . inr_number($a['due']) . ' due).' : null;
        default: // wallet, per_order
            return $need > 0.004 ? 'Needs ₹' . inr_number($need, 2) . ' more with Looma (balance ₹' . inr_number($a['balance'], 2) . ', order ₹' . inr_number($amount, 2) . ').' : null;
    }
}

/** Statement rows, newest first. */
function partner_statement(int $partnerId, int $limit = 200): array
{
    return q('SELECT l.*, po.ref AS po_ref FROM partner_ledger l LEFT JOIN partner_orders po ON po.id = l.partner_order_id
              WHERE l.partner_id = ? ORDER BY l.id DESC LIMIT ' . (int)$limit, [$partnerId])->fetchAll();
}

/** Partner: "I paid ₹X" with UTR and a screenshot — waits for the team to confirm. */
function partner_payment_send(array $pu, array $in, ?array $file, ?int $poId = null): ?string
{
    $amt = round((float)str_replace([',', '₹', ' '], '', (string)($in['amount'] ?? '')), 2);
    $ref = mb_substr(trim((string)($in['ref'] ?? '')), 0, 100);
    if ($amt <= 0 || $amt > 10000000) {
        return 'Type the amount you paid.';
    }
    if ($ref === '' && (!$file || $file['error'] === UPLOAD_ERR_NO_FILE)) {
        return 'Add the UTR / reference number or a screenshot of the payment.';
    }
    $proof = '';
    if ($file && $file['error'] !== UPLOAD_ERR_NO_FILE) {
        $mime = $file['error'] === UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name']) ? (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) : '';
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            return 'The screenshot must be a JPG, PNG or WEBP picture.';
        }
        [$path, $err] = store_upload($file);
        if ($err) {
            return $err;
        }
        $proof = (string)$path;
    }
    if ($poId && !partner_order($poId, (int)$pu['partner']['id'])) {
        $poId = null;
    }
    q("INSERT INTO partner_ledger (partner_id, kind, amount, status, partner_order_id, ref, note, proof, created_by_partner, created_at)
       VALUES (?, 'payment', ?, 'pending', ?, ?, ?, ?, ?, ?)",
        [$pu['partner']['id'], $amt, $poId, $ref, mb_substr(trim((string)($in['note'] ?? '')), 0, 255), $proof, $pu['id'], now()]);
    partner_notify($pu['partner'], 'paid ₹' . inr_number($amt) . ($ref !== '' ? ' · ' . $ref : '') . ' — confirm it', 'partner_money.php');
    return null;
}

/** Partner: pay out ₹X of their money with Looma (COD received etc.) to their bank. */
function partner_payout_request(array $pu, float $amount, string $note): ?string
{
    $a = partner_account($pu['partner']);
    $amount = round($amount, 2);
    if ($amount <= 0) {
        return 'Type the amount.';
    }
    if ($amount > $a['available'] + 0.004) {
        return 'You can ask for up to ₹' . inr_number($a['available'], 2) . '.';
    }
    q("INSERT INTO partner_ledger (partner_id, kind, amount, status, note, created_by_partner, created_at) VALUES (?, 'payout', ?, 'pending', ?, ?, ?)",
        [$pu['partner']['id'], $amount, mb_substr(trim($note), 0, 255), $pu['id'], now()]);
    partner_notify($pu['partner'], 'asks for a payout of ₹' . inr_number($amount), 'partner_money.php');
    return null;
}

/** Team: confirm / reject a payment, mark a payout paid / reject it. */
function partner_ledger_decide(int $id, bool $ok, string $ref = ''): bool
{
    if (!cap('billing')) {
        return false;
    }
    $l = q("SELECT * FROM partner_ledger WHERE id = ? AND status = 'pending'", [$id])->fetch();
    if (!$l) {
        return false;
    }
    q('UPDATE partner_ledger SET status = ?, ref = IF(? <> \'\', ?, ref), done_by = ?, done_at = ? WHERE id = ?',
        [$ok ? 'ok' : 'rejected', $ref, mb_substr($ref, 0, 100), current_user()['id'], now(), $id]);
    if ($ok) {
        partner_settle((int)$l['partner_id']);
    }
    return true;
}

/** Team: money received another way (cash, bank) or a correction. */
function partner_ledger_add(int $partnerId, string $kind, float $amount, string $ref, string $note): ?string
{
    if (!cap('billing') || !in_array($kind, ['payment', 'credit', 'debit'], true)) {
        return 'Not allowed.';
    }
    $amount = round($amount, 2);
    if ($amount <= 0) {
        return 'Type the amount.';
    }
    q('INSERT INTO partner_ledger (partner_id, kind, amount, ref, note, created_by, created_at, done_by, done_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$partnerId, $kind, $amount, mb_substr(trim($ref), 0, 100), mb_substr(trim($note), 0, 255), current_user()['id'], now(), current_user()['id'], now()]);
    partner_settle($partnerId);
    return null;
}

// ---------------------------------------------------------------- COD collected for them

/** Shipped COD partner orders whose money has not come in yet. */
function partner_cod_waiting(int $partnerId = 0): array
{
    return q("SELECT o.id, o.cod_amount, o.tracking_no, o.courier, o.shipped_at, o.ship_name, po.ref, po.id AS po_id, p.name AS partner_name, p.id AS partner_id
              FROM partner_orders po JOIN orders o ON o.id = po.order_id JOIN partners p ON p.id = po.partner_id
              WHERE po.status = 'accepted' AND o.payment_type = 'cod' AND o.shipped = 1 AND o.deleted_at IS NULL"
        . ($partnerId ? ' AND po.partner_id = ' . $partnerId : '') . "
                AND NOT EXISTS (SELECT 1 FROM partner_ledger l WHERE l.order_id = o.id AND l.kind = 'cod' AND l.status = 'ok')
              ORDER BY o.shipped_at")->fetchAll();
}

/** COD money of these Looma orders came in from the courier: it goes to each partner's account. Returns how many. */
function partner_cod_received(array $orderIds, string $ref = ''): int
{
    if (!cap('billing')) {
        return 0;
    }
    $n = 0;
    $touched = [];
    foreach (array_unique(array_map('intval', $orderIds)) as $oid) {
        $row = q("SELECT o.cod_amount, o.tracking_no, po.id po_id, po.partner_id, po.ref FROM partner_orders po JOIN orders o ON o.id = po.order_id
                  WHERE o.id = ? AND o.payment_type = 'cod' AND po.status = 'accepted'
                    AND NOT EXISTS (SELECT 1 FROM partner_ledger l WHERE l.order_id = o.id AND l.kind = 'cod' AND l.status = 'ok')", [$oid])->fetch();
        if (!$row || (float)$row['cod_amount'] <= 0) {
            continue;
        }
        q("INSERT INTO partner_ledger (partner_id, kind, amount, partner_order_id, order_id, ref, note, created_by, created_at, done_by, done_at)
           VALUES (?, 'cod', ?, ?, ?, ?, ?, ?, ?, ?, ?)", [$row['partner_id'], $row['cod_amount'], $row['po_id'], $oid, $ref !== '' ? $ref : $row['tracking_no'],
            'COD of ' . $row['ref'], current_user()['id'], now(), current_user()['id'], now()]);
        log_change($oid, 'partner_order', null, 'COD ₹' . inr_number((float)$row['cod_amount']) . ' received — to the partner’s account');
        $touched[$row['partner_id']] = true;
        $n++;
    }
    foreach (array_keys($touched) as $pid) {
        partner_settle((int)$pid);
    }
    return $n;
}

/**
 * Courier's COD remittance report (.csv / .xlsx): rows matched to partner COD orders by AWB / tracking number.
 * Returns [received, not matched].
 */
function partner_cod_import(array $rows): array
{
    if (count($rows) < 2) {
        return [0, 0];
    }
    $h = array_map(fn($x) => strtolower(preg_replace('/[^a-z0-9]/i', '', (string)$x)), $rows[0]);
    $tcol = null;
    foreach ($h as $i => $name) {
        if (preg_match('/^(awb|awbno|awbnumber|waybill|waybillno|trackingid|trackingno|trackingnumber|tracking|consignmentno|cnno)$/', $name)) {
            $tcol = $i;
            break;
        }
    }
    if ($tcol === null) {
        return [0, -1];
    }
    $ids = [];
    $miss = 0;
    foreach (array_slice($rows, 1) as $r) {
        $t = trim((string)($r[$tcol] ?? ''), " '\t");
        if ($t === '') {
            continue;
        }
        $oid = q("SELECT o.id FROM orders o JOIN partner_orders po ON po.order_id = o.id WHERE o.tracking_no = ? AND o.payment_type = 'cod' LIMIT 1", [$t])->fetchColumn();
        $oid ? $ids[] = (int)$oid : $miss++;
    }
    return [partner_cod_received($ids, ''), $miss];
}
