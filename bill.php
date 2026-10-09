<?php
// One bill: new (?new=1&type=invoice|proforma[&order=ID]), view (?id=), edit (?id=&edit=1).
// Tax invoices take stock when saved; proforma invoices don't until they are converted.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/orders.php';
require __DIR__ . '/inc/billing.php';

require_login();
if (!cap('billing')) {
    http_response_code(403);
    exit('You are not allowed to make bills.');
}
$id = (int)($_GET['id'] ?? 0);
$bill = $id ? get_bill($id) : null;
if ($id && !$bill) {
    flash('Bill not found.', 'err');
    redirect('bills.php');
}
$type = $bill ? $bill['type'] : (($_GET['type'] ?? '') === 'proforma' ? 'proforma' : 'invoice');
$editing = !$bill || !empty($_GET['edit']);
$num = fn($v) => round((float)str_replace(',', '.', (string)$v), 2);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    if ($do === 'save') {
        $branding = ($_POST['branding'] ?? '') === 'plain' ? 'plain' : 'looma';
        $head = [
            'bill_date' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['bill_date'] ?? '') ? $_POST['bill_date'] : today(),
            'order_id' => (int)($_POST['order_id'] ?? 0) ?: null,
            'customer_code' => mb_substr(trim((string)($_POST['customer_code'] ?? '')), 0, 80),
            'bill_name' => mb_substr(trim((string)($_POST['bill_name'] ?? '')), 0, 150),
            'bill_phone' => mb_substr(trim((string)($_POST['bill_phone'] ?? '')), 0, 40),
            'bill_address' => mb_substr(trim((string)($_POST['bill_address'] ?? '')), 0, 1000),
            'bill_pincode' => mb_substr(trim((string)($_POST['bill_pincode'] ?? '')), 0, 12),
            'bill_gstin' => strtoupper(mb_substr(trim((string)($_POST['bill_gstin'] ?? '')), 0, 20)),
            'bill_state' => mb_substr(trim((string)($_POST['bill_state'] ?? '')), 0, 60),
            'inter_state' => !empty($_POST['inter_state']) ? 1 : 0,
            'branding' => $branding,
            'discount' => max(0, $num($_POST['discount'] ?? 0)),
            'shipping' => max(0, $num($_POST['shipping'] ?? 0)),
            'notes' => mb_substr(trim((string)($_POST['notes'] ?? '')), 0, 2000),
        ];
        // Seller details are kept on the bill, so old bills print the same even if Settings change later.
        if ($branding === 'looma') {
            $head += ['seller_name' => setting('inv_seller_name', setting('company_name', 'Looma Apparels')), 'seller_address' => setting('inv_seller_address', ''),
                'seller_phone' => setting('inv_seller_phone', ''), 'seller_gstin' => setting('inv_gstin', '')];
        } else {
            $head += ['seller_name' => mb_substr(trim((string)($_POST['seller_name'] ?? '')), 0, 150), 'seller_address' => mb_substr(trim((string)($_POST['seller_address'] ?? '')), 0, 1000),
                'seller_phone' => mb_substr(trim((string)($_POST['seller_phone'] ?? '')), 0, 40), 'seller_gstin' => strtoupper(mb_substr(trim((string)($_POST['seller_gstin'] ?? '')), 0, 20))];
        }
        $lines = [];
        foreach ((array)($_POST['lines'] ?? []) as $l) {
            if (!is_array($l) || (trim((string)($l['description'] ?? '')) === '' && (float)($l['rate'] ?? 0) == 0)) {
                continue;
            }
            $sid = (int)($l['stock_id'] ?? 0);
            $st = $sid ? stock_get($sid) : null;
            // Only goods that are counted take stock; printing and other services never do.
            $lines[] = ['stock_id' => $st && $st['track'] ? $sid : null, 'description' => trim((string)$l['description']) ?: 'Item',
                'hsn' => trim((string)($l['hsn'] ?? '')), 'qty' => max(0, $num($l['qty'] ?? 1)), 'unit' => trim((string)($l['unit'] ?? 'pcs')) ?: 'pcs',
                'rate' => max(0, $num($l['rate'] ?? 0)), 'gst_rate' => max(0, $num($l['gst_rate'] ?? 0))];
        }
        if (!$lines) {
            flash('Add at least one line to the bill.', 'err');
            redirect($bill ? 'bill.php?id=' . $id . '&edit=1' : 'bill.php?new=1&type=' . $type);
        }
        if ($bill && $bill['status'] === 'cancelled') {
            flash('This bill is cancelled. Restore it before editing.', 'err');
            redirect('bill.php?id=' . $id);
        }
        $newId = save_bill($bill ? $id : null, $type, $head, $lines);
        $madeOrder = null;
        if (!$head['order_id'] && !empty($_POST['make_order'])) {
            $madeOrder = order_from_bill($newId);
        }
        $b = get_bill($newId);
        $short = [];
        if (bill_takes_stock($b)) {
            foreach (bill_items($newId) as $l) {
                if ($l['stock_id'] && ($st = stock_get((int)$l['stock_id'])) && (float)$st['qty'] < 0) {
                    $short[] = stock_name($st) . ' (' . qty_fmt($st['qty']) . ')';
                }
            }
        }
        flash(BILL_TYPES[$type] . ' ' . $b['number'] . ' saved.' . ($madeOrder ? ' Order ' . (customer_order_no(get_order($madeOrder)) ?: order_no($madeOrder)) . ' was made for it.' : '') . ($short ? "\nStock is now below zero for: " . implode(', ', $short) . '. Add stock when it arrives.' : ''), $short ? 'err' : 'ok');
        redirect('bill.php?id=' . $newId);
    }
    if (!$bill) {
        redirect('bills.php');
    }
    if ($do === 'pay') {
        $amt = $num($_POST['amount'] ?? 0);
        if ($amt > 0) {
            q('INSERT INTO bill_payments (bill_id, amount, mode, paid_on, note, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [
                $id, $amt, in_array($_POST['mode'] ?? '', PAY_MODES, true) ? $_POST['mode'] : 'Other',
                preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['paid_on'] ?? '') ? $_POST['paid_on'] : today(),
                mb_substr(trim((string)($_POST['note'] ?? '')), 0, 250), current_user()['id'], now()]);
            bill_recount_paid($id);
            flash('Payment of ' . money($amt) . ' recorded.');
        }
    } elseif ($do === 'del_pay') {
        q('DELETE FROM bill_payments WHERE id = ? AND bill_id = ?', [(int)($_POST['pay_id'] ?? 0), $id]);
        bill_recount_paid($id);
        flash('Payment removed.');
    } elseif ($do === 'make_order' && !$bill['order_id']) {
        $o = order_from_bill($id);
        flash($o ? 'Order ' . (customer_order_no(get_order($o)) ?: order_no($o)) . ' made from this bill.' : 'This bill has no items for an order.', $o ? 'ok' : 'err');
        if ($o) {
            redirect('order.php?id=' . $o);
        }
    } elseif ($do === 'cancel') {
        bill_set_status($id, 'cancelled');
        flash($bill['number'] . ' cancelled.' . (bill_takes_stock($bill) ? ' Its stock was put back.' : ''));
    } elseif ($do === 'restore') {
        bill_set_status($id, 'final');
        flash($bill['number'] . ' restored.' . ($bill['type'] === 'invoice' ? ' Its stock was taken again.' : ''));
    } elseif ($do === 'convert') {
        $new = convert_proforma($id);
        if ($new) {
            if (!$bill['order_id'] && !empty($_POST['make_order'])) {
                order_from_bill($new);
            }
            flash('Tax invoice ' . get_bill($new)['number'] . ' made from ' . $bill['number'] . '. Stock was taken.');
            redirect('bill.php?id=' . $new);
        }
    } elseif ($do === 'delete' && (is_admin() || $bill['type'] === 'proforma')) {
        $pdo = db();
        $pdo->beginTransaction();
        bill_restore_stock($bill, 'Deleted');
        q('DELETE FROM bill_items WHERE bill_id = ?', [$id]);
        q('DELETE FROM bill_payments WHERE bill_id = ?', [$id]);
        q('DELETE FROM bills WHERE id = ?', [$id]);
        q('UPDATE bills SET converted_to = NULL WHERE converted_to = ?', [$id]);
        $pdo->commit();
        flash($bill['number'] . ' deleted.');
        redirect('bills.php');
    }
    redirect('bill.php?id=' . $id);
}

