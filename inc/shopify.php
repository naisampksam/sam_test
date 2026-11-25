<?php
// Partner Shopify shops: their store sends each order to Looma by webhook (no key, no access to their store).
// Each Shopify product (by its title) is linked once to a saved design + T-shirt; colour and size come from the
// variant ("Black / XL"). Orders wait in Partner orders for ✓ Accept, like portal orders.
// Safety nets: missing order numbers are shown, and Shopify's own orders export (.csv) can be uploaded to add any missed.
declare(strict_types=1);

require_once __DIR__ . '/partners.php';

/** Private address their Shopify sends orders to (the token in it says which partner). */
function shop_webhook_url(array $p): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'localhost');
    return ($https ? 'https' : 'http') . '://' . explode(',', $host)[0] . base_url('partner/shopify.php?k=' . $p['shop_token']);
}

/** Turn the Shopify connection on/off and save the signing key and the first order number to take. */
function shop_settings_save(int $partnerId, array $in): array
{
    $p = partner_by_id($partnerId);
    if (!$p) {
        return ['Partner not found.'];
    }
    $secret = trim((string)($in['shop_secret'] ?? ''));
    $from = trim((string)($in['shop_from'] ?? ''));
    $set = ['shop_on' => !empty($in['shop_on']) ? 1 : 0, 'shop_from' => $from === '' ? null : max(0, (int)preg_replace('/\D/', '', $from))];
    if ($secret !== '' && $secret !== '••••••••') {
        if (strlen($secret) < 20 || strlen($secret) > 200) {
            return ['The signing key looks wrong: copy the long code under Shopify → Settings → Notifications → Webhooks.'];
        }
        $set['shop_secret'] = $secret;
    }
    if ($p['shop_token'] === '' || !empty($in['new_token'])) {
        $set['shop_token'] = bin2hex(random_bytes(16));
    }
    update_row('partners', $partnerId, $set + ['updated_at' => now()]);
    return [];
}

/** Shopify signs every message with the shop's key: true only when it really came from their shop. */
function shop_verify(string $body, string $hmacHeader, string $secret): bool
{
    return $secret !== '' && $hmacHeader !== '' && hash_equals(base64_encode(hash_hmac('sha256', $body, $secret, true)), trim($hmacHeader));
}

function shop_title_key(string $title): string
{
    return mb_substr(mb_strtolower(trim(preg_replace('/\s+/', ' ', $title))), 0, 191);
}

/** "Lavander" → "Lavender", "2XL" → "XXL": the closest name in the list, or ''. */
function shop_match(string $v, array $list, bool $size = false): string
{
    $norm = function (string $s) use ($size): string {
        $s = strtoupper(preg_replace('/[^a-z0-9]/i', '', $s));
        if ($size) {
            $s = preg_replace(['/^2XL$/', '/^3XL$/', '/^4XL$/', '/^XXXL$/', '/^XXXXL$/'], ['XXL', 'XXXL', 'XXXXL', 'XXXL', 'XXXXL'], $s);
            $s = ['SMALL' => 'S', 'MEDIUM' => 'M', 'LARGE' => 'L', 'EXTRALARGE' => 'XL', 'EXTRASMALL' => 'XS'][$s] ?? $s;
        }
        return $s;
    };
    $n = $norm($v);
    if ($n === '') {
        return '';
    }
    foreach ($list as $x) {
        if ($norm((string)$x) === $n) {
            return (string)$x;
        }
    }
    if ($size || strlen($n) < 5) {
        return '';
    }
    $best = '';
    $bestD = 3;
    foreach ($list as $x) {
        $d = levenshtein($n, $norm((string)$x));
        if ($d < $bestD) {
            [$best, $bestD] = [(string)$x, $d];
        }
    }
    return $best;
}

/**
 * Fill design / T-shirt from the product links, colour / size from the variant. A Shopify product with the same name
 * as one of the partner's products (portal → Products) needs no link: its T-shirt and colour are used. Items not
 * linked stay open.
 */
