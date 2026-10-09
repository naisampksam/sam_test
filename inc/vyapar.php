<?php
// Import from Vyapar: the "Items" export (T-shirts → stock, printing → services, shipping → shipping charges)
// and the "Party report" (→ customers). Reads .xlsx directly, or .csv saved from Excel.
declare(strict_types=1);

require_once __DIR__ . '/catalog_import.php';

/** Rows of the first sheet of an .xlsx or a .csv file, as arrays of strings. */
function sheet_rows(string $path, string $name): array
{
    if (preg_match('/\.csv$/i', $name)) {
        $rows = [];
        $fh = fopen($path, 'r');
        while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            $rows[] = array_map(fn($v) => trim((string)preg_replace('/^\xEF\xBB\xBF/', '', (string)$v)), $r);
        }
        fclose($fh);
        return $rows;
    }
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('This server cannot open .xlsx files. In Excel use “Save as → CSV” and upload the .csv instead.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('Could not open the file. Is it the Excel file exported from Vyapar?');
    }
    $strings = [];
    if (($xml = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $sx = simplexml_load_string($xml);
        foreach ($sx->si as $si) {
            $t = isset($si->t) ? (string)$si->t : '';
            foreach ($si->r as $run) {
                $t .= (string)$run->t;
            }
            $strings[] = $t;
        }
    }
    // First sheet listed in the workbook.
    $sheet = 'xl/worksheets/sheet1.xml';
    if (($rels = $zip->getFromName('xl/_rels/workbook.xml.rels')) !== false && ($wb = $zip->getFromName('xl/workbook.xml')) !== false) {
        $wbx = simplexml_load_string($wb);
        $first = $wbx->sheets->sheet[0] ?? null;
        $rid = $first ? (string)$first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'] : '';
        foreach (simplexml_load_string($rels)->Relationship as $rel) {
            if ((string)$rel['Id'] === $rid) {
                $target = ltrim((string)$rel['Target'], '/');
                $sheet = str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
            }
        }
    }
    $xml = $zip->getFromName($sheet);
    $zip->close();
    if ($xml === false) {
        throw new RuntimeException('No sheet found in the file.');
    }
    $rows = [];
    foreach (simplexml_load_string($xml)->sheetData->row as $row) {
        $r = [];
        foreach ($row->c as $c) {
            $col = 0;
            foreach (str_split(preg_replace('/\d/', '', (string)$c['r'])) as $ch) {
                $col = $col * 26 + (ord($ch) - 64);
            }
            $type = (string)$c['t'];
            $v = $type === 'inlineStr' ? (string)$c->is->t : (string)$c->v;
            if ($type === 's') {
                $v = $strings[(int)$v] ?? '';
            }
            $r[$col - 1] = trim($v);
        }
        if ($r) {
            $max = max(array_keys($r));
            $rows[] = array_replace(array_fill(0, $max + 1, ''), $r);
        }
    }
    return $rows;
}

/** Rows keyed by header (lower case), from the first row that has $mustHave in it. */
function rows_by_header(array $rows, string $mustHave): array
{
    foreach ($rows as $i => $r) {
        $heads = array_map(fn($h) => strtolower(trim(rtrim((string)$h, '*'))), $r);
        if (in_array($mustHave, $heads, true)) {
            $out = [];
            foreach (array_slice($rows, $i + 1) as $data) {
                $row = [];
                foreach ($heads as $k => $h) {
                    if ($h !== '') {
                        $row[$h] = trim((string)($data[$k] ?? ''));
                    }
                }
                if (implode('', $row) !== '') {
                    $out[] = $row;
                }
            }
            return $out;
        }
    }
    throw new RuntimeException("This doesn't look like the right Vyapar file (no “" . $mustHave . "” column).");
}

/** What a Vyapar item is: 'shirt' (counted stock), 'service' (printing etc., never counted) or 'shipping'. */
function vyapar_kind(string $name, string $category): string
{
    $cat = strtoupper(trim($category));
    if ($cat === 'SHIPPING' || is_shipping_text($name)) {
        return 'shipping';
    }
    if ($cat === 'PRINT' || preg_match('/dtf|print|embroid|\bhd\b|label|design|nova|balance|pending/i', $name)) {
        return 'service';
    }
    return 'shirt';
}

