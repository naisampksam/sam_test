<?php
// Stock, bills (tax invoices & proforma invoices), payments and monthly sales.
// Stock goes down when an invoice is saved and comes back when it is edited, cancelled or deleted.
// Proforma invoices never touch stock until they are converted into an invoice.
declare(strict_types=1);

const BILL_TYPES = ['invoice' => 'Tax invoice', 'proforma' => 'Proforma invoice'];
const STOCK_CATEGORIES = ['Blank T-shirt', 'DTF film', 'Labels', 'Packing', 'Ink & consumables', 'Other', 'Printing / service'];
// Printing, embroidery etc.: on bills with a price, but never counted as stock.
const SERVICE_CATEGORY = 'Printing / service';
const STOCK_REASONS = ['opening' => 'Opening stock', 'purchase' => 'Stock added', 'adjust' => 'Adjusted', 'bill' => 'Sold (bill)',
    'bill_back' => 'Bill edited / cancelled', 'return' => 'Returned'];
const PAY_MODES = ['UPI', 'Cash', 'Bank transfer', 'Card', 'COD', 'Other'];
const EXPENSE_CATEGORIES = ['Blank T-shirts / fabric', 'DTF film & ink', 'Printing (outside)', 'Packing material', 'Courier & shipping', 'Rent',
    'Electricity & water', 'Internet & phone', 'Machine & repairs', 'Marketing & ads', 'Transport & travel', 'Office & stationery', 'Bank charges', 'Other'];

function money(float $v, int $d = 2): string
{
    return ($v < 0 && round($v, $d) != 0 ? '−₹' : '₹') . number_format(abs($v), $d);
}

function qty_fmt($v): string
{
    $v = (float)$v;
    return rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
}

// ---------------------------------------------------------------- WhatsApp

/** Full address of this app, e.g. https://orders.loomaapparels.com/ (for links sent to customers). */
function public_url(string $path = ''): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    return ($https ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . base_url($path);
}

/** Private link the customer can open without logging in (made the first time it is needed). */
function bill_share_url(array $b): string
{
    $t = (string)$b['share_token'];
    if ($t === '') {
        $t = bin2hex(random_bytes(16));
        q('UPDATE bills SET share_token = ? WHERE id = ?', [$t, $b['id']]);
    }
    return public_url('bill_print.php?t=' . $t);
}

/** Indian mobile number for wa.me (adds 91), or null. */
function wa_number(string $phone): ?string
{
    $d = preg_replace('/\D/', '', $phone);
    if (strlen($d) === 11 && $d[0] === '0') {
        $d = substr($d, 1);
    }
    if (strlen($d) === 10) {
        $d = '91' . $d;
    }
    return strlen($d) >= 11 ? $d : null;
}

/** WhatsApp message for a bill (template in Settings → Billing). */
function bill_whatsapp_text(array $b): string
{
    $due = max(0, (float)$b['total'] - (float)$b['paid']);
    $tpl = setting('wa_bill', '') ?: "Hi {name}, here is your {doc} {number} dated {date} for {total}.{due}\nView / download: {link}\nThank you!";
    $seller = $b['branding'] === 'plain' ? (string)$b['seller_name'] : setting('inv_seller_name', setting('company_name', 'Looma Apparels'));
    return strtr($tpl, [
        '{name}' => trim(explode(' ', trim((string)$b['bill_name']))[0] ?? '') ?: 'there',
        '{fullname}' => (string)$b['bill_name'],
        '{doc}' => $b['type'] === 'proforma' ? 'proforma invoice' : 'invoice',
        '{number}' => (string)$b['number'],
        '{date}' => date('d M Y', strtotime((string)$b['bill_date'])),
        '{total}' => money((float)$b['total']),
        '{due}' => $b['type'] === 'invoice' && $due > 0.004 ? ' Balance to pay: ' . money($due) . '.' : '',
        '{link}' => bill_share_url($b),
        '{brand}' => $seller,
    ]);
}

// ---------------------------------------------------------------- stock

function stock_get(int $id): ?array
{
    return q('SELECT * FROM stock_items WHERE id = ?', [$id])->fetch() ?: null;
}