function shop_resolve_items(array $p, array $items): array
{
    $links = [];
    foreach (q('SELECT * FROM shop_links WHERE partner_id = ?', [$p['id']])->fetchAll() as $l) {
        $links[$l['title_key']] = $l;
    }
    $cat = [];
    foreach (catalog() as $g) {
        foreach ($g['products'] as $pr) {
            $cat[$g['gsm'] . '|' . $pr['name']] = $pr;
        }
    }
    $own = $colourOf = [];
    foreach (partner_designs($p) as $d) {
        $colourOf[(int)$d['id']] = (string)$d['color'];
        if ($d['product'] !== '') {
            $own[shop_title_key($d['name'])] ??= ['design_id' => (int)$d['id'], 'gsm' => $d['gsm'], 'product' => $d['product']];
        }
    }
    $names = [];
    foreach ($items as &$it) {
        $key = shop_title_key((string)($it['shop_title'] ?? ''));
        $l = $links[$key] ?? $own[$key] ?? null;
        if (!$l) {
            continue;
        }
        if (!(int)($it['design_id'] ?? 0)) {
            $names[$l['design_id']] ??= (string)q('SELECT name FROM designs WHERE id = ?', [$l['design_id']])->fetchColumn();
            $it['design_id'] = (int)$l['design_id'];
            $it['design'] = $names[$l['design_id']];
        }
        if (($it['gsm'] ?? '') === '') {
            $it['gsm'] = $l['gsm'];
            $it['product'] = $l['product'];
        }
        $pr = $cat[$it['gsm'] . '|' . $it['product']] ?? null;
        if ($pr) {
            $parts = array_merge(preg_split('~\s*[/|,]\s*~', (string)($it['variant'] ?? '')) ?: [], array_values((array)($it['props'] ?? [])));
            foreach ($parts as $part) {
                if (($it['color'] ?? '') === '' && ($c = shop_match((string)$part, array_column($pr['colors'], 'name')))) {
                    $it['color'] = $c;
                } elseif (($it['size'] ?? '') === '' && ($s = shop_match((string)$part, $pr['sizes'], true))) {
                    $it['size'] = $s;
                }
            }
            // no colour in the variant (one-colour product): the product's own colour
            $dc = $colourOf[(int)$it['design_id']] ?? '';
            if (($it['color'] ?? '') === '' && $dc !== '' && in_array($dc, array_column($pr['colors'], 'name'), true)) {
                $it['color'] = $dc;
            }
        }
    }
    unset($it);
    return $items;
}

/** What still needs your team before accepting: ['link' => [titles], 'fix' => [item index => missing words]]. */
function shop_open_points(array $items): array
{
    $out = ['link' => [], 'fix' => []];
    foreach ($items as $i => $it) {
        if (!(int)($it['design_id'] ?? 0)) {
            $out['link'][shop_title_key((string)($it['shop_title'] ?? ''))] = (string)($it['shop_title'] ?? '');
            continue;
        }
        $miss = array_keys(array_filter(['colour' => $it['color'] ?? '', 'size' => $it['size'] ?? ''], fn($v) => $v === ''));
        if ($miss) {
            $out['fix'][$i] = $miss;
        }
    }
    return $out;
}

function shop_phone(string $s): string
{
    $d = preg_replace('/\D/', '', $s);
    if (strlen($d) === 12 && str_starts_with($d, '91')) {
        $d = substr($d, 2);
    } elseif (strlen($d) === 11 && $d[0] === '0') {
        $d = substr($d, 1);
    }
    return $d;
}

