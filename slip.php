<?php
// Shipping label, 75 mm × 125 mm. One order (?id=) or several (?ids=1,2,3).
// Every field on the label can be changed at print time; "Save & print" also stores the changes on the order.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/orders.php';
require __DIR__ . '/inc/billing.php';
require __DIR__ . '/inc/label.php';

require_login();
if (!cap('slips')) {
    http_response_code(403);
    exit('You are not allowed to print shipping labels.');
}

$ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? $_GET['id'] ?? ''))))));
if (!$ids) {
    redirect('orders.php');
}
$ids = array_slice($ids, 0, 200);


$brandDefault = (string)setting('slip_brand', setting('company_name', 'Looma Apparels'));

/** Name under "Return to" when the label has none of its own: the order's customer name, else the shop name. */
function return_name(array $o, string $brandDefault): string
{
    return trim((string)$o['customer_name']) !== '' ? trim((string)$o['customer_name']) : $brandDefault;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $saved = 0;
    foreach ((array)($_POST['f'] ?? []) as $oid => $vals) {
        $oid = (int)$oid;
        if (!in_array($oid, $ids, true) || !is_array($vals) || !($old = get_order($oid))) {
            continue;
        }
        $changes = [];
        foreach (LABEL_FIELDS as $k => [$col]) {
            if ($col === null || !array_key_exists($k, $vals)) {
                continue;
            }
            [$v] = label_clean($k, $vals[$k]);
            if ($k === 'slip_brand' && $v === return_name($old, $brandDefault)) {
                $v = ''; // left as suggested: keep following the customer name
            }
            if ((string)$old[$col] !== (string)$v) {
                $changes[$col] = $v;
                log_change($oid, $col, $old[$col], (string)$v === '' ? '(empty)' : $v);
            }
        }
        if ($changes) {
            update_row('orders', $oid, $changes + ['updated_at' => now(), 'updated_by' => current_user()['id']]);
            remember_customer($oid);
            $saved++;
        }
    }
    flash($saved ? 'Label details saved.' : 'No changes to save.');
    redirect('slip.php?ids=' . implode(',', $ids) . (!empty($_POST['print']) ? '&print=1' : ''));
}

$in = implode(',', $ids);
$orders = q("SELECT * FROM orders WHERE id IN ($in) AND deleted_at IS NULL ORDER BY FIELD(id, $in)")->fetchAll();
$itemsBy = [];
foreach (q("SELECT * FROM order_items WHERE order_id IN ($in) ORDER BY sort, id")->fetchAll() as $it) {
    $itemsBy[$it['order_id']][] = $it;
}
$defaults = label_defaults();
$suggest = [
    'courier' => couriers(),
    'slip_brand' => array_values(array_unique(array_filter(array_merge([$defaults['slip_brand']],
        array_column(q("SELECT DISTINCT slip_brand FROM orders WHERE slip_brand <> '' ORDER BY slip_brand LIMIT 50")->fetchAll(), 'slip_brand'))))),
];
$f = flash();
label_head('Shipping label' . (count($orders) === 1 ? ' ' . order_no($orders[0]['id']) : 's (' . count($orders) . ')'));
?>
<body class="slip-page">
<form method="post" id="labelForm">
<?= csrf_field() ?>
<div class="slip-top">
  <a class="back" href="<?= count($orders) === 1 ? 'order.php?id=' . (int)$orders[0]['id'] : 'orders.php?tab=pack' ?>">← Back</a>
  <h1>Shipping label<?= count($orders) > 1 ? 's · ' . count($orders) . ' orders' : '' ?></h1>
  <?php if ($f): ?><p class="alert <?= h($f['type']) ?>" style="margin-top:10px"><?= h($f['msg']) ?></p><?php endif; ?>
  <?php if (!$orders): ?><p class="alert err">No orders found.</p><?php endif; ?>
  <div class="slip-bar">
    <button class="btn primary" name="print" value="1">💾 Save &amp; print</button>
    <button class="btn" name="save" value="1">Save only</button>
    <button class="btn ghost" type="button" onclick="window.print()">🖨 Print without saving</button>
    <div class="opts">
      <label><input type="checkbox" data-opt="items" checked> Contents</label>
    </div>
  </div>
  <p class="muted small">Courier list is managed in More → Order form options → Couriers. Printer: paper 75 × 125 mm (or label 3×5″), margins <b>none</b>, scale <b>100%</b>. Changes show on the label as you type.</p>
</div>

<datalist id="dl_slip_brand"><?php foreach ($suggest['slip_brand'] as $c): ?><option value="<?= h($c) ?>"><?php endforeach; ?></datalist>

<div class="label-blocks">
<?php foreach ($orders as $o):
    $oid = (int)$o['id'];
    $v = [];
    foreach (LABEL_FIELDS as $k => [$col]) {
        $v[$k] = $col === null ? '' : (string)($o[$col] ?? '');
        if ($k === 'slip_brand' && $v[$k] === $brandDefault) {
            $v[$k] = ''; // saved before customer names existed: follow the customer name now
        }
        if ($v[$k] === '' && isset($defaults[$k])) {
            $v[$k] = $k === 'slip_brand' ? return_name($o, $brandDefault) : (string)$defaults[$k];
        }
    }
    $cno = customer_order_no($o);
    $its = $itemsBy[$oid] ?? [];
    $v['contents'] = label_contents($its);
?>
  <div class="label-block" data-label>
    <div class="label-form">
      <h2><span><?= h(order_no($oid)) ?> <small class="muted"><?= h($cno) ?><?= $o['customer_name'] !== '' ? ' · ' . h($o['customer_name']) : '' ?></small></span></h2>
      <?php label_form_fields('f[' . $oid . ']', $v, $suggest['courier'], 'dl_slip_brand'); ?>
    </div>

    <div class="label-preview">
      <?php label_card($v, ($cno !== '' ? '<b class="s-cno">' . h($cno) . '</b>' : '') . '<span class="s-ref">ORD- <b data-show="order_ref">' . h($v['order_ref']) . '</b></span>' . h(order_no($oid))); ?>
    </div>
  </div>
<?php endforeach; ?>
</div>
</form>

<?php label_script(!empty($_GET['print'])); ?>
</body>
</html>
