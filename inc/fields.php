<?php
// The order fields. Each one gets its own Hidden / View / Edit permission per staff member.
declare(strict_types=1);

function core_fields(): array
{
    return [
        'customer_id'  => ['label' => 'Customer ID',      'group' => 'Order',          'type' => 'text'],
        'gsm'          => ['label' => 'GSM',              'group' => 'Blank T-shirt',  'type' => 'gsm'],
        'product'      => ['label' => 'Product',          'group' => 'Blank T-shirt',  'type' => 'product'],
        'color'        => ['label' => 'Color',            'group' => 'Blank T-shirt',  'type' => 'color'],
        'size'         => ['label' => 'Size',             'group' => 'Blank T-shirt',  'type' => 'size'],
        'quantity'     => ['label' => 'Quantity',         'group' => 'Blank T-shirt',  'type' => 'number'],
        'mockups'      => ['label' => 'Mock-up images',   'group' => 'Print',          'type' => 'images'],
        'front_print'  => ['label' => 'Front print',      'group' => 'Print',          'type' => 'textarea'],
        'back_print'   => ['label' => 'Back print',       'group' => 'Print',          'type' => 'textarea'],
        'chest_print'  => ['label' => 'Chest print',      'group' => 'Print',          'type' => 'textarea'],
        'neck_label'   => ['label' => 'Neck label',       'group' => 'Print',          'type' => 'textarea'],
        'custom_print' => ['label' => 'Custom print',     'group' => 'Print',          'type' => 'textarea'],
        'notes'        => ['label' => 'Notes',            'group' => 'Order',          'type' => 'textarea'],
        'due_date'     => ['label' => 'Dispatch by',      'group' => 'Order',          'type' => 'date'],
        'printed'      => ['label' => 'Printed ✓',        'group' => 'Progress',       'type' => 'stage'],
        'packed'       => ['label' => 'Packed ✓',         'group' => 'Progress',       'type' => 'stage'],
        'shipped'      => ['label' => 'Shipped ✓',        'group' => 'Progress',       'type' => 'stage'],
        'courier'      => ['label' => 'Courier',          'group' => 'Shipping',       'type' => 'courier'],
        'tracking_no'  => ['label' => 'Tracking number',  'group' => 'Shipping',       'type' => 'text'],
    ];
}

/** Extra fields the admin added from Settings -> Custom fields. Stored in orders.extra as JSON. */
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
                'group' => 'Extra',
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
    $orderInfo = ['customer_id', 'gsm', 'product', 'color', 'size', 'quantity', 'mockups',
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
        'catalog' => 'Add / edit catalog & options (products, colors, sizes, print options, couriers)',
    ];
}