/** A Shopify order (webhook JSON) → a partner order row. */
function shop_order_row(array $p, array $j): array
{
    $sa = (array)($j['shipping_address'] ?? []) ?: (array)($j['billing_address'] ?? []);
    $name = trim((string)($sa['name'] ?? trim(($sa['first_name'] ?? '') . ' ' . ($sa['last_name'] ?? ''))));
    $phone = shop_phone((string)($sa['phone'] ?? '') ?: (string)($j['phone'] ?? '') ?: (string)($j['customer']['phone'] ?? '') ?: (string)($j['billing_address']['phone'] ?? ''));
    $addr = trim(implode(', ', array_filter([trim((string)($sa['address1'] ?? '')), trim((string)($sa['company'] ?? '')), trim((string)($sa['city'] ?? '')), trim((string)($sa['province'] ?? ''))], 'strlen')));
    $gw = strtolower(implode(' ', (array)($j['payment_gateway_names'] ?? [])) . ' ' . (string)($j['gateway'] ?? ''));
    $cod = str_contains($gw, 'cash on delivery') || preg_match('/\bcod\b/', $gw);
    $due = (float)($j['total_outstanding'] ?? 0) ?: (float)($j['total_price'] ?? 0);
    $items = [];
    foreach ((array)($j['line_items'] ?? []) as $li) {
        if ((int)($li['quantity'] ?? 0) < 1 || ($li['requires_shipping'] ?? true) === false) {
            continue; // tips, gift cards
        }
        $props = [];
        foreach ((array)($li['properties'] ?? []) as $pp) {
            if (is_array($pp) && isset($pp['value']) && !str_starts_with((string)($pp['name'] ?? ''), '_')) {
                $props[(string)$pp['name']] = (string)$pp['value'];
            }
        }
        $items[] = ['design_id' => 0, 'design' => (string)($li['title'] ?? ''), 'gsm' => '', 'product' => '', 'color' => '', 'size' => '', 'qty' => (int)$li['quantity'],
            'shop_title' => (string)($li['title'] ?? ''), 'variant' => (string)($li['variant_title'] ?? ''), 'props' => $props];
    }
    $tags = strtolower((string)($j['tags'] ?? ''));
    return [
        'partner_id' => $p['id'], 'ref' => mb_substr(ltrim((string)($j['name'] ?? ('#' . ($j['order_number'] ?? ''))), '#'), 0, 60), 'source' => 'shopify',
        'ext_id' => (string)($j['id'] ?? ''), 'ext_no' => isset($j['order_number']) ? (int)$j['order_number'] : null, 'status' => 'new',
        'ship_name' => mb_substr($name, 0, 150), 'ship_phone' => mb_substr($phone, 0, 40), 'ship_phone2' => '', 'ship_address' => $addr,
        'landmark' => mb_substr(trim((string)($sa['address2'] ?? '')), 0, 200), 'ship_pincode' => mb_substr(preg_replace('/\s/', '', (string)($sa['zip'] ?? '')), 0, 12),
        'ship_email' => mb_substr((string)($j['email'] ?? $j['contact_email'] ?? ''), 0, 150),
        'payment_type' => $cod ? 'cod' : 'prepaid', 'cod_amount' => $cod && $due > 0 ? number_format($due, 2, '.', '') : null,
        'courier' => '', 'urgent' => str_contains($tags, 'urgent') ? 1 : 0, 'notes' => mb_substr(trim((string)($j['note'] ?? '')), 0, 1000),
        'items' => json_encode(shop_resolve_items($p, $items), JSON_UNESCAPED_UNICODE),
        'created_at' => now(),
    ];
}