// ---------------------------------------------------------------- data for the page
$order = null;
if ($bill) {
    $lines = bill_items($id);
    $head = $bill;
    $order = $bill['order_id'] ? get_order((int)$bill['order_id']) : null;
} else {
    $head = ['bill_date' => today(), 'order_id' => null, 'customer_code' => '', 'bill_name' => '', 'bill_phone' => '', 'bill_address' => '', 'bill_pincode' => '',
        'bill_gstin' => '', 'bill_state' => setting('inv_state', 'Kerala'), 'inter_state' => 0, 'branding' => 'looma', 'seller_name' => '', 'seller_address' => '',
        'seller_phone' => '', 'seller_gstin' => '', 'discount' => 0, 'shipping' => 0, 'notes' => ''];
    $lines = [];
    if (!empty($_GET['order']) && ($order = get_order((int)$_GET['order']))) {
        $c = $order['customer_id'] !== '' ? q('SELECT * FROM customers WHERE code = ?', [$order['customer_id']])->fetch() : null;
        $head = array_merge($head, ['order_id' => (int)$order['id'], 'customer_code' => $order['customer_id'],
            'bill_name' => $order['customer_name'] !== '' ? $order['customer_name'] : $order['ship_name'],
            'bill_phone' => $order['ship_phone'], 'bill_address' => (string)$order['ship_address'], 'bill_pincode' => $order['ship_pincode']]);
        $lines = bill_lines_from_order((int)$order['id']);
    }
    if (!$lines) {
        $lines = [['stock_id' => null, 'description' => '', 'hsn' => setting('inv_default_hsn', '6109'), 'qty' => 1, 'unit' => 'pcs', 'rate' => 0, 'gst_rate' => (float)setting('inv_default_gst', '5')]];
    }
}
[$calcLines, $t] = compute_bill($lines, (float)$head['discount'], (float)$head['shipping'], !empty($head['inter_state']));
$payments = $bill ? q('SELECT * FROM bill_payments WHERE bill_id = ? ORDER BY paid_on, id', [$id])->fetchAll() : [];

