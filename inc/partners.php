<?php
// Print-on-demand partners: companies (e.g. Outfitcrew, Mangatholi) that sell T-shirts which Looma prints and ships
// to their customers. Partners log in at /partner/ (their own logins, apart from staff), save their designs and send
// orders; Looma's team accepts each order, which then becomes a normal Looma order.
declare(strict_types=1);

require_once __DIR__ . '/orders.php';

const PARTNER_STATUS = ['new' => 'Waiting for Looma', 'accepted' => 'Accepted', 'rejected' => 'Not accepted', 'cancelled' => 'Cancelled'];

// ---------------------------------------------------------------- partners

function partner_by_id(int $id): ?array
{
    $p = q('SELECT * FROM partners WHERE id = ?', [$id])->fetch();
    return $p ?: null;
}

function partners_all(bool $activeOnly = false): array
{
    return q('SELECT * FROM partners' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY active DESC, name')->fetchAll();
}

/** Order numbers prefix: typed one, else letters of the name (Outfit Crew → "OC"). */
function partner_prefix(array $p): string
{
    if ($p['prefix'] !== '') {
        return $p['prefix'];
    }
    $words = preg_split('/[^A-Za-z0-9]+/', $p['name'], -1, PREG_SPLIT_NO_EMPTY) ?: ['P'];
    return strtoupper(count($words) > 1 ? implode('', array_map(fn($w) => $w[0], array_slice($words, 0, 3))) : substr($words[0], 0, 3));
}

/** Add or change a partner (admin). Returns [id, errors]. */
function save_partner(?int $id, array $in): array
{
    $v = [];
    foreach (['name' => 150, 'code' => 80, 'prefix' => 10, 'phone' => 40, 'email' => 150, 'slip_brand' => 150, 'ret_phone' => 40,
                 'ret_pincode' => 12, 'courier' => 100] as $k => $len) {
        $v[$k] = mb_substr(trim((string)($in[$k] ?? '')), 0, $len);
    }
    $v['prefix'] = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $v['prefix']));
    $v['ret_address'] = trim((string)($in['ret_address'] ?? ''));
    $v['notes'] = trim((string)($in['notes'] ?? ''));
    $v['active'] = !empty($in['active']) ? 1 : 0;
    $errors = [];
    if ($v['name'] === '') {
        $errors[] = 'Partner name is required.';
    }
    if ($v['code'] === '') {
        $errors[] = 'Customer ID is required (their orders are made under it).';
    } elseif (q('SELECT 1 FROM partners WHERE code = ? AND id <> ?', [$v['code'], (int)$id])->fetchColumn()) {
        $errors[] = 'Another partner already uses customer ID ' . $v['code'] . '.';
    }
    if ($v['email'] !== '' && !filter_var($v['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Email address looks wrong.';
    }
    if ($v['ret_pincode'] !== '' && !preg_match('/^\d{6}$/', $v['ret_pincode'])) {
        $errors[] = 'Return pincode must be 6 digits.';
    }
    if ($errors) {
        return [$id, $errors];
    }
    // A saved customer typed in another letter case uses the saved ID.
    if ($c = q('SELECT code FROM customers WHERE code = ?', [$v['code']])->fetchColumn()) {
        $v['code'] = $c;
    }
    if ($id) {
        $old = partner_by_id($id);
        update_row('partners', $id, $v + ['updated_at' => now()]);
        // Customer ID changed: their saved designs follow.
        if ($old && $old['code'] !== $v['code']) {
            q('UPDATE design_customers SET customer_code = ? WHERE customer_code = ?', [$v['code'], $old['code']]);
        }
    } else {
        $cols = array_keys($v + ['created_at' => 1]);
        q('INSERT INTO partners (' . implode(',', $cols) . ') VALUES (' . rtrim(str_repeat('?,', count($cols)), ',') . ')', array_values($v + ['created_at' => now()]));
        $id = (int)db()->lastInsertId();
    }
    // The partner is also a customer in the customer book (their orders are made under that customer ID).
    q("INSERT INTO customers (code, name, phone, email, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE name = IF(name = '', VALUES(name), name)", [$v['code'], $v['name'], $v['phone'], $v['email'], now(), now()]);
    return [$id, []];
}

// ---------------------------------------------------------------- partner logins

function partner_users(int $partnerId): array
{
    return q('SELECT * FROM partner_users WHERE partner_id = ? ORDER BY active DESC, username', [$partnerId])->fetchAll();
}

/** Add a login, or change name / password / active of one (admin). Returns errors. */
function save_partner_user(int $partnerId, ?int $userId, array $in): array
{
    $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 120);
    $pass = (string)($in['password'] ?? '');
    $errors = [];
    if ($pass !== '' && (strlen($pass) < 8 || !preg_match('/[A-Za-z]/', $pass) || !preg_match('/\d/', $pass))) {
        $errors[] = 'Password: at least 8 characters with letters and numbers.';
    }
    if (!$userId) {
        $username = strtolower(trim((string)($in['username'] ?? '')));
        if (!preg_match('/^[a-z0-9._-]{3,60}$/', $username)) {
            $errors[] = 'Username: 3–60 letters, numbers, dot, dash or underscore.';
        } elseif (q('SELECT 1 FROM partner_users WHERE username = ?', [$username])->fetchColumn()
            || q('SELECT 1 FROM users WHERE username = ?', [$username])->fetchColumn()) {
            $errors[] = 'Username ' . $username . ' is already taken.';
        }
        if ($pass === '') {
            $errors[] = 'Give the new login a password.';
        }
        if ($errors) {
            return $errors;
        }
        q('INSERT INTO partner_users (partner_id, username, name, password_hash, created_at) VALUES (?, ?, ?, ?, ?)',
            [$partnerId, $username, $name, password_hash($pass, PASSWORD_DEFAULT), now()]);
        return [];
    }
    if ($errors) {
        return $errors;
    }
    $u = q('SELECT * FROM partner_users WHERE id = ? AND partner_id = ?', [$userId, $partnerId])->fetch();
    if (!$u) {
        return ['Login not found.'];
    }
    $active = !empty($in['active']) ? 1 : 0;
    $set = ['name' => $name, 'active' => $active];
    // A new password or switching it off logs that person out everywhere.
    if ($pass !== '' || ($u['active'] && !$active)) {
        $set['session_version'] = (int)$u['session_version'] + 1;
    }
    if ($pass !== '') {
        $set['password_hash'] = password_hash($pass, PASSWORD_DEFAULT);
    }
    update_row('partner_users', $userId, $set);
    return [];
}