/** A message from the partner's Shopify. Returns what happened, for the reply and the log. */
function shop_receive(array $p, string $topic, array $j): string
{
    q('UPDATE partners SET shop_seen_at = ? WHERE id = ?', [now(), $p['id']]);
    $extId = (string)($j['id'] ?? '');
    if ($extId === '') {
        return 'ignored: no order id';
    }
    if ($topic === 'orders/cancelled') {
        $po = q('SELECT id, status, order_id, ref FROM partner_orders WHERE partner_id = ? AND ext_id = ?', [$p['id'], $extId])->fetch();
        if (!$po) {
            return 'cancelled: not here';
        }
        if ($po['status'] === 'new') {
            q("UPDATE partner_orders SET status = 'cancelled', reason = 'Cancelled in Shopify', updated_at = ? WHERE id = ?", [now(), $po['id']]);
            return 'cancelled';
        }
        if ($po['status'] === 'accepted' && $po['order_id']) {
            // Already being made: the team decides (shown on the order and in Partner orders).
            q("UPDATE partner_orders SET change_req = 'Cancelled in Shopify — stop this order if it is not shipped yet.', change_state = 'open', change_at = ? WHERE id = ?", [now(), $po['id']]);
            log_change((int)$po['order_id'], 'partner_order', null, 'cancelled in ' . $p['name'] . '’s Shopify (' . $po['ref'] . ')');
            partner_notify($p, $po['ref'] . ' was cancelled in Shopify — check the order', 'partner_orders.php?tab=changes');
            return 'cancel flagged';
        }
        return 'cancelled: nothing to do';
    }
    if ($topic !== 'orders/create' && $topic !== 'orders/paid' && $topic !== '') {
        return 'ignored: ' . $topic;
    }
    if (!empty($j['cancelled_at'])) {
        return 'ignored: cancelled';
    }
    $no = isset($j['order_number']) ? (int)$j['order_number'] : null;
    if ($p['shop_from'] !== null && $no !== null && $no < (int)$p['shop_from']) {
        return 'ignored: before ' . $p['shop_from'];
    }
    $row = shop_order_row($p, $j);
    if (q('SELECT 1 FROM partner_orders WHERE partner_id = ? AND (ext_id = ? OR ref = ?)', [$p['id'], $extId, $row['ref']])->fetchColumn()) {
        return 'duplicate';
    }
    $c = array_keys($row);
    q('INSERT IGNORE INTO partner_orders (' . implode(',', $c) . ') VALUES (' . rtrim(str_repeat('?,', count($c)), ',') . ')', array_values($row));
    partner_notify($p, $row['ref'] . ' from Shopify — waiting to be accepted');
    return 'created ' . $row['ref'];
}

/** Link a Shopify product to a saved design + T-shirt; orders waiting with it are filled in. */
function shop_link_save(int $partnerId, string $title, int $designId, string $tshirt): ?string
{
    $p = partner_by_id($partnerId);
    [$gsm, $product] = array_pad(explode('|', $tshirt, 2), 2, '');
    if (!$p || !partner_design($p, $designId)) {
        return 'Pick one of ' . ($p['name'] ?? 'the partner') . '’s saved designs (add it in Saved designs with their customer ID first).';
    }
    if (!catalog_product($gsm, $product)) {
        return 'Pick the T-shirt.';
    }
    $key = shop_title_key($title);
    q('INSERT INTO shop_links (partner_id, title_key, title, design_id, gsm, product, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE design_id = VALUES(design_id), gsm = VALUES(gsm), product = VALUES(product)',
        [$partnerId, $key, mb_substr($title, 0, 255), $designId, $gsm, $product, current_user()['id'] ?? null, now()]);
    foreach (q("SELECT id, items FROM partner_orders WHERE partner_id = ? AND status = 'new' AND source <> 'portal'", [$partnerId])->fetchAll() as $po) {
        $items = json_decode($po['items'] ?: '[]', true) ?: [];
        foreach ($items as &$it) {
            if (shop_title_key((string)($it['shop_title'] ?? '')) === $key) {
                $it['design_id'] = 0; // linked again: take the new link
                $it['gsm'] = $it['product'] = $it['color'] = $it['size'] = '';
            }
        }
        unset($it);
        q('UPDATE partner_orders SET items = ? WHERE id = ?', [json_encode(shop_resolve_items($p, $items), JSON_UNESCAPED_UNICODE), $po['id']]);
    }
    return null;
}

/** The team picks a colour / size Shopify's variant didn't make clear. */
function shop_fix_items(int $poId, array $fix): ?string
{
    $po = partner_order($poId);
    if (!$po || $po['status'] !== 'new') {
        return 'Order not waiting any more.';
    }
    $items = $po['items'];
    foreach ($fix as $i => $f) {
        if (!isset($items[(int)$i]) || !is_array($f)) {
            continue;
        }
        $pr = catalog_product($items[(int)$i]['gsm'], $items[(int)$i]['product']);
        foreach (['color', 'size'] as $k) {
            if (($f[$k] ?? '') !== '') {
                $items[(int)$i][$k] = mb_substr(trim((string)$f[$k]), 0, 80);
            }
        }
    }
    q('UPDATE partner_orders SET items = ? WHERE id = ?', [json_encode($items, JSON_UNESCAPED_UNICODE), $poId]);
    return null;
}