$pageTitle = $bill ? $bill['number'] : 'New ' . strtolower(BILL_TYPES[$type]);
$active = 'bills';
require __DIR__ . '/inc/header.php';

if (!$editing): // ================================================== VIEW
    $due = round((float)$bill['total'] - (float)$bill['paid'], 2);
    ?>
    <div class="page-head">
      <div><a class="back" href="bills.php">← Bills</a>
        <h1><?= h($bill['number']) ?> <span class="badge <?= $bill['status'] === 'cancelled' ? 'delayed' : ($type === 'proforma' ? 'pending' : 'shipped') ?>"><?= $bill['status'] === 'cancelled' ? 'Cancelled' : h(BILL_TYPES[$type]) ?></span>
          <?php if ($bill['status'] === 'final' && $type === 'invoice'): ?><span class="badge <?= $due <= 0 ? 'shipped' : 'printing' ?>"><?= $due <= 0 ? 'Paid' : 'Due ' . h(money($due, 0)) ?></span><?php endif; ?></h1>
        <p class="muted small"><?= h(fmt_date($bill['bill_date'])) ?> · <?= $bill['branding'] === 'plain' ? 'Plain bill (no Looma branding)' : 'With ' . h($bill['seller_name']) . ' details' ?>
          <?php if ($order): ?> · Order <a href="order.php?id=<?= (int)$order['id'] ?>"><?= h(order_no($order['id'])) ?></a><?php endif; ?>
          <?php if ($bill['converted_to']): ?> · Converted to <a href="bill.php?id=<?= (int)$bill['converted_to'] ?>"><?= h((string)q('SELECT number FROM bills WHERE id = ?', [$bill['converted_to']])->fetchColumn()) ?></a><?php endif; ?>
          <?php if ($bill['converted_from']): ?> · From <a href="bill.php?id=<?= (int)$bill['converted_from'] ?>"><?= h((string)q('SELECT number FROM bills WHERE id = ?', [$bill['converted_from']])->fetchColumn()) ?></a><?php endif; ?></p>
      </div>
      <div class="actions">
        <a class="btn primary" href="bill_print.php?id=<?= $id ?>" target="_blank">🖨 Print / PDF</a>
        <?php $waText = bill_whatsapp_text($bill); $waNum = wa_number((string)$bill['bill_phone']); ?>
        <details class="dropdown">
          <summary class="btn wa-btn"><?= wa_icon() ?> Send on WhatsApp</summary>
          <div class="menu">
            <a href="bill_print.php?id=<?= $id ?>&send=pdf">📄 With PDF of the bill</a>
            <a href="bill_print.php?id=<?= $id ?>&send=png">🖼 With picture of the bill</a>
            <a href="https://wa.me/<?= h((string)$waNum) ?>?text=<?= h(rawurlencode($waText)) ?>" target="_blank" rel="noopener">💬 Message + link only</a>
            <?php if (!$waNum): ?><span class="muted small" style="padding:6px 12px;display:block">No phone on the bill — WhatsApp will ask who to send it to.</span><?php endif; ?>
          </div>
        </details>
        <button type="button" class="btn" data-copy-text="<?= h(bill_share_url(get_bill($id))) ?>">🔗 Copy link</button>
        <?php if ($bill['status'] === 'final'): ?><a class="btn" href="bill.php?id=<?= $id ?>&edit=1">✎ Edit</a><?php endif; ?>
        <?php if (!$order && $bill['status'] === 'final'): ?>
          <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="make_order"><button class="btn">📦 Make order</button></form>
        <?php endif; ?>
        <?php if ($type === 'proforma' && !$bill['converted_to'] && $bill['status'] === 'final'): ?>
          <form method="post" class="inline" onsubmit="return confirm('Make a tax invoice from this proforma? Stock will be taken.');"><?= csrf_field() ?><input type="hidden" name="do" value="convert"><?php if (!$order): ?><input type="hidden" name="make_order" value="1"><?php endif; ?><button class="btn">➜ Convert to tax invoice<?= $order ? '' : ' + make order' ?></button></form>
        <?php endif; ?>
      </div>
    </div>

    <div class="two-col">
      <section class="panel">
        <h2>Bill to</h2>
        <p><b><?= h($bill['bill_name'] ?: '—') ?></b><?= $bill['customer_code'] !== '' ? ' <span class="muted small">(' . h($bill['customer_code']) . ')</span>' : '' ?><br>
          <?= nl2br(h((string)$bill['bill_address'])) ?><?= $bill['bill_pincode'] !== '' ? ' – ' . h($bill['bill_pincode']) : '' ?><br>
          <?= $bill['bill_phone'] !== '' ? '☎ ' . h($bill['bill_phone']) . '<br>' : '' ?>
          <?= $bill['bill_gstin'] !== '' ? 'GSTIN ' . h($bill['bill_gstin']) . '<br>' : '' ?>
          <?= $bill['bill_state'] !== '' ? h($bill['bill_state']) : '' ?></p>
      </section>
      <section class="panel">
        <h2>Amounts</h2>
        <table class="table compact totals-table"><tbody>
          <tr><td>Items</td><td class="num"><?= h(money((float)$bill['subtotal'])) ?></td></tr>
          <?php if ((float)$bill['discount'] > 0): ?><tr><td>Discount</td><td class="num">−<?= h(money((float)$bill['discount'])) ?></td></tr><?php endif; ?>
          <tr><td><b>Sales value</b> <small class="muted">(before tax &amp; shipping)</small></td><td class="num"><b><?= h(money((float)$bill['taxable'])) ?></b></td></tr>
          <tr><td>GST <?= $bill['inter_state'] ? '(IGST)' : '(CGST + SGST)' ?></td><td class="num"><?= h(money((float)$bill['tax_total'])) ?></td></tr>
          <?php if ((float)$bill['shipping'] > 0): ?><tr><td>Shipping</td><td class="num"><?= h(money((float)$bill['shipping'])) ?></td></tr><?php endif; ?>
          <?php if ((float)$bill['round_off'] != 0): ?><tr><td>Round off</td><td class="num"><?= h(money((float)$bill['round_off'])) ?></td></tr><?php endif; ?>
          <tr class="total"><td><b>Total</b></td><td class="num"><b><?= h(money((float)$bill['total'])) ?></b></td></tr>
          <?php if ($type === 'invoice'): ?><tr><td>Received</td><td class="num"><?= h(money((float)$bill['paid'])) ?></td></tr>
          <tr><td><b>Balance due</b></td><td class="num"><b class="<?= $due > 0 ? 'err-text' : '' ?>"><?= h(money(max(0, $due))) ?></b></td></tr><?php endif; ?>
        </tbody></table>
      </section>
    </div>

    <section class="panel">
      <h2>Items</h2>
      <div class="table-wrap"><table class="table compact">
        <thead><tr><th>#</th><th>Item</th><th>HSN</th><th class="num">Qty</th><th class="num">Rate</th><th class="num">Amount</th><th class="num">GST</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $i => $l): ?>
          <tr><td><?= $i + 1 ?></td><td class="wrap-cell"><?= h($l['description']) ?><?= $l['stock_id'] ? ' <small class="muted">· from stock</small>' : '' ?></td><td><?= h($l['hsn']) ?></td>
            <td class="num"><?= qty_fmt($l['qty']) ?> <?= h($l['unit']) ?></td><td class="num"><?= h(money((float)$l['rate'])) ?></td><td class="num"><?= h(money((float)$l['amount'])) ?></td><td class="num"><?= qty_fmt($l['gst_rate']) ?>%</td></tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
      <?php if ($bill['notes']): ?><p class="muted"><?= nl2br(h($bill['notes'])) ?></p><?php endif; ?>
    </section>

    <?php if ($type === 'invoice'): ?>
    <section class="panel">
      <h2>Payments</h2>
      <?php if ($payments): ?>
        <div class="table-wrap"><table class="table compact"><tbody>
          <?php foreach ($payments as $p): ?>
            <tr><td><?= h(fmt_date($p['paid_on'])) ?></td><td><?= h($p['mode']) ?></td><td class="wrap-cell"><?= h($p['note']) ?></td><td class="num"><b><?= h(money((float)$p['amount'])) ?></b></td>
              <td><form method="post" class="inline" onsubmit="return confirm('Remove this payment?');"><?= csrf_field() ?><input type="hidden" name="do" value="del_pay"><input type="hidden" name="pay_id" value="<?= (int)$p['id'] ?>"><button class="icon-btn" title="Remove">✕</button></form></td></tr>
          <?php endforeach; ?>
        </tbody></table></div>
      <?php endif; ?>
      <?php if ($bill['status'] === 'final'): ?>
      <form method="post" class="grid" style="margin-top:10px">
        <?= csrf_field() ?><input type="hidden" name="do" value="pay">
        <label class="field"><span class="lbl">Amount received</span><input type="number" step="any" min="0" name="amount" value="<?= $due > 0 ? h(number_format($due, 2, '.', '')) : '' ?>" required></label>
        <label class="field"><span class="lbl">Mode</span><select name="mode"><?php foreach (PAY_MODES as $m): ?><option><?= $m ?></option><?php endforeach; ?></select></label>
        <label class="field"><span class="lbl">Date</span><input type="date" name="paid_on" value="<?= h(today()) ?>"></label>
        <label class="field"><span class="lbl">Note</span><input name="note" placeholder="UPI ref, etc."></label>
        <div class="field"><span class="lbl">&nbsp;</span><button class="btn">+ Record payment</button></div>
      </form>
      <?php endif; ?>
    </section>
    <?php endif; ?>

    <div class="danger-zone">
      <?php if ($bill['status'] === 'final'): ?>
        <form method="post" class="inline" onsubmit="return confirm('Cancel this bill?<?= $type === 'invoice' ? ' Its stock will be put back.' : '' ?>');"><?= csrf_field() ?><input type="hidden" name="do" value="cancel"><button class="btn">Cancel bill</button></form>
      <?php else: ?>
        <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="do" value="restore"><button class="btn">Restore bill</button></form>
      <?php endif; ?>
      <?php if (is_admin() || $type === 'proforma'): ?>
        <form method="post" class="inline" onsubmit="return confirm('Delete this bill for good?');"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><button class="btn danger">Delete</button></form>
      <?php endif; ?>
    </div>
    <?php
    require __DIR__ . '/inc/footer.php';
    exit;
