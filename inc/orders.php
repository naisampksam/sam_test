<?php
// Orders and their items: saving, ticks, image uploads and change history.
//
// An order is one parcel for one customer (customer ID, dispatch date, packed, shipped, courier).
// It holds one or more items; each item is its own blank (GSM / product / color / size / qty),
// its own mock-ups and print details, and its own Printed tick.
declare(strict_types=1);

const ORDER_STAGES = ['packed', 'shipped'];
const STAGES = ['printed', 'packed', 'shipped'];
const ORDER_TEXT_FIELDS = ['customer_id', 'ship_name', 'ship_phone', 'ship_address', 'ship_pincode', 'notes', 'courier', 'tracking_no'];
const ITEM_TEXT_FIELDS = ['gsm', 'product', 'color', 'size', 'front_print', 'back_print', 'chest_print', 'neck_label', 'custom_print'];

/** SQL snippet: per-order item totals, for list queries on "orders o". */
const ORDER_TOTALS_SQL = "(SELECT IFNULL(SUM(quantity),0) FROM order_items it WHERE it.order_id = o.id) AS total_qty,
    (SELECT COUNT(*) FROM order_items it WHERE it.order_id = o.id) AS item_count,
    (SELECT COUNT(*) FROM order_items it WHERE it.order_id = o.id AND it.printed = 1) AS printed_count";

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
        if (can_edit($f) && array_key_exists($f, $post)) {
            $set[$f] = trim((string)$post[$f]);
        }
    }
    if (($isNew || isset($set['customer_id'])) && ($set['customer_id'] ?? '') === '' && can_edit('customer_id')) {
        $errors[] = 'Customer ID is required.';
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
        if (can_edit('quantity') && isset($ip['quantity'])) {
            $qty = (int)$ip['quantity'];
            if ($qty < 1 || $qty > 100000) {
                $errors[] = 'Item quantity must be at least 1.';
            }
            $iset['quantity'] = $qty;
        }
        if (!$cur && can_edit('printed')) {
            $iset['printed'] = !empty($ip['printed']) ? 1 : 0;
        }
        [$iextra, $ichanges] = read_custom($ip, $cur['extra'] ?? [], 'item');
        $itemPlans[] = ['key' => $key, 'cur' => $cur, 'set' => $iset, 'extra' => $iextra, 'extra_changes' => $ichanges,
            'sort' => isset($ip['sort']) ? (int)$ip['sort'] : null];
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
            if (can_edit('mockups')) {
                $errors = array_merge($errors, save_uploads($id, $itemId, $files['item_mockups'] ?? null, $plan['key']));
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
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [$id, $errors];
}

function update_row(string $table, int $id, array $changes): void
{
    $sql = implode(', ', array_map(fn($c) => "$c = ?", array_keys($changes)));
    q("UPDATE $table SET $sql WHERE id = ?", [...array_values($changes), $id]);
}

function item_label(array $it): string
{
    $s = trim(implode(' · ', array_filter([$it['gsm'] ?? '', $it['product'] ?? '', $it['color'] ?? '', $it['size'] ?? ''], 'strlen')));
    return ($s ?: 'Item') . ' × ' . (int)($it['quantity'] ?? 1);
}

function delete_item(int $orderId, array $item): void
{
    foreach (q('SELECT id FROM order_images WHERE item_id = ?', [$item['id']])->fetchAll() as $img) {
        delete_image((int)$img['id'], $orderId, false);
    }
    q('DELETE FROM order_items WHERE id = ?', [$item['id']]);
    log_change($orderId, 'item_removed', item_label($item), null, (int)$item['id']);
}

/** Order counts as printed when every item is printed. Kept on the order row for fast filters. */
function recompute_order_printed(int $orderId): void
{
    $r = q('SELECT COUNT(*) n, SUM(printed) p, MAX(printed_at) last FROM order_items WHERE order_id = ?', [$orderId])->fetch();
    $all = $r['n'] > 0 && (int)$r['p'] === (int)$r['n'];
    $by = $all ? q('SELECT printed_by FROM order_items WHERE order_id = ? ORDER BY printed_at DESC LIMIT 1', [$orderId])->fetchColumn() : null;
    q('UPDATE orders SET printed = ?, printed_at = ?, printed_by = ? WHERE id = ?', [$all ? 1 : 0, $all ? $r['last'] : null, $by ?: null, $orderId]);
}

/** Tick / untick Packed or Shipped on an order. */
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

/** Tick / untick Printed on one item (or all items of the order when $itemId is null). */
function set_printed(int $orderId, ?int $itemId, bool $on): bool
{
    if (!can_edit('printed') || !get_order($orderId)) {
        return false;
    }
    $u = current_user();
    $items = $itemId
        ? q('SELECT * FROM order_items WHERE id = ? AND order_id = ?', [$itemId, $orderId])->fetchAll()
        : q('SELECT * FROM order_items WHERE order_id = ?', [$orderId])->fetchAll();
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

function save_uploads(int $orderId, int $itemId, ?array $files, string $key): array
{
    global $CONFIG;
    $errors = [];
    $max = (int)($CONFIG['max_upload_mb'] ?? 15) * 1024 * 1024;
    foreach (files_for_key($files, $key) as $f) {
        if ($f['error'] === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            $errors[] = 'Upload failed for ' . $f['name'] . ' (file may be too large).';
            continue;
        }
        if ($f['size'] > $max) {
            $errors[] = $f['name'] . ' is larger than ' . ($max >> 20) . ' MB.';
            continue;
        }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'][$mime] ?? null;
        if (!$ext || @getimagesize($f['tmp_name']) === false) {
            $errors[] = $f['name'] . ' is not a JPG, PNG, WEBP or GIF image.';
            continue;
        }
        $sub = date('Y/m');
        $dir = upload_dir() . '/' . $sub;
        if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
            $errors[] = 'Could not create the uploads folder. Check folder permissions.';
            continue;
        }
        $name = store_image($f['tmp_name'], $mime, upload_dir() . '/' . $sub . '/' . bin2hex(random_bytes(12)), $ext);
        if (!$name) {
            $errors[] = 'Could not save ' . $f['name'] . '.';
            continue;
        }
        q('INSERT INTO order_images (order_id, item_id, filename, original_name, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$orderId, $itemId, $sub . '/' . $name, mb_substr($f['name'], 0, 250), current_user()['id'], now()]);
        log_change($orderId, 'mockups', null, 'added ' . $f['name'], $itemId);
    }
    return $errors;
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
    $keepPng = $mime === 'image/png';
    $outExt = $keepPng ? 'png' : 'jpg';
    $ok = write_resized($src, 2000, "$basePath.$outExt", $keepPng);
    write_resized($src, 480, "{$basePath}_t.$outExt", $keepPng);
    imagedestroy($src);
    return $ok ? basename("$basePath.$outExt") : null;
}

function write_resized($src, int $max, string $path, bool $png): bool
{
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1, $max / max($w, $h));
    $nw = max(1, (int)round($w * $scale));
    $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    if ($png) {
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
    } else {
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    }
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $ok = $png ? imagepng($dst, $path, 6) : imagejpeg($dst, $path, 85);
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
    foreach ([$img['filename'], thumb_path($img['filename'])] as $f) {
        $p = upload_dir() . '/' . $f;
        if (is_file($p)) {
            @unlink($p);
        }
    }
    if ($log) {
        log_change($orderId, 'mockups', 'removed ' . $img['original_name'], null, $img['item_id'] ? (int)$img['item_id'] : null);
    }
}

/**
 * Free up space: delete every mock-up image of a shipped order (files + image rows).
 * All other order data stays. Returns the number of images removed.
 */
function clear_order_images(int $orderId): int
{
    $o = get_order($orderId);
    if (!$o || !$o['shipped'] || !cap('cleanup')) {
        return 0;
    }
    $n = 0;
    foreach (q('SELECT id FROM order_images WHERE order_id = ?', [$orderId])->fetchAll() as $img) {
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
        $where[] = '(o.customer_id LIKE ? OR o.tracking_no LIKE ? OR o.ship_name LIKE ? OR o.ship_phone LIKE ? OR o.ship_pincode = ? OR o.id = ?
                     OR EXISTS (SELECT 1 FROM order_items si WHERE si.order_id = o.id AND (si.product LIKE ? OR si.color LIKE ? OR si.gsm LIKE ?)))';
        array_push($p, "%$s%", "%$s%", "%$s%", "%$s%", $s, $idFromNo, "%$s%", "%$s%", "%$s%");
    }
    $dateCol = ['created' => 'o.created_at', 'printed' => 'o.printed_at', 'packed' => 'o.packed_at', 'shipped' => 'o.shipped_at'][$g['by'] ?? 'created'] ?? 'o.created_at';
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
    $special = ['created' => 'Order created', 'item_added' => 'Item added', 'item_removed' => 'Item removed'];
    if (isset($special[$key])) {
        return $special[$key];
    }
    return all_fields()[$key]['label'] ?? (custom_fields(false)[$key]['label'] ?? $key);
}
