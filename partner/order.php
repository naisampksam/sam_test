<?php
// Partner: send a new order (few taps: pick the design picture, colour, + on sizes), see where an order is,
// change or cancel it while Looma has not accepted it yet, or order the same T-shirts again.
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/partner_money.php';
require __DIR__ . '/../inc/partner_ui.php';

$pu = require_partner();
$p = $pu['partner'];
$id = (int)($_GET['id'] ?? 0);
$po = $id ? partner_order($id, (int)$p['id']) : null;
if ($id && !$po) {
    http_response_code(404);
    exit('Order not found.');
}
$errors = [];
$in = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'change' && $po) {
        $err = partner_change_request($pu, $id, (string)($_POST['change'] ?? ''));
        flash($err ?? 'Change request sent. Looma will reply here.', $err ? 'err' : 'ok');
        redirect('partner/order.php?id=' . $id);
    }
    if ($po && $po['source'] !== 'portal') {
        flash('This order came from your Shopify — change or cancel it there.', 'err');
        redirect('partner/order.php?id=' . $id);
    }
    if (($_POST['action'] ?? '') === 'cancel' && $po) {
        $ok = partner_order_cancel($pu, $id);
        flash($ok ? $po['ref'] . ' cancelled.' : 'Looma has already accepted this order. Call or WhatsApp us to change it.', $ok ? 'ok' : 'err');
        redirect('partner/order.php?id=' . $id);
    }
    [$newId, $errors] = partner_order_save($pu, $po ? $id : null, $_POST);
    if (!$errors) {
        $saved = partner_order((int)$newId, (int)$p['id']);
        flash($po ? $saved['ref'] . ' updated.' : '✓ ' . $saved['ref'] . ' sent to Looma. Follow it here: accepted → printing → shipped.');
        redirect('partner/order.php?id=' . $newId);
    }
    $in = $_POST;
}

$designs = partner_designs($p);
$byId = [];
foreach ($designs as $d) {
    $byId[(int)$d['id']] = $d;
}
$editing = !$po || ($po['status'] === 'new' && $po['source'] === 'portal' && (!empty($_GET['edit']) || $errors));

// ---------------------------------------------------------------- one order
if (!$editing):
    $o = $po['order_id'] ? get_order((int)$po['order_id']) : null;
    [$label, $cls, $detail] = partner_order_stage($po, $o);
    $pcs = array_sum(array_column($po['items'], 'qty'));
    partner_head($po['ref'], 'orders', $pu); ?>
<a class="pt-back" href="<?= h(base_url('partner/index.php')) ?>">‹ Orders</a>
<div class="pt-head">
  <div><h1 class="pt-title"><?= h($po['ref']) ?></h1><p class="pt-sub"><?= h($po['ship_name']) ?> · <?= plural((int)$pcs, 'T-shirt') ?> · sent <?= h(fmt_date($po['created_at'], true)) ?></p></div>
  <span class="pt-pill s-<?= h($cls) ?>"><?= h($label) ?></span>
</div>

<?php if (in_array($po['status'], ['rejected', 'cancelled'], true)): ?>
  <div class="pt-note err"><b><?= h($label) ?></b><?= $po['reason'] !== '' ? ' — ' . h($po['reason']) : '' ?></div>
<?php else: ?>
  <ol class="pt-steps" aria-label="Progress">
    <?php foreach (partner_steps($po, $o) as [$sl, $done]): ?><li class="<?= $done ? 'done' : '' ?>"><span></span><?= h($sl) ?></li><?php endforeach; ?>
  </ol>
  <?php if ($o && $o['shipped']): ?>
    <section class="pt-card pt-track">
      <div><small>Shipped with <?= h($o['courier'] ?: 'courier') ?></small><b class="mono"><?= h($o['tracking_no'] ?: '—') ?></b></div>
      <?php if ($o['tracking_no'] !== ''): ?><button type="button" class="pt-btn tinted" data-copy="<?= h($o['tracking_no']) ?>">Copy tracking</button><?php endif; ?>
    </section>
  <?php elseif ($detail !== ''): ?><p class="pt-sub"><?= h($detail) ?></p><?php endif; ?>
<?php endif; ?>