/** The logged-in partner person (with 'partner' = their company), or null. Separate from staff sessions. */
function current_partner_user(): ?array
{
    static $pu = false;
    if ($pu !== false) {
        return $pu;
    }
    start_session();
    $pu = null;
    if (!empty($_SESSION['pu'])) {
        $u = q('SELECT * FROM partner_users WHERE id = ? AND active = 1', [$_SESSION['pu']])->fetch();
        $p = $u ? partner_by_id((int)$u['partner_id']) : null;
        if ($u && $p && $p['active'] && (int)$u['session_version'] === (int)($_SESSION['psv'] ?? -1)) {
            $u['partner'] = $p;
            $pu = $u;
        } else {
            unset($_SESSION['pu'], $_SESSION['psv']);
        }
    }
    return $pu;
}

function require_partner(): array
{
    $pu = current_partner_user();
    if (!$pu) {
        redirect('partner/index.php');
    }
    ensure_schema();
    return $pu;
}

/** Partner login with the same "10 wrong tries in 15 minutes" lock as staff. Returns an error or null. */
function partner_login(string $username, string $password): ?string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    q('DELETE FROM login_attempts WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 900)]);
    if ((int)q('SELECT COUNT(*) FROM login_attempts WHERE ip = ?', [$ip])->fetchColumn() >= 10) {
        return 'Too many wrong attempts. Please wait 15 minutes.';
    }
    $u = q('SELECT u.* FROM partner_users u JOIN partners p ON p.id = u.partner_id WHERE u.username = ? AND u.active = 1 AND p.active = 1',
        [strtolower(trim($username))])->fetch();
    if (!$u || !password_verify($password, $u['password_hash'])) {
        q('INSERT INTO login_attempts (ip, created_at) VALUES (?, ?)', [$ip, now()]);
        return 'Wrong username or password.';
    }
    session_regenerate_id(true);
    $_SESSION['pu'] = (int)$u['id'];
    $_SESSION['psv'] = (int)$u['session_version'];
    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        q('UPDATE partner_users SET password_hash = ? WHERE id = ?', [password_hash($password, PASSWORD_DEFAULT), $u['id']]);
    }
    q('UPDATE partner_users SET last_login = ? WHERE id = ?', [now(), $u['id']]);
    q('DELETE FROM login_attempts WHERE ip = ?', [$ip]);
    return null;
}