/** Change a stock level and record why. Positive adds, negative takes away. */
function stock_move(int $stockId, float $change, string $reason, ?int $billId = null, string $note = '', ?float $unitCost = null): void
{
    if (abs($change) < 0.000001) {
        return;
    }
    q('UPDATE stock_items SET qty = qty + ?, updated_at = ? WHERE id = ?', [$change, now(), $stockId]);
    q('INSERT INTO stock_moves (stock_id, qty_change, reason, bill_id, unit_cost, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
        [$stockId, $change, $reason, $billId, $unitCost, mb_substr($note, 0, 250), current_user()['id'] ?? null, now()]);
}

/**
 * Blank T-shirt in stock that matches an order item (same GSM, product, colour and size).
 * Exact first; otherwise a loose match, so "190 GSM · Regular Fit - Single Jersey · Black · M" finds "REGULAR FIT 190 BLACK -M".
 */
function stock_for_item(array $it): ?array
{
    if (!item_has_blank($it)) {
        return null;
    }
    $exact = q("SELECT * FROM stock_items WHERE active = 1 AND track = 1 AND gsm = ? AND product = ? AND color = ? AND size = ? ORDER BY id LIMIT 1",
        [$it['gsm'], $it['product'], $it['color'], $it['size']])->fetch();
    if ($exact) {
        return $exact;
    }
    $want = stock_match_key((string)$it['gsm'], (string)$it['product'], (string)$it['color'], (string)$it['size']);
    if ($want['size'] === '' || $want['color'] === '') {
        return null;
    }
    foreach (q("SELECT * FROM stock_items WHERE active = 1 AND track = 1 AND size <> '' ORDER BY id")->fetchAll() as $s) {
        $k = stock_match_key($s['gsm'], $s['product'], $s['color'], $s['size']);
        if ($k['size'] === $want['size'] && $k['color'] === $want['color'] && ($want['gsm'] === '' || $k['gsm'] === $want['gsm'])
            && ($want['style'] === '' || $k['style'] === $want['style'])) {
            return $s;
        }
    }
    return null;
}

/** Comparable parts of a blank: GSM number, style (oversized / regular / polo…), colour letters, size. */
function stock_match_key(string $gsm, string $product, string $color, string $size): array
{
    $p = strtolower($product);
    $style = '';
    foreach (['acid' => 'acid', 'polo' => 'polo', 'hood' => 'hoodie', 'kid' => 'kids', 'sleeveless' => 'sleeveless', 'full sleeve' => 'fullsleeve',
                 'over' => 'oversized', 'regular' => 'regular', 'round' => 'regular'] as $needle => $name) {
        if (str_contains($p, $needle)) {
            $style = $name;
            break;
        }
    }
    $size = strtoupper(preg_replace('/\s+/', '', $size));
    $size = ['2XL' => 'XXL', '3XL' => 'XXXL', 'XXXXL' => '4XL'][$size] ?? $size;
    return ['gsm' => preg_replace('/\D/', '', $gsm), 'style' => $style, 'color' => preg_replace('/[^a-z]/', '', strtolower($color)), 'size' => $size];
}

function stock_name(array $s): string
{
    if ($s['category'] === 'Blank T-shirt' && ($s['product'] !== '' || $s['color'] !== '')) {
        return trim(implode(' · ', array_filter([$s['gsm'], $s['product'], $s['color'], $s['size']], 'strlen')));
    }
    return $s['name'];
}

/** Stock items, services and shipping charges for the bill line picker. */
function stock_for_picker(): array
{
    $out = [];
    foreach (q('SELECT * FROM stock_items WHERE active = 1 ORDER BY track DESC, category, gsm, product, color, size, name')->fetchAll() as $s) {
        $out[] = ['id' => (int)$s['id'], 'name' => $s['name'] !== '' ? $s['name'] : stock_name($s), 'unit' => $s['unit'], 'qty' => (float)$s['qty'],
            'rate' => (float)$s['sale_price'], 'hsn' => $s['hsn'], 'gst' => (float)$s['gst_rate'], 'track' => (bool)$s['track']];
    }
    foreach (shipping_presets() as $p) {
        $out[] = ['id' => 0, 'name' => $p['name'], 'unit' => '', 'qty' => 0, 'rate' => (float)$p['rate'], 'hsn' => '', 'gst' => 0, 'track' => false, 'ship' => true];
    }
    return $out;
}