/** Shopify order numbers that never arrived (between the first taken and the last seen, last 60 days). */
function shop_missing_numbers(array $p): array
{
    $nos = q("SELECT ext_no FROM partner_orders WHERE partner_id = ? AND source <> 'portal' AND ext_no IS NOT NULL AND created_at >= ? ORDER BY ext_no",
        [$p['id'], date('Y-m-d H:i:s', strtotime('-60 days'))])->fetchAll(PDO::FETCH_COLUMN);
    if (count($nos) < 2) {
        return [];
    }
    $have = array_flip(array_map('intval', $nos));
    $lo = max((int)$nos[0], (int)($p['shop_from'] ?? 0));
    $hi = (int)end($nos);
    $miss = [];
    for ($n = $lo; $n <= $hi && count($miss) < 50; $n++) {
        if (!isset($have[$n])) {
            $miss[] = $n;
        }
    }
    return $miss;
}

/**
 * Shopify's own orders export (Orders → Export → CSV): orders not here yet are added to the waiting list.
 * One row per line item; the first row of an order has the address. Returns [added, skipped, errors].
 */
function shop_import_export(array $p, array $rows): array
{
    if (count($rows) < 2) {
        return [0, 0, ['The file is empty.']];
    }
    $h = array_map(fn($x) => strtolower(trim((string)$x)), $rows[0]);
    $col = fn(array $r, string $name) => ($i = array_search(strtolower($name), $h, true)) === false ? '' : trim((string)($r[$i] ?? ''));
    if (array_search('name', $h, true) === false || array_search('lineitem name', $h, true) === false) {
        return [0, 0, ['This is not a Shopify orders export (needs the “Name” and “Lineitem name” columns). In Shopify: Orders → Export → “CSV for Excel…”.']];
    }
    $orders = [];
    foreach (array_slice($rows, 1) as $r) {
        $name = $col($r, 'Name');
        if ($name === '') {
            continue;
        }
        $o = &$orders[$name];
        if (!$o) {
            $o = ['name' => $name, 'id' => $col($r, 'Id'), 'email' => $col($r, 'Email'), 'note' => $col($r, 'Notes'), 'tags' => $col($r, 'Tags'),
                'cancelled_at' => $col($r, 'Cancelled at'), 'payment_gateway_names' => [$col($r, 'Payment Method') ?: $col($r, 'Payment Gateway')],
                'total_price' => $col($r, 'Total'), 'total_outstanding' => $col($r, 'Outstanding Balance'),
                'order_number' => (int)preg_replace('/\D/', '', $name) ?: null,
                'shipping_address' => ['name' => $col($r, 'Shipping Name'), 'address1' => $col($r, 'Shipping Address1') ?: $col($r, 'Shipping Street'),
                    'address2' => $col($r, 'Shipping Address2'), 'company' => $col($r, 'Shipping Company'), 'city' => $col($r, 'Shipping City'),
                    'province' => $col($r, 'Shipping Province Name') ?: $col($r, 'Shipping Province'), 'zip' => ltrim($col($r, 'Shipping Zip'), "'"),
                    'phone' => $col($r, 'Shipping Phone') ?: $col($r, 'Phone')],
                'line_items' => []];
        }
        // "Lineitem name" is "Title - Black / XL" (variant after the last " - ")
        $ln = $col($r, 'Lineitem name');
        $title = $ln;
        $variant = '';
        if (preg_match('/^(.*) - ([^-]+)$/u', $ln, $m)) {
            [$title, $variant] = [trim($m[1]), trim($m[2])];
        }
        $o['line_items'][] = ['title' => $title, 'variant_title' => $variant, 'quantity' => (int)$col($r, 'Lineitem quantity')];
        unset($o);
    }
    $added = $skipped = 0;
    foreach ($orders as $o) {
        if ($o['id'] === '') {
            $o['id'] = 'csv-' . $o['name'];
        }
        $res = shop_receive($p, 'orders/create', $o);
        str_starts_with($res, 'created') ? $added++ : $skipped++;
    }
    return [$added, $skipped, []];
}

/** Partner order with Shopify lines: anything still to link or fill before it can be accepted? */
function partner_order_ready(array $po): bool
{
    $op = shop_open_points($po['items']);
    return !$op['link'] && !$op['fix'];
}
