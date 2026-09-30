<?php
// The order fields. Each one gets its own Hidden / View / Edit permission per staff member.
declare(strict_types=1);

function core_fields(): array
{
    // scope: 'order' = once per order (the parcel), 'item' = per product line inside the order.
    return [
        'customer_id'  => ['label' => 'Customer ID',      'group' => 'Order',          'type' => 'text'],
        'ship_name'    => ['label' => 'Ship to (name)',   'group' => 'Shipping address', 'type' => 'text'],
        'ship_phone'   => ['label' => 'Phone',            'group' => 'Shipping address', 'type' => 'tel'],
        'ship_address' => ['label' => 'Address',          'group' => 'Shipping address', 'type' => 'textarea'],
        'ship_pincode' => ['label' => 'Pincode',          'group' => 'Shipping address', 'type' => 'pincode'],
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
        'custom_print' => ['label' => 'Custom print',     'group' => 'Print',          'type' => 'textarea', 'scope' => 'item'],
        'notes'        => ['label' => 'Notes',            'group' => 'Order',          'type' => 'textarea'],
        'due_date'     => ['label' => 'Dispatch by',      'group' => 'Order',          'type' => 'date'],
        'printed'      => ['label' => 'Printed ✓ (each item)', 'group' => 'Progress',       'type' => 'stage', 'scope' => 'item'],
        'packed'       => ['label' => 'Packed ✓',         'group' => 'Progress',       'type' => 'stage'],
        'shipped'      => ['label' => 'Shipped ✓',        'group' => 'Progress',       'type' => 'stage'],
        'courier'      => ['label' => 'Courier',          'group' => 'Shipping',       'type' => 'courier'],
        'tracking_no'  => ['label' => 'Tracking number',  'group' => 'Shipping',       'type' => 'text'],
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
    $orderInfo = ['customer_id', 'ship_name', 'ship_phone', 'ship_address', 'ship_pincode', 'gsm', 'product', 'color', 'size', 'quantity', 'mockups',
        'front_print', 'back_print', 'chest_print', 'neck_label', 'custom_print', 'notes'];
    foreach (custom_fields() as $k => $f) {
        $orderInfo[] = $k;
    }
    return [
        'order_creator' => ['label' => 'Order creator',
            'perms' => array_merge($view, array_fill_keys($orderInfo, 'edit')),
            'caps' => ['create' => 1]],
        'printer' => ['label' => 'Printer',
            'perms' => array_merge($view, ['printed' => 'edit', 'courier' => 'none', 'tracking_no' => 'none']),
            'caps' => []],
        'packer' => ['label' => 'Packer / shipping',
            'perms' => array_merge($view, ['packed' => 'edit', 'shipped' => 'edit', 'courier' => 'edit', 'tracking_no' => 'edit']),
            'caps' => []],
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
        'cleanup' => 'Free up space: delete mock-up images of shipped orders (order details are kept)',
        'catalog' => 'Add / edit catalog & options (products, colors, sizes, print options, couriers)',
    ];
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
