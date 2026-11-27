<?php
// Partner imports their Shopify products (Shopify → Products → Export → CSV) as their products (portal → Products):
// one product per Shopify product, named as in Shopify (so their Shopify orders match it by name), with the T-shirt and
// the colour names matched to Looma's (e.g. "Chocolate" → Brown, kept for their orders). The Shopify pictures are
// downloaded afterwards, one by one, and stored small.
declare(strict_types=1);

require_once __DIR__ . '/partners.php';
require_once __DIR__ . '/shopify.php';
require_once __DIR__ . '/partner_studio.php';

const SHOP_IMPORT_MAX = 200;

/** Other words for colours (lower case) → the word to look for in Looma's colour names. */
const SHOP_COLOUR_WORDS = [
    'chocolate' => 'brown', 'coffee' => 'brown', 'mocha' => 'brown', 'rust' => 'brown', 'tan' => 'beige', 'sand' => 'beige', 'khaki' => 'beige',
    'cream' => 'beige', 'off white' => 'white', 'offwhite' => 'white', 'ivory' => 'white', 'jet black' => 'black', 'charcoal' => 'black',
    'navy' => 'navy blue', 'dark blue' => 'navy blue', 'royal' => 'royal blue', 'cobalt' => 'royal blue',
    'purple' => 'lavender', 'lilac' => 'lavender', 'violet' => 'lavender', 'maroon' => 'red', 'wine' => 'red', 'burgundy' => 'red',
    'olive' => 'green', 'bottle green' => 'green', 'forest' => 'green', 'grey' => 'grey', 'gray' => 'grey',
];

/**
 * Read a Shopify products export. Returns [products, error]; each product:
 * ['handle', 'title', 'hint' (type, tags, options — for guessing the T-shirt), 'colours' (Shopify colour values),
 *  'sizes', 'images' (first two picture URLs)].
 */
function shop_csv_products(string $file): array
{
    $fh = @fopen($file, 'r');
    if (!$fh) {
        return [[], 'The file could not be read.'];
    }
    $head = fgetcsv($fh, 0, ',', '"', '');
    if (!$head) {
        return [[], 'The file is empty.'];
    }
    $head = array_map(fn($h) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$h))), $head);
    $col = array_flip($head);
    if (!isset($col['handle'], $col['title'])) {
        return [[], 'This is not a Shopify products export: it needs the columns “Handle” and “Title”. In Shopify: Products → Export → CSV.'];
    }
    $get = fn(array $r, string $k) => isset($col[$k]) ? trim((string)($r[$col[$k]] ?? '')) : '';
    $out = [];
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        $h = $get($r, 'handle');
        if ($h === '') {
            continue;
        }
        if (!isset($out[$h])) {
            if (count($out) >= SHOP_IMPORT_MAX) {
                break;
            }
            $out[$h] = ['handle' => $h, 'title' => '', 'hint' => '', 'colours' => [], 'sizes' => [], 'images' => [], 'opt' => []];
        }
        $p = &$out[$h];
        if ($p['title'] === '' && $get($r, 'title') !== '') {
            $p['title'] = mb_substr($get($r, 'title'), 0, 150);
            $p['hint'] = mb_substr(implode(' ', [$get($r, 'title'), $get($r, 'type'), $get($r, 'tags'), $get($r, 'product category')]), 0, 1000);
            for ($i = 1; $i <= 3; $i++) {
                $p['opt'][$i] = strtolower($get($r, "option{$i} name"));
            }
        }
        for ($i = 1; $i <= 3; $i++) {
            $v = $get($r, "option{$i} value");
            $n = $p['opt'][$i] ?? '';
            if ($v === '' || $n === '') {
                continue;
            }
            if (preg_match('/colou?r|shade/', $n) && !in_array($v, $p['colours'], true)) {
                $p['colours'][] = mb_substr($v, 0, 60);
            } elseif (str_contains($n, 'size') && !in_array($v, $p['sizes'], true)) {
                $p['sizes'][] = mb_substr($v, 0, 20);
            } elseif (preg_match('/gsm|fit|style|material|type/', $n)) {
                $p['hint'] .= ' ' . $v;
            }
        }
        foreach (['image src', 'variant image'] as $k) {
            $u = $get($r, $k);
            if ($u !== '' && count($p['images']) < 2 && !in_array($u, $p['images'], true) && shop_image_url_ok($u)) {
                $p['images'][] = $u;
            }
        }
        unset($p);
    }
    fclose($fh);
    $out = array_values(array_filter($out, fn($p) => $p['title'] !== ''));
    foreach ($out as &$p) {
        unset($p['opt']);
    }
    return [$out, $out ? null : 'No products found in the file.'];
}

