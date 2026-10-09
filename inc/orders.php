<?php
// Orders and their items: saving, ticks, image uploads and change history.
//
// An order is one parcel for one customer (customer ID, dispatch date, packed, shipped, courier).
// It holds one or more items; each item is its own blank (GSM / product / color / size / qty),
// its own mock-ups and print details, and its own Printed tick.
declare(strict_types=1);

const ORDER_STAGES = ['print_processed', 'packed', 'shipped'];
const STAGES = ['print_processed', 'printed', 'packed', 'shipped'];
/** Ticks as shown on screen, in workflow order. */
const STAGE_LABELS = ['print_processed' => 'Print processed', 'printed' => 'Printed', 'packed' => 'Packed', 'shipped' => 'Shipped'];
const ORDER_TEXT_FIELDS = ['customer_id', 'customer_name', 'order_ref', 'ship_name', 'ship_phone', 'ship_address', 'ship_pincode', 'notes', 'courier', 'tracking_no'];
const ITEM_TEXT_FIELDS = ['sub_order_id', 'gsm', 'product', 'color', 'size', 'front_print', 'back_print', 'chest_print', 'neck_label', 'chest_logo', 'custom_print'];
/**
 * Extras on an item that work the same way: a switch, a name, their own images and a "done" tick.
 * field = the permission field; images are stored in order_images with kind = the key.
 */
const ITEM_ADDONS = [
    'neck' => ['field' => 'neck_label', 'on' => 'neck_label_on', 'done' => 'neck_done', 'label' => 'Neck label', 'icon' => '🏷',
        'text_label' => 'Brand name on label', 'placeholder' => 'e.g. Looma, or the customer\'s brand'],
    'logo' => ['field' => 'chest_logo', 'on' => 'chest_logo_on', 'done' => 'logo_done', 'label' => 'Chest logo', 'icon' => '🔰',
        'text_label' => 'Logo / brand name', 'placeholder' => 'e.g. customer logo, Looma logo'],
];

/** Images of one kind ('' = mock-ups, 'neck', 'logo') from an item's image list. */
function images_of(array $imgs, string $kind): array
{
    return array_values(array_filter($imgs, fn($i) => ($i['kind'] ?? '') === $kind));
}

/** Print places and the column that holds each one's print size (A2 / A3 / A4 / Logo …). */
const PRINT_PLACES = [
    'front_print' => ['Front print', 'front_size'],
    'back_print' => ['Back print', 'back_size'],
    'chest_print' => ['Chest print', 'chest_size'],
    'custom_print' => ['Custom print', 'custom_size'],
];

/**
 * Editable here? Besides normal field permissions, whoever creates an order may set its
 * delivery partner and tracking number at creation time (both optional).
 */
/** The customer's order number for the day, e.g. "C101-2" for C101's second order that day ('' without a customer ID). */
function customer_order_no(array $o): string
{
    $code = trim((string)($o['customer_id'] ?? ''));
    return $code === '' ? '' : $code . (!empty($o['cust_seq']) ? '-' . (int)$o['cust_seq'] : '');
}