// ---------------------------------------------------------------- designs

/** A partner's saved designs (linked to their customer ID), newest first, with photo thumbnails. */
function partner_designs(array $p): array
{
    $rows = q('SELECT d.* FROM design_customers dc JOIN designs d ON d.id = dc.design_id WHERE dc.customer_code = ? AND d.active = 1 ORDER BY d.name',
        [$p['code']])->fetchAll();
    $imgs = [];
    if ($rows) {
        foreach (q('SELECT design_id, filename FROM design_images WHERE design_id IN (' . implode(',', array_map('intval', array_column($rows, 'id'))) . ') ORDER BY id')->fetchAll() as $r) {
            $imgs[$r['design_id']][] = $r['filename'];
        }
    }
    foreach ($rows as &$d) {
        $d['images'] = $imgs[$d['id']] ?? [];
        $d['extra'] = json_decode($d['extra'] ?: '{}', true) ?: [];
    }
    return $rows;
}

function partner_design(array $p, int $designId): ?array
{
    foreach (partner_designs($p) as $d) {
        if ((int)$d['id'] === $designId) {
            return $d;
        }
    }
    return null;
}

/** A partner saves a new design: name, note and mock-up photos. It goes into Saved designs, linked to them. */
function partner_create_design(array $pu, array $in, ?array $files): array
{
    $p = $pu['partner'];
    $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 150);
    $note = mb_substr(trim((string)($in['note'] ?? '')), 0, 2000);
    $errors = [];
    if ($name === '') {
        $errors[] = 'Give the design a name.';
    } elseif (q('SELECT 1 FROM design_customers dc JOIN designs d ON d.id = dc.design_id WHERE dc.customer_code = ? AND d.active = 1 AND d.name = ?', [$p['code'], $name])->fetchColumn()) {
        $errors[] = 'You already have a design called “' . $name . '”.';
    }
    $list = [];
    foreach ((array)($files['name'] ?? []) as $i => $n) {
        $f = ['name' => (string)$n, 'tmp_name' => (string)($files['tmp_name'][$i] ?? ''), 'error' => (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int)($files['size'][$i] ?? 0)];
        if ($f['error'] !== UPLOAD_ERR_NO_FILE) {
            $list[] = $f;
        }
    }
    if (!$list) {
        $errors[] = 'Add at least one mock-up photo.';
    } elseif (count($list) > 8) {
        $errors[] = 'At most 8 photos per design.';
    }
    foreach ($list as $f) {
        // Only photos the server re-saves (anything hidden in the file is dropped); no GIF.
        $mime = $f['error'] === UPLOAD_ERR_OK && is_uploaded_file($f['tmp_name']) ? (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']) : '';
        if ($f['error'] === UPLOAD_ERR_OK && !in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
            $errors[] = $f['name'] . ' is not a JPG, PNG or WEBP photo.';
        }
    }
    if ($errors) {
        return [null, $errors];
    }
    $paths = [];
    foreach ($list as $f) {
        [$path, $err] = store_upload($f);
        if ($err) {
            $errors[] = $err;
        } elseif ($path) {
            $paths[] = [$path, mb_substr($f['name'], 0, 250)];
        }
    }
    if (!$paths) {
        return [null, $errors ?: ['The photos could not be saved.']];
    }
    $extra = ['partner' => $p['code'], 'partner_new' => 1, 'partner_note' => $note, 'partner_by' => $pu['name'] ?: $pu['username']];
    q('INSERT INTO designs (name, code, extra, active, created_at) VALUES (?, ?, ?, 1, ?)', [$name, '', json_encode($extra, JSON_UNESCAPED_UNICODE), now()]);
    $id = (int)db()->lastInsertId();
    foreach ($paths as [$path, $orig]) {
        q('INSERT INTO design_images (design_id, filename, original_name, created_at) VALUES (?, ?, ?, ?)', [$id, $path, $orig, now()]);
    }
    q('INSERT IGNORE INTO design_customers (design_id, customer_code, created_at) VALUES (?, ?, ?)', [$id, $p['code'], now()]);
    return [$id, $errors];
}

