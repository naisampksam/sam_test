<?php
// A partner's own stock kept at Looma:
//  · return stock — printed T-shirts that came back (RTO): sent again for packing + shipping only;
//  · own blanks — blank T-shirts a print-only partner sends: printed for printing + packing + shipping (no T-shirt).
// Neither touches Looma's own stock. Taken when Looma accepts the order; RTO puts the T-shirts back.
declare(strict_types=1);

require_once __DIR__ . '/partners.php';

const PARTNER_SRC = ['new' => 'New T-shirt', 'return' => 'From my returns', 'blank' => 'My own T-shirt'];

function pstock_key(string $kind, array $it): string
{
    return implode('|', [$kind === 'return' ? (int)($it['design_id'] ?? 0) : 0, $it['gsm'] ?? '', $it['product'] ?? '', $it['color'] ?? '', $it['size'] ?? '']);
}

/** ['return' => [key => qty], 'blank' => [key => qty]] (only what is there). */
function partner_stock_map(int $partnerId): array
{
    $out = ['return' => [], 'blank' => []];
    try {
        foreach (q('SELECT * FROM partner_stock WHERE partner_id = ? AND qty > 0', [$partnerId])->fetchAll() as $r) {
            $out[$r['kind']][pstock_key($r['kind'], $r)] = (int)$r['qty'];
        }
    } catch (Throwable $e) {
    }
    return $out;
}