/** "OVERSIZED FIT 190 BLACK -M" → gsm "190 GSM", product "Oversized Fit", color "Black", size "M". */
function vyapar_parse_shirt(string $name): array
{
    $n = strtoupper(trim(preg_replace('/\s+/', ' ', $name)));
    $n = preg_replace('/-?\s*NEW\s*-?/', ' ', $n);
    $size = '';
    if (preg_match('/[\s-]+((?:\d?X{0,3}L)|XS|S|M|\d{1,2}\s*-\s*\d{1,2})\s*$/', $n, $m)) {
        $size = preg_replace('/\s+/', '', $m[1]);
        $size = ['2XL' => 'XXL', '3XL' => 'XXXL'][$size] ?? $size;
        $n = trim(substr($n, 0, -strlen($m[0])));
    }
    $gsm = '';
    if (preg_match('/(?<!\d)(1[5-9]\d|[2-4]\d\d)\s*(GSM|G)?(?![\dA-Z])/', $n, $m)) {
        $gsm = $m[1] . ' GSM';
        $n = trim(str_replace($m[0], ' ', $n));
    }
    $colors = ['ROYAL BLUE', 'NAVY BLUE', 'OFF WHITE', 'MELANGE GREY', 'MINT GREEN', 'BOTTLE GREEN', 'SKY BLUE', 'BLACK', 'WHITE', 'RED', 'BLUE', 'NAVY', 'GREY', 'GRAY',
        'GREEN', 'MINTGREEN', 'MAROON', 'BEIGE', 'BROWN', 'CHOCOLATE', 'COFFEE', 'CREEM', 'CREAM', 'LAVENDER', 'PINK', 'PURPLE', 'ORANGE', 'YELLOW', 'OLIVE', 'MUSTARD'];
    $color = '';
    foreach ($colors as $c) {
        if (preg_match('/\b' . $c . '\b/', $n)) {
            $color = $c;
            $n = trim(preg_replace('/\b' . $c . '\b/', ' ', $n, 1));
            break;
        }
    }
    $product = ucwords(strtolower(trim(preg_replace('/\s+/', ' ', str_replace(['-', 'GSM'], ' ', $n)))));
    return ['gsm' => $gsm, 'product' => $product, 'color' => ucwords(strtolower($color)), 'size' => $size];
}

function vyapar_num(string $v): float
{
    return round((float)str_replace([',', '₹', ' '], '', $v), 2);
}

/**
 * Items export → stock. Existing items (same item code, or same name) are updated, never doubled.
 * $setQty: also set stock to the file's "Current stock quantity" (for counted items).
 */