<h2 class="pt-h2">T-shirts</h2>
<section class="pt-card pt-list">
  <?php foreach ($po['items'] as $it): $d = $byId[(int)$it['design_id']] ?? null; ?>
    <div class="pt-row"><?php if ($d && $d['images']): ?><img class="pt-thumb" src="<?= h(partner_img($d['images'][0])) ?>" alt="" loading="lazy"><?php else: ?><span class="pt-thumb">👕</span><?php endif; ?>
      <span class="pt-row-main"><b><?= h($it['design']) ?></b><small><?= h($it['product'] . ' · ' . $it['gsm'] . ' · ' . $it['color']) ?></small></span>
      <span class="pt-row-end"><b><?= h($it['size']) ?></b> × <?= (int)$it['qty'] ?></span></div>
  <?php endforeach; ?>
</section>

<?php if (!in_array($po['status'], ['rejected', 'cancelled'], true) && partner_order_ready_safe($po)):
    $bill = $po['bill_id'] ? get_bill((int)$po['bill_id']) : null;
    [$qlines, $qt] = partner_quote($p, $po['items'], (string)$po['payment_type']);
    $total = $bill ? (float)$bill['total'] : (float)$qt['total'];
    $acct = partner_account($p); ?>
<h2 class="pt-h2">Cost<?= $bill ? '' : ' (when Looma accepts)' ?></h2>
<section class="pt-card pt-list pt-cost">
  <?php foreach ($qlines as $ql): ?><div class="pt-row"><span class="pt-row-main"><span><?= h($ql['description']) ?></span></span><span class="pt-row-end"><small><?= (float)$ql['qty'] != 1 ? h(qty_fmt($ql['qty'])) . ' × ₹' . h(inr_number((float)$ql['rate'], 2)) : '' ?></small> ₹<?= h(inr_number((float)$ql['amount'], 2)) ?></span></div><?php endforeach; ?>
  <?php if ((float)$qt['tax_total'] > 0): ?><div class="pt-row"><span class="pt-row-main"><span>GST</span></span><span class="pt-row-end">₹<?= h(inr_number((float)$qt['tax_total'], 2)) ?></span></div><?php endif; ?>
  <div class="pt-row tot"><span class="pt-row-main"><b>Total</b></span><span class="pt-row-end"><b>₹<?= h(inr_number($total, 2)) ?></b><?= $bill ? '<small>' . ((float)$bill['paid'] >= (float)$bill['total'] - 0.005 ? 'Paid' : 'Due') . '</small>' : '' ?></span></div>
</section>
<?php if ($po['status'] === 'new' && in_array($p['pay_mode'], ['wallet', 'per_order'], true) && $acct['balance'] < $total - 0.004): ?>
  <div class="pt-actions"><a class="pt-btn filled" href="<?= h(base_url('partner/money.php?po=' . $id)) ?>">Pay ₹<?= h(inr_number($total - max(0, $acct['balance']), 2)) ?> for this order</a>
    <span class="pt-sub">Looma accepts it once the payment is confirmed.</span></div>
<?php endif; endif; ?>

<h2 class="pt-h2">Customer</h2>
<section class="pt-card pt-kv">
  <div><small>Name</small><b><?= h($po['ship_name']) ?></b></div>
  <div><small>Mobile</small><a href="tel:<?= h($po['ship_phone']) ?>"><?= h($po['ship_phone']) ?></a><?= $po['ship_phone2'] !== '' ? ' · ' . h($po['ship_phone2']) : '' ?></div>
  <div><small>Address</small><span><?= nl2br(h($po['ship_address'])) ?><?= $po['landmark'] !== '' ? '<br>Landmark: ' . h($po['landmark']) : '' ?><br><b><?= h($po['ship_pincode']) ?></b></span></div>
  <div><small>Payment</small><span><?= $po['payment_type'] === 'cod' ? 'Cash on delivery · ₹' . h(inr_number((float)$po['cod_amount'])) : 'Prepaid' ?><?= $po['urgent'] ? ' · ⚡ Urgent' : '' ?><?= $po['courier'] !== '' ? ' · ' . h($po['courier']) : '' ?></span></div>
  <?php if ($po['notes'] !== ''): ?><div><small>Note</small><span><?= nl2br(h($po['notes'])) ?></span></div><?php endif; ?>
</section>

<?php if ($po['change_state'] !== ''): ?>
  <h2 class="pt-h2">Change request</h2>
  <section class="pt-card pt-kv">
    <div><small>You asked</small><span><?= nl2br(h((string)$po['change_req'])) ?></span></div>
    <div><small>Looma</small><span><b><?= ['open' => 'Looking at it…', 'done' => '✓ Done', 'declined' => 'Not possible'][$po['change_state']] ?? '' ?></b><?= $po['change_reply'] !== '' ? ' — ' . h($po['change_reply']) : '' ?></span></div>
  </section>