/** Rows of one kind (with the design's first photo for returns), biggest first. */
function partner_stock_rows(int $partnerId, string $kind, bool $withEmpty = false): array
{
    return q('SELECT s.*, (SELECT filename FROM design_images i WHERE i.design_id = s.design_id ORDER BY i.id LIMIT 1) img FROM partner_stock s
              WHERE s.partner_id = ? AND s.kind = ?' . ($withEmpty ? '' : ' AND s.qty > 0') . ' ORDER BY s.design_name, s.gsm, s.product, s.color, s.size', [$partnerId, $kind])->fetchAll();
}

/** Change the partner's stock (+ in, − out) and remember why. */
function partner_stock_move(int $partnerId, string $kind, array $it, int $change, string $reason, ?int $poId = null, ?int $orderId = null, string $note = ''): void
{
    $did = $kind === 'return' ? (int)($it['design_id'] ?? 0) : 0;
    $name = $kind === 'return' ? mb_substr((string)($it['design'] ?? $it['design_name'] ?? ''), 0, 200) : '';
    q('INSERT INTO partner_stock (partner_id, kind, design_id, design_name, gsm, product, color, size, qty, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
       ON DUPLICATE KEY UPDATE qty = qty + VALUES(qty), updated_at = VALUES(updated_at), design_name = IF(VALUES(design_name) <> \'\', VALUES(design_name), design_name)',
        [$partnerId, $kind, $did, $name, (string)$it['gsm'], (string)$it['product'], (string)$it['color'], (string)$it['size'], $change, now()]);
    $sid = (int)q('SELECT id FROM partner_stock WHERE partner_id = ? AND kind = ? AND design_id = ? AND gsm = ? AND product = ? AND color = ? AND size = ?',
        [$partnerId, $kind, $did, (string)$it['gsm'], (string)$it['product'], (string)$it['color'], (string)$it['size']])->fetchColumn();
    q('INSERT INTO partner_stock_moves (partner_id, stock_id, qty_change, reason, partner_order_id, order_id, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)',
        [$partnerId, $sid, $change, $reason, $poId, $orderId, mb_substr($note, 0, 255), current_user()['id'] ?? null, now()]);
}

/** Lines that use their returns / own blanks: is there enough? Returns errors. */
function partner_stock_check(int $partnerId, array $items): array
{
    $need = [];
    foreach ($items as $it) {
        $src = $it['src'] ?? 'new';
        if ($src === 'new') {
            continue;
        }
        $k = $src . '#' . pstock_key($src, $it);
        $need[$k] = ($need[$k] ?? 0) + (int)$it['qty'];
    }
    if (!$need) {
        return [];
    }
    $have = partner_stock_map($partnerId);
    $pp = partner_by_id($partnerId);
    if ($pp && !partner_own_blanks($pp)) {
        $have['blank'] = [];
    }
    $e = [];
    foreach ($need as $k => $n) {
        [$src, $key] = explode('#', $k, 2);
        $got = $have[$src][$key] ?? 0;
        if ($got < $n) {
            [, $g, $pr, $c, $sz] = explode('|', $key);
            $e[] = ($src === 'return' ? 'Returns' : 'Your own T-shirts') . ": only $got of $pr · $c · $sz left (needs $n).";
        }
    }
    return $e;
}

/** Looma accepted: take the returns / own blanks the order uses. */
function partner_stock_take(array $po, int $orderId): void
{
    foreach ($po['items'] as $it) {
        $src = $it['src'] ?? 'new';
        if ($src !== 'new') {
            partner_stock_move((int)$po['partner_id'], $src, $it, -(int)$it['qty'], 'used', (int)$po['id'], $orderId, 'Order ' . $po['ref']);
        }
    }
}

/** A shipped order came back (RTO): its printed T-shirts go into the partner's return stock. */
function partner_rto(int $poId): ?string
{
    if (!cap('create') && !cap('billing') && !cap('partners')) {
        return 'Not allowed.';
    }
    $po = partner_order($poId);
    $o = $po && $po['order_id'] ? get_order((int)$po['order_id']) : null;
    if (!$po || !$o || $po['status'] !== 'accepted') {
        return 'Order not found.';
    }
    if (!$o['shipped']) {
        return 'Only a shipped order can come back.';
    }
    if ($po['rto_at']) {
        return 'Already marked as returned.';
    }
    q('UPDATE partner_orders SET rto_at = ? WHERE id = ? AND rto_at IS NULL', [now(), $poId]);
    foreach (order_items((int)$o['id']) as $it) {
        if (!item_has_blank($it) || (int)$it['quantity'] < 1) {
            continue;
        }
        partner_stock_move((int)$po['partner_id'], 'return', ['design_id' => (int)$it['design_id'], 'design' => preg_replace('/ · .*$/', '', (string)$it['design_name']),
            'gsm' => $it['gsm'], 'product' => $it['product'], 'color' => $it['color'], 'size' => $it['size']], (int)$it['quantity'], 'rto', $poId, (int)$o['id'], 'RTO ' . $po['ref']);
    }
    log_change((int)$o['id'], 'partner_order', null, 'returned (RTO) — T-shirts added to ' . (partner_by_id((int)$po['partner_id'])['name'] ?? 'the partner') . '’s return stock');
    return null;
}

/** Blank T-shirts received from the partner: [size => qty]. */
function partner_blanks_in(int $partnerId, string $tshirt, string $color, array $sizes, string $note): ?string
{
    [$gsm, $product] = array_pad(explode('|', $tshirt, 2), 2, '');
    $pr = catalog_product($gsm, $product);
    if (!$pr) {
        return 'Pick the T-shirt.';
    }
    $n = 0;
    foreach ($sizes as $sz => $q) {
        $q = (int)$q;
        if ($q !== 0) {
            partner_stock_move($partnerId, 'blank', ['gsm' => $gsm, 'product' => $product, 'color' => trim($color), 'size' => (string)$sz], $q, $q > 0 ? 'in' : 'adjust', null, null, $note);
            $n += $q;
        }
    }
    return $n ? null : 'Type how many of each size came in.';
}

/** Correct one stock line to what is really there. */
function partner_stock_set(int $stockId, int $qty, string $note): ?string
{
    $s = q('SELECT * FROM partner_stock WHERE id = ?', [$stockId])->fetch();
    if (!$s) {
        return 'Not found.';
    }
    $diff = max(0, $qty) - (int)$s['qty'];
    if ($diff) {
        q('UPDATE partner_stock SET qty = qty + ?, updated_at = ? WHERE id = ?', [$diff, now(), $stockId]);
        q('INSERT INTO partner_stock_moves (partner_id, stock_id, qty_change, reason, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$s['partner_id'], $stockId, $diff, 'adjust', mb_substr($note ?: 'Counted', 0, 255), current_user()['id'] ?? null, now()]);
    }
    return null;
}

/** Print-only partner: sends their own blank T-shirts (the admin switches this on). Off = never shown anywhere. */
function partner_own_blanks(array $p): bool
{
    $s = json_decode((string)($p['settings'] ?? '') ?: '{}', true) ?: [];
    return !empty($s['own_blanks']);
}

/** Show the Stock tab: they have returns (now or before) or own blanks are on. */
function partner_has_stock_tab(array $p): bool
{
    if (partner_own_blanks($p)) {
        return true;
    }
    try {
        return (bool)q("SELECT 1 FROM partner_stock WHERE partner_id = ? AND kind = 'return' LIMIT 1", [$p['id']])->fetchColumn()
            || (bool)q('SELECT 1 FROM partner_orders WHERE partner_id = ? AND rto_at IS NOT NULL LIMIT 1', [$p['id']])->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/** Own blanks running low (at or below the partner's warning number, 5 by default). */
function partner_blanks_low(array $p): array
{
    if (!partner_own_blanks($p)) {
        return [];
    }
    $s = json_decode((string)($p['settings'] ?? '') ?: '{}', true) ?: [];
    $low = (int)($s['blank_low'] ?? 5);
    try {
        return q("SELECT * FROM partner_stock WHERE partner_id = ? AND kind = 'blank' AND qty <= ? AND
                  EXISTS (SELECT 1 FROM partner_stock_moves m WHERE m.stock_id = partner_stock.id) ORDER BY qty", [$p['id'], $low])->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}