function import_vyapar_items(array $rows, bool $setQty): array
{
    $rows = rows_by_header($rows, 'item name');
    $res = ['shirts' => 0, 'services' => 0, 'shipping' => 0, 'updated' => 0, 'skipped' => 0];
    $key = fn(string $s) => strtolower(preg_replace('/\s+/', ' ', trim($s)));
    $byName = $bySku = [];
    foreach (q('SELECT id, name, sku FROM stock_items')->fetchAll() as $s) {
        $byName[$key($s['name'])] = (int)$s['id'];
        if ($s['sku'] !== '') {
            $bySku[$s['sku']] = (int)$s['id'];
        }
    }
    $ship = [];
    foreach (shipping_presets() as $p) {
        $ship[$key($p['name'])] = $p;
    }
    $idx = catalog_index();
    $unmatched = [];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($rows as $r) {
            $name = trim(preg_replace('/\s+/', ' ', $r['item name'] ?? ''));
            if ($name === '') {
                $res['skipped']++;
                continue;
            }
            $kind = vyapar_kind($name, $r['category'] ?? '');
            $rate = vyapar_num($r['sale price'] ?? '0');
            if ($kind === 'shipping') {
                $ship[$key($name)] = ['name' => $name, 'rate' => $rate];
                $res['shipping']++;
                continue;
            }
            preg_match('/(\d+(?:\.\d+)?)\s*%/', $r['tax rate'] ?? '', $m);
            $unit = strtolower($r['base unit (x)'] ?? $r['base unit'] ?? '');
            $unit = str_starts_with($unit, 'met') || $unit === 'mtr' || $unit === 'm' ? 'm' : (in_array($unit, ['kg', 'roll', 'box', 'set'], true) ? $unit : 'pcs');
            $row = [
                'name' => mb_substr($name, 0, 200), 'sku' => mb_substr($r['item code'] ?? '', 0, 60),
                'hsn' => mb_substr($r['hsn'] ?? '', 0, 20), 'sale_price' => $rate, 'cost_price' => vyapar_num($r['purchase price'] ?? '0'),
                'gst_rate' => isset($m[1]) ? (float)$m[1] : (float)setting('inv_default_gst', '5'), 'unit' => $unit,
                'track' => $kind === 'shirt' ? 1 : 0,
            ];
            if ($kind === 'shirt') {
                // Only T-shirts that are in the catalog; their price is the catalog price.
                $parsed = vyapar_parse_shirt($name);
                $match = vyapar_catalog_match($parsed, $idx);
                if (!$match) {
                    $unmatched[] = $parsed + ['name' => $name, 'sku' => $row['sku'], 'hsn' => $row['hsn'], 'gst_rate' => $row['gst_rate'], 'cost_price' => $row['cost_price'],
                        'vyapar_price' => $rate, 'qty' => vyapar_num($r['current stock quantity'] ?? '0'), 'low_level' => max(0, vyapar_num($r['minimum stock quantity'] ?? '0'))];
                    if ($old = ($row['sku'] !== '' ? ($bySku[$row['sku']] ?? null) : null) ?? ($byName[$key($name)] ?? null)) {
                        q("UPDATE stock_items SET active = 0 WHERE id = ? AND category = 'Blank T-shirt'", [$old]); // was imported before, not in the catalog
                    }
                    continue;
                }
                $row = $match + $row + ['category' => 'Blank T-shirt', 'low_level' => max(0, vyapar_num($r['minimum stock quantity'] ?? '0')), 'active' => 1];
                $row['sale_price'] = $match['price'] ?? 0;
                unset($row['price']);
                $res['shirts']++;
            } else {
                $row += ['category' => SERVICE_CATEGORY];
                $res['services']++;
            }
            $id = ($row['sku'] !== '' ? ($bySku[$row['sku']] ?? null) : null) ?? ($byName[$key($name)] ?? null);
            if ($id) {
                $row['updated_at'] = now();
                $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($row)));
                q("UPDATE stock_items SET $sets WHERE id = ?", array_merge(array_values($row), [$id]));
                $res['updated']++;
            } else {
                $row += ['qty' => 0, 'created_at' => now()];
                $row['active'] = 1;
                q('INSERT INTO stock_items (' . implode(',', array_keys($row)) . ') VALUES (' . rtrim(str_repeat('?,', count($row)), ',') . ')', array_values($row));
                $id = (int)$pdo->lastInsertId();
                $byName[$key($name)] = $id;
            }
            if ($kind === 'shirt' && $setQty && ($r['current stock quantity'] ?? '') !== '') {
                $cur = (float)q('SELECT qty FROM stock_items WHERE id = ?', [$id])->fetchColumn();
                stock_move($id, round(vyapar_num($r['current stock quantity']) - $cur, 2), 'adjust', null, 'Vyapar import');
            }
        }
        set_setting('ship_items', json_encode(array_values($ship), JSON_UNESCAPED_UNICODE));
        set_setting('import_unmatched', json_encode($unmatched, JSON_UNESCAPED_UNICODE));
        $res['not_in_catalog'] = count($unmatched);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $res;
}