/** Image file belongs to this partner (their design photos or their orders' photos)? */
function partner_owns_image(array $p, string $file): bool
{
    $file = preg_replace('/_t(\.\w+)$/', '$1', $file);
    return (bool)q('SELECT 1 FROM design_images di JOIN design_customers dc ON dc.design_id = di.design_id WHERE di.filename = ? AND dc.customer_code = ? LIMIT 1',
        [$file, $p['code']])->fetchColumn();
}

// ---------------------------------------------------------------- orders from partners

/** Check a partner order form. Returns [clean data, errors]. Products must be in the catalog, designs theirs. */
function partner_order_clean(array $p, array $in): array
{
    $e = [];
    $d = [];
    foreach (['ship_name' => 150, 'ship_phone' => 40, 'ship_phone2' => 40, 'landmark' => 200, 'ship_pincode' => 12, 'ship_email' => 150, 'courier' => 100] as $k => $len) {
        $d[$k] = mb_substr(trim(preg_replace('/\s+/', ' ', (string)($in[$k] ?? ''))), 0, $len);
    }
    $d['ship_address'] = mb_substr(trim((string)($in['ship_address'] ?? '')), 0, 1000);
    $d['notes'] = mb_substr(trim((string)($in['notes'] ?? '')), 0, 1000);
    $d['urgent'] = !empty($in['urgent']) ? 1 : 0;
    if ($d['ship_name'] === '') {
        $e[] = 'Customer name is required.';
    }
    foreach (['ship_phone' => 'Phone', 'ship_phone2' => 'Second phone'] as $k => $l) {
        $digits = preg_replace('/\D/', '', $d[$k]);
        if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
            $digits = substr($digits, 2);
        }
        if ($d[$k] !== '' || $k === 'ship_phone') {
            if (!preg_match('/^[6-9]\d{9}$/', $digits)) {
                $e[] = $l . ': a 10-digit mobile number.';
            }
            $d[$k] = $digits;
        }
    }
    if ($d['ship_address'] === '') {
        $e[] = 'Address is required.';
    }
    if (!preg_match('/^[1-9]\d{5}$/', $d['ship_pincode'])) {
        $e[] = 'Pincode must be 6 digits.';
    }
    if ($d['ship_email'] !== '' && !filter_var($d['ship_email'], FILTER_VALIDATE_EMAIL)) {
        $e[] = 'Email address looks wrong.';
    }
    $d['payment_type'] = ($in['payment_type'] ?? '') === 'cod' ? 'cod' : 'prepaid';
    $d['cod_amount'] = null;
    if ($d['payment_type'] === 'cod') {
        $amt = str_replace([',', '₹', ' '], '', (string)($in['cod_amount'] ?? ''));
        if (!is_numeric($amt) || (float)$amt <= 0 || (float)$amt > 100000) {
            $e[] = 'COD: type the amount the courier collects from your customer.';
        } else {
            $d['cod_amount'] = number_format((float)$amt, 2, '.', '');
        }
    }
    // Items: design + product + colour + size + quantity, all from lists.
    $cat = [];
    foreach (catalog() as $g) {
        foreach ($g['products'] as $pr) {
            $cat[$g['gsm'] . '|' . $pr['name']] = $pr;
        }
    }
    $designs = [];
    foreach (partner_designs($p) as $ds) {
        $designs[(int)$ds['id']] = $ds;
    }
    // The form sends lines (design + T-shirt + colour, with a quantity for each size): one item per size.
    $rawItems = (array)($in['items'] ?? []);
    if (isset($in['lines'])) {
        $rawItems = [];
        foreach (array_values((array)$in['lines']) as $li => $ln) {
            if (!is_array($ln)) {
                continue;
            }
            [$lg, $lp] = array_pad(explode('|', (string)($ln['tshirt'] ?? ''), 2), 2, '');
            $any = false;
            foreach ((array)($ln['qty'] ?? []) as $sz => $n) {
                if ((int)$n > 0) {
                    $rawItems[] = ['design_id' => $ln['design_id'] ?? 0, 'gsm' => $lg, 'product' => $lp, 'color' => $ln['color'] ?? '', 'size' => (string)$sz, 'qty' => (int)$n];
                    $any = true;
                }
            }
            if (!$any && ((int)($ln['design_id'] ?? 0) || $lg !== '')) {
                $e[] = 'T-shirt ' . ($li + 1) . ': tap + on at least one size.';
            }
        }
    }
    $items = [];
    foreach (array_values($rawItems) as $i => $it) {
        if (!is_array($it)) {
            continue;
        }
        $n = $i + 1;
        $did = (int)($it['design_id'] ?? 0);
        $gsm = trim((string)($it['gsm'] ?? ''));
        $prod = trim((string)($it['product'] ?? ''));
        $color = trim((string)($it['color'] ?? ''));
        $size = trim((string)($it['size'] ?? ''));
        $qty = (int)($it['qty'] ?? 0);
        if (!$did && $gsm === '' && $prod === '' && $qty === 0) {
            continue; // empty row
        }
        if (!isset($designs[$did])) {
            $e[] = "T-shirt $n: pick one of your designs.";
        }
        $pr = $cat[$gsm . '|' . $prod] ?? null;
        if (!$pr) {
            $e[] = "T-shirt $n: pick the T-shirt (GSM and fit).";
        } else {
            if (!in_array($color, array_column($pr['colors'], 'name'), true)) {
                $e[] = "T-shirt $n: pick a colour.";
            }
            if (!in_array($size, $pr['sizes'], true)) {
                $e[] = "T-shirt $n: pick a size.";
            }
        }
        if ($qty < 1 || $qty > 500) {
            $e[] = "T-shirt $n: quantity 1–500.";
        }
        $items[] = ['design_id' => $did, 'design' => $designs[$did]['name'] ?? '', 'gsm' => $gsm, 'product' => $prod, 'color' => $color, 'size' => $size, 'qty' => max(1, $qty)];
    }
    if (!$items) {
        $e[] = 'Add at least one T-shirt.';
    }
    if (count($items) > 50) {
        $e[] = 'At most 50 lines in one order.';
    }
    $d['items'] = $items;
    return [$d, $e];
}

