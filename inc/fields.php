<?php
// The order fields. Each one gets its own Hidden / View / Edit permission per staff member.
declare(strict_types=1);

function core_fields(): array
{
    // scope: 'order' = once per order (the parcel), 'item' = per product line inside the order.
    return [
        'customer_id'  => ['label' => 'Customer ID',      'group' => 'Order',          'type' => 'text'],
        'customer_name' => ['label' => 'Customer name',   'group' => 'Order',          'type' => 'text'],
        'ship_name'    => ['label' => 'Ship-to name',   'group' => 'Shipping address', 'type' => 'text'],
        'ship_phone'   => ['label' => 'Phone',            'group' => 'Shipping address', 'type' => 'tel'],
        'ship_address' => ['label' => 'Address',          'group' => 'Shipping address', 'type' => 'textarea'],
        'ship_pincode' => ['label' => 'Pincode',          'group' => 'Shipping address', 'type' => 'pincode'],
        'order_ref'    => ['label' => 'Order reference (ORD-)', 'group' => 'Order', 'type' => 'text'],
        'sub_order_id' => ['label' => 'Sub-order ID',     'group' => 'Item',           'type' => 'text', 'scope' => 'item'],
        'gsm'          => ['label' => 'GSM',              'group' => 'Blank T-shirt',  'type' => 'gsm', 'scope' => 'item'],
        'product'      => ['label' => 'Product',          'group' => 'Blank T-shirt',  'type' => 'product', 'scope' => 'item'],
        'color'        => ['label' => 'Color',            'group' => 'Blank T-shirt',  'type' => 'color', 'scope' => 'item'],
        'size'         => ['label' => 'Size',             'group' => 'Blank T-shirt',  'type' => 'size', 'scope' => 'item'],
        'quantity'     => ['label' => 'Quantity',         'group' => 'Blank T-shirt',  'type' => 'number', 'scope' => 'item'],
        'mockups'      => ['label' => 'Mock-up images',   'group' => 'Print',          'type' => 'images', 'scope' => 'item'],
        'front_print'  => ['label' => 'Front print',      'group' => 'Print',          'type' => 'textarea', 'scope' => 'item'],
        'back_print'   => ['label' => 'Back print',       'group' => 'Print',          'type' => 'textarea', 'scope' => 'item'],
        'chest_print'  => ['label' => 'Chest print',      'group' => 'Print',          'type' => 'textarea', 'scope' => 'item'],
        'neck_label'   => ['label' => 'Neck label',       'group' => 'Print',          'type' => 'textarea', 'scope' => 'item'],
        'chest_logo'   => ['label' => 'Chest logo',       'group' => 'Print',          'type' => 'textarea', 'scope' => 'item'],
        'custom_print' => ['label' => 'Custom print',     'group' => 'Print',          'type' => 'textarea', 'scope' => 'item'],
        'notes'        => ['label' => 'Notes',            'group' => 'Order',          'type' => 'textarea'],
        'due_date'     => ['label' => 'Dispatch by',      'group' => 'Order',          'type' => 'date'],
        'print_processed' => ['label' => 'Print processed ✓', 'group' => 'Progress',    'type' => 'stage'],
        'printed'      => ['label' => 'Printed ✓ (each item)', 'group' => 'Progress',       'type' => 'stage', 'scope' => 'item'],
        'packed'       => ['label' => 'Packed ✓',         'group' => 'Progress',       'type' => 'stage'],
        'shipped'      => ['label' => 'Shipped ✓',        'group' => 'Progress',       'type' => 'stage'],
        'courier'      => ['label' => 'Delivery partner',          'group' => 'Shipping',       'type' => 'courier'],
        'tracking_no'  => ['label' => 'Tracking number',  'group' => 'Shipping',       'type' => 'text'],
        // Not form fields of their own: who may see / change prices on order items and the order's bill.
        'item_price'   => ['label' => 'Prices on items (T-shirt & printing)', 'group' => 'Prices & bill', 'type' => 'meta', 'scope' => 'item'],
        'bill'         => ['label' => 'Bill on the order (GSTIN, discount, shipping, payment)', 'group' => 'Prices & bill', 'type' => 'meta'],
    ];
}

/** Extra fields the admin added from Settings -> Custom fields. Stored as JSON in orders.extra or order_items.extra. */
function custom_fields(bool $activeOnly = true): array
{
    static $cache = [];
    $k = $activeOnly ? 1 : 0;
    if (!isset($cache[$k])) {
        $rows = q('SELECT * FROM custom_fields' . ($activeOnly ? ' WHERE active = 1' : '') . ' ORDER BY sort, id')->fetchAll();
        $cache[$k] = [];
        foreach ($rows as $r) {
            $cache[$k]['cf_' . $r['id']] = [
                'label' => $r['label'],
                'group' => $r['scope'] === 'order' ? 'Order' : 'Extra',
                'scope' => $r['scope'] === 'order' ? 'order' : 'item',
                'type' => $r['type'],
                'options' => array_values(array_filter(array_map('trim', explode(',', (string)$r['options'])), 'strlen')),
                'custom' => true,
            ];
        }
    }
    return $cache[$k];
}

