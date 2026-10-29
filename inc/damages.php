<?php
// Damaged T-shirts: T-shirt damage, printing mistakes and embroidery mistakes. Each one takes the pieces out of stock;
// printing and embroidery mistakes are put against the person who made them (a red mark on their performance).
declare(strict_types=1);

require_once __DIR__ . '/billing.php'; // stock_move()
require_once __DIR__ . '/tasks.php';   // user_job(), staff_list()

const DAMAGE_KINDS = ['damage' => 'T-shirt damage', 'print' => 'Printing mistake', 'embroidery' => 'Embroidery mistake'];
const DAMAGE_ICONS = ['damage' => '🩹', 'print' => '🖨', 'embroidery' => '🧵'];
/** Kinds that are someone's mistake (a red mark on their performance). */
const DAMAGE_MISTAKES = ['print', 'embroidery'];

/** Who can record damaged T-shirts: the admin, stock keepers and the production (orders) team. */
function damage_access(?array $u = null): bool
{
    $u = $u ?? current_user();
    return $u && (is_admin($u) || cap('stock', $u) || user_job($u) === 'production');
}

/** Who can delete a record (puts the pieces back in stock): the admin and stock keepers. */
function damage_can_delete(?array $u = null): bool
{
    $u = $u ?? current_user();
    return $u && (is_admin($u) || cap('stock', $u));
}

/**
 * Record damaged pieces of one product + colour: [size => qty]. Each size is one row and is taken out of stock
 * (the stock item is made, at 0, the first time). Returns the number of pieces recorded.
 */
