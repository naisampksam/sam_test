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
                $row = vyapar_parse_shirt($name) + $row + ['category' => 'Blank T-shirt', 'low_level' => max(0, vyapar_num($r['minimum stock quantity'] ?? '0'))];
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
                $row += ['qty' => 0, 'active' => 1, 'created_at' => now()];
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
        $res['catalog'] = sync_stock_with_catalog();
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

/**
 * Link stock T-shirts with the order form's catalog (GSM → product → colour / size), so picking a blank on an order
 * finds its stock, price and GST. A stock item joins the catalog product of the same GSM and style (e.g. Vyapar
 * "REGULAR FIT 190 BLACK -M" → "190 GSM · Regular Fit - Single Jersey · Black · M"); missing GSMs, products,
 * colours and sizes are added to the catalog. The stock item keeps its own (Vyapar) name.
 */
function sync_stock_with_catalog(): array
{
    $res = ['linked' => 0, 'products_added' => 0, 'colors_added' => 0];
    $digits = fn(string $s) => preg_replace('/\D/', '', $s);
    $letters = fn(string $s) => preg_replace('/[^a-z]/', '', strtolower($s));
    $gsms = q('SELECT * FROM gsm_options ORDER BY sort, id')->fetchAll();
    $products = q('SELECT * FROM products ORDER BY active DESC, sort, id')->fetchAll();
    $colors = q('SELECT * FROM product_colors ORDER BY id')->fetchAll();
    $pdo = db();
    foreach (q("SELECT * FROM stock_items WHERE track = 1 AND active = 1 AND category = 'Blank T-shirt' AND product <> ''")->fetchAll() as $s) {
        // GSM
        $g = null;
        foreach ($gsms as $row) {
            if ($s['gsm'] === '' ? strcasecmp($row['label'], 'Other') === 0 : $digits($row['label']) === $digits($s['gsm'])) {
                $g = $row;
                break;
            }
        }
        if (!$g) {
            $label = $s['gsm'] === '' ? 'Other' : $digits($s['gsm']) . ' GSM';
            q('INSERT INTO gsm_options (label, sort) VALUES (?, ?)', [$label, 50 + (int)$digits($label)]);
            $g = ['id' => (int)$pdo->lastInsertId(), 'label' => $label];
            $gsms[] = $g;
        }
        // Product: same name, else the only one of the same style in this GSM, else a new one.
        $style = stock_match_key('', $s['product'], '', '')['style'];
        $same = $exact = null;
        $sameCount = 0;
        foreach ($products as $p) {
            if ((int)$p['gsm_id'] !== (int)$g['id']) {
                continue;
            }
            if (strcasecmp(trim($p['name']), trim($s['product'])) === 0) {
                $exact = $p;
            }
            if ($style !== '' && stock_match_key('', $p['name'], '', '')['style'] === $style) {
                $same = $same ?? $p;
                $sameCount++;
            }
        }
        $p = $exact ?? ($sameCount === 1 ? $same : null);
        if (!$p) {
            q('INSERT INTO products (gsm_id, name, sizes) VALUES (?, ?, ?)', [$g['id'], $s['product'], $s['size']]);
            $p = ['id' => (int)$pdo->lastInsertId(), 'gsm_id' => $g['id'], 'name' => $s['product'], 'sizes' => $s['size']];
            $products[] = $p;
            $res['products_added']++;
        }
        // Size
        $sizes = array_values(array_filter(array_map('trim', explode(',', (string)$p['sizes'])), 'strlen'));
        if ($s['size'] !== '' && !in_array(strtoupper($s['size']), array_map('strtoupper', $sizes), true)) {
            $sizes[] = $s['size'];
            $order = ['XS', 'S', 'M', 'L', 'XL', 'XXL', 'XXXL'];
            usort($sizes, fn($a, $b) => (array_search(strtoupper($a), $order) === false ? 99 : array_search(strtoupper($a), $order)) <=> (array_search(strtoupper($b), $order) === false ? 99 : array_search(strtoupper($b), $order)));
            q('UPDATE products SET sizes = ? WHERE id = ?', [implode(', ', $sizes), $p['id']]);
            foreach ($products as &$pp) {
                if ((int)$pp['id'] === (int)$p['id']) {
                    $pp['sizes'] = implode(', ', $sizes);
                }
            }
            unset($pp);
        }
        // Colour
        $color = $s['color'];
        if ($color !== '') {
            $c = null;
            foreach ($colors as $row) {
                if ((int)$row['product_id'] === (int)$p['id'] && $letters($row['name']) === $letters($color)) {
                    $c = $row;
                    break;
                }
            }
            if (!$c) {
                q('INSERT INTO product_colors (product_id, name, hex, sort) VALUES (?, ?, ?, 99)', [$p['id'], $color, guess_color_hex($color)]);
                $c = ['id' => (int)$pdo->lastInsertId(), 'product_id' => $p['id'], 'name' => $color];
                $colors[] = $c;
                $res['colors_added']++;
            }
            $color = $c['name'];
        }
        if ($s['gsm'] !== $g['label'] || $s['product'] !== $p['name'] || $s['color'] !== $color) {
            q('UPDATE stock_items SET gsm = ?, product = ?, color = ?, updated_at = ? WHERE id = ?', [$g['label'], $p['name'], $color, now(), $s['id']]);
        }
        $res['linked']++;
    }
    return $res;
}