/** Shipping charges kept from Vyapar ("PACKING AND SHIPPING - KERALA" …): picked on a bill they go to the shipping charge, not a line. */
function shipping_presets(): array
{
    $list = json_decode(setting('ship_items', '[]'), true);
    return is_array($list) ? $list : [];
}

/** Bill line looks like a shipping / courier charge. */
function is_shipping_text(string $s): bool
{
    return (bool)preg_match('/shipping|transportation|courier|delivery charge/i', $s);
}

// ---------------------------------------------------------------- bills

/** Indian financial year of a date, e.g. "26-27" for 2026-10-09. */
function fin_year(string $date): string
{
    $y = (int)substr($date, 0, 4);
    $m = (int)substr($date, 5, 2);
    $start = $m >= 4 ? $y : $y - 1;
    return sprintf('%02d-%02d', $start % 100, ($start + 1) % 100);
}

function next_bill_number(string $type, string $date): array
{
    $fy = fin_year($date);
    $seq = 1 + (int)q('SELECT IFNULL(MAX(seq), 0) FROM bills WHERE type = ? AND fy = ?', [$type, $fy])->fetchColumn();
    $prefix = $type === 'proforma' ? setting('pi_prefix', 'PI') : setting('inv_prefix', 'INV');
    return [($prefix ?: strtoupper($type)) . '/' . $fy . '/' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT), $fy, $seq];
}

/**
 * Totals of a bill. Line amount = qty × rate (before tax); the bill discount is shared over the lines
 * by amount before GST is worked out; shipping is added after tax. Returns [lines, totals].
 */
function compute_bill(array $lines, float $discount, float $shipping, bool $interState): array
{
    $sub = 0.0;
    foreach ($lines as &$l) {
        $l['qty'] = max(0, (float)$l['qty']);
        $l['rate'] = max(0, (float)$l['rate']);
        $l['gst_rate'] = max(0, (float)$l['gst_rate']);
        $l['amount'] = round($l['qty'] * $l['rate'], 2);
        $sub += $l['amount'];
    }
    unset($l);
    $discount = min(max(0, $discount), $sub);
    $taxable = 0.0;
    $tax = 0.0;
    $byRate = [];
    foreach ($lines as &$l) {
        $l['taxable'] = $sub > 0 ? round($l['amount'] - $discount * $l['amount'] / $sub, 2) : 0.0;
        $l['tax'] = round($l['taxable'] * $l['gst_rate'] / 100, 2);
        $taxable += $l['taxable'];
        $tax += $l['tax'];
        $k = (string)(float)$l['gst_rate'];
        $byRate[$k] ??= ['rate' => (float)$l['gst_rate'], 'taxable' => 0.0, 'tax' => 0.0];
        $byRate[$k]['taxable'] += $l['taxable'];
        $byRate[$k]['tax'] += $l['tax'];
    }
    unset($l);
    ksort($byRate, SORT_NUMERIC);
    $shipping = max(0, $shipping);
    $raw = $taxable + $tax + $shipping;
    $total = round($raw);
    return [$lines, [
        'subtotal' => round($sub, 2), 'discount' => round($discount, 2), 'taxable' => round($taxable, 2), 'tax_total' => round($tax, 2),
        'cgst' => $interState ? 0.0 : round($tax / 2, 2), 'sgst' => $interState ? 0.0 : round($tax - round($tax / 2, 2), 2),
        'igst' => $interState ? round($tax, 2) : 0.0, 'shipping' => round($shipping, 2), 'round_off' => round($total - $raw, 2),
        'total' => $total, 'by_rate' => array_values($byRate),
    ]];
}

function get_bill(int $id): ?array
{
    return q('SELECT * FROM bills WHERE id = ?', [$id])->fetch() ?: null;
}

function bill_items(int $id): array
{
    return q('SELECT * FROM bill_items WHERE bill_id = ? ORDER BY sort, id', [$id])->fetchAll();
}

/** Stock this bill took (only final tax invoices take stock). */
function bill_takes_stock(array $b): bool
{
    return $b['type'] === 'invoice' && $b['status'] === 'final';
}

