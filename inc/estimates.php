<?php
// Estimate products: what can be quoted, how each is cut (its pattern type) and the admin's making costs & margin.
// Stored as JSON in settings.estimate_products; the built-in list is used until an admin saves changes.
declare(strict_types=1);

/** Pattern types the calculator knows how to cut (pieces & size chart live in assets/estimate.js). */
function est_bases(): array
{
    return [
        'regular' => 'Regular fit T-shirt', 'oversized' => 'Oversized T-shirt', 'polo' => 'Polo T-shirt',
        'sweatshirt' => 'Sweatshirt', 'hoodie' => 'Hoodie', 'trackpants' => 'Track pants', 'joggers' => 'Joggers / shorts',
    ];
}

/** Every value a product carries, with the starting numbers. */
function est_product_template(): array
{
    return [
        // making costs (admin)
        'c_cmt' => 35, 'c_acc' => 0, 'acc_label' => 'Other accessories', 'c_print' => 0, 'c_embroidery' => 0, 'c_labels' => 3,
        'c_trims' => 2, 'c_finishing' => 3, 'c_packing' => 3, 'c_other' => 0, 'fixed' => 0, 'extra' => [], 'rib_g' => 12,
        // margin (admin only)
        'buffer' => 10, 'profit' => 40, 'gst' => 5,
        // starting fabric (staff can change on each estimate)
        'gsm' => 180, 'fabric_form' => 'open', 'roll_width' => 72, 'fabric_price' => 420, 'rib_price' => 450, 'wastage' => 5,
    ];
}

/** Keys of a product that are making costs, margin and fabric starting values. */
const EST_MAKING_KEYS = ['c_cmt', 'c_acc', 'acc_label', 'c_print', 'c_embroidery', 'c_labels', 'c_trims', 'c_finishing', 'c_packing', 'c_other', 'fixed', 'extra', 'rib_g'];
const EST_MARGIN_KEYS = ['buffer', 'profit', 'gst'];
const EST_FABRIC_KEYS = ['gsm', 'fabric_form', 'roll_width', 'fabric_price', 'rib_price', 'wastage'];

function est_builtin_products(): array
{
    $t = est_product_template();
    $p = fn(string $key, array $d) => ['key' => $key, 'name' => est_bases()[$key], 'base' => $key, 'active' => 1, 'defaults' => array_merge($t, $d)];
    $list = [
        $p('regular', ['c_cmt' => 35, 'rib_g' => 12, 'gsm' => 180]),
        $p('oversized', ['c_cmt' => 40, 'rib_g' => 15, 'gsm' => 220]),
        $p('polo', ['c_cmt' => 60, 'c_acc' => 25, 'acc_label' => 'Collar, cuffs & buttons', 'rib_g' => 0, 'gsm' => 220]),
        $p('sweatshirt', ['c_cmt' => 90, 'rib_g' => 50, 'gsm' => 300]),
        $p('hoodie', ['c_cmt' => 110, 'c_acc' => 10, 'acc_label' => 'Drawcord & eyelets', 'rib_g' => 45, 'gsm' => 320]),
        $p('trackpants', ['c_cmt' => 70, 'c_acc' => 15, 'acc_label' => 'Elastic & drawcord', 'rib_g' => 0, 'gsm' => 260]),
        $p('joggers', ['c_cmt' => 65, 'c_acc' => 15, 'acc_label' => 'Elastic & drawcord', 'rib_g' => 20, 'gsm' => 260]),
    ];
    // Rates an admin saved with the earlier "Save rates as default" button.
    $old = json_decode((string)setting('estimate_defaults', '{}'), true) ?: [];
    foreach ($list as &$prod) {
        if (!empty($old[$prod['key']]) && is_array($old[$prod['key']])) {
            $prod['defaults'] = array_merge($prod['defaults'], array_intersect_key($old[$prod['key']], $t));
        }
    }
    unset($prod);
    return $list;
}

/** All products (or only the ones staff can pick), each with every template key filled in. */
function est_products(bool $activeOnly = false): array
{
    static $cache = null;
    if ($cache === null) {
        $saved = json_decode((string)setting('estimate_products', ''), true);
        $cache = [];
        foreach (is_array($saved) && $saved ? $saved : est_builtin_products() as $p) {
            if (!is_array($p) || empty($p['key'])) {
                continue;
            }
            $p['base'] = isset(est_bases()[$p['base'] ?? '']) ? $p['base'] : 'regular';
            $p['name'] = trim((string)($p['name'] ?? '')) ?: est_bases()[$p['base']];
            $p['active'] = !empty($p['active']) ? 1 : 0;
            $p['defaults'] = array_merge(est_product_template(), is_array($p['defaults'] ?? null) ? $p['defaults'] : []);
            $cache[$p['key']] = $p;
        }
    }
    return $activeOnly ? array_filter($cache, fn($p) => $p['active']) : $cache;
}

function est_save_products(array $products): void
{
    set_setting('estimate_products', json_encode(array_values($products), JSON_UNESCAPED_UNICODE));
}

/** The product an estimate uses: its saved product, else a product cut like its pattern type. */
function est_product_for(array $data): ?array
{
    $all = est_products();
    if (!empty($data['product']) && isset($all[$data['product']])) {
        return $all[$data['product']];
    }
    foreach ($all as $p) {
        if ($p['base'] === ($data['style'] ?? 'regular')) {
            return $p;
        }
    }
    return reset($all) ?: null;
}
