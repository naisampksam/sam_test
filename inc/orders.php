<?php
// Order saving, stage ticks, image uploads and change history.
declare(strict_types=1);

const STAGES = ['printed', 'packed', 'shipped'];
const TEXT_FIELDS = ['customer_id', 'gsm', 'product', 'color', 'size', 'front_print', 'back_print',
    'chest_print', 'neck_label', 'custom_print', 'notes', 'courier', 'tracking_no'];

function get_order(int $id): ?array
{
    $o = q('SELECT * FROM orders WHERE id = ? AND deleted_at IS NULL', [$id])->fetch();
    if (!$o) {
        return null;
    }
    $o['extra'] = json_decode($o['extra'] ?: '{}', true) ?: [];
    return $o;
}

function order_images(int $id): array
{
    return q('SELECT * FROM order_images WHERE order_id = ? ORDER BY id', [$id])->fetchAll();
}

function log_change(int $orderId, string $field, $old, $new): void
{
    q('INSERT INTO order_log (order_id, user_id, field, old_value, new_value, created_at) VALUES (?, ?, ?, ?, ?, ?)',
        [$orderId, current_user()['id'] ?? null, $field, $old === null ? null : (string)$old, $new === null ? null : (string)$new, now()]);
}

/**
 * Create or update an order from a form post. Only fields the user may edit are read.
 * Returns [orderId, errors].
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

    $set = [];
    foreach (TEXT_FIELDS as $f) {
        if (can_edit($f) && array_key_exists($f, $post)) {
            $set[$f] = trim((string)$post[$f]);
        }
    }
    if (isset($set['customer_id']) && $set['customer_id'] === '') {
        $errors[] = 'Customer ID is required.';
    }
    if (can_edit('quantity') && isset($post['quantity'])) {
        $qty = (int)$post['quantity'];
        if ($qty < 1 || $qty > 100000) {
            $errors[] = 'Quantity must be at least 1.';
        }
        $set['quantity'] = $qty;
    }
    if (can_edit('due_date') && !empty($post['due_date'])) {
        $d = DateTime::createFromFormat('Y-m-d', (string)$post['due_date']);
        if (!$d) {
            $errors[] = 'Dispatch date is not valid.';
        } else {
            $set['due_date'] = $d->format('Y-m-d');
        }
    }
    foreach (STAGES as $s) {
        // Ticks on existing orders are changed with the one-tap buttons (set_stage), not the edit form.
        if (can_edit($s) && ($isNew || array_key_exists($s, $post))) {
            $set[$s] = !empty($post[$s]) ? 1 : 0;
        }
    }

    // Custom fields live in the JSON "extra" column.
    $extra = $old['extra'] ?? [];
    $extraChanged = [];
    foreach (custom_fields() as $k => $f) {
        if (can_edit($k) && ($f['type'] === 'checkbox' || array_key_exists($k, $post))) {
            $v = $f['type'] === 'checkbox' ? (!empty($post[$k]) ? '1' : '') : trim((string)$post[$k]);
            if (($extra[$k] ?? '') !== $v) {
                $extraChanged[$k] = [$extra[$k] ?? '', $v];
            }
            $extra[$k] = $v;
        }
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
                'quantity' => 1,
                'due_date' => compute_due_date($created),
                'extra' => json_encode($extra, JSON_UNESCAPED_UNICODE),
                'created_at' => $created,
                'created_by' => $u['id'],
            ];
            foreach (STAGES as $s) {
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
                    if (in_array($f, STAGES, true)) {
                        $changes[$f . '_at'] = $v ? now() : null;
                        $changes[$f . '_by'] = $v ? $u['id'] : null;
                    }
                }
            }
            foreach ($extraChanged as $k => [$a, $b]) {
                log_change($id, $k, $a, $b);
            }
            if ($extraChanged) {
                $changes['extra'] = json_encode($extra, JSON_UNESCAPED_UNICODE);
            }
            if ($changes) {
                $changes['updated_at'] = now();
                $changes['updated_by'] = $u['id'];
                $sql = implode(', ', array_map(fn($c) => "$c = ?", array_keys($changes)));
                q("UPDATE orders SET $sql WHERE id = ?", [...array_values($changes), $id]);
            }
        }

        if (can_edit('mockups')) {
            foreach ((array)($post['delete_images'] ?? []) as $imgId) {
                delete_image((int)$imgId, $id);
            }
            $errors = array_merge($errors, save_uploads($id, $files['mockups'] ?? null));
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return [$id, $errors];
}

/** Tick / untick one stage (printed, packed, shipped). */
function set_stage(int $id, string $stage, bool $on): bool
{
    if (!in_array($stage, STAGES, true) || !can_edit($stage)) {
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

// ---------------------------------------------------------------- images

function upload_dir(): string
{
    return APP_ROOT . '/uploads';
}

/** Normalise the PHP $_FILES structure for a multi-file input. */
function normalise_files(?array $f): array
{
    if (!$f || !isset($f['name'])) {
        return [];
    }
    if (!is_array($f['name'])) {
        return [$f];
    }
    $out = [];
    foreach ($f['name'] as $i => $n) {
        $out[] = ['name' => $n, 'type' => $f['type'][$i], 'tmp_name' => $f['tmp_name'][$i], 'error' => $f['error'][$i], 'size' => $f['size'][$i]];
    }
    return $out;
}

function save_uploads(int $orderId, ?array $files): array
{
    global $CONFIG;
    $errors = [];
    $max = (int)($CONFIG['max_upload_mb'] ?? 15) * 1024 * 1024;
    foreach (normalise_files($files) as $f) {
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
        $base = $sub . '/' . bin2hex(random_bytes(12));
        $name = store_image($f['tmp_name'], $mime, upload_dir() . '/' . $base, $ext);
        if (!$name) {
            $errors[] = 'Could not save ' . $f['name'] . '.';
            continue;
        }
        q('INSERT INTO order_images (order_id, filename, original_name, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?)',
            [$orderId, $sub . '/' . $name, mb_substr($f['name'], 0, 250), current_user()['id'], now()]);
        log_change($orderId, 'mockups', null, 'added ' . $f['name']);
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

function delete_image(int $imgId, int $orderId): void
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
    log_change($orderId, 'mockups', 'removed ' . $img['original_name'], null);
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
        $where[] = '(o.customer_id LIKE ? OR o.tracking_no LIKE ? OR o.product LIKE ? OR o.id = ?)';
        array_push($p, "%$s%", "%$s%", "%$s%", $idFromNo);
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
    if ($key === 'created') {
        return 'Order created';
    }
    return all_fields()[$key]['label'] ?? (custom_fields(false)[$key]['label'] ?? $key);
}