/** Save a partner order (new, or a change while Looma has not accepted it yet). Returns [id, errors]. */
function partner_order_save(array $pu, ?int $id, array $in): array
{
    $p = $pu['partner'];
    [$d, $errors] = partner_order_clean($p, $in);
    if ($id) {
        $po = partner_order($id, (int)$p['id']);
        if (!$po) {
            return [null, ['Order not found.']];
        }
        if ($po['status'] !== 'new') {
            $errors[] = 'Looma has already ' . ($po['status'] === 'accepted' ? 'accepted' : 'closed') . ' this order; it can’t be changed here.';
        }
    }
    // Same day, same customer, same T-shirts sent twice by mistake.
    if (!$id && !$errors && !empty($in['dup_ok']) === false) {
        $twin = q("SELECT ref FROM partner_orders WHERE partner_id = ? AND ship_phone = ? AND items = ? AND status IN ('new', 'accepted') AND created_at >= ? LIMIT 1",
            [$p['id'], $d['ship_phone'], json_encode($d['items'], JSON_UNESCAPED_UNICODE), date('Y-m-d H:i:s', time() - 86400)])->fetchColumn();
        if ($twin) {
            $errors[] = 'The same order for this customer was already sent today (' . $twin . '). Tick “Yes, send it again” if it is really a second order.';
        }
    }
    if ($errors) {
        return [$id, $errors];
    }
    $cols = ['ship_name', 'ship_phone', 'ship_phone2', 'ship_address', 'landmark', 'ship_pincode', 'ship_email', 'payment_type', 'cod_amount', 'courier', 'urgent', 'notes'];
    $row = array_intersect_key($d, array_flip($cols));
    $row['items'] = json_encode($d['items'], JSON_UNESCAPED_UNICODE);
    if ($id) {
        update_row('partner_orders', $id, $row + ['updated_at' => now()]);
        return [$id, []];
    }
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $seq = (int)q('SELECT next_seq FROM partners WHERE id = ? FOR UPDATE', [$p['id']])->fetchColumn();
        q('UPDATE partners SET next_seq = ? WHERE id = ?', [$seq + 1, $p['id']]);
        $row += ['partner_id' => $p['id'], 'ref' => partner_prefix($p) . '-' . str_pad((string)$seq, 4, '0', STR_PAD_LEFT),
            'source' => 'portal', 'status' => 'new', 'created_by' => $pu['id'], 'created_at' => now()];
        $c = array_keys($row);
        q('INSERT INTO partner_orders (' . implode(',', $c) . ') VALUES (' . rtrim(str_repeat('?,', count($c)), ',') . ')', array_values($row));
        $id = (int)$pdo->lastInsertId();
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    partner_notify_new($p, $row['ref']);
    return [$id, []];
}