function damage_record(string $kind, array $product, string $color, array $qtyBySize, ?int $staffId, ?int $orderId, string $note, string $date): int
{
    if (!isset(DAMAGE_KINDS[$kind])) {
        throw new InvalidArgumentException('Pick what happened: T-shirt damage, printing mistake or embroidery mistake.');
    }
    if (in_array($kind, DAMAGE_MISTAKES, true) && !$staffId) {
        throw new InvalidArgumentException('Pick who made the ' . strtolower(DAMAGE_KINDS[$kind]) . '.');
    }
    $staffId = in_array($kind, DAMAGE_MISTAKES, true) ? $staffId : null;
    $pdo = db();
    $pdo->beginTransaction();
    $total = 0;
    try {
        foreach ($qtyBySize as $size => $qty) {
            $size = trim((string)$size);
            $qty = (int)round((float)$qty);
            if ($size === '' || $qty <= 0) {
                continue;
            }
            $sid = (int)q("SELECT id FROM stock_items WHERE category = 'Blank T-shirt' AND gsm = ? AND product = ? AND color = ? AND size = ? ORDER BY active DESC, id LIMIT 1",
                [$product['gsm'], $product['name'], $color, $size])->fetchColumn();
            if (!$sid) {
                $row = ['name' => trim("{$product['gsm']} {$product['name']} $color $size"), 'category' => 'Blank T-shirt', 'gsm' => $product['gsm'],
                    'product' => $product['name'], 'color' => $color, 'size' => $size, 'unit' => 'pcs', 'sale_price' => $product['price'] ?? 0,
                    'hsn' => setting('inv_default_hsn', '6109'), 'gst_rate' => (float)setting('inv_default_gst', '5'), 'low_level' => 5, 'created_at' => now()];
                q('INSERT INTO stock_items (' . implode(',', array_keys($row)) . ') VALUES (' . rtrim(str_repeat('?,', count($row)), ',') . ')', array_values($row));
                $sid = (int)$pdo->lastInsertId();
            }
            q('INSERT INTO damages (damage_date, kind, stock_id, gsm, product, color, size, qty, staff_id, order_id, note, created_by, created_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$date, $kind, $sid, $product['gsm'], $product['name'], $color, $size, $qty, $staffId, $orderId, mb_substr($note, 0, 250), current_user()['id'] ?? null, now()]);
            $did = (int)$pdo->lastInsertId();
            stock_move($sid, -$qty, 'damage', null, DAMAGE_KINDS[$kind] . ' #' . $did . ($staffId ? ' · ' . user_name($staffId) : '') . ($note !== '' ? ' · ' . $note : ''));
            $total += $qty;
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $total;
}

/** Remove a record (entered by mistake): its pieces go back into stock. The row is kept, marked deleted. */
function damage_delete(int $id): bool
{
    $d = q('SELECT * FROM damages WHERE id = ? AND deleted_at IS NULL', [$id])->fetch();
    if (!$d) {
        return false;
    }
    q('UPDATE damages SET deleted_at = ?, deleted_by = ? WHERE id = ?', [now(), current_user()['id'] ?? null, $id]);
    if ($d['stock_id'] && stock_get((int)$d['stock_id'])) {
        stock_move((int)$d['stock_id'], (float)$d['qty'], 'damage', null, 'Back in stock: damage record #' . $id . ' deleted');
    }
    return true;
}

/** Damaged pieces between two dates: ['pcs' => all, 'by' => [kind => pcs], 'n' => records]. */
function damage_totals(string $from, string $to): array
{
    $by = array_fill_keys(array_keys(DAMAGE_KINDS), 0);
    $n = 0;
    foreach (q('SELECT kind, SUM(qty) pcs, COUNT(*) n FROM damages WHERE deleted_at IS NULL AND damage_date BETWEEN ? AND ? GROUP BY kind', [$from, $to])->fetchAll() as $r) {
        $by[$r['kind']] = (int)$r['pcs'];
        $n += (int)$r['n'];
    }
    return ['pcs' => array_sum($by), 'by' => $by, 'n' => $n];
}

/** Printing / embroidery mistakes per person between two dates: [user_id => ['print' => pcs, 'embroidery' => pcs, 'pcs' => all, 'times' => records]]. */
function damage_mistakes(string $from, string $to): array
{
    $out = [];
    foreach (q("SELECT staff_id, kind, SUM(qty) pcs, COUNT(*) n FROM damages WHERE deleted_at IS NULL AND staff_id IS NOT NULL
                AND kind IN ('print', 'embroidery') AND damage_date BETWEEN ? AND ? GROUP BY staff_id, kind", [$from, $to])->fetchAll() as $r) {
        $o = &$out[(int)$r['staff_id']];
        $o ??= ['print' => 0, 'embroidery' => 0, 'pcs' => 0, 'times' => 0];
        $o[$r['kind']] = (int)$r['pcs'];
        $o['pcs'] += (int)$r['pcs'];
        $o['times'] += (int)$r['n'];
        unset($o);
    }
    return $out;
}

/** Records, newest first. Optional: one person's mistakes only. */
function damage_list(string $from, string $to, ?int $staffId = null, int $limit = 200): array
{
    $sql = 'SELECT * FROM damages WHERE deleted_at IS NULL AND damage_date BETWEEN ? AND ?';
    $args = [$from, $to];
    if ($staffId) {
        $sql .= " AND staff_id = ? AND kind IN ('print', 'embroidery')";
        $args[] = $staffId;
    }
    return q($sql . ' ORDER BY damage_date DESC, id DESC LIMIT ' . $limit, $args)->fetchAll();
}

/** "2 pcs · 190 GSM Regular Fit · Black · M" */
function damage_line(array $d): string
{
    return (int)$d['qty'] . ((int)$d['qty'] === 1 ? ' pc · ' : ' pcs · ') . trim($d['gsm'] . ' ' . $d['product']) . ' · ' . $d['color'] . ($d['size'] !== '' ? ' · ' . $d['size'] : '');
}

/** The red mark shown on performance pages: "2 mistakes · 5 pcs" (empty when none). */
function mistake_badge(?array $m, bool $short = false): string
{
    if (!$m || !$m['pcs']) {
        return '';
    }
    $parts = array_filter([$m['print'] ? $m['print'] . ' printing' : '', $m['embroidery'] ? $m['embroidery'] . ' embroidery' : '']);
    return '<span class="mistake-mark" title="' . h('Mistakes: ' . implode(', ', $parts) . ' pcs damaged') . '">✖ ' . $m['times'] . ($short ? '' : ' mistake' . ($m['times'] === 1 ? '' : 's'))
        . ' · ' . $m['pcs'] . ' pcs</span>';
}