/** Give back the stock a bill took (before an edit, cancel or delete). */
function bill_restore_stock(array $b, string $why): void
{
    if (!bill_takes_stock($b)) {
        return;
    }
    foreach (bill_items((int)$b['id']) as $l) {
        if ($l['stock_id'] && stock_get((int)$l['stock_id'])) {
            stock_move((int)$l['stock_id'], (float)$l['qty'], 'bill_back', (int)$b['id'], $why . ' ' . $b['number']);
        }
    }
}

/** Take the stock for a bill's lines. */
function bill_take_stock(array $b): void
{
    if (!bill_takes_stock($b)) {
        return;
    }
    foreach (bill_items((int)$b['id']) as $l) {
        if ($l['stock_id'] && stock_get((int)$l['stock_id'])) {
            stock_move((int)$l['stock_id'], -(float)$l['qty'], 'bill', (int)$b['id'], $b['number']);
        }
    }
}

/**
 * Create or update a bill with its lines. $head = bill fields, $lines = [description, stock_id, hsn, qty, unit, rate, gst_rate].
 * Returns the bill id.
 */
function save_bill(?int $id, string $type, array $head, array $lines): int
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $old = $id ? get_bill($id) : null;
        if ($old) {
            bill_restore_stock($old, 'Edited');
        }
        [$lines, $t] = compute_bill($lines, (float)$head['discount'], (float)$head['shipping'], !empty($head['inter_state']));
        $row = array_merge($head, [
            'subtotal' => $t['subtotal'], 'discount' => $t['discount'], 'taxable' => $t['taxable'], 'tax_total' => $t['tax_total'],
            'shipping' => $t['shipping'], 'round_off' => $t['round_off'], 'total' => $t['total'], 'updated_at' => now(),
        ]);
        if ($old) {
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($row)));
            q("UPDATE bills SET $sets WHERE id = ?", array_merge(array_values($row), [$id]));
            q('DELETE FROM bill_items WHERE bill_id = ?', [$id]);
        } else {
            [$number, $fy, $seq] = next_bill_number($type, $row['bill_date']);
            $row += ['type' => $type, 'number' => $number, 'fy' => $fy, 'seq' => $seq, 'status' => 'final',
                'created_by' => current_user()['id'], 'created_at' => now()];
            q('INSERT INTO bills (' . implode(',', array_keys($row)) . ') VALUES (' . rtrim(str_repeat('?,', count($row)), ',') . ')', array_values($row));
            $id = (int)$pdo->lastInsertId();
        }
        foreach (array_values($lines) as $i => $l) {
            q('INSERT INTO bill_items (bill_id, stock_id, description, hsn, qty, unit, rate, gst_rate, amount, sort) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$id, $l['stock_id'] ?: null, mb_substr((string)$l['description'], 0, 250), mb_substr((string)$l['hsn'], 0, 20), $l['qty'],
                    mb_substr((string)($l['unit'] ?: 'pcs'), 0, 10), $l['rate'], $l['gst_rate'], $l['amount'], $i]);
        }
        $b = get_bill($id);
        bill_take_stock($b);
        bill_recount_paid($id);
        $pdo->commit();
        return $id;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function bill_recount_paid(int $id): void
{
    q('UPDATE bills SET paid = (SELECT IFNULL(SUM(amount), 0) FROM bill_payments WHERE bill_id = ?) WHERE id = ?', [$id, $id]);
}

function bill_set_status(int $id, string $status): void
{
    $b = get_bill($id);
    if (!$b || $b['status'] === $status) {
        return;
    }
    $pdo = db();
    $pdo->beginTransaction();
    if ($status === 'cancelled') {
        bill_restore_stock($b, 'Cancelled');
    }
    q('UPDATE bills SET status = ?, updated_at = ? WHERE id = ?', [$status, now(), $id]);
    if ($status === 'final') {
        bill_take_stock(get_bill($id));
    }
    $pdo->commit();
}