/** A partner order (optionally only if it is that partner's). Items decoded. */
function partner_order(int $id, ?int $partnerId = null): ?array
{
    $po = q('SELECT * FROM partner_orders WHERE id = ?' . ($partnerId ? ' AND partner_id = ' . $partnerId : ''), [$id])->fetch();
    if (!$po) {
        return null;
    }
    $po['items'] = json_decode($po['items'] ?: '[]', true) ?: [];
    return $po;
}

/** Items grouped back into form lines: design + T-shirt + colour with a quantity per size. */
function partner_lines(array $items): array
{
    $lines = [];
    foreach ($items as $it) {
        $k = (int)$it['design_id'] . '|' . $it['gsm'] . '|' . $it['product'] . '|' . $it['color'];
        $lines[$k] ??= ['design_id' => (int)$it['design_id'], 'tshirt' => $it['gsm'] . '|' . $it['product'], 'color' => $it['color'], 'qty' => []];
        $lines[$k]['qty'][$it['size']] = ($lines[$k]['qty'][$it['size']] ?? 0) + (int)$it['qty'];
    }
    return array_values($lines);
}

/** A returning customer of this partner (by mobile): their last name and address, or null. Only this partner's orders. */
function partner_customer_lookup(array $p, string $phone): ?array
{
    $digits = preg_replace('/\D/', '', $phone);
    if (strlen($digits) === 12 && str_starts_with($digits, '91')) {
        $digits = substr($digits, 2);
    }
    if (!preg_match('/^\d{10}$/', $digits)) {
        return null;
    }
    $r = q('SELECT ship_name, ship_phone2, ship_address, landmark, ship_pincode, ship_email FROM partner_orders WHERE partner_id = ? AND ship_phone = ? ORDER BY id DESC LIMIT 1',
        [$p['id'], $digits])->fetch();
    return $r ?: null;
}

/** Partner cancels an order Looma has not accepted yet. */
function partner_order_cancel(array $pu, int $id): bool
{
    $po = partner_order($id, (int)$pu['partner']['id']);
    if (!$po || $po['status'] !== 'new') {
        return false;
    }
    q("UPDATE partner_orders SET status = 'cancelled', updated_at = ?, reason = 'Cancelled by partner' WHERE id = ? AND status = 'new'", [now(), $id]);
    return true;
}

/** Where the order is, in the partner's words, with courier and tracking once shipped: [label, css class, detail]. */
function partner_order_stage(array $po, ?array $o): array
{
    if ($po['status'] !== 'accepted') {
        return [PARTNER_STATUS[$po['status']] ?? $po['status'], $po['status'], $po['reason']];
    }
    if (!$o) {
        return ['Accepted', 'accepted', ''];
    }
    if ($o['shipped']) {
        return ['Shipped', 'shipped', trim($o['courier'] . ' ' . $o['tracking_no'])];
    }
    if ($o['packed']) {
        return ['Packed', 'packed', ''];
    }
    if ($o['printed']) {
        return ['Printed', 'printed', ''];
    }
    if ($o['print_processed']) {
        return ['Printing', 'printing', ''];
    }
    return ['Accepted', 'accepted', $o['due_date'] ? 'Dispatch by ' . fmt_date($o['due_date']) : ''];
}