endif;

// ================================================== EDIT / NEW
?>
<div class="page-head">
  <div><a class="back" href="<?= $bill ? 'bill.php?id=' . $id : 'bills.php' ?>">← <?= $bill ? h($bill['number']) : 'Bills' ?></a>
    <h1><?= $bill ? 'Edit ' . h($bill['number']) : 'New ' . h(strtolower(BILL_TYPES[$type])) ?></h1>
    <p class="muted small"><?= $type === 'invoice' ? 'T-shirts picked from stock come off stock when you save. Printing and shipping never change stock.' : 'A proforma (quotation) does not touch stock. Convert it to a tax invoice when the customer confirms.' ?>
      <?php if ($order): ?> From order <a href="order.php?id=<?= (int)$order['id'] ?>"><?= h(order_no($order['id'])) ?></a>.<?php endif; ?></p></div>
</div>

<form method="post" id="billForm" class="bill-form" data-stock="<?= h(json_encode(stock_for_picker(), JSON_UNESCAPED_UNICODE)) ?>" data-seller-state="<?= h(setting('inv_state', 'Kerala')) ?>">
  <?= csrf_field() ?><input type="hidden" name="do" value="save"><input type="hidden" name="order_id" value="<?= (int)$head['order_id'] ?>">
  <section class="panel">
    <h2>Bill</h2>
    <div class="grid">
      <label class="field"><span class="lbl">Date</span><input type="date" name="bill_date" value="<?= h($head['bill_date']) ?>" required></label>
      <?php if (!$head['order_id']): ?>
        <label class="field check"><input type="checkbox" name="make_order" value="1" <?= !$bill && $type === 'invoice' ? 'checked' : '' ?>> 📦 Also make the order (printing &amp; packing) from this bill</label>
      <?php endif; ?>
      <div class="field"><span class="lbl">Branding</span>
        <div class="seg-toggle est-seg" role="radiogroup">
          <label><input type="radio" name="branding" value="looma" <?= $head['branding'] !== 'plain' ? 'checked' : '' ?> data-branding><span><?= h(setting('inv_seller_name', setting('company_name', 'Looma Apparels'))) ?></span></label>
          <label><input type="radio" name="branding" value="plain" <?= $head['branding'] === 'plain' ? 'checked' : '' ?> data-branding><span>Plain (no branding)</span></label>
        </div></div>
    </div>
    <div class="grid plain-seller" <?= $head['branding'] === 'plain' ? '' : 'hidden' ?>>
      <p class="hint full" style="grid-column:1/-1;margin:0">Plain bills show no Looma name, address, GSTIN or bank details. Optionally print another seller (e.g. your customer’s brand) — or leave empty.</p>
      <label class="field"><span class="lbl">Seller name (optional)</span><input name="seller_name" value="<?= h($head['branding'] === 'plain' ? $head['seller_name'] : '') ?>"></label>
      <label class="field"><span class="lbl">Seller phone</span><input name="seller_phone" value="<?= h($head['branding'] === 'plain' ? $head['seller_phone'] : '') ?>"></label>
      <label class="field"><span class="lbl">Seller GSTIN</span><input name="seller_gstin" value="<?= h($head['branding'] === 'plain' ? $head['seller_gstin'] : '') ?>"></label>
      <label class="field full"><span class="lbl">Seller address</span><textarea name="seller_address" rows="2"><?= h($head['branding'] === 'plain' ? (string)$head['seller_address'] : '') ?></textarea></label>
    </div>
  </section>

  <section class="panel">
    <h2>Bill to</h2>
    <div class="grid">
      <label class="field"><span class="lbl">Customer ID</span><input name="customer_code" value="<?= h($head['customer_code']) ?>" data-bill-customer data-party-suggest autocomplete="off" placeholder="Type ID, name or phone"></label>
      <label class="field"><span class="lbl">Name</span><input name="bill_name" value="<?= h($head['bill_name']) ?>" data-party-suggest autocomplete="off"></label>
      <label class="field"><span class="lbl">Phone</span><input name="bill_phone" value="<?= h($head['bill_phone']) ?>" inputmode="tel"></label>
      <label class="field full"><span class="lbl">Address</span><textarea name="bill_address" rows="2"><?= h((string)$head['bill_address']) ?></textarea></label>
      <label class="field"><span class="lbl">Pincode</span><input name="bill_pincode" value="<?= h($head['bill_pincode']) ?>" inputmode="numeric" maxlength="6"></label>
      <label class="field"><span class="lbl">GSTIN (if business)</span><input name="bill_gstin" value="<?= h($head['bill_gstin']) ?>" maxlength="15"></label>
      <label class="field"><span class="lbl">State</span><input name="bill_state" value="<?= h($head['bill_state']) ?>" list="dlStates" data-bill-state></label>
      <label class="field check"><input type="checkbox" name="inter_state" value="1" <?= $head['inter_state'] ? 'checked' : '' ?> data-inter> Other state (IGST instead of CGST + SGST)</label>
    </div>
  </section>

  <section class="panel">
    <h2>Items</h2>
    <div class="bill-lines" id="billLines">
      <?php foreach ($lines as $i => $l): bill_line_row((string)$i, $l); endforeach; ?>
    </div>
    <template id="lineTpl"><?php bill_line_row('__N__', ['stock_id' => null, 'description' => '', 'hsn' => setting('inv_default_hsn', '6109'), 'qty' => 1, 'unit' => 'pcs', 'rate' => 0, 'gst_rate' => (float)setting('inv_default_gst', '5')]); ?></template>
    <button type="button" class="btn small" data-add-line>+ Add line</button>
    <p class="hint">Start typing in “Item” — pick a T-shirt (stock goes down), a printing service (no stock) or shipping. Price, HSN and GST fill in. Prices are before GST.</p>
    <datalist id="dlStates"><?php foreach (['Kerala', 'Tamil Nadu', 'Karnataka', 'Maharashtra', 'Delhi', 'Telangana', 'Andhra Pradesh', 'Goa', 'Gujarat', 'Rajasthan', 'Uttar Pradesh', 'West Bengal', 'Punjab', 'Haryana', 'Madhya Pradesh', 'Bihar', 'Odisha', 'Assam', 'Puducherry'] as $st): ?><option value="<?= $st ?>"><?php endforeach; ?></datalist>
  </section>

  <section class="panel">
    <div class="grid">
      <label class="field"><span class="lbl">Discount (₹, before GST)</span><input type="number" step="any" min="0" name="discount" value="<?= h((string)(float)$head['discount']) ?>" data-calc></label>
      <label class="field"><span class="lbl">Shipping charge (₹)</span><input type="number" step="any" min="0" name="shipping" value="<?= h((string)(float)$head['shipping']) ?>" data-calc></label>
      <label class="field full"><span class="lbl">Notes on the bill</span><textarea name="notes" rows="2"><?= h((string)$head['notes']) ?></textarea></label>
    </div>
    <table class="table compact totals-table" id="billTotals"><tbody></tbody></table>
  </section>

  <div class="sticky-actions">
    <span class="total-pcs" id="billBar"></span>
    <button class="btn primary">💾 <?= $bill ? 'Save changes' : 'Save ' . strtolower(BILL_TYPES[$type]) ?></button>
    <a class="btn ghost" href="<?= $bill ? 'bill.php?id=' . $id : 'bills.php' ?>">Cancel</a>
  </div>