<?php endif; ?>
<?php if ($po['status'] === 'accepted' && !($o['shipped'] ?? 0) && $po['change_state'] !== 'open'): ?>
  <details class="pt-more"><summary>Request a change</summary>
    <form method="post" class="pt-card pt-fields"><?= csrf_field() ?><input type="hidden" name="action" value="change">
      <label class="tall"><span>Change</span><textarea name="change" rows="3" required maxlength="1000" placeholder="e.g. size M instead of L, new address…"></textarea></label>
      <div style="padding:8px 16px 14px"><button class="pt-btn filled">Send to Looma</button></div></form></details>
<?php endif; ?>
<div class="pt-actions">
  <?php if ($po['source'] === 'portal'): ?><a class="pt-btn tinted" href="<?= h(base_url('partner/order.php?new=1&again=' . $id)) ?>">↻ Order these again</a><?php endif; ?>
  <?php if ($po['status'] === 'new' && $po['source'] !== 'portal'): ?><p class="pt-sub">From your Shopify — to change or cancel it, do it in Shopify.</p>
  <?php elseif ($po['status'] === 'new'): ?>
    <a class="pt-btn tinted" href="?id=<?= $id ?>&edit=1">Change</a>
    <form method="post" onsubmit="return confirm('Cancel <?= h($po['ref']) ?>?')"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="pt-btn plain danger">Cancel order</button></form>
  <?php endif; ?>
</div>
<?php partner_foot(); exit; endif;

// ---------------------------------------------------------------- new order / change
$blank = ['ship_name' => '', 'ship_phone' => '', 'ship_phone2' => '', 'ship_address' => '', 'landmark' => '', 'ship_pincode' => '', 'ship_email' => '',
    'payment_type' => 'prepaid', 'cod_amount' => '', 'courier' => '', 'urgent' => 0, 'notes' => ''];
$v = $in ?? ($po ?: $blank);
if ($in !== null) {
    $lines = array_values((array)($in['lines'] ?? []));
} elseif ($po) {
    $lines = partner_lines($po['items']);
} elseif (!empty($_GET['again']) && ($ag = partner_order((int)$_GET['again'], (int)$p['id']))) {
    $lines = partner_lines($ag['items']);
} else {
    $lines = [['design_id' => (int)($_GET['design'] ?? 0), 'tshirt' => '', 'color' => '', 'qty' => []]];
}
partner_head($po ? 'Change ' . $po['ref'] : 'New order', $po ? 'orders' : 'new', $pu);
?>
<?php if ($po): ?><a class="pt-back" href="?id=<?= $id ?>">‹ <?= h($po['ref']) ?></a><?php endif; ?>
<h1 class="pt-title"><?= $po ? 'Change ' . h($po['ref']) : 'New order' ?></h1>
<?php if ($errors): ?><div class="pt-note err" role="alert"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>
<?php if (!$designs): ?>
  <div class="pt-empty"><div class="pt-empty-ico">🎨</div><b>Save your first design</b><p>Add the design's mock-up photo once — then every order is a few taps.</p>
    <a class="pt-btn filled" href="<?= h(base_url('partner/designs.php?new=1')) ?>">＋ New design</a></div>
<?php partner_foot(); exit; endif; ?>