/** Tell the team (admin + production phones) that a partner sent an order. */
function partner_notify_new(array $p, string $ref): void
{
    partner_notify($p, $ref . ' — waiting to be accepted');
}

/** A message on the team's phones (after the page is sent, so nobody waits for it). */
function partner_notify(array $p, string $text, string $page = 'partner_orders.php'): void
{
    register_shutdown_function(function () use ($p, $text, $page) {
        try {
            require_once __DIR__ . '/push.php';
            foreach (ship_alert_users() as $uid) {
                push_to_user($uid, ['title' => 'Partner · ' . $p['name'], 'body' => $text, 'url' => base_url($page), 'tag' => 'partner-order']);
            }
        } catch (Throwable $e) {
            error_log('Looma partner push: ' . $e->getMessage());
        }
    });
}

// ---------------------------------------------------------------- change requests (after Looma accepted)

/** Partner asks for a change on an accepted order that is not shipped yet. */
function partner_change_request(array $pu, int $id, string $text): ?string
{
    $po = partner_order($id, (int)$pu['partner']['id']);
    $text = mb_substr(trim($text), 0, 1000);
    if (!$po || $po['status'] !== 'accepted') {
        return 'Only accepted orders take change requests (a waiting order can be changed directly).';
    }
    $o = $po['order_id'] ? get_order((int)$po['order_id']) : null;
    if ($o && $o['shipped']) {
        return 'This order has already been shipped.';
    }
    if ($text === '') {
        return 'Write what should change.';
    }
    q("UPDATE partner_orders SET change_req = ?, change_state = 'open', change_at = ?, change_reply = '' WHERE id = ?", [$text, now(), $id]);
    if ($o) {
        log_change((int)$o['id'], 'partner_order', null, 'change asked by ' . $pu['partner']['name'] . ': ' . $text);
    }
    partner_notify($pu['partner'], $po['ref'] . ' — change asked: ' . mb_substr($text, 0, 80), 'partner_orders.php?tab=changes');
    return null;
}

/** The team answers a change request: 'done' or 'declined' (with a reply the partner sees). */
function partner_change_answer(int $id, string $state, string $reply): bool
{
    if (!cap('create') || !in_array($state, ['done', 'declined'], true)) {
        return false;
    }
    $ok = q("UPDATE partner_orders SET change_state = ?, change_reply = ? WHERE id = ? AND change_state = 'open'", [$state, mb_substr(trim($reply), 0, 255), $id])->rowCount() > 0;
    $po = $ok ? partner_order($id) : null;
    if ($po && $po['order_id']) {
        log_change((int)$po['order_id'], 'partner_order', null, 'change request ' . ($state === 'done' ? 'done' : 'not possible') . ($reply !== '' ? ': ' . $reply : ''));
    }
    return $ok;
}