/** Next number for this customer's orders on $day (Y-m-d); $exceptId leaves that order out. */
function next_cust_seq(string $code, string $day, int $exceptId = 0): int
{
    return 1 + (int)q("SELECT IFNULL(MAX(cust_seq), 0) FROM orders WHERE customer_id = ? AND deleted_at IS NULL
                       AND created_at >= ? AND created_at <= ? AND id <> ?", [trim($code), "$day 00:00:00", "$day 23:59:59", $exceptId])->fetchColumn();
}

function can_edit_field(string $k, bool $isNew = false): bool
{
    return can_edit($k) || ($isNew && cap('create') && in_array($k, ['courier', 'tracking_no'], true));
}

/** Brand names used on neck labels before (default brand first), for suggestions. */
function neck_label_suggestions(): array
{
    $rows = q("SELECT neck_label FROM order_items WHERE neck_label_on = 1 AND neck_label <> '' GROUP BY neck_label ORDER BY MAX(id) DESC LIMIT 40")->fetchAll();
    return array_values(array_unique(array_filter(array_merge([(string)setting('slip_brand', setting('company_name', 'Looma Apparels'))],
        array_column($rows, 'neck_label')), 'strlen')));
}

/** Print size options (editable in Catalog → Print options). */
function print_sizes(): array
{
    $raw = (string)setting('print_sizes', 'A2 (16×22), A3 (11×16), A4 (8×11), Logo (2.5×2.5), Custom');
    return array_values(array_unique(array_filter(array_map('trim', explode(',', $raw)), 'strlen')));
}

/**
 * How each print place is made: ['back_print' => ['m' => 'dtf'|'emb'|…, 'st' => stitches, 'pr' => ₹ per print|null, 'dg' => digitizing ₹|null], …].
 * Places without a saved method are DTF.
 */
function print_spec(array $it): array
{
    $spec = json_decode((string)($it['print_spec'] ?? ''), true);
    $out = [];
    foreach (array_keys(PRINT_PLACES) as $k) {
        $p = is_array($spec[$k] ?? null) ? $spec[$k] : [];
        $out[$k] = ['m' => isset(PRINT_METHODS[$p['m'] ?? '']) ? $p['m'] : 'dtf', 'st' => max(0, (int)($p['st'] ?? 0)),
            'pr' => isset($p['pr']) && $p['pr'] !== '' && $p['pr'] !== null ? (float)$p['pr'] : null,
            'dg' => isset($p['dg']) && $p['dg'] !== '' && $p['dg'] !== null ? (float)$p['dg'] : null];
    }
    return $out;
}

/** Short method text for a print place, e.g. "Embroidery · 8,000 stitches" ('' for plain DTF). */
function print_method_text(array $sp): string
{
    if ($sp['m'] === 'dtf') {
        return '';
    }
    return PRINT_METHODS[$sp['m']] . ($sp['m'] === 'emb' && $sp['st'] ? ' · ' . number_format($sp['st']) . ' stitches' : '');
}

/** "Back print (A3): text" style lines for an item or design, only for places with something filled in. */
function print_lines(array $row): array
{
    $out = [];
    foreach (PRINT_PLACES as $k => [$label, $sizeCol]) {
        $text = trim((string)($row[$k] ?? ''));
        $size = trim((string)($row[$sizeCol] ?? ''));
        $method = isset($row['print_spec']) ? print_method_text(print_spec($row)[$k]) : '';
        if (($text !== '' || $size !== '' || $method !== '') && can_view($k)) {
            $out[$k] = ['label' => $label, 'size' => trim($method . ($method !== '' && $size !== '' ? ' · ' : '') . $size), 'text' => $text];
        }
    }
    return $out;
}

/**
 * Item types. 'print' = T-shirt + print, 'plain' = T-shirt without print, 'print_only' = print without a
 * T-shirt (customer's garment / transfers), 'dtf_roll' = DTF film sold by the metre.
 * Only 'plain' skips printing; the last two have no blank T-shirt to pick.
 */
const ITEM_TYPES = [
    'print' => 'T-shirt + print',
    'plain' => 'Plain T-shirt',
    'print_only' => 'Print only (no T-shirt)',
    'dtf_roll' => 'DTF roll',
];

function item_has_blank(array $it): bool
{
    return !in_array($it['item_type'] ?? 'print', ['print_only', 'dtf_roll'], true);
}

/** Short description of an item's blank, e.g. "250 GSM · Oversized · Black · L" or "DTF roll · 2.5 m". */
function item_spec(array $it): string
{
    $type = $it['item_type'] ?? 'print';
    if ($type === 'dtf_roll') {
        return 'DTF roll · ' . rtrim(rtrim(number_format((float)($it['length_m'] ?? 0), 2, '.', ''), '0'), '.') . ' m';
    }
    if ($type === 'print_only') {
        return 'Print only (no T-shirt)';
    }
    return implode(' · ', array_filter([$it['gsm'] ?? '', $it['product'] ?? '', $it['color'] ?? '', $it['size'] ?? ''], 'strlen')) ?: 'Item';
}

/** General contents for shipping labels, e.g. "T-shirt × 4\nDTF sticker × 5\nDTF roll × 2.5 m" (no sizes/colours). */
function label_contents(array $items): string
{
    $shirts = $stickers = 0;
    $roll = 0.0;
    foreach ($items as $it) {
        $type = $it['item_type'] ?? (!empty($it['plain']) ? 'plain' : 'print');
        if ($type === 'dtf_roll') {
            $roll += (float)$it['length_m'];
        } elseif ($type === 'print_only') {
            $stickers += (int)$it['quantity'];
        } else {
            $shirts += (int)$it['quantity'];
        }
    }
    $lines = [];
    if ($shirts) {
        $lines[] = 'T-shirt × ' . $shirts;
    }
    if ($stickers) {
        $lines[] = 'DTF sticker × ' . $stickers;
    }
    if ($roll > 0) {
        $lines[] = 'DTF roll × ' . rtrim(rtrim(number_format($roll, 2, '.', ''), '0'), '.') . ' m';
    }
    return implode("\n", $lines);
}

/** SQL: one item as text, e.g. "250 GSM Oversized Black L ×3" / "DTF roll 2.50 m" (for GROUP_CONCAT). */
const ITEM_LINE_SQL = "CASE it.item_type WHEN 'dtf_roll' THEN CONCAT('DTF roll ', IFNULL(it.length_m, 0), ' m')
    WHEN 'print_only' THEN CONCAT('Print only ×', it.quantity)
    ELSE CONCAT_WS(' ', it.gsm, it.product, it.color, it.size, CONCAT('×', it.quantity)) END";

/** SQL snippet: per-order item totals, for list queries on "orders o". */
const ORDER_TOTALS_SQL = "(SELECT IFNULL(SUM(quantity),0) FROM order_items it WHERE it.order_id = o.id) AS total_qty,
    (SELECT COUNT(*) FROM order_items it WHERE it.order_id = o.id) AS item_count,
    (SELECT COUNT(*) FROM order_items it WHERE it.order_id = o.id AND it.plain = 0) AS printable_count,
    (SELECT COUNT(*) FROM order_items it WHERE it.order_id = o.id AND it.plain = 0 AND it.printed = 1) AS printed_count";

function get_order(int $id): ?array
{
    $o = q('SELECT o.*, ' . ORDER_TOTALS_SQL . ' FROM orders o WHERE o.id = ? AND o.deleted_at IS NULL', [$id])->fetch();
    if (!$o) {
        return null;
    }
    $o['extra'] = json_decode($o['extra'] ?: '{}', true) ?: [];
    return $o;
}

function order_items(int $orderId): array
{
    $items = q('SELECT * FROM order_items WHERE order_id = ? ORDER BY sort, id', [$orderId])->fetchAll();
    foreach ($items as &$it) {
        $it['extra'] = json_decode($it['extra'] ?: '{}', true) ?: [];
    }
    return $items;
}

/** Images of an order grouped by item id. */
function order_images_by_item(int $orderId): array
{
    $out = [];
    foreach (q('SELECT * FROM order_images WHERE order_id = ? ORDER BY id', [$orderId])->fetchAll() as $img) {
        $out[(int)$img['item_id']][] = $img;
    }
    return $out;
}

/**
 * Items that used a saved design but have no mock-up of their own show the design's images
 * (rows get id 0 and 'from_design' so they are never offered for deletion from the order).
 */
function with_design_images(array $items, array $byItem): array
{
    $need = [];
    foreach ($items as $it) {
        if (!empty($it['design_id']) && !images_of($byItem[(int)$it['id']] ?? [], '')) {
            $need[(int)$it['design_id']][] = (int)$it['id'];
        }
    }
    if ($need) {
        $ids = implode(',', array_map('intval', array_keys($need)));
        foreach (q("SELECT design_id, filename, original_name FROM design_images WHERE design_id IN ($ids) ORDER BY id")->fetchAll() as $img) {
            foreach ($need[(int)$img['design_id']] as $itemId) {
                $byItem[$itemId][] = ['id' => 0, 'filename' => $img['filename'], 'original_name' => $img['original_name'], 'kind' => '', 'from_design' => true];
            }
        }
    }
    return $byItem;
}

/** SQL for an order's first picture: its own mock-up, else the first image of a saved design one of its items used. */
const ORDER_FIRST_IMG_SQL = "COALESCE((SELECT filename FROM order_images i WHERE i.order_id = o.id ORDER BY i.id LIMIT 1),
    (SELECT di.filename FROM order_items it2 JOIN design_images di ON di.design_id = it2.design_id WHERE it2.order_id = o.id ORDER BY it2.sort, it2.id, di.id LIMIT 1))";