/** Only pictures from Shopify's own servers are fetched (a test address can be allowed with LOOMA_SHOP_IMG_TEST). */
function shop_image_url_ok(string $url): bool
{
    $u = parse_url($url);
    $host = strtolower((string)($u['host'] ?? ''));
    $test = (string)getenv('LOOMA_SHOP_IMG_TEST');
    if ($test !== '' && str_starts_with($url, rtrim($test, '/') . '/')) {
        return true;
    }
    return ($u['scheme'] ?? '') === 'https' && ($host === 'cdn.shopify.com' || str_ends_with($host, '.shopify.com') || str_ends_with($host, '.myshopify.com') || $host === 'cdn.shopifycdn.net');
}

/** Looma T-shirt that fits a Shopify product best ("GSM|name"), from words in its title, type, tags and options; '' = not sure. */
function shop_guess_tshirt(string $hint): string
{
    $t = strtolower($hint);
    $gsm = preg_match('/(\d{3})\s*-?\s*gsm/', $t, $m) ? (int)$m[1] : 0;
    $want = [
        'acid' => str_contains($t, 'acid'), 'sleeve' => (bool)preg_match('/full\s*-?\s*sleeve|long\s*sleeve/', $t),
        'regular' => (bool)preg_match('/regular|classic fit|normal fit/', $t), 'terry' => (bool)preg_match('/terry|loop\s*knit|heavy\s*weight/', $t),
        'oversized' => (bool)preg_match('/over\s*-?\s*sized?|drop\s*shoulder|boxy/', $t),
    ];
    $best = '';
    $bestScore = 0;
    foreach (catalog() as $g) {
        $pg = (int)preg_replace('/\D+/', '', $g['gsm']);
        foreach ($g['products'] as $pr) {
            $n = strtolower($pr['name']);
            $is = ['acid' => str_contains($n, 'acid'), 'sleeve' => (bool)preg_match('/full\s*-?\s*sleeve/', $n), 'regular' => str_contains($n, 'regular'),
                'terry' => str_contains($n, 'terry'), 'oversized' => str_contains($n, 'oversized')];
            $score = 0;
            if ($gsm) {
                $score += abs($gsm - $pg) <= 10 ? 4 : ($gsm >= 245 && $pg >= 245 ? 2 : -2);
            }
            foreach ($want as $k => $w) {
                if ($w && $is[$k]) {
                    $score += 3;
                } elseif ($w !== $is[$k] && in_array($k, ['acid', 'sleeve', 'regular'], true)) {
                    $score -= 2; // an acid wash / full sleeve / regular fit T-shirt is a different product
                }
            }
            if ($score > $bestScore) {
                [$best, $bestScore] = [$g['gsm'] . '|' . $pr['name'], $score];
            }
        }
    }
    return $best;
}

/** Looma colour (of that T-shirt) for a Shopify colour value: same name, a close spelling, another word for it; '' = not sure. */
function shop_guess_colour(string $value, string $tshirt): string
{
    $names = [];
    foreach (catalog() as $g) {
        foreach ($g['products'] as $pr) {
            if ($g['gsm'] . '|' . $pr['name'] === $tshirt) {
                $names = array_column($pr['colors'], 'name');
            }
        }
    }
    if (!$names || trim($value) === '') {
        return '';
    }
    if ($c = shop_match($value, $names)) {
        return $c;
    }
    $v = strtolower(trim(preg_replace('/\s+/', ' ', $value)));
    foreach (SHOP_COLOUR_WORDS as $word => $to) {
        if ($v === $word || str_contains($v, $word)) {
            foreach ($names as $n) {
                if (strtolower($n) === $to) {
                    return $n;
                }
            }
            foreach ($names as $n) {
                if (str_contains(strtolower($n), $to)) {
                    return $n;
                }
            }
        }
    }
    foreach ($names as $n) { // "Black Melange" → Black
        if (preg_match('/\b' . preg_quote(strtolower($n), '/') . '\b/', $v)) {
            return $n;
        }
    }
    return '';
}

/**
 * Create the chosen products. $rows: handle => ['on' => 1, 'tshirt' => 'GSM|name', 'colour' => [Shopify value => Looma colour]].
 * Returns [created count, skipped titles (already there), errors].
 */