</form>
<?php
function bill_line_row(string $i, array $l): void
{
    $n = fn($k) => 'lines[' . $i . '][' . $k . ']';
    ?>
    <div class="bill-line" data-line>
      <input type="hidden" name="<?= $n('stock_id') ?>" value="<?= (int)$l['stock_id'] ?: '' ?>" data-f="stock_id">
      <label class="field bl-desc"><span class="lbl">Item</span><input name="<?= $n('description') ?>" value="<?= h((string)$l['description']) ?>" data-f="description" autocomplete="off" placeholder="Type to search items, printing, shipping"><small class="hint" data-stockhint></small></label>
      <label class="field"><span class="lbl">Qty</span><input type="number" step="any" min="0" name="<?= $n('qty') ?>" value="<?= h(qty_fmt($l['qty'])) ?>" data-f="qty"></label>
      <label class="field"><span class="lbl">Unit</span><input name="<?= $n('unit') ?>" value="<?= h((string)$l['unit']) ?>" data-f="unit"></label>
      <label class="field"><span class="lbl">Rate ₹</span><input type="number" step="any" min="0" name="<?= $n('rate') ?>" value="<?= h((string)(float)$l['rate']) ?>" data-f="rate"></label>
      <label class="field"><span class="lbl">GST %</span><input type="number" step="any" min="0" name="<?= $n('gst_rate') ?>" value="<?= h(qty_fmt($l['gst_rate'])) ?>" data-f="gst_rate"></label>
      <label class="field"><span class="lbl">HSN</span><input name="<?= $n('hsn') ?>" value="<?= h((string)$l['hsn']) ?>" data-f="hsn"></label>
      <div class="field bl-amt"><span class="lbl">Amount</span><b data-amount>₹0</b></div>
      <button type="button" class="icon-btn" data-del-line title="Remove line" aria-label="Remove line">✕</button>
    </div>
    <?php
}
?>
<script src="<?= h(asset('assets/bill.js')) ?>"></script>
<?php require __DIR__ . '/inc/footer.php'; ?>