function log_change(int $orderId, string $field, $old, $new, ?int $itemId = null): void
{
    q('INSERT INTO order_log (order_id, item_id, user_id, field, old_value, new_value, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
        [$orderId, $itemId, current_user()['id'] ?? null, $field, $old === null ? null : (string)$old, $new === null ? null : (string)$new, now()]);
}

/** Read the custom fields of one scope from a post array. Returns [newExtra, changes]. */
function read_custom(array $post, array $extra, string $scope): array
{
    $changes = [];
    foreach (custom_fields() as $k => $f) {
        if ($f['scope'] !== $scope || !can_edit($k)) {
            continue;
        }
        if ($f['type'] !== 'checkbox' && !array_key_exists($k, $post)) {
            continue;
        }
        $v = $f['type'] === 'checkbox' ? (!empty($post[$k]) ? '1' : '') : trim((string)$post[$k]);
        if (($extra[$k] ?? '') !== $v) {
            $changes[$k] = [$extra[$k] ?? '', $v];
        }
        $extra[$k] = $v;
    }
    return [$extra, $changes];
}

/**
 * Create or update an order with its items from a form post. Only fields the user may edit are read.
 * Items come as $post['items'][KEY] (KEY = "e<ID>" for existing, anything else for new),
 * their mock-ups as $files['item_mockups'][KEY][]. Returns [orderId, errors].
 */