function shop_import_save(array $pu, array $products, array $rows): array
{
    $p = $pu['partner'];
    $by = $pu['name'] ?: $pu['username'];
    $made = 0;
    $skipped = $errors = [];
    $cat = [];
    foreach (catalog() as $g) {
        foreach ($g['products'] as $pr) {
            $cat[$g['gsm'] . '|' . $pr['name']] = array_column($pr['colors'], 'name');
        }
    }
    foreach ($products as $sp) {
        $r = $rows[$sp['handle']] ?? null;
        if (!$r || empty($r['on'])) {
            continue;
        }
        $tshirt = (string)($r['tshirt'] ?? '');
        if (!isset($cat[$tshirt])) {
            $errors[] = '“' . $sp['title'] . '”: choose the T-shirt.';
            continue;
        }
        $map = [];
        $first = '';
        foreach ($sp['colours'] ?: [''] as $sc) {
            $c = (string)(($r['colour'] ?? [])[$sc === '' ? '_' : $sc] ?? '');
            if (!in_array($c, $cat[$tshirt], true)) {
                $errors[] = '“' . $sp['title'] . '”: choose our colour for ' . ($sc !== '' ? '“' . $sc . '”' : 'it') . '.';
                continue 2;
            }
            $first = $first !== '' ? $first : $c;
            if ($sc !== '') {
                $map[mb_strtolower($sc)] = $c;
            }
        }
        if (q('SELECT 1 FROM design_customers dc JOIN designs d ON d.id = dc.design_id WHERE dc.customer_code = ? AND d.active = 1 AND d.name = ?', [$p['code'], $sp['title']])->fetchColumn()) {
            $skipped[] = $sp['title'];
            continue;
        }
        [$gsm, $prod] = explode('|', $tshirt, 2);
        $extra = ['partner' => $p['code'], 'partner_new' => 1, 'partner_by' => $by, 'print_link' => '', 'partner_note' => '',
            'shop_handle' => $sp['handle'], 'shop_colours' => $map, 'shop_images' => $sp['images'], 'colours' => array_values(array_unique(array_values($map) ?: [$first]))];
        q('INSERT INTO designs (name, code, gsm, product, color, extra, active, created_at) VALUES (?, ?, ?, ?, ?, ?, 1, ?)',
            [$sp['title'], '', $gsm, $prod, $first, json_encode($extra, JSON_UNESCAPED_UNICODE), now()]);
        $id = (int)db()->lastInsertId();
        q('INSERT IGNORE INTO design_customers (design_id, customer_code, created_at) VALUES (?, ?, ?)', [$id, $p['code'], now()]);
        $made++;
    }
    return [$made, $skipped, $errors];
}

/** Products still waiting for their Shopify pictures (ids). */
function shop_import_pending(array $p): array
{
    $ids = [];
    foreach (partner_designs($p) as $d) {
        if (!empty($d['extra']['shop_images']) && !$d['images']) {
            $ids[] = (int)$d['id'];
        }
    }
    return $ids;
}

/** Download one imported product's Shopify pictures and store them small (1200px WebP). Returns null or why not. */
function shop_import_fetch_images(array $pu, int $designId): ?string
{
    $d = partner_design($pu['partner'], $designId);
    if (!$d || empty($d['extra']['shop_images'])) {
        return 'Nothing to fetch.';
    }
    $got = 0;
    foreach ((array)$d['extra']['shop_images'] as $i => $url) {
        if (!shop_image_url_ok((string)$url) || !function_exists('curl_init')) {
            continue;
        }
        $tmp = tempnam(sys_get_temp_dir(), 'si');
        $fh = fopen($tmp, 'w');
        $ch = curl_init((string)$url);
        curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | (getenv('LOOMA_SHOP_IMG_TEST') ? CURLPROTO_HTTP : 0), CURLOPT_USERAGENT => 'Looma/1.0',
            CURLOPT_NOPROGRESS => false, CURLOPT_PROGRESSFUNCTION => fn($c, $dt, $dn) => $dn > 10 * 1024 * 1024 ? 1 : 0]);
        $ok = curl_exec($ch) && curl_getinfo($ch, CURLINFO_RESPONSE_CODE) === 200;
        curl_close($ch);
        fclose($fh);
        $mime = $ok ? (new finfo(FILEINFO_MIME_TYPE))->file($tmp) : '';
        $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$mime] ?? null;
        if ($ext && @getimagesize($tmp) !== false) {
            $sub = date('Y/m');
            $dir = upload_dir() . '/' . $sub;
            if (is_dir($dir) || mkdir($dir, 0755, true)) {
                $name = store_image($tmp, $mime, $dir . '/' . bin2hex(random_bytes(12)), $ext, PARTNER_MOCK_PX, true);
                if ($name) {
                    q('INSERT INTO design_images (design_id, filename, original_name, created_at) VALUES (?, ?, ?, ?)', [$designId, $sub . '/' . $name, 'shopify-' . ($i + 1) . '.webp', now()]);
                    $got++;
                }
            }
        }
        @unlink($tmp);
    }
    $x = $d['extra'];
    unset($x['shop_images']); // tried once; a missing picture can be added with Edit
    q('UPDATE designs SET extra = ? WHERE id = ?', [json_encode($x, JSON_UNESCAPED_UNICODE), $designId]);
    return $got ? null : 'The Shopify picture could not be downloaded — add a mock-up with Edit.';
}