/** Turn a proforma into a tax invoice (new number, today's date); stock is taken now. */
function convert_proforma(int $id): ?int
{
    $p = get_bill($id);
    if (!$p || $p['type'] !== 'proforma' || $p['converted_to']) {
        return null;
    }
    $head = array_intersect_key($p, array_flip(['order_id', 'customer_code', 'bill_name', 'bill_phone', 'bill_address', 'bill_pincode', 'bill_gstin',
        'bill_state', 'branding', 'seller_name', 'seller_address', 'seller_phone', 'seller_gstin', 'inter_state', 'discount', 'shipping', 'notes']));
    $head['bill_date'] = today();
    $head['converted_from'] = $id;
    $lines = array_map(fn($l) => array_intersect_key($l, array_flip(['stock_id', 'description', 'hsn', 'qty', 'unit', 'rate', 'gst_rate'])), bill_items($id));
    $new = save_bill(null, 'invoice', $head, $lines);
    q('UPDATE bills SET converted_to = ? WHERE id = ?', [$new, $id]);
    return $new;
}

/** Sales in a month (YYYY-MM) from tax invoices that are not cancelled: before tax, without shipping. */
function month_sales(string $month): float
{
    return (float)q("SELECT IFNULL(SUM(taxable), 0) FROM bills WHERE type = 'invoice' AND status = 'final' AND bill_date >= ? AND bill_date <= LAST_DAY(?)",
        [$month . '-01', $month . '-01'])->fetchColumn();
}

/** Bill lines suggested from an order: matched to blank stock where possible. */
function bill_lines_from_order(int $orderId): array
{
    $hsn = setting('inv_default_hsn', '6109');
    $gst = (float)setting('inv_default_gst', '5');
    $lines = [];
    foreach (order_items($orderId) as $it) {
        $s = stock_for_item($it);
        $desc = $it['item_type'] === 'dtf_roll' ? 'DTF print roll' : ($it['item_type'] === 'print_only' ? 'DTF print' : 'T-shirt');
        if (item_has_blank($it)) {
            $desc .= ' – ' . implode(' · ', array_filter([$it['gsm'], $it['product'], $it['color'], 'Size ' . $it['size']], fn($v) => $v !== '' && $v !== 'Size '));
            $desc .= $it['plain'] ? ' (plain)' : ' (printed)';
        }
        $lines[] = [
            'stock_id' => $s ? (int)$s['id'] : null, 'description' => $desc,
            'hsn' => $s && $s['hsn'] !== '' ? $s['hsn'] : $hsn,
            'qty' => $it['item_type'] === 'dtf_roll' ? (float)$it['length_m'] : (int)$it['quantity'],
            'unit' => $it['item_type'] === 'dtf_roll' ? 'm' : 'pcs',
            'rate' => $s ? (float)$s['sale_price'] : 0, 'gst_rate' => $s ? (float)$s['gst_rate'] : $gst,
        ];
    }
    return $lines;
}

/**
 * Make the production order for a bill that has none (one form does both). Lines from stock become T-shirt items
 * (printed when the bill also has printing, else plain); a bill with only printing becomes print-only / DTF roll items.
 * Shipping is never an item. Returns the new order id, or null when the bill has nothing to make.
 */
