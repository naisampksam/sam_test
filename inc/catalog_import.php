<?php
// Bulk catalog import: one line per product.
//   GSM | Product | Colors (comma separated, optional #hex after each) | Sizes (comma separated)
// Existing GSMs / products are matched by name and updated (colors are added, never removed).
declare(strict_types=1);

const COLOR_HEX = [
    'black' => '#111111', 'white' => '#ffffff', 'navy' => '#1f2a44', 'navy blue' => '#1f2a44',
    'red' => '#c62828', 'maroon' => '#6d1a1f', 'beige' => '#d8c7a6', 'olive' => '#6b6b3a',
    'grey' => '#8a8a8a', 'gray' => '#8a8a8a', 'grey melange' => '#a7a7a7', 'charcoal' => '#3b3b3b',
    'bottle green' => '#0f4d32', 'green' => '#2e7d32', 'royal blue' => '#2246b5', 'sky blue' => '#87bde8',
    'blue' => '#1e5bb8', 'yellow' => '#f2c500', 'mustard' => '#d19b1a', 'orange' => '#ef6c00',
    'pink' => '#f0a1b8', 'purple' => '#6a3d9a', 'lavender' => '#b9a7d9', 'brown' => '#6d4c32',
    'cream' => '#f3ead3', 'off white' => '#f4f1e8', 'mint' => '#a8dcc5', 'peach' => '#f6c3a3',
];

function guess_color_hex(string $name): string
{
    return COLOR_HEX[strtolower(trim($name))] ?? '';
}

/** Returns [products added or updated, list of error strings]. */
function import_catalog_text(PDO $pdo, string $text, bool $inTransaction = true): array
{
    $count = 0;
    $errors = [];
    if ($inTransaction) {
        $pdo->beginTransaction();
    }
    foreach (preg_split('/\r\n|\r|\n/', $text) as $n => $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        $parts = array_map('trim', explode('|', $line));
        if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
            $errors[] = 'Line ' . ($n + 1) . ': needs at least "GSM | Product"';
            continue;
        }
        [$gsm, $product] = $parts;
        $colors = array_filter(array_map('trim', explode(',', $parts[2] ?? '')), 'strlen');
        $sizes = implode(',', array_filter(array_map('trim', explode(',', $parts[3] ?? '')), 'strlen'));

        $st = $pdo->prepare('SELECT id FROM gsm_options WHERE label = ?');
        $st->execute([$gsm]);
        $gsmId = $st->fetchColumn();
        if (!$gsmId) {
            $sort = (int)$pdo->query('SELECT IFNULL(MAX(sort), 0) + 1 FROM gsm_options')->fetchColumn();
            $pdo->prepare('INSERT INTO gsm_options (label, sort) VALUES (?, ?)')->execute([$gsm, $sort]);
            $gsmId = $pdo->lastInsertId();
        }

        $st = $pdo->prepare('SELECT id FROM products WHERE gsm_id = ? AND name = ?');
        $st->execute([$gsmId, $product]);
        $pid = $st->fetchColumn();
        if (!$pid) {
            $pdo->prepare('INSERT INTO products (gsm_id, name, sizes) VALUES (?, ?, ?)')
                ->execute([$gsmId, $product, $sizes !== '' ? $sizes : 'S,M,L,XL,XXL']);
            $pid = $pdo->lastInsertId();
        } else {
            $pdo->prepare('UPDATE products SET active = 1' . ($sizes !== '' ? ', sizes = ?' : '') . ' WHERE id = ?')
                ->execute($sizes !== '' ? [$sizes, $pid] : [$pid]);
        }

        $i = 0;
        foreach ($colors as $c) {
            $hex = '';
            if (preg_match('/^(.*?)\s*(#[0-9a-fA-F]{6})$/', $c, $m)) {
                [$c, $hex] = [trim($m[1]), strtoupper($m[2])];
            }
            $hex = $hex ?: guess_color_hex($c);
            $st = $pdo->prepare('SELECT id FROM product_colors WHERE product_id = ? AND name = ?');
            $st->execute([$pid, $c]);
            if ($cid = $st->fetchColumn()) {
                $pdo->prepare('UPDATE product_colors SET active = 1, hex = IF(? = \'\', hex, ?) WHERE id = ?')->execute([$hex, $hex, $cid]);
            } else {
                $pdo->prepare('INSERT INTO product_colors (product_id, name, hex, sort) VALUES (?, ?, ?, ?)')
                    ->execute([$pid, $c, $hex, $i]);
            }
            $i++;
        }
        $count++;
    }
    if ($inTransaction) {
        $pdo->commit();
    }
    return [$count, $errors];
}