function all_fields(): array
{
    return core_fields() + custom_fields();
}

/** Permission presets offered on the user screen. */
function permission_presets(): array
{
    $all = array_keys(all_fields());
    $view = array_fill_keys($all, 'view');
    $orderInfo = ['customer_id', 'customer_name', 'order_ref', 'sub_order_id', 'ship_name', 'ship_phone', 'ship_address', 'ship_pincode', 'gsm', 'product', 'color', 'size', 'quantity', 'mockups',
        'front_print', 'back_print', 'chest_print', 'neck_label', 'chest_logo', 'custom_print', 'notes'];
    foreach (custom_fields() as $k => $f) {
        $orderInfo[] = $k;
    }
    return [
        'order_creator' => ['label' => 'Order creator',
            'perms' => array_merge($view, array_fill_keys($orderInfo, 'edit')),
            'caps' => ['create' => 1, 'designs' => 1, 'slips' => 1]],
        'printer' => ['label' => 'Printer',
            'perms' => array_merge($view, ['print_processed' => 'edit', 'printed' => 'edit', 'courier' => 'none', 'tracking_no' => 'none']),
            'caps' => []],
        'packer' => ['label' => 'Packer / shipping',
            'perms' => array_merge($view, ['packed' => 'edit', 'shipped' => 'edit', 'courier' => 'edit', 'tracking_no' => 'edit', 'order_ref' => 'edit']),
            'caps' => ['slips' => 1]],
        'viewer' => ['label' => 'View only', 'perms' => $view, 'caps' => []],
    ];
}

function capability_labels(): array
{
    return [
        'create' => 'Create new orders',
        'delete' => 'Delete orders',
        'dashboard' => 'See dashboard & daily reports',
        'export' => 'Download orders as Excel/CSV',
        'slips' => 'Print & edit shipping labels / packing slips',
        'designs' => 'Create, edit & delete saved designs (products with mock-ups, reused when creating orders)',
        'customers' => 'Customers page: see order history, edit & delete customers',
        'cleanup' => 'Free up space: delete mock-up images of shipped orders (order details are kept)',
        'catalog' => 'Add / edit / delete catalog & options (products, colors, sizes, print options, couriers)',
        'estimate' => 'Production estimates: work out per-piece cost & quote for custom production',
        'stock' => 'Stock: add stock, adjust and see stock levels',
        'billing' => 'Bills: create invoices & proforma invoices, record payments, see the sales report',
        'accounts' => 'Expenses, profit & loss and balance sheet',
    ];
}

/** Cost groups on the Estimate page an admin can set per staff member (margin is always admin only). */
function estimate_fields(): array
{
    return [
        'est_fabric' => 'Fabric details & fabric cost (GSM, roll, ₹/kg, grams, cut pieces)',
        'est_making' => 'Making costs set by admin (stitching, printing, accessories…) — staff can never change them',
        'est_breakdown' => 'Cost breakdown (fabric, stitching, … line by line)',
        'est_cost' => 'Final cost per piece',
        'est_price' => 'Quote price, order total, GST & printing the quote',
    ];
}

/** What staff get for a group the admin has not set yet. */
const EST_PERM_DEFAULTS = ['fabric' => 'edit', 'making' => 'view', 'breakdown' => 'view', 'cost' => 'view', 'price' => 'view'];

/** Estimate cost group level for the current user: none, view or edit. Admins edit everything. */
function est_perm(string $group): string
{
    $u = current_user();
    if (!$u) {
        return 'none';
    }
    if (is_admin($u)) {
        return 'edit';
    }
    if ($group === 'margin') {
        return 'none'; // buffer & profit: admin only
    }
    $v = $u['perms']['est_' . $group] ?? (EST_PERM_DEFAULTS[$group] ?? 'view');
    $v = in_array($v, ['none', 'view', 'edit'], true) ? $v : 'view';
    return $group === 'making' && $v === 'edit' ? 'view' : $v; // making costs are set by the admin
}

function field_scope(string $key): string
{
    return all_fields()[$key]['scope'] ?? 'order';
}

/** Fields of one scope ('order' or 'item'), in display order. */
function scoped_fields(string $scope): array
{
    return array_filter(all_fields(), fn($f) => ($f['scope'] ?? 'order') === $scope);
}
