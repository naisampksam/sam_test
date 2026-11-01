<?php
// Minimum stock per product, size (and colour), and planning a production from the fabric kg per colour:
// the pieces follow the same ratio as the minimum stock (production_plan.php).
declare(strict_types=1);

require_once __DIR__ . '/billing.php'; // piece_weights(), batch_stats()

/** Minimum stock of a product: ['' => [SIZE => n] (all colours), 'Black' => [SIZE => n] (only where a colour differs)]. */
function stock_mins(int $productId): array
{
    $out = [];
    foreach (q('SELECT color, size, qty FROM stock_min WHERE product_id = ?', [$productId])->fetchAll() as $r) {
        $out[$r['color']][strtoupper($r['size'])] = (int)$r['qty'];
    }
    return $out;
}

/** The minimum for one colour and size: the colour's own number, else the "all colours" number (0 = none). */
function min_for(array $mins, string $color, string $size): int
{
    $size = strtoupper($size);
    return $mins[$color][$size] ?? $mins[''][$size] ?? 0;
}

/** Save the minimums: $all = [size => n]; $byColor = [colour => [size => n|'']] ('' = same as all colours). */
function save_stock_mins(int $productId, array $all, array $byColor): void
{
    q('DELETE FROM stock_min WHERE product_id = ?', [$productId]);
    $ins = function (string $color, $size, $v) use ($productId) {
        if (trim((string)$v) === '' || trim((string)$size) === '') {
            return;
        }
        q('INSERT INTO stock_min (product_id, color, size, qty) VALUES (?, ?, ?, ?)', [$productId, $color, mb_substr(strtoupper(trim((string)$size)), 0, 40), max(0, (int)$v)]);
    };
    foreach ($all as $size => $v) {
        $ins('', $size, $v);
    }
    foreach ($byColor as $color => $sizes) {
        foreach ((array)$sizes as $size => $v) {
            if (trim((string)$v) !== '' && (int)$v !== (int)($all[$size] ?? -1)) { // only where it differs
                $ins(mb_substr((string)$color, 0, 80), $size, $v);
            }
        }
    }
}

/** A product with what it needs for planning: gsm label, sizes, colours, stock [colour lower][SIZE] => qty. */
function plan_product(int $productId): ?array
{
    $p = q('SELECT p.*, g.label AS gsm FROM products p JOIN gsm_options g ON g.id = p.gsm_id WHERE p.id = ?', [$productId])->fetch();
    if (!$p) {
        return null;
    }
    $p['size_list'] = array_values(array_filter(array_map(fn($s) => strtoupper(trim($s)), explode(',', (string)$p['sizes'])), 'strlen'));
    $p['colors'] = q('SELECT name, hex FROM product_colors WHERE product_id = ? AND active = 1 ORDER BY sort, id', [$productId])->fetchAll();
    $p['stock'] = [];
    foreach (q("SELECT color, size, qty FROM stock_items WHERE category = 'Blank T-shirt' AND active = 1 AND gsm = ? AND product = ?", [$p['gsm'], $p['name']])->fetchAll() as $it) {
        $p['stock'][strtolower($it['color'])][strtoupper($it['size'])] = ($p['stock'][strtolower($it['color'])][strtoupper($it['size'])] ?? 0) + (float)$it['qty'];
    }
    return $p;
}

/** Colours and sizes below their minimum: [['color', 'size', 'qty', 'min'], …]. */
function below_min(array $p, array $mins): array
{
    $out = [];
    foreach ($p['colors'] as $c) {
        foreach ($p['size_list'] as $sz) {
            $min = min_for($mins, $c['name'], $sz);
            $have = (float)($p['stock'][strtolower($c['name'])][$sz] ?? 0);
            if ($min > 0 && $have < $min) {
                $out[] = ['color' => $c['name'], 'size' => $sz, 'qty' => $have, 'min' => $min];
            }
        }
    }
    return $out;
}

/** Average fabric waste % of this product's production batches (Add stock → production batch); 15 when unknown. */
function plan_waste_pct(string $gsm, string $product): float
{
    $w = [];
    foreach (q('SELECT * FROM production_batches WHERE gsm = ? AND product = ? ORDER BY batch_date DESC LIMIT 10', [$gsm, $product])->fetchAll() as $b) {
        $st = batch_stats($b);
        if ($st['waste_pct'] !== null) {
            $w[] = $st['waste_pct'];
        }
    }
    return $w ? round(array_sum($w) / count($w), 1) : 15.0;
}

/**
 * Pieces per size from the fabric: the same ratio as $ratio [SIZE => n], each piece using $fabricG [SIZE => g of fabric].
 * Whole pieces; fabric left over goes to the sizes furthest behind their share. Returns [SIZE => pieces].
 */
function plan_pieces(float $kg, array $ratio, array $fabricG): array
{
    $ratio = array_filter($ratio, fn($r) => $r > 0);
    $per = 0.0;
    foreach ($ratio as $sz => $r) {
        if (empty($fabricG[$sz])) {
            return [];
        }
        $per += $r * $fabricG[$sz];
    }
    if ($per <= 0 || $kg <= 0) {
        return [];
    }
    $k = $kg * 1000 / $per;
    $out = [];
    $left = $kg * 1000;
    foreach ($ratio as $sz => $r) {
        $out[$sz] = (int)floor($k * $r + 1e-9);
        $left -= $out[$sz] * $fabricG[$sz];
    }
    while (true) {
        $best = null;
        foreach ($ratio as $sz => $r) {
            if ($fabricG[$sz] <= $left + 1e-9 && ($best === null || $k * $r - $out[$sz] > $k * $ratio[$best] - $out[$best])) {
                $best = $sz;
            }
        }
        if ($best === null) {
            break;
        }
        $out[$best]++;
        $left -= $fabricG[$best];
    }
    return $out;
}

/** Simple way: total pieces (kg × pieces per kg) split by the ratio, whole pieces (largest remainder first). [SIZE => pieces] */
function plan_pieces_count(int $total, array $ratio): array
{
    $ratio = array_filter($ratio, fn($r) => $r > 0);
    $sum = array_sum($ratio);
    if ($sum <= 0 || $total <= 0) {
        return [];
    }
    $out = [];
    $rest = [];
    foreach ($ratio as $sz => $r) {
        $exact = $total * $r / $sum;
        $out[$sz] = (int)floor($exact + 1e-9);
        $rest[$sz] = $exact - $out[$sz];
    }
    arsort($rest);
    foreach (array_slice(array_keys($rest), 0, $total - array_sum($out)) as $sz) {
        $out[$sz]++;
    }
    return $out;
}