<form method="post" class="pt-form" id="ptForm" novalidate
      data-catalog="<?= h(json_encode(catalog(), JSON_UNESCAPED_UNICODE)) ?>"
      data-designs="<?= h(json_encode(array_map(fn($d) => ['id' => (int)$d['id'], 'name' => $d['name'], 'thumb' => $d['images'] ? partner_img($d['images'][0]) : ''], $designs), JSON_UNESCAPED_UNICODE)) ?>"
      data-lines="<?= h(json_encode($lines, JSON_UNESCAPED_UNICODE)) ?>" data-lookup="<?= h(base_url('partner/customer.php')) ?>" data-newdesign="<?= h(base_url('partner/designs.php?new=1')) ?>">
  <?= csrf_field() ?>

  <h2 class="pt-h2">T-shirts</h2>
  <div id="ptLines"></div>
  <button type="button" class="pt-btn tinted wide" id="ptAddLine">＋ Another design or colour</button>

  <h2 class="pt-h2">Customer</h2>
  <details class="pt-paste" <?= $v['ship_name'] === '' && !$errors ? 'open' : '' ?>>
    <summary>📋 Paste customer details <small>from WhatsApp — we fill the boxes</small></summary>
    <textarea id="ptPasteText" rows="4" placeholder="*Name*: Yadhal&#10;*Address*: Nila manzil, Muthalamada&#10;*Land mark*: Chulliyardam&#10;*Pincode*: 678507&#10;*Mobile Number*: 9446032140"></textarea>
    <button type="button" class="pt-btn tinted" id="ptPasteGo">Fill in</button>
  </details>
  <section class="pt-card pt-fields">
    <label><span>Mobile</span><input name="ship_phone" value="<?= h($v['ship_phone']) ?>" inputmode="tel" autocomplete="off" placeholder="10-digit mobile" required></label>
    <p class="pt-hint" id="ptKnown" hidden></p>
    <label><span>Name</span><input name="ship_name" value="<?= h($v['ship_name']) ?>" autocomplete="off" required></label>
    <label class="tall"><span>Address</span><textarea name="ship_address" rows="3" required placeholder="House, street, area, city"><?= h($v['ship_address']) ?></textarea></label>
    <label><span>Landmark</span><input name="landmark" value="<?= h($v['landmark']) ?>" placeholder="Optional"></label>
    <label><span>Pincode</span><input name="ship_pincode" value="<?= h($v['ship_pincode']) ?>" inputmode="numeric" maxlength="6" required></label>
  </section>
  <details class="pt-more" <?= $v['ship_phone2'] !== '' || $v['ship_email'] !== '' ? 'open' : '' ?>><summary>Second mobile, email</summary>
    <section class="pt-card pt-fields">
      <label><span>2nd mobile</span><input name="ship_phone2" value="<?= h($v['ship_phone2']) ?>" inputmode="tel"></label>
      <label><span>Email</span><input name="ship_email" type="email" value="<?= h($v['ship_email']) ?>"></label>
    </section></details>

  <h2 class="pt-h2">Payment</h2>
  <div class="pt-seg" role="radiogroup">
    <label><input type="radio" name="payment_type" value="prepaid" <?= ($v['payment_type'] ?? '') !== 'cod' ? 'checked' : '' ?>><span>Prepaid</span></label>
    <label><input type="radio" name="payment_type" value="cod" <?= ($v['payment_type'] ?? '') === 'cod' ? 'checked' : '' ?>><span>Cash on delivery</span></label>
  </div>
  <section class="pt-card pt-fields pt-cod" <?= ($v['payment_type'] ?? '') === 'cod' ? '' : 'hidden' ?>>
    <label><span>Collect ₹</span><input name="cod_amount" inputmode="decimal" value="<?= h((string)($v['cod_amount'] ?? '')) ?>" placeholder="Amount the courier collects"></label>
  </section>
  <section class="pt-card pt-fields">
    <label class="pt-switch"><span>⚡ Urgent</span><input type="checkbox" name="urgent" value="1" role="switch" <?= !empty($v['urgent']) ? 'checked' : '' ?>><i></i></label>
  </section>
  <details class="pt-more" <?= $v['courier'] !== '' || $v['notes'] !== '' ? 'open' : '' ?>><summary>Courier, note for Looma</summary>
    <section class="pt-card pt-fields">
      <label><span>Courier</span><input name="courier" value="<?= h($v['courier']) ?>" list="dl_pt_courier" placeholder="Looma chooses"></label>
      <label class="tall"><span>Note</span><textarea name="notes" rows="2" placeholder="Anything Looma should know"><?= h($v['notes']) ?></textarea></label>
    </section></details>
  <datalist id="dl_pt_courier"><?php foreach (couriers() as $c): ?><option value="<?= h($c) ?>"><?php endforeach; ?></datalist>
  <?php if ($errors && preg_grep('/already sent today/', $errors)): ?>
    <section class="pt-card pt-fields"><label class="pt-switch"><span>Yes, send it again (a second order)</span><input type="checkbox" name="dup_ok" value="1" role="switch"><i></i></label></section>
  <?php endif; ?>

  <div class="pt-dock">
    <span id="ptCount">0 T-shirts</span>
    <button class="pt-btn filled" id="ptSend"><?= $po ? 'Save changes' : 'Send order' ?></button>
  </div>
</form>
<script src="<?= h(asset('assets/partner.js')) ?>"></script>
<?php partner_foot(); ?>