/**
 * Party report → customers. Party names like "C1003" are customer IDs; "C1214-ZEROEARTH" is ID C1214 named ZEROEARTH;
 * other names ("Shop sale") are used as both. Empty values never wipe what is saved; the balance is refreshed.
 */
function import_vyapar_parties(array $rows): array
{
    $rows = rows_by_header($rows, 'name');
    $res = ['added' => 0, 'updated' => 0, 'skipped' => 0];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($rows as $r) {
            $party = trim(preg_replace('/\s+/', ' ', $r['name'] ?? ''));
            if ($party === '' || strcasecmp($party, 'total') === 0) {
                $res['skipped']++;
                continue;
            }
            $name = '';
            if (preg_match('/^(C?\d+)\s*[-–]\s*(.+)$/i', $party, $m)) {
                [$code, $name] = [strtoupper($m[1]), trim($m[2])];
            } elseif (preg_match('/^C\d+$/i', $party)) {
                $code = strtoupper($party);
            } else {
                $code = $party;
                $name = preg_match('/^\d+$/', $party) ? '' : $party;
            }
            $phone = trim($r['phone no.'] ?? $r['phone'] ?? '');
            if (strlen(preg_replace('/\D/', '', $phone)) < 10) {
                $phone = '';
            }
            $address = trim($r['address'] ?? '');
            $pin = preg_match('/\b(\d{6})\b/', $address, $m) ? $m[1] : '';
            $balance = vyapar_num($r['receivable balance'] ?? '0') - vyapar_num($r['payable balance'] ?? '0');
            $data = ['name' => mb_substr($name, 0, 150), 'phone' => mb_substr($phone, 0, 40), 'address' => $address, 'pincode' => $pin,
                'email' => mb_substr(trim($r['email'] ?? ''), 0, 150), 'gstin' => strtoupper(mb_substr(trim($r['gstin'] ?? ''), 0, 20))];
            $old = q('SELECT * FROM customers WHERE code = ?', [$code])->fetch();
            if ($old) {
                $set = array_filter($data, fn($v) => $v !== '') + ['balance' => $balance, 'updated_at' => now()];
                q('UPDATE customers SET ' . implode(', ', array_map(fn($k) => "$k = ?", array_keys($set))) . ' WHERE id = ?', array_merge(array_values($set), [$old['id']]));
                $res['updated']++;
            } else {
                $row = ['code' => mb_substr($code, 0, 80)] + $data + ['balance' => $balance, 'created_at' => now(), 'updated_at' => now()];
                q('INSERT INTO customers (' . implode(',', array_keys($row)) . ') VALUES (' . rtrim(str_repeat('?,', count($row)), ',') . ')', array_values($row));
                $res['added']++;
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $res;
}

/** The catalog in one go, for matching: GSMs, products (with price) and colours. */
function catalog_index(): array
{
    return [
        'gsms' => q('SELECT * FROM gsm_options ORDER BY sort, id')->fetchAll(),
        'products' => q('SELECT * FROM products ORDER BY active DESC, sort, id')->fetchAll(),
        'colors' => q('SELECT * FROM product_colors ORDER BY id')->fetchAll(),
    ];
}

/**
 * Catalog blank for a T-shirt (gsm, product, color, size): same GSM (240 counts as 250), the product of the same name
 * or the only one of the same style (oversized / regular / acid wash…), a colour of that product and one of its sizes.
 * Returns ['gsm' => label, 'product' => name, 'color' => name, 'size' => size, 'price' => catalog price|null] or null.
 */
function vyapar_catalog_match(array $t, array $idx): ?array
{
    $letters = fn(string $s) => preg_replace('/[^a-z]/', '', strtolower($s));
    if ($t['gsm'] === '' || $t['product'] === '' || $t['color'] === '' || $t['size'] === '') {
        return null;
    }
    $want = stock_match_key($t['gsm'], $t['product'], '', '');
    foreach ($idx['gsms'] as $g) {
        if (gsm_key($g['label']) !== $want['gsm']) {
            continue;
        }
        $inGsm = array_filter($idx['products'], fn($p) => (int)$p['gsm_id'] === (int)$g['id']);
        $exact = array_values(array_filter($inGsm, fn($p) => strcasecmp(trim($p['name']), trim($t['product'])) === 0));
        $same = array_values(array_filter($inGsm, fn($p) => $want['style'] !== '' && stock_match_key('', $p['name'], '', '')['style'] === $want['style']));
        $p = $exact[0] ?? (count($same) === 1 ? $same[0] : null);
        if (!$p) {
            continue;
        }
        $color = null;
        foreach ($idx['colors'] as $c) {
            if ((int)$c['product_id'] === (int)$p['id'] && $letters($c['name']) === $letters($t['color'])) {
                $color = $c['name'];
                break;
            }
        }
        $sizes = array_map('strtoupper', array_filter(array_map('trim', explode(',', (string)$p['sizes'])), 'strlen'));
        if ($color === null || ($sizes && !in_array(strtoupper($t['size']), $sizes, true))) {
            continue;
        }
        return ['gsm' => $g['label'], 'product' => $p['name'], 'color' => $color, 'size' => $t['size'], 'price' => $p['price'] !== null ? (float)$p['price'] : null];
    }
    return null;
}

/**
 * Stock → "Link with order catalog": put stock T-shirts on their catalog blank and catalog price (nothing is added to the catalog).
 */
function link_stock_to_catalog(): array
{
    $res = ['linked' => 0, 'not_found' => 0];
    $idx = catalog_index();
    foreach (q("SELECT * FROM stock_items WHERE track = 1 AND active = 1 AND category = 'Blank T-shirt'")->fetchAll() as $s) {
        $m = vyapar_catalog_match(['gsm' => $s['gsm'], 'product' => $s['product'], 'color' => $s['color'], 'size' => $s['size']], $idx);
        if (!$m) {
            $res['not_found']++;
            continue;
        }
        q('UPDATE stock_items SET gsm = ?, product = ?, color = ?, sale_price = ?, updated_at = ? WHERE id = ?',
            [$m['gsm'], $m['product'], $m['color'], $m['price'] ?? $s['sale_price'], now(), $s['id']]);
        $res['linked']++;
    }
    return $res;
}

/** T-shirts from the last import that are not in the catalog, grouped by GSM + product (for "add as new products"). */
function unmatched_groups(): array
{
    $groups = [];
    foreach (json_decode(setting('import_unmatched', '[]'), true) ?: [] as $i => $t) {
        $gk = gsm_key($t['gsm']);
        $k = $gk . '|' . strtolower($t['product']);
        $groups[$k] ??= ['gsm' => $gk !== '' ? $gk . ' GSM' : 'Other', 'product' => $t['product'] !== '' ? $t['product'] : 'T-shirt', 'colors' => [], 'sizes' => [], 'items' => [], 'prices' => []];
        $groups[$k]['items'][$i] = $t;
        if ($t['color'] !== '') {
            $groups[$k]['colors'][$t['color']] = true;
        }
        if ($t['size'] !== '') {
            $groups[$k]['sizes'][$t['size']] = true;
        }
        if ($t['vyapar_price'] > 0) {
            $groups[$k]['prices'][] = $t['vyapar_price'];
        }
    }
    return $groups;
}

/**
 * Add chosen not-in-catalog groups as new catalog products (GSM, product, colours, sizes, price) and put their T-shirts
 * in stock with the file's quantity. $pick = [group key => ['add' => 1, 'gsm' => label, 'product' => name, 'price' => ₹]].
 */
function add_unmatched_as_products(array $pick): array
{
    $res = ['products' => 0, 'shirts' => 0];
    $groups = unmatched_groups();
    $all = json_decode(setting('import_unmatched', '[]'), true) ?: [];
    $pdo = db();
    $pdo->beginTransaction();
    try {
        foreach ($groups as $k => $g) {
            $in = $pick[$k] ?? null;
            if (!$in || empty($in['add'])) {
                continue;
            }
            $gsmLabel = trim((string)($in['gsm'] ?? '')) ?: $g['gsm'];
            $name = trim((string)($in['product'] ?? '')) ?: $g['product'];
            $price = trim((string)($in['price'] ?? '')) === '' ? null : max(0, round((float)$in['price'], 2));
            $gsm = null;
            foreach (q('SELECT * FROM gsm_options')->fetchAll() as $row) {
                if (strcasecmp($row['label'], $gsmLabel) === 0 || (gsm_key($row['label']) !== '' && gsm_key($row['label']) === gsm_key($gsmLabel))) {
                    $gsm = $row;
                    break;
                }
            }
            if (!$gsm) {
                q('INSERT INTO gsm_options (label, sort) VALUES (?, ?)', [$gsmLabel, 50 + (int)preg_replace('/\D/', '', $gsmLabel)]);
                $gsm = ['id' => (int)$pdo->lastInsertId(), 'label' => $gsmLabel];
            }
            $order = ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'];
            $sizes = array_keys($g['sizes']);
            usort($sizes, fn($a, $b) => ((array_search(strtoupper($a), $order) === false) ? 99 : array_search(strtoupper($a), $order)) <=> ((array_search(strtoupper($b), $order) === false) ? 99 : array_search(strtoupper($b), $order)));
            $p = q('SELECT * FROM products WHERE gsm_id = ? AND name = ?', [$gsm['id'], $name])->fetch();
            if ($p) {
                $have = array_filter(array_map('trim', explode(',', (string)$p['sizes'])), 'strlen');
                q('UPDATE products SET sizes = ?, price = IFNULL(?, price), active = 1 WHERE id = ?', [implode(', ', array_unique(array_merge($have, $sizes))), $price, $p['id']]);
                $pid = (int)$p['id'];
            } else {
                q('INSERT INTO products (gsm_id, name, sizes, price) VALUES (?, ?, ?, ?)', [$gsm['id'], $name, implode(', ', $sizes), $price]);
                $pid = (int)$pdo->lastInsertId();
                $res['products']++;
            }
            foreach (array_keys($g['colors']) as $c) {
                if (!q('SELECT id FROM product_colors WHERE product_id = ? AND name = ?', [$pid, $c])->fetch()) {
                    q('INSERT INTO product_colors (product_id, name, hex, sort) VALUES (?, ?, ?, 50)', [$pid, $c, guess_color_hex($c)]);
                }
            }
            foreach ($g['items'] as $i => $t) {
                $row = ['name' => $t['name'], 'sku' => $t['sku'], 'category' => 'Blank T-shirt', 'gsm' => $gsm['label'], 'product' => $name, 'color' => $t['color'],
                    'size' => $t['size'], 'unit' => 'pcs', 'low_level' => $t['low_level'], 'cost_price' => $t['cost_price'], 'sale_price' => $price ?? 0,
                    'hsn' => $t['hsn'], 'gst_rate' => $t['gst_rate'], 'track' => 1, 'active' => 1];
                $id = q('SELECT id FROM stock_items WHERE name = ? LIMIT 1', [$t['name']])->fetchColumn();
                if ($id) {
                    q('UPDATE stock_items SET ' . implode(', ', array_map(fn($c) => "$c = ?", array_keys($row))) . ', updated_at = ? WHERE id = ?', array_merge(array_values($row), [now(), $id]));
                } else {
                    $row += ['qty' => 0, 'created_at' => now()];
                    q('INSERT INTO stock_items (' . implode(',', array_keys($row)) . ') VALUES (' . rtrim(str_repeat('?,', count($row)), ',') . ')', array_values($row));
                    $id = (int)$pdo->lastInsertId();
                }
                $cur = (float)q('SELECT qty FROM stock_items WHERE id = ?', [$id])->fetchColumn();
                stock_move((int)$id, round((float)$t['qty'] - $cur, 2), 'adjust', null, 'Vyapar import (new product)');
                unset($all[$i]);
                $res['shirts']++;
            }
        }
        set_setting('import_unmatched', json_encode(array_values($all), JSON_UNESCAPED_UNICODE));
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return $res;
}