function order_from_bill(int $billId): ?int
{
    $b = get_bill($billId);
    if (!$b || $b['order_id']) {
        return $b['order_id'] ?? null;
    }
    $shirts = $services = [];
    foreach (bill_items($billId) as $l) {
        $st = $l['stock_id'] ? stock_get((int)$l['stock_id']) : null;
        if (is_shipping_text((string)$l['description'])) {
            continue;
        }
        if (($st && $st['track']) || (!$st && preg_match('/t-?shirt|oversi|regular fit|polo|hoodie|sleeve/i', (string)$l['description']))) {
            $shirts[] = [$l, $st];
        } else {
            $services[] = $l;
        }
    }
    if (!$shirts && !$services) {
        return null;
    }
    $code = trim((string)$b['customer_code']) ?: (trim((string)$b['bill_name']) ?: 'Shop sale');
    if ($saved = q('SELECT code FROM customers WHERE code = ?', [$code])->fetchColumn()) {
        $code = $saved;
    }
    $cust = q('SELECT * FROM customers WHERE code = ?', [$code])->fetch();
    $printing = implode(', ', array_map(fn($l) => $l['description'] . ' × ' . qty_fmt($l['qty']), $services));
    $created = now();
    $row = [
        'customer_id' => mb_substr($code, 0, 80), 'customer_name' => $cust ? (string)$cust['name'] : '',
        'order_ref' => $b['number'], 'ship_name' => (string)$b['bill_name'], 'ship_phone' => (string)$b['bill_phone'],
        'ship_address' => (string)$b['bill_address'], 'ship_pincode' => (string)$b['bill_pincode'],
        'notes' => trim(($shirts && $printing !== '' ? 'Printing: ' . $printing . "\n" : '') . (string)$b['notes']),
        'cust_seq' => next_cust_seq($code, substr($created, 0, 10)), 'due_date' => compute_due_date($created),
        'extra' => '{}', 'created_at' => $created, 'created_by' => current_user()['id'],
    ];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        q('INSERT INTO orders (' . implode(',', array_keys($row)) . ') VALUES (' . rtrim(str_repeat('?,', count($row)), ',') . ')', array_values($row));
        $orderId = (int)$pdo->lastInsertId();
        $sort = 0;
        $add = function (array $it) use ($orderId, &$sort) {
            $it += ['order_id' => $orderId, 'sort' => ++$sort, 'extra' => '{}', 'created_at' => now()];
            q('INSERT INTO order_items (' . implode(',', array_keys($it)) . ') VALUES (' . rtrim(str_repeat('?,', count($it)), ',') . ')', array_values($it));
        };
        foreach ($shirts as [$l, $st]) {
            $type = $services ? 'print' : 'plain';
            $add([
                'item_type' => $type, 'plain' => $type === 'plain' ? 1 : 0,
                'gsm' => $st ? $st['gsm'] : '', 'product' => $st ? ($st['product'] !== '' ? $st['product'] : $st['name']) : mb_substr((string)$l['description'], 0, 150),
                'color' => $st ? $st['color'] : '', 'size' => $st ? $st['size'] : '',
                'quantity' => max(1, (int)round((float)$l['qty'])), 'custom_print' => $type === 'print' ? mb_substr($printing, 0, 1000) : '',
            ]);
        }
        if (!$shirts) {
            foreach ($services as $l) {
                $roll = preg_match('/roll/i', (string)$l['description']) || in_array(strtolower((string)$l['unit']), ['m', 'meters', 'mtr'], true);
                $add($roll
                    ? ['item_type' => 'dtf_roll', 'plain' => 0, 'quantity' => 1, 'length_m' => max(0.01, (float)$l['qty']), 'custom_print' => mb_substr((string)$l['description'], 0, 1000)]
                    : ['item_type' => 'print_only', 'plain' => 0, 'quantity' => max(1, (int)round((float)$l['qty'])), 'custom_print' => mb_substr((string)$l['description'], 0, 1000)]);
            }
        }
        q('UPDATE bills SET order_id = ? WHERE id = ?', [$orderId, $billId]);
        log_change($orderId, 'created', null, order_no($orderId) . ' from bill ' . $b['number']);
        remember_customer($orderId);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $orderId;
}

/** Amount in Indian words, e.g. 1250 → "One Thousand Two Hundred Fifty Rupees Only". */
function amount_in_words(float $amount): string
{
    $n = (int)round($amount);
    $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten', 'Eleven', 'Twelve', 'Thirteen',
        'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
    $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];
    $two = fn(int $x) => $x < 20 ? $ones[$x] : trim($tens[intdiv($x, 10)] . ' ' . $ones[$x % 10]);
    $three = fn(int $x) => trim(($x >= 100 ? $ones[intdiv($x, 100)] . ' Hundred ' : '') . $two($x % 100));
    if ($n === 0) {
        return 'Zero Rupees Only';
    }
    $parts = [];
    foreach ([[10000000, 'Crore'], [100000, 'Lakh'], [1000, 'Thousand']] as [$div, $word]) {
        if ($n >= $div) {
            $parts[] = $three(intdiv($n, $div)) . ' ' . $word;
            $n %= $div;
        }
    }
    if ($n > 0) {
        $parts[] = $three($n);
    }
    return trim(implode(' ', $parts)) . ' Rupees Only';
}