function save_order(?int $id, array $post, array $files): array
{
    $u = current_user();
    $errors = [];
    $old = $id ? get_order($id) : null;
    if ($id && !$old) {
        return [null, ['Order not found.']];
    }
    $isNew = !$old;
    if ($isNew && !cap('create')) {
        return [null, ['You are not allowed to create orders.']];
    }
    $canAddItems = cap('create');

    // ---- order-level fields
    $set = [];
    foreach (ORDER_TEXT_FIELDS as $f) {
        if (can_edit_field($f, $isNew) && array_key_exists($f, $post)) {
            $set[$f] = trim((string)$post[$f]);
        }
    }
    // A saved customer typed in different letter case (c101) uses the saved ID (C101).
    if (($set['customer_id'] ?? '') !== '' && ($code = q('SELECT code FROM customers WHERE code = ?', [$set['customer_id']])->fetchColumn())) {
        $set['customer_id'] = $code;
    }
    if (($isNew || isset($set['customer_id'])) && ($set['customer_id'] ?? '') === '' && can_edit('customer_id')) {
        $errors[] = 'Customer ID is required.';
    }
    // Shipping address is optional; when a phone or pincode is typed it must look right.
    if (($set['ship_phone'] ?? '') !== '' && strlen(preg_replace('/\D/', '', $set['ship_phone'])) < 10) {
        $errors[] = 'Phone number looks too short.';
    }
    if (($set['ship_pincode'] ?? '') !== '' && !preg_match('/^\d{6}$/', $set['ship_pincode'])) {
        $errors[] = 'Pincode must be 6 digits.';
    }
    if (can_edit('due_date') && !empty($post['due_date'])) {
        $d = DateTime::createFromFormat('Y-m-d', (string)$post['due_date']);
        if (!$d) {
            $errors[] = 'Dispatch date is not valid.';
        } else {
            $set['due_date'] = $d->format('Y-m-d');
        }
    }
    if ($isNew) {
        foreach (ORDER_STAGES as $s) {
            if (can_edit($s)) {
                $set[$s] = !empty($post[$s]) ? 1 : 0;
            }
        }
    }
    [$extra, $extraChanges] = read_custom($post, $old['extra'] ?? [], 'order');

    // ---- items
    $existing = [];
    if (!$isNew) {
        foreach (order_items($id) as $it) {
            $existing['e' . $it['id']] = $it;
        }
    }
    $itemPlans = [];
    $keptCount = 0;
    foreach ((array)($post['items'] ?? []) as $key => $ip) {
        $key = (string)$key;
        if (!is_array($ip)) {
            continue;
        }
        $cur = $existing[$key] ?? null;
        if (!$cur && !$canAddItems) {
            continue;
        }
        if (!empty($ip['delete'])) {
            if ($cur && $canAddItems) {
                $itemPlans[] = ['key' => $key, 'delete' => true, 'cur' => $cur];
            }
            continue;
        }
        $iset = [];
        foreach (ITEM_TEXT_FIELDS as $f) {
            if (can_edit($f) && array_key_exists($f, $ip)) {
                $iset[$f] = trim((string)$ip[$f]);
            }
        }
        // Item type is part of the blank spec, so whoever may edit the product may set it.
        if (can_edit('product') && (isset($ip['item_type']) || isset($ip['plain']))) {
            $type = (string)($ip['item_type'] ?? (!empty($ip['plain']) ? 'plain' : 'print'));
            $type = isset(ITEM_TYPES[$type]) ? $type : 'print';
            $iset['item_type'] = $type;
            $iset['plain'] = $type === 'plain' ? 1 : 0;
            if (!item_has_blank(['item_type' => $type])) {
                foreach (['gsm', 'product', 'color', 'size'] as $f) {
                    $iset[$f] = '';
                }
            }
        }
        $type = $iset['item_type'] ?? ($cur['item_type'] ?? 'print');
        if ($type === 'dtf_roll' && can_edit('quantity') && isset($ip['length_m'])) {
            $len = round((float)str_replace(',', '.', (string)$ip['length_m']), 2);
            if ($len <= 0 || $len > 10000) {
                $errors[] = 'DTF roll length must be more than 0 metres.';
            }
            $iset['length_m'] = $len;
            $ip['quantity'] = 1;
        } elseif ($type !== 'dtf_roll' && isset($iset['item_type'])) {
            $iset['length_m'] = null;
        }
        foreach (PRINT_PLACES as $k => [, $sizeCol]) {
            if (can_edit($k) && isset($ip[$sizeCol])) {
                $iset[$sizeCol] = mb_substr(trim((string)$ip[$sizeCol]), 0, 40);
            }
        }
        // Print method per place (DTF / embroidery stitches / puff / HD / screen with a price per print).
        if (is_array($ip['spec'] ?? null)) {
            $spec = print_spec($cur ?? []);
            $num = fn($v) => trim((string)$v) === '' ? null : max(0, round((float)str_replace(',', '.', (string)$v), 2));
            foreach ($ip['spec'] as $k => $sp) {
                if (!isset(PRINT_PLACES[$k]) || !can_edit($k) || !is_array($sp)) {
                    continue;
                }
                $spec[$k] = ['m' => isset(PRINT_METHODS[$sp['m'] ?? '']) ? $sp['m'] : 'dtf', 'st' => max(0, (int)preg_replace('/\D/', '', (string)($sp['st'] ?? ''))),
                    'pr' => $num($sp['pr'] ?? ''), 'dg' => $num($sp['dg'] ?? '')];
            }
            $iset['print_spec'] = json_encode($spec);
        }
        foreach (ITEM_ADDONS as $ad) {
            if (can_edit($ad['field']) && isset($ip[$ad['on']])) {
                $iset[$ad['on']] = !empty($ip[$ad['on']]) ? 1 : 0;
            }
        }
        if (can_edit('quantity') && isset($ip['quantity'])) {
            $qty = (int)$ip['quantity'];
            if ($qty < 1 || $qty > 100000) {
                $errors[] = 'Item quantity must be at least 1.';
            }
            $iset['quantity'] = $qty;
        }
        // Selling price / printing charge for the bill (people who make bills).
        if (cap('billing')) {
            foreach (['rate', 'print_rate'] as $f) {
                if (array_key_exists($f, $ip)) {
                    $v = trim(str_replace(',', '.', (string)$ip[$f]));
                    $iset[$f] = $v === '' ? null : number_format(max(0, (float)$v), 2, '.', '');
                }
            }
        }
        if (!$cur && can_edit('printed') && $type !== 'plain') {
            $iset['printed'] = !empty($ip['printed']) ? 1 : 0;
        }
        // Saved design picked for this item: remember it and link its mock-ups (once per change).
        $attachDesign = null;
        $designId = (int)($ip['design_id'] ?? 0);
        if ($designId && can_edit('mockups') && $designId !== (int)($cur['design_id'] ?? 0)
            && ($d = q('SELECT name, code FROM designs WHERE id = ? AND active = 1', [$designId])->fetch())) {
            $dname = $d['name'];
            $iset['design_id'] = $designId;
            // Kept on the item, so the order still shows it if the design is renamed or deleted later.
            $iset['design_name'] = mb_substr($d['name'] . ($d['code'] !== '' ? ' · ' . $d['code'] : ''), 0, 200);
            $attachDesign = [$designId, $dname];
        }
        [$iextra, $ichanges] = read_custom($ip, $cur['extra'] ?? [], 'item');
        $itemPlans[] = ['key' => $key, 'cur' => $cur, 'set' => $iset, 'extra' => $iextra, 'extra_changes' => $ichanges,
            'sort' => isset($ip['sort']) ? (int)$ip['sort'] : null, 'design' => $attachDesign];
        $keptCount++;
    }
    // Items not in the post (e.g. form without that item) are left alone.
    $untouched = array_diff(array_keys($existing), array_column($itemPlans, 'key'));
    if ($keptCount + count($untouched) < 1) {
        $errors[] = 'An order needs at least one item.';
    }

    if ($errors) {
        return [$id, $errors];
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($isNew) {
            $created = now();
            $row = $set + [
                'cust_seq' => ($set['customer_id'] ?? '') !== '' ? next_cust_seq($set['customer_id'], substr($created, 0, 10)) : null,
                'due_date' => compute_due_date($created),
                'extra' => json_encode($extra, JSON_UNESCAPED_UNICODE),
                'created_at' => $created,
                'created_by' => $u['id'],
            ];
            foreach (ORDER_STAGES as $s) {
                if (!empty($row[$s])) {
                    $row[$s . '_at'] = $created;
                    $row[$s . '_by'] = $u['id'];
                }
            }
            $cols = array_keys($row);
            q('INSERT INTO orders (' . implode(',', $cols) . ') VALUES (' . rtrim(str_repeat('?,', count($cols)), ',') . ')', array_values($row));
            $id = (int)$pdo->lastInsertId();
            log_change($id, 'created', null, order_no($id));
        } else {
            $changes = [];
            foreach ($set as $f => $v) {
                if ((string)$old[$f] !== (string)$v) {
                    $changes[$f] = $v;
                    log_change($id, $f, $old[$f], $v);
                }
            }
            foreach ($extraChanges as $k => [$a, $b]) {
                log_change($id, $k, $a, $b);
            }
            if ($extraChanges) {
                $changes['extra'] = json_encode($extra, JSON_UNESCAPED_UNICODE);
            }
            // New customer ID: the order takes that customer's next number for the day it was created.
            if (isset($changes['customer_id']) && mb_strtolower(trim((string)$old['customer_id'])) !== mb_strtolower($changes['customer_id'])) {
                $changes['cust_seq'] = $changes['customer_id'] !== '' ? next_cust_seq($changes['customer_id'], substr((string)$old['created_at'], 0, 10), $id) : null;
            }
            if ($changes) {
                update_row('orders', $id, $changes + ['updated_at' => now(), 'updated_by' => $u['id']]);
            }
        }

        $sort = (int)q('SELECT IFNULL(MAX(sort), 0) FROM order_items WHERE order_id = ?', [$id])->fetchColumn();
        $itemsTouched = false;
        foreach ($itemPlans as $plan) {
            $cur = $plan['cur'];
            if (!empty($plan['delete'])) {
                delete_item($id, $cur);
                $itemsTouched = true;
                continue;
            }
            if (!$cur) {
                $row = $plan['set'] + [
                    'order_id' => $id,
                    'sort' => ++$sort,
                    'extra' => json_encode($plan['extra'], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                ];
                if (!empty($row['printed'])) {
                    $row['printed_at'] = now();
                    $row['printed_by'] = $u['id'];
                }
                $cols = array_keys($row);
                q('INSERT INTO order_items (' . implode(',', $cols) . ') VALUES (' . rtrim(str_repeat('?,', count($cols)), ',') . ')', array_values($row));
                $itemId = (int)$pdo->lastInsertId();
                if (!$isNew) {
                    log_change($id, 'item_added', null, item_label($row), $itemId);
                }
                $itemsTouched = true;
            } else {
                $itemId = (int)$cur['id'];
                $changes = [];
                foreach ($plan['set'] as $f => $v) {
                    if ((string)$cur[$f] !== (string)$v) {
                        $changes[$f] = $v;
                        log_change($id, $f, $cur[$f], $v, $itemId);
                    }
                }
                foreach ($plan['extra_changes'] as $k => [$a, $b]) {
                    log_change($id, $k, $a, $b, $itemId);
                }
                if ($plan['extra_changes']) {
                    $changes['extra'] = json_encode($plan['extra'], JSON_UNESCAPED_UNICODE);
                }
                if ($changes) {
                    update_row('order_items', $itemId, $changes);
                    $itemsTouched = true;
                }
            }
            if ($plan['design']) {
                [$designId, $dname] = $plan['design'];
                $n = attach_design_images($id, $itemId, $designId);
                log_change($id, 'design', null, "$dname ($n mock-up" . ($n === 1 ? '' : 's') . ')', $itemId);
            }
            if (can_edit('mockups')) {
                $errors = array_merge($errors, save_uploads($id, $itemId, $files['item_mockups'] ?? null, $plan['key']));
                foreach (ITEM_ADDONS as $kind => $ad) {
                    if (can_edit($ad['field'])) {
                        $errors = array_merge($errors, save_uploads($id, $itemId, $files['item_' . $kind] ?? null, $plan['key'], $kind));
                    }
                }
            }
        }

        if (can_edit('mockups')) {
            foreach ((array)($post['delete_images'] ?? []) as $imgId) {
                delete_image((int)$imgId, $id);
            }
        }
        if ($itemsTouched && !$isNew) {
            q('UPDATE orders SET updated_at = ?, updated_by = ? WHERE id = ?', [now(), $u['id'], $id]);
        }
        recompute_order_printed($id);
        remember_customer($id);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [$id, $errors];
}

/** Save / refresh the customer book entry from an order's customer ID and shipping address (latest wins). */
function remember_customer(int $orderId): void
{
    $o = q('SELECT customer_id, customer_name, ship_name, ship_phone, ship_address, ship_pincode FROM orders WHERE id = ?', [$orderId])->fetch();
    if (!$o || trim($o['customer_id']) === '') {
        return;
    }
    // Blank values on the order never wipe what the customer book already has.
    q("INSERT INTO customers (code, name, ship_name, phone, address, pincode, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE name = IF(VALUES(name) <> '', VALUES(name), name), ship_name = IF(VALUES(ship_name) <> '', VALUES(ship_name), ship_name),
         phone = IF(VALUES(phone) <> '', VALUES(phone), phone), address = IF(IFNULL(VALUES(address), '') <> '', VALUES(address), address),
         pincode = IF(VALUES(pincode) <> '', VALUES(pincode), pincode), updated_at = VALUES(updated_at)",
        [trim($o['customer_id']), trim($o['customer_name']), $o['ship_name'], $o['ship_phone'], (string)$o['ship_address'], $o['ship_pincode'], now(), now()]);
}

/**
 * wa.me link that opens WhatsApp with a ready message to the order's customer.
 * $which: 'confirm' or 'shipped' (templates are editable in Settings). Returns null without a usable phone.
 */
function whatsapp_link(array $o, string $which): ?string
{
    $digits = preg_replace('/\D/', '', (string)$o['ship_phone']);
    if (strlen($digits) === 11 && $digits[0] === '0') {
        $digits = substr($digits, 1);
    }
    if (strlen($digits) === 10) {
        $digits = '91' . $digits; // Indian mobile without country code
    }
    if (strlen($digits) < 11) {
        return null;
    }
    $items = [];
    foreach (order_items((int)$o['id']) as $it) {
        $items[] = ($it['item_type'] === 'dtf_roll' ? '' : (int)$it['quantity'] . '× ') . item_spec($it);
    }
    $defaults = [
        'confirm' => "Hi {name}, thank you for your order with {brand}! 🙏\nOrder {order}: {items}.\nWe will dispatch it by {dispatch}.",
        'shipped' => "Hi {name}, your {brand} order {order} has been shipped via {courier} 🚚\nTracking number: {tracking}\nThank you for shopping with us!",
    ];
    $text = strtr((string)setting('wa_' . $which, $defaults[$which] ?? ''), [
        '{name}' => trim(explode(' ', trim((string)$o['ship_name']))[0]) ?: 'there',
        '{fullname}' => (string)$o['ship_name'],
        '{brand}' => $o['slip_brand'] !== '' ? $o['slip_brand'] : setting('slip_brand', setting('company_name', 'Looma Apparels')),
        '{order}' => order_no($o['id']),
        '{items}' => implode(', ', $items),
        '{pcs}' => (string)array_sum(array_map(fn($s) => (int)$s, $items)),
        '{dispatch}' => $o['due_date'] ? date('d M', strtotime($o['due_date'])) : '',
        '{courier}' => $o['courier'] !== '' ? $o['courier'] : 'courier',
        '{tracking}' => $o['tracking_no'] !== '' ? $o['tracking_no'] : '-',
    ]);
    return 'https://wa.me/' . $digits . '?text=' . rawurlencode($text);
}

/** Active saved designs with their images, for the order form picker. */
function designs_for_picker(): array
{
    $imgs = [];
    foreach (q('SELECT di.design_id, di.filename FROM design_images di JOIN designs d ON d.id = di.design_id WHERE d.active = 1 ORDER BY di.id')->fetchAll() as $r) {
        $imgs[$r['design_id']][] = thumb_path($r['filename']);
    }
    $out = [];
    foreach (q('SELECT * FROM designs WHERE active = 1 ORDER BY name')->fetchAll() as $d) {
        $out[] = [
            'id' => (int)$d['id'], 'name' => $d['name'], 'code' => $d['code'],
            'gsm' => $d['gsm'], 'product' => $d['product'], 'color' => $d['color'], 'size' => (string)($d['size'] ?? ''),
            'front_size' => (string)($d['front_size'] ?? ''), 'back_size' => (string)($d['back_size'] ?? ''),
            'chest_size' => (string)($d['chest_size'] ?? ''), 'custom_size' => (string)($d['custom_size'] ?? ''),
            'front_print' => (string)$d['front_print'], 'back_print' => (string)$d['back_print'], 'chest_print' => (string)$d['chest_print'],
            'custom_print' => (string)$d['custom_print'], 'neck_label_on' => (int)$d['neck_label_on'], 'neck_label' => (string)$d['neck_label'],
            'extra' => json_decode($d['extra'] ?: '{}', true) ?: [],
            'thumbs' => $imgs[$d['id']] ?? [],
        ];
    }
    return $out;
}

function update_row(string $table, int $id, array $changes): void
{
    $sql = implode(', ', array_map(fn($c) => "$c = ?", array_keys($changes)));
    q("UPDATE $table SET $sql WHERE id = ?", [...array_values($changes), $id]);
}

function item_label(array $it): string
{
    $type = $it['item_type'] ?? (!empty($it['plain']) ? 'plain' : 'print');
    return item_spec($it) . ($type === 'dtf_roll' ? '' : ' × ' . (int)($it['quantity'] ?? 1)) . ($type === 'plain' ? ' (plain)' : '');
}

function delete_item(int $orderId, array $item): void
{
    foreach (q('SELECT id FROM order_images WHERE item_id = ?', [$item['id']])->fetchAll() as $img) {
        delete_image((int)$img['id'], $orderId, false);
    }
    q('DELETE FROM order_items WHERE id = ?', [$item['id']]);
    log_change($orderId, 'item_removed', item_label($item), null, (int)$item['id']);
}

/**
 * Order counts as printed when every item that needs printing is printed (plain T-shirts don't).
 * An order of only plain T-shirts is ready to pack straight away. Kept on the order row for fast filters.
 */
function recompute_order_printed(int $orderId): void
{
    $r = q('SELECT COUNT(*) n, SUM(plain = 0) printable, SUM(plain = 0 AND printed = 1) done,
                   MAX(CASE WHEN plain = 0 THEN printed_at END) last FROM order_items WHERE order_id = ?', [$orderId])->fetch();
    $all = $r['n'] > 0 && (int)$r['done'] === (int)$r['printable'];
    $by = $all && $r['last'] ? q('SELECT printed_by FROM order_items WHERE order_id = ? AND plain = 0 ORDER BY printed_at DESC LIMIT 1', [$orderId])->fetchColumn() : null;
    q('UPDATE orders SET printed = ?, printed_at = ?, printed_by = ? WHERE id = ?', [$all ? 1 : 0, $all ? $r['last'] : null, $by ?: null, $orderId]);
}

/** Tick / untick Print processed, Packed or Shipped on an order. */
function set_stage(int $id, string $stage, bool $on): bool
{
    if (!in_array($stage, ORDER_STAGES, true) || !can_edit($stage)) {
        return false;
    }
    $o = get_order($id);
    if (!$o) {
        return false;
    }
    if ((bool)$o[$stage] === $on) {
        return true;
    }
    $u = current_user();
    q("UPDATE orders SET $stage = ?, {$stage}_at = ?, {$stage}_by = ?, updated_at = ?, updated_by = ? WHERE id = ?",
        [$on ? 1 : 0, $on ? now() : null, $on ? $u['id'] : null, now(), $u['id'], $id]);
    log_change($id, $stage, $o[$stage], $on ? 1 : 0);
    return true;
}

/**
 * Work still open on an order before it can be packed: items not printed, neck labels / chest logos not done.
 * Returns one line per item, e.g. "Item 2 (Black · M): printing, neck label". Empty = everything done.
 */
function pending_work(int $orderId): array
{
    $out = [];
    foreach (order_items($orderId) as $i => $it) {
        $todo = [];
        if (!$it['plain'] && !$it['printed']) {
            $todo[] = 'printing';
        }
        foreach (ITEM_ADDONS as $ad) {
            if (!empty($it[$ad['on']]) && empty($it[$ad['done']])) {
                $todo[] = strtolower($ad['label']);
            }
        }
        if ($todo) {
            $short = item_has_blank($it) ? implode(' · ', array_filter([$it['color'], $it['size']], 'strlen')) : '';
            $out[] = 'Item ' . ($i + 1) . ' (' . ($short !== '' ? $short : item_spec($it)) . '): ' . implode(', ', $todo);
        }
    }
    return $out;
}

/** Tick / untick "Neck label done" / "Chest logo done" on one item (same permission as Printed). */
function set_addon_done(int $orderId, int $itemId, string $kind, bool $on): bool
{
    $ad = ITEM_ADDONS[$kind] ?? null;
    if (!$ad || !can_edit('printed') || !get_order($orderId)) {
        return false;
    }
    $it = q('SELECT * FROM order_items WHERE id = ? AND order_id = ?', [$itemId, $orderId])->fetch();
    if (!$it) {
        return false;
    }
    $c = $ad['done'];
    if ((bool)$it[$c] !== $on) {
        $u = current_user();
        q("UPDATE order_items SET $c = ?, {$c}_at = ?, {$c}_by = ? WHERE id = ?", [$on ? 1 : 0, $on ? now() : null, $on ? $u['id'] : null, $itemId]);
        log_change($orderId, $c, $it[$c], $on ? 1 : 0, $itemId);
    }
    return true;
}

/** Tick / untick Printed on one item (or all items of the order when $itemId is null). */
function set_printed(int $orderId, ?int $itemId, bool $on): bool
{
    if (!can_edit('printed') || !get_order($orderId)) {
        return false;
    }
    $u = current_user();
    $items = $itemId
        ? q('SELECT * FROM order_items WHERE id = ? AND order_id = ? AND plain = 0', [$itemId, $orderId])->fetchAll()
        : q('SELECT * FROM order_items WHERE order_id = ? AND plain = 0', [$orderId])->fetchAll();
    if (!$items) {
        return false;
    }
    foreach ($items as $it) {
        if ((bool)$it['printed'] === $on) {
            continue;
        }
        q('UPDATE order_items SET printed = ?, printed_at = ?, printed_by = ? WHERE id = ?',
            [$on ? 1 : 0, $on ? now() : null, $on ? $u['id'] : null, $it['id']]);
        log_change($orderId, 'printed', $it['printed'], $on ? 1 : 0, (int)$it['id']);
    }
    q('UPDATE orders SET updated_at = ?, updated_by = ? WHERE id = ?', [now(), $u['id'], $orderId]);
    recompute_order_printed($orderId);
    return true;
}

// ---------------------------------------------------------------- images

function upload_dir(): string
{
    return APP_ROOT . '/uploads';
}

/** Files posted as item_mockups[KEY][] for one item key. */
function files_for_key(?array $f, string $key): array
{
    if (!$f || !isset($f['name'][$key])) {
        return [];
    }
    $out = [];
    foreach ((array)$f['name'][$key] as $i => $n) {
        $out[] = ['name' => $n, 'type' => $f['type'][$key][$i], 'tmp_name' => $f['tmp_name'][$key][$i],
            'error' => $f['error'][$key][$i], 'size' => $f['size'][$key][$i]];
    }
    return $out;
}

/**
 * Validate and store one uploaded image. Returns [stored path relative to uploads/, error].
 * Exactly one of the two is null; both are null when no file was chosen.
 */
function store_upload(array $f): array
{
    global $CONFIG;
    $max = (int)($CONFIG['max_upload_mb'] ?? 15) * 1024 * 1024;
    if ($f['error'] === UPLOAD_ERR_NO_FILE) {
        return [null, null];
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        return [null, 'Upload failed for ' . $f['name'] . ' (file may be too large).'];
    }
    if ($f['size'] > $max) {
        return [null, $f['name'] . ' is larger than ' . ($max >> 20) . ' MB.'];
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime] ?? null;
    if (!$ext || @getimagesize($f['tmp_name']) === false) {
        return [null, $f['name'] . ' is not a JPG, PNG, WEBP or GIF image.'];
    }
    $sub = date('Y/m');
    $dir = upload_dir() . '/' . $sub;
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return [null, 'Could not create the uploads folder. Check folder permissions.'];
    }
    $name = store_image($f['tmp_name'], $mime, $dir . '/' . bin2hex(random_bytes(12)), $ext);
    return $name ? [$sub . '/' . $name, null] : [null, 'Could not save ' . $f['name'] . '.'];
}

function save_uploads(int $orderId, int $itemId, ?array $files, string $key, string $kind = ''): array
{
    $errors = [];
    foreach (files_for_key($files, $key) as $f) {
        [$path, $err] = store_upload($f);
        if ($err) {
            $errors[] = $err;
        }
        if (!$path) {
            continue;
        }
        q('INSERT INTO order_images (order_id, item_id, filename, original_name, kind, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$orderId, $itemId, $path, mb_substr($f['name'], 0, 250), $kind, current_user()['id'], now()]);
        log_change($orderId, 'mockups', null, 'added ' . ($kind !== '' ? strtolower(ITEM_ADDONS[$kind]['label']) . ' image ' : '') . $f['name'], $itemId);
    }
    return $errors;
}

/** Link a saved design's images to an order item (same files, no copies). */
function attach_design_images(int $orderId, int $itemId, int $designId): int
{
    $n = 0;
    foreach (q('SELECT * FROM design_images WHERE design_id = ? ORDER BY id', [$designId])->fetchAll() as $img) {
        q('INSERT INTO order_images (order_id, item_id, filename, original_name, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$orderId, $itemId, $img['filename'], $img['original_name'], current_user()['id'], now()]);
        $n++;
    }
    return $n;
}

/** Delete an image file (and its thumbnail) once no order or saved design uses it any more. */
function unlink_if_unused(string $filename): void
{
    $used = (int)q('SELECT (SELECT COUNT(*) FROM order_images WHERE filename = ?) + (SELECT COUNT(*) FROM design_images WHERE filename = ?)',
        [$filename, $filename])->fetchColumn();
    if ($used > 0) {
        return;
    }
    foreach ([$filename, thumb_path($filename)] as $f) {
        $p = upload_dir() . '/' . $f;
        if (is_file($p)) {
            @unlink($p);
        }
    }
}

/**
 * Save a resized copy (max 2000px) plus a 480px thumbnail. Falls back to the original
 * file when GD is not available. Returns the stored file name (without folder).
 */
function store_image(string $tmp, string $mime, string $basePath, string $ext): ?string
{
    $src = null;
    if (function_exists('imagecreatefromstring') && $mime !== 'image/gif') {
        $src = @imagecreatefromstring((string)file_get_contents($tmp));
    }
    if (!$src) {
        return move_uploaded_file($tmp, "$basePath.$ext") ? basename("$basePath.$ext") : null;
    }
    // Phone photos: honour the EXIF rotation.
    if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
        $o = (int)(@exif_read_data($tmp)['Orientation'] ?? 1);
        $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($rot) {
            $src = imagerotate($src, $rot, 0);
        }
    }
    // Keep files small: JPEG unless the image really uses transparency; then WebP (small, keeps
    // transparency) when the server supports it, otherwise PNG.
    $format = 'jpg';
    if (in_array($mime, ['image/png', 'image/webp'], true) && image_has_alpha($src)) {
        $format = function_exists('imagewebp') ? 'webp' : 'png';
    }
    $ok = write_resized($src, 2000, "$basePath.$format", $format);
    write_resized($src, 480, "{$basePath}_t.$format", $format);
    imagedestroy($src);
    return $ok ? basename("$basePath.$format") : null;
}

/** True when the image has (partly) transparent pixels. Checks a grid of ~10,000 points, which is fast on big images. */
function image_has_alpha($img): bool
{
    if (!imageistruecolor($img)) {
        if (imagecolortransparent($img) < 0) {
            return false;
        }
        imagepalettetotruecolor($img); // so alpha can be read per pixel
    }
    $w = imagesx($img);
    $h = imagesy($img);
    $step = max(1, (int)floor(sqrt($w * $h / 10000)));
    for ($y = 0; $y < $h; $y += $step) {
        for ($x = 0; $x < $w; $x += $step) {
            if (((imagecolorat($img, $x, $y) >> 24) & 0x7F) > 0) {
                return true;
            }
        }
    }
    return false;
}

function write_resized($src, int $max, string $path, string $format): bool
{
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1, $max / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    if ($format === 'jpg') {
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    } else {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $ok = match ($format) {
        'webp' => imagewebp($dst, $path, 85),
        'png' => imagepng($dst, $path, 9),
        default => imagejpeg($dst, $path, 82),
    };
    imagedestroy($dst);
    return $ok;
}

function thumb_path(string $filename): string
{
    $t = preg_replace('/(\.\w+)$/', '_t$1', $filename);
    return is_file(upload_dir() . '/' . $t) ? $t : $filename;
}

function delete_image(int $imgId, int $orderId, bool $log = true): void
{
    $img = q('SELECT * FROM order_images WHERE id = ? AND order_id = ?', [$imgId, $orderId])->fetch();
    if (!$img) {
        return;
    }
    q('DELETE FROM order_images WHERE id = ?', [$imgId]);
    unlink_if_unused($img['filename']);
    if ($log) {
        log_change($orderId, 'mockups', 'removed ' . $img['original_name'], null, $img['item_id'] ? (int)$img['item_id'] : null);
    }
}

/** SQL condition: this order image is not a saved design's image (those are always kept). */
const NOT_DESIGN_IMAGE = 'NOT EXISTS (SELECT 1 FROM design_images di WHERE di.filename = i.filename)';

/**
 * Free up space: delete the mock-up images of a shipped order (files + image rows).
 * Images that came from a saved design are kept, and so is all other order data. Returns the number of images removed.
 */
function clear_order_images(int $orderId): int
{
    $o = get_order($orderId);
    if (!$o || !$o['shipped'] || !cap('cleanup')) {
        return 0;
    }
    $n = 0;
    foreach (q('SELECT i.id FROM order_images i WHERE i.order_id = ? AND ' . NOT_DESIGN_IMAGE, [$orderId])->fetchAll() as $img) {
        delete_image((int)$img['id'], $orderId, false);
        $n++;
    }
    if ($n) {
        q('UPDATE orders SET images_cleared_at = ? WHERE id = ?', [now(), $orderId]);
        log_change($orderId, 'mockups', null, "cleared $n image(s) to free space");
    }
    return $n;
}

function dir_size(string $dir): int
{
    $size = 0;
    if (!is_dir($dir)) {
        return 0;
    }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $f) {
        $size += $f->getSize();
    }
    return $size;
}

function human_size(int $bytes): string
{
    foreach (['B', 'KB', 'MB', 'GB'] as $u) {
        if ($bytes < 1024 || $u === 'GB') {
            return ($u === 'B' ? $bytes : number_format($bytes, 1)) . ' ' . $u;
        }
        $bytes /= 1024;
    }
    return '';
}

// ---------------------------------------------------------------- listing

/** Build WHERE clause for the order list filters. */
function order_filter_sql(array $g): array
{
    $where = ['o.deleted_at IS NULL'];
    $p = [];
    $today = today();
    switch ($g['tab'] ?? 'open') {
        case 'print':   $where[] = 'o.printed = 0 AND o.shipped = 0'; break;
        case 'pack':    $where[] = 'o.printed = 1 AND o.packed = 0 AND o.shipped = 0'; break;
        case 'ship':    $where[] = 'o.packed = 1 AND o.shipped = 0'; break;
        case 'delayed': $where[] = 'o.shipped = 0 AND o.due_date < ?'; $p[] = $today; break;
        case 'due':     $where[] = 'o.shipped = 0 AND o.due_date = ?'; $p[] = $today; break;
        case 'shipped': $where[] = 'o.shipped = 1'; break;
        case 'all':     break;
        default:        $where[] = 'o.shipped = 0';
    }
    if (!empty($g['q'])) {
        $s = trim((string)$g['q']);
        $idFromNo = preg_match('/^(?:LA)?0*(\d+)$/i', $s, $m) ? (int)$m[1] : 0;
        // "C101-2" finds that customer's order number 2.
        if (preg_match('/^(.+)-(\d+)$/', $s, $m)) {
            $where[] = '(o.customer_id LIKE ? OR (o.customer_id = ? AND o.cust_seq = ?) OR o.tracking_no LIKE ? OR o.order_ref LIKE ?)';
            array_push($p, "%$s%", $m[1], (int)$m[2], "%$s%", "%$s%");
            $s = null;
        }
    }
    if (!empty($g['q']) && $s !== null) {
        $where[] = '(o.customer_id LIKE ? OR o.customer_name LIKE ? OR o.tracking_no LIKE ? OR o.ship_name LIKE ? OR o.ship_phone LIKE ? OR o.ship_pincode = ? OR o.id = ?
                     OR EXISTS (SELECT 1 FROM order_items si WHERE si.order_id = o.id AND (si.product LIKE ? OR si.color LIKE ? OR si.gsm LIKE ?)))';
        array_push($p, "%$s%", "%$s%", "%$s%", "%$s%", "%$s%", $s, $idFromNo, "%$s%", "%$s%", "%$s%");
    }
    $dateCol = ['created' => 'o.created_at', 'print_processed' => 'o.print_processed_at', 'printed' => 'o.printed_at', 'packed' => 'o.packed_at', 'shipped' => 'o.shipped_at'][$g['by'] ?? 'created'] ?? 'o.created_at';
    if (!empty($g['from'])) {
        $where[] = "$dateCol >= ?";
        $p[] = $g['from'] . ' 00:00:00';
    }
    if (!empty($g['to'])) {
        $where[] = "$dateCol <= ?";
        $p[] = $g['to'] . ' 23:59:59';
    }
    if (!empty($g['courier'])) {
        $where[] = 'o.courier = ?';
        $p[] = $g['courier'];
    }
    return [implode(' AND ', $where), $p];
}

function field_label(string $key): string
{
    $special = ['created' => 'Order created', 'item_added' => 'Item added', 'item_removed' => 'Item removed',
        'print_hold' => 'Print list', 'chest_logo_on' => 'Chest logo', 'neck_done' => 'Neck label done', 'logo_done' => 'Chest logo done', 'plain' => 'Plain T-shirt (no print)', 'neck_label_on' => 'Neck label', 'design' => 'Saved design used', 'print_spec' => 'Print method', 'rate' => 'Price', 'print_rate' => 'Printing price',
        'design_id' => 'Saved design', 'design_name' => 'Saved design', 'front_size' => 'Front print size', 'back_size' => 'Back print size',
        'chest_size' => 'Chest print size', 'custom_size' => 'Custom print size'];
    if (isset($special[$key])) {
        return $special[$key];
    }
    return all_fields()[$key]['label'] ?? (custom_fields(false)[$key]['label'] ?? $key);
}