/** Open change request on a Looma order (for the banner on the order page), or null. */
function partner_change_for_order(int $orderId): ?array
{
    try {
        $r = q("SELECT po.id, po.ref, po.change_req, po.change_at, p.name FROM partner_orders po JOIN partners p ON p.id = po.partner_id
                WHERE po.order_id = ? AND po.change_state = 'open' LIMIT 1", [$orderId])->fetch();
        return $r ?: null;
    } catch (Throwable $e) {
        return null;
    }
}

// ---------------------------------------------------------------- the team: accept / not accept

function partner_new_count(): int
{
    try {
        return (int)q("SELECT COUNT(*) FROM partner_orders WHERE status = 'new'")->fetchColumn();
    } catch (Throwable $e) {
        return 0; // table not there yet (first load after an update)
    }
}

/**
 * Accept: make a normal Looma order (customer = the partner, address = their customer, items with the saved design and
 * its mock-ups, label with the partner's brand). Needs "Create orders". Returns [order id, errors].
 */
function partner_order_accept(int $id): array
{
    if (!cap('create')) {
        return [null, ['You are not allowed to create orders.']];
    }
    $po = partner_order($id);
    if (!$po || $po['status'] !== 'new') {
        return [null, ['This order was already accepted, not accepted or cancelled.']];
    }
    require_once __DIR__ . '/shopify.php';
    if (!partner_order_ready($po)) {
        return [null, ['Link its Shopify product(s) to a design and fill colour / size first.']];
    }
    $p = partner_by_id((int)$po['partner_id']);
    $address = trim((string)$po['ship_address']) . ($po['landmark'] !== '' ? "\nLandmark: " . $po['landmark'] : '');
    $notes = trim(($po['urgent'] ? '⚡ URGENT · ' : '') . 'Partner order ' . $po['ref'] . ($po['ship_phone2'] !== '' ? ' · 2nd phone ' . $po['ship_phone2'] : '')
        . ($po['notes'] !== '' ? "\n" . $po['notes'] : ''));
    $post = ['customer_id' => $p['code'], 'customer_name' => $p['name'], 'order_ref' => $po['ref'], 'ship_name' => $po['ship_name'],
        'ship_phone' => $po['ship_phone'], 'ship_address' => $address, 'ship_pincode' => $po['ship_pincode'], 'ship_email' => $po['ship_email'],
        'payment_type' => $po['payment_type'], 'cod_amount' => (string)$po['cod_amount'], 'courier' => $po['courier'] !== '' ? $po['courier'] : $p['courier'],
        'notes' => $notes, 'items' => []];
    foreach ($po['items'] as $i => $it) {
        $d = q('SELECT * FROM designs WHERE id = ?', [(int)$it['design_id']])->fetch();
        $row = ['item_type' => 'print', 'gsm' => $it['gsm'], 'product' => $it['product'], 'color' => $it['color'], 'size' => $it['size'],
            'quantity' => (int)$it['qty'], 'design_id' => (int)$it['design_id']];
        if ($d) {
            // Where and how big it prints comes from the saved design.
            foreach (PRINT_PLACES as $k => [, $sizeCol]) {
                $row[$k] = (string)($d[$k] ?? '');
                $row[$sizeCol] = (string)($d[$sizeCol] ?? '');
            }
            $row['neck_label_on'] = (int)$d['neck_label_on'];
            $row['neck_label'] = (string)$d['neck_label'];
        }
        $post['items']['n' . $i] = $row;
    }
    // The team member accepts on the partner's behalf: every field of the partner order is filled in.
    $GLOBALS['perm_all'] = true;
    try {
        [$orderId, $errors] = save_order(null, $post, []);
    } finally {
        $GLOBALS['perm_all'] = false;
    }
    if (!$orderId || $errors && !get_order((int)$orderId)) {
        return [null, $errors ?: ['The order could not be made.']];
    }
    // Shipping label: the partner's brand and return address.
    $label = array_filter(['slip_brand' => $p['slip_brand'], 'ret_phone' => $p['ret_phone'], 'ret_address' => (string)$p['ret_address'], 'ret_pincode' => $p['ret_pincode']], 'strlen');
    if ($label) {
        update_row('orders', (int)$orderId, $label);
    }
    q("UPDATE partner_orders SET status = 'accepted', order_id = ?, decided_by = ?, decided_at = ? WHERE id = ? AND status = 'new'",
        [$orderId, current_user()['id'], now(), $id]);
    log_change((int)$orderId, 'partner_order', null, 'from ' . $p['name'] . ' ' . $po['ref']);
    // Its designs have been checked now.
    foreach ($po['items'] as $it) {
        $d = q('SELECT extra FROM designs WHERE id = ?', [(int)$it['design_id']])->fetch();
        if ($d && ($x = json_decode($d['extra'] ?: '{}', true) ?: []) && !empty($x['partner_new'])) {
            unset($x['partner_new']);
            q('UPDATE designs SET extra = ? WHERE id = ?', [json_encode($x, JSON_UNESCAPED_UNICODE), (int)$it['design_id']]);
        }
    }
    return [(int)$orderId, $errors];
}

/** Not accepted, with the reason the partner sees. */
function partner_order_reject(int $id, string $reason): bool
{
    $reason = mb_substr(trim($reason), 0, 255);
    if (!cap('create') || $reason === '') {
        return false;
    }
    return q("UPDATE partner_orders SET status = 'rejected', reason = ?, decided_by = ?, decided_at = ? WHERE id = ? AND status = 'new'",
        [$reason, current_user()['id'], now(), $id])->rowCount() > 0;
}
