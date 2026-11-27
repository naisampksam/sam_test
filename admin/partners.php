<?php
// Admin: print-on-demand partners (Outfitcrew, Mangatholi …) and their logins for the partner portal.
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/shopify.php';
require __DIR__ . '/../inc/partner_money.php';
require __DIR__ . '/../inc/partner_stock.php';

require_login();
if (!cap('partners')) { // the admin, and staff the admin gave “Partners (POD)”
    http_response_code(403);
    exit('Not allowed. Ask the admin for the “Partners (POD)” permission.');
}
$id = (int)($_GET['id'] ?? 0);
$p = $id ? partner_by_id($id) : null;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'partner') {
        [$newId, $errors] = save_partner($p ? $id : null, $_POST);
        if (!$errors) {
            flash($p ? 'Partner saved.' : 'Partner added. Now give them a login below.');
            redirect('admin/partners.php?id=' . $newId);
        }
    } elseif (in_array($act, ['approve', 'reject'], true)) {
        $err = partner_approve((int)($_POST['pid'] ?? 0), $act === 'approve', (string)($_POST['code'] ?? ''));
        flash($err ?? ($act === 'approve' ? 'Approved — they can log in now. Set their prices below.' : 'Sign-up rejected.'), $err ? 'err' : 'ok');
        redirect('admin/partners.php' . ($act === 'approve' && !$err ? '?id=' . (int)$_POST['pid'] . '#money' : ''));
    } elseif ($act === 'money' && $p) {
        $errors = save_partner_money($id, $_POST, $_FILES['qr_own'] ?? null);
        if (!$errors) {
            flash('Prices and payment saved.');
            redirect('admin/partners.php?id=' . $id . '#money');
        }
    } elseif ($act === 'shop' && $p) {
        $errors = shop_settings_save($id, $_POST);
        if (!$errors) {
            flash('Shopify settings saved.');
            redirect('admin/partners.php?id=' . $id . '#shop');
        }
    } elseif ($act === 'unlink' && $p) {
        q('DELETE FROM shop_links WHERE id = ? AND partner_id = ?', [(int)($_POST['link_id'] ?? 0), $id]);
        flash('Link removed. New orders with that product will ask again.');
        redirect('admin/partners.php?id=' . $id . '#shop');
    } elseif ($act === 'product' && $p) { // staff add / edit one of the partner's products
        $did = (int)($_POST['design_id'] ?? 0);
        [$newId, $errors] = partner_save_product(staff_as_partner($p), $_POST, $_FILES['photos'] ?? null, $did ?: null);
        if (!$errors) {
            flash('Product “' . trim((string)$_POST['name']) . '” saved for ' . $p['name'] . '.');
            redirect('admin/partners.php?id=' . $id . '#products');
        }
    } elseif ($act === 'product_delete' && $p) {
        $err = partner_delete_product(staff_as_partner($p), (int)($_POST['design_id'] ?? 0));
        flash($err ?? 'Product deleted.', $err ? 'err' : 'ok');
        redirect('admin/partners.php?id=' . $id . '#products');
    } elseif ($act === 'pay_setup') { // payment QR codes + Looma WhatsApp for partners
        foreach (['partner_qr_gst' => 'qr_gst', 'partner_qr_nogst' => 'qr_nogst'] as $k => $field) {
            [$path, $err] = partner_qr_store($_FILES[$field] ?? null);
            if ($err) {
                $errors[] = $err;
                break;
            }
            if ($path) {
                $old = (string)setting($k, '');
                set_setting($k, $path);
                if ($old !== '') {
                    @unlink(upload_dir() . '/' . $old);
                }
            }
        }
        if (!$errors) {
            set_setting('partner_whatsapp', trim((string)($_POST['partner_whatsapp'] ?? '')));
            flash('Payment QR codes and WhatsApp number saved.');
            redirect('admin/partners.php#paysetup');
        }
    } elseif ($act === 'login' && $p) {
        $errors = save_partner_user($id, ($_POST['user_id'] ?? '') !== '' ? (int)$_POST['user_id'] : null, $_POST);
        if (!$errors) {
            flash(($_POST['user_id'] ?? '') !== '' ? 'Login saved.' : 'Login added. Send the username and password to the partner.');
            redirect('admin/partners.php?id=' . $id . '#logins');
        }
    }
}

$pageTitle = 'Partners';
$active = 'partners';
require __DIR__ . '/../inc/header.php';
$portal = is_erp_site() ? 'https://partners.' . preg_replace('/^(erp|www|orders)\./', '', strtolower(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_HOST'] ?? $_SERVER['HTTP_HOST'] ?? 'loomaapparels.com'))[0])) . '/' : base_url('partner/');
?>
<div class="page-head">
  <div><h1>Partners <small class="muted">print on demand</small></h1>
    <p class="muted small">Companies whose customers' orders you print and ship. They log in at <b><?= h($portal) ?></b>, save designs and send orders; you accept them in <a href="<?= h(base_url('partner_orders.php')) ?>">Partner orders</a>.</p></div>
  <div class="actions"><?php if ($p || $id === 0 && empty($_GET['new'])): ?><a class="btn primary" href="?new=1">＋ Add partner</a><?php endif; ?></div>
</div>
<?php if ($errors): ?><div class="alert err"><?= implode('<br>', array_map('h', $errors)) ?></div><?php endif; ?>

<?php if ($p || !empty($_GET['new'])): $v = $_POST && ($_POST['action'] ?? '') === 'partner' ? $_POST + ['active' => 0] : ($p ?: ['name' => '', 'code' => '', 'prefix' => '', 'phone' => '', 'email' => '',
    'slip_brand' => '', 'ret_phone' => '', 'ret_address' => '', 'ret_pincode' => '', 'courier' => '', 'notes' => '', 'active' => 1]); ?>
<section class="panel">
  <h2><?= $p ? h($p['name']) : 'New partner' ?></h2>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="action" value="partner">
    <div class="grid-2">
      <label class="field"><span class="lbl">Partner name <i class="req">*</i></span><input name="name" value="<?= h($v['name']) ?>" required placeholder="e.g. Outfitcrew"></label>
      <label class="field"><span class="lbl">Customer ID <i class="req">*</i> <small class="muted">their orders are made under it</small></span><input name="code" value="<?= h($v['code']) ?>" required placeholder="e.g. C900" list="dl_codes"></label>
      <label class="field"><span class="lbl">Order number letters <small class="muted">e.g. OC → OC-0001</small></span><input name="prefix" value="<?= h($v['prefix']) ?>" maxlength="10" placeholder="<?= $p ? h(partner_prefix($p)) : 'auto' ?>"></label>
      <label class="field"><span class="lbl">Phone</span><input name="phone" value="<?= h($v['phone']) ?>"></label>
      <label class="field"><span class="lbl">Email</span><input name="email" type="email" value="<?= h($v['email']) ?>"></label>
      <label class="field"><span class="lbl">Usual courier</span><input name="courier" value="<?= h($v['courier']) ?>" list="dl_couriers"></label>
    </div>
    <h3>Shipping label <small class="muted">their brand on the parcel instead of Looma</small></h3>
    <div class="grid-2">
      <label class="field"><span class="lbl">Seller name on label</span><input name="slip_brand" value="<?= h($v['slip_brand']) ?>" placeholder="e.g. Outfitcrew"></label>
      <label class="field"><span class="lbl">Return phone</span><input name="ret_phone" value="<?= h($v['ret_phone']) ?>"></label>
      <label class="field"><span class="lbl">Return address</span><textarea name="ret_address" rows="2"><?= h((string)$v['ret_address']) ?></textarea></label>
      <label class="field"><span class="lbl">Return pincode</span><input name="ret_pincode" value="<?= h($v['ret_pincode']) ?>" inputmode="numeric" maxlength="6"></label>
    </div>
    <label class="field"><span class="lbl">Notes (only staff see this)</span><textarea name="notes" rows="2"><?= h((string)$v['notes']) ?></textarea></label>
    <label class="check"><input type="checkbox" name="active" value="1" <?= !empty($v['active']) ? 'checked' : '' ?>> Active (untick to stop their logins and orders)</label>
    <div class="status-btns"><button class="btn primary">Save partner</button><a class="btn ghost" href="partners.php">Back</a></div>
  </form>
  <datalist id="dl_codes"><?php foreach (q('SELECT code FROM customers ORDER BY code LIMIT 500')->fetchAll(PDO::FETCH_COLUMN) as $c): ?><option value="<?= h($c) ?>"><?php endforeach; ?></datalist>
  <datalist id="dl_couriers"><?php foreach (couriers() as $c): ?><option value="<?= h($c) ?>"><?php endforeach; ?></datalist>
</section>

<?php if ($p): $users = partner_users($id); ?>
<section class="panel" id="logins">
  <h2>Logins <small class="muted">people at <?= h($p['name']) ?> who send orders</small></h2>
  <?php if ($users): ?>
  <div class="table-wrap"><table class="table compact pt-logins">
    <thead><tr><th>Username</th><th>Name</th><th>Last login</th><th>Change</th></tr></thead>
    <tbody>
    <?php foreach ($users as $u): ?>
      <tr><td><b><?= h($u['username']) ?></b><?= $u['active'] ? '' : ' <span class="badge">off</span>' ?></td><td><?= h($u['name']) ?></td>
        <td class="small muted"><?= $u['last_login'] ? h(fmt_date($u['last_login'], true)) : 'never' ?></td>
        <td><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="login"><input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
          <input name="name" value="<?= h($u['name']) ?>" placeholder="Name" aria-label="Name">
          <input name="password" type="password" placeholder="New password (optional)" autocomplete="new-password" aria-label="New password">
          <label class="check"><input type="checkbox" name="active" value="1" <?= $u['active'] ? 'checked' : '' ?>> Active</label>
          <button class="btn small">Save</button></form></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
  <form method="post" class="inline-form pt-newlogin"><?= csrf_field() ?><input type="hidden" name="action" value="login">
    <input name="username" required placeholder="Username, e.g. outfitcrew" autocapitalize="none" aria-label="Username">
    <input name="name" placeholder="Person's name" aria-label="Name">
    <input name="password" type="password" required placeholder="Password (8+, letters and numbers)" autocomplete="new-password" aria-label="Password">
    <button class="btn primary small">＋ Add login</button></form>
  <p class="hint">A new password or switching a login off logs that person out at once.</p>
</section>
<?php endif; ?>
<?php if ($p): ?>
<section class="panel" id="money">
  <h2>Prices &amp; payment <small class="muted">before GST · charged when you accept an order</small></h2>
  <?php $pr = partner_prices($p); $acct = partner_account($p); $pd = partner_designs($p); ?>
  <p class="small">Balance with Looma: <b class="<?= $acct['balance'] < 0 ? 'err-text' : '' ?>">₹<?= h(inr_number($acct['balance'], 2)) ?></b><?= $acct['overdue'] ? ' · <b class="err-text">overdue</b>' : '' ?> · <a href="<?= h(base_url('partner_money.php?partner=' . $id)) ?>">Statement →</a></p>
  <form method="post" class="stack" enctype="multipart/form-data">
    <?= csrf_field() ?><input type="hidden" name="action" value="money">
    <div class="pm-row-fields">
      <label class="field"><span class="lbl">How they pay</span><select name="pay_mode" data-paymode><?php foreach (PARTNER_PAY_MODES as $k => $l): ?><option value="<?= $k ?>" <?= $p['pay_mode'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
      <label class="field" data-credit><span class="lbl">Credit limit ₹ <small class="muted">0 = no limit</small></span><input name="credit_limit" inputmode="decimal" value="<?= h((string)(float)$p['credit_limit']) ?>"></label>
      <label class="field" data-credit><span class="lbl">Pay within (days)</span><input name="credit_days" inputmode="numeric" value="<?= (int)$p['credit_days'] ?>"></label>
    </div>
    <div class="pm-row-fields">
      <label class="field"><span class="lbl">Their GSTIN <small class="muted">empty = no GST bill</small></span><input name="gstin" value="<?= h($p['gstin']) ?>" maxlength="15" style="text-transform:uppercase"></label>
      <label class="check"><input type="checkbox" name="gst_add" value="1" <?= $p['gst_add'] ? 'checked' : '' ?>> Add GST on their charges</label>
    </div>
    <label class="check"><input type="checkbox" name="own_blanks" value="1" <?= partner_own_blanks($p) ? 'checked' : '' ?>> Print-only partner (sends their own blank T-shirts)</label>
    <?php $ps = json_decode((string)($p['settings'] ?? '') ?: '{}', true) ?: []; $qrNow = partner_qr_file($p); ?>
    <h3>Payment QR code <small class="muted">shown when their wallet needs a recharge · the GST / non-GST QR codes are in Settings</small></h3>
    <div class="grid-2">
      <label class="field"><span class="lbl">QR code they see</span><select name="qr"><?php foreach (PARTNER_QR_CHOICES as $k => $l): ?><option value="<?= $k ?>" <?= ($ps['qr'] ?? 'auto') === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span class="lbl">Their own QR <small class="muted">upload = use it</small></span><input type="file" name="qr_own" accept="image/png,image/jpeg,image/webp"></label>
    </div>
    <p class="small"><?= $qrNow !== '' ? 'Now showing: <a href="' . h(base_url('image.php?f=' . urlencode($qrNow))) . '" target="_blank" rel="noopener">QR picture</a>' : '<span class="err-text">No QR picture yet — they get “Recharge on WhatsApp” only.</span> Add the QR codes in Settings.' ?></p>
    <h3>T-shirt price <small class="muted">per piece · empty = your catalog price</small></h3>
    <div class="grid-3 pm-prices">
      <?php foreach (catalog() as $g) foreach ($g['products'] as $cp): $k = $g['gsm'] . '|' . $cp['name']; ?>
        <label class="field"><span class="lbl"><?= h($cp['name'] . ' · ' . $g['gsm']) ?></span><input name="tshirt[<?= h($k) ?>]" inputmode="decimal" value="<?= isset($pr['tshirt'][$k]) ? h((string)$pr['tshirt'][$k]) : '' ?>" placeholder="₹ <?= h((string)(catalog_price($g['gsm'], $cp['name'], 1) ?? '')) ?>"></label>
      <?php endforeach; ?>
    </div>
    <h3>Printing, packing, COD</h3>
    <div class="pm-row-fields">
      <label class="field"><span class="lbl">Printing per piece (default)</span><input name="print" inputmode="decimal" value="<?= h((string)$pr['print']) ?>"></label>
      <label class="field"><span class="lbl">Packing per order</span><input name="packing" inputmode="decimal" value="<?= h((string)$pr['packing']) ?>"></label>
      <label class="field"><span class="lbl">COD charge per COD order</span><input name="cod_fee" inputmode="decimal" value="<?= h((string)$pr['cod_fee']) ?>"></label>
    </div>
    <h3>Shipping <small class="muted">per order · Kerala = pincodes 67…, 68…, 69…</small></h3>
    <div class="pm-ship">
      <b></b><b>First piece ₹</b><b>Each extra piece ₹</b>
      <span>Kerala</span>
      <input name="ship_first_kl" inputmode="decimal" aria-label="Kerala: first piece" value="<?= $pr['ship_first_kl'] !== null ? h((string)$pr['ship_first_kl']) : '' ?>" placeholder="same as outside">
      <input name="ship_extra_kl" inputmode="decimal" aria-label="Kerala: each extra piece" value="<?= $pr['ship_extra_kl'] !== null ? h((string)$pr['ship_extra_kl']) : '' ?>" placeholder="same as outside">
      <span>Outside Kerala</span>
      <input name="ship_first" inputmode="decimal" aria-label="Outside Kerala: first piece" value="<?= h((string)$pr['ship_first']) ?>">
      <input name="ship_extra" inputmode="decimal" aria-label="Outside Kerala: each extra piece" value="<?= h((string)$pr['ship_extra']) ?>">
    </div>
    <div class="pm-row-fields">
      <label class="field"><span class="lbl">Bulk: weight of one T-shirt (g)</span><input name="bulk_grams" inputmode="decimal" value="<?= h((string)$pr['bulk_grams']) ?>"></label>
      <label class="field"><span class="lbl">Bulk: shipping per kg ₹</span><input name="bulk_kg" inputmode="decimal" value="<?= h((string)$pr['bulk_kg']) ?>"></label>
    </div>
    <p class="small muted" style="margin:0">Bulk orders (to the partner): T-shirts × weight, rounded up to whole kg, × ₹ per kg (e.g. 4 × 300 g = 1.2 kg → 2 kg × ₹60 = ₹120). One order can be changed in Partner orders.</p>
    <?php if ($pd): ?>
      <details><summary class="small">Printing price for a design (if different from the default) · <?= count($pd) ?> designs</summary>
        <div class="grid-3"><?php foreach ($pd as $dd): ?><label class="field"><span class="lbl"><?= h($dd['name']) ?></span><input name="design[<?= (int)$dd['id'] ?>]" inputmode="decimal" value="<?= isset($pr['design'][(string)$dd['id']]) ? h((string)$pr['design'][(string)$dd['id']]) : '' ?>" placeholder="₹ <?= h((string)$pr['print']) ?>"></label><?php endforeach; ?></div></details>
    <?php endif; ?>
    <div class="status-btns"><button class="btn primary">Save prices &amp; payment</button></div>
  </form>
  <script>(function () { var s = document.querySelector('[data-paymode]'); function t() { [].forEach.call(document.querySelectorAll('[data-credit]'), function (c) { c.hidden = s.value !== 'credit'; }); } s.addEventListener('change', t); t(); })();</script>
</section>
<section class="panel" id="shop">
  <h2>Shopify <small class="muted">their shop sends each order here — no access to their store</small></h2>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="action" value="shop">
    <label class="check"><input type="checkbox" name="shop_on" value="1" <?= $p['shop_on'] ? 'checked' : '' ?>> Take orders from <?= h($p['name']) ?>’s Shopify</label>
    <div class="grid-2">
      <label class="field"><span class="lbl">Webhook signing key <small class="muted">from their Shopify (step 4)</small></span>
        <input name="shop_secret" value="<?= $p['shop_secret'] !== '' ? '••••••••' : '' ?>" autocomplete="off" placeholder="paste the long code"></label>
      <label class="field"><span class="lbl">Start from order no. <small class="muted">older orders are ignored</small></span>
        <input name="shop_from" value="<?= h((string)($p['shop_from'] ?? '')) ?>" inputmode="numeric" placeholder="e.g. 2700"></label>
    </div>
    <div class="status-btns"><button class="btn primary">Save Shopify settings</button></div>
  </form>
  <?php if ($p['shop_token'] !== ''): ?>
    <div class="field"><span class="lbl">Webhook address <small class="muted">private — only for <?= h($p['name']) ?></small></span>
      <div class="inline-form"><input readonly value="<?= h(shop_webhook_url($p)) ?>" onclick="this.select()" id="shopUrl"><button type="button" class="btn small" onclick="navigator.clipboard && navigator.clipboard.writeText(document.getElementById('shopUrl').value); this.textContent='Copied ✓'">Copy</button></div></div>
    <p class="small muted"><?= $p['shop_seen_at'] ? 'Last message from their Shopify: ' . h(fmt_date($p['shop_seen_at'], true)) : 'No message from their Shopify yet.' ?></p>
  <?php endif; ?>
  <details class="pt-guide"><summary><b>Steps for <?= h($p['name']) ?></b> (send them this)</summary>
    <ol class="small">
      <li>Shopify Admin → <b>Settings</b> → <b>Notifications</b> → scroll down → <b>Webhooks</b>.</li>
      <li><b>Create webhook</b> → Event: <b>Order creation</b> · Format: <b>JSON</b> · URL: the webhook address above → Save.</li>
      <li>Again: <b>Create webhook</b> → Event: <b>Order cancellation</b> · same URL → Save.</li>
      <li>Copy the line “Your webhooks will be signed with <b>…</b>” code and send it to Looma (it only proves the orders come from their shop).</li>
      <li>Optional test: on the webhook, “Send test notification” — it shows up in Partner orders.</li>
    </ol></details>
  <?php $links = q('SELECT l.*, d.name AS design FROM shop_links l LEFT JOIN designs d ON d.id = l.design_id WHERE l.partner_id = ? ORDER BY l.title', [$id])->fetchAll(); if ($links): ?>
    <h3>Linked Shopify products</h3>
    <div class="table-wrap"><table class="table compact"><thead><tr><th>Shopify product</th><th>Design</th><th>T-shirt</th><th></th></tr></thead><tbody>
    <?php foreach ($links as $l): ?><tr><td><?= h($l['title']) ?></td><td><?= h((string)$l['design']) ?></td><td><?= h($l['gsm'] . ' · ' . $l['product']) ?></td>
      <td><form method="post" onsubmit="return confirm('Remove this link?')"><?= csrf_field() ?><input type="hidden" name="action" value="unlink"><input type="hidden" name="link_id" value="<?= (int)$l['id'] ?>"><button class="btn small ghost">Remove</button></form></td></tr><?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</section>

<?php $pdes = partner_designs($p); $pe = isset($_GET['product']) ? ($_GET['product'] === 'new' ? 'new' : partner_design($p, (int)$_GET['product'])) : null;
  if (($_POST['action'] ?? '') === 'product' && $errors) { $pe = (int)($_POST['design_id'] ?? 0) ? partner_design($p, (int)$_POST['design_id']) : 'new'; }
  $catTs = []; foreach (catalog() as $g) foreach ($g['products'] as $cp) $catTs[$g['gsm'] . '|' . $cp['name']] = ['label' => $cp['name'] . ' · ' . $g['gsm'], 'colours' => array_column($cp['colors'], 'name')]; ?>
<section class="panel" id="products">
  <div class="row-between"><h2>Products <small class="muted">their T-shirts with their design — what they pick in an order</small></h2>
    <?php if (!$pe): ?><a class="btn small primary" href="?id=<?= $id ?>&product=new#products">＋ Add product</a><?php endif; ?></div>
  <?php if ($pe): $ev = $pe === 'new' ? ['name' => '', 'gsm' => '', 'product' => '', 'color' => '', 'extra' => []] : $pe;
      $fv = ($_POST['action'] ?? '') === 'product' ? $_POST : ['name' => $ev['name'], 'tshirt' => $ev['product'] !== '' ? $ev['gsm'] . '|' . $ev['product'] : '', 'color' => $ev['color'],
          'print_link' => (string)($ev['extra']['print_link'] ?? ''), 'note' => (string)($ev['extra']['partner_note'] ?? '')];
      $pimgs = $pe === 'new' ? [] : q('SELECT id, filename FROM design_images WHERE design_id = ? ORDER BY id', [(int)$pe['id']])->fetchAll(); ?>
    <form method="post" enctype="multipart/form-data" class="stack pm-product" data-ts="<?= h(json_encode($catTs, JSON_UNESCAPED_UNICODE)) ?>">
      <?= csrf_field() ?><input type="hidden" name="action" value="product"><input type="hidden" name="design_id" value="<?= $pe === 'new' ? '' : (int)$pe['id'] ?>">
      <h3><?= $pe === 'new' ? 'New product for ' . h($p['name']) : 'Edit “' . h($pe['name']) . '”' ?></h3>
      <div class="pm-row-fields">
        <label class="field"><span class="lbl">Product name <small class="muted">same as on their Shopify, if they use it</small></span><input name="name" required maxlength="150" value="<?= h((string)$fv['name']) ?>"></label>
        <label class="field"><span class="lbl">T-shirt</span><select name="tshirt" required data-pm-ts><option value="">Choose…</option>
          <?php foreach ($catTs as $k => $t): ?><option value="<?= h($k) ?>" <?= ($fv['tshirt'] ?? '') === $k ? 'selected' : '' ?>><?= h($t['label']) ?></option><?php endforeach; ?></select></label>
        <label class="field"><span class="lbl">Colour</span><select name="color" required data-pm-col data-sel="<?= h((string)($fv['color'] ?? '')) ?>"><option value="">Choose the T-shirt first</option></select></label>
      </div>
      <div class="pm-row-fields">
        <label class="field"><span class="lbl">Print file link <small class="muted">Google Drive</small></span><input name="print_link" type="url" value="<?= h((string)($fv['print_link'] ?? '')) ?>" placeholder="https://drive.google.com/…"></label>
        <label class="field"><span class="lbl">Note</span><input name="note" maxlength="2000" value="<?= h((string)($fv['note'] ?? '')) ?>"></label>
        <label class="field"><span class="lbl">Add mock-up photos <small class="muted">stored small</small></span><input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple></label>
      </div>
      <?php if ($pimgs): ?><div class="pm-pics"><?php foreach ($pimgs as $im): ?>
        <label class="pm-pic"><img src="<?= h(base_url('image.php?f=' . urlencode(thumb_path($im['filename'])))) ?>" alt=""><span class="check small"><input type="checkbox" name="remove_img[]" value="<?= (int)$im['id'] ?>"> Remove</span></label>
      <?php endforeach; ?></div><?php endif; ?>
      <?php if (!empty($ev['extra']['shop_colours'])): ?><p class="small muted">Their Shopify colours: <?= h(implode(', ', array_map(fn($k, $v) => $k . ' → ' . $v, array_keys($ev['extra']['shop_colours']), $ev['extra']['shop_colours']))) ?></p><?php endif; ?>
      <div class="status-btns"><button class="btn primary">Save product</button><a class="btn ghost" href="?id=<?= $id ?>#products">Cancel</a></div>
    </form>
    <script>(function () { var f = document.querySelector('.pm-product'), T = JSON.parse(f.dataset.ts), ts = f.querySelector('[data-pm-ts]'), c = f.querySelector('[data-pm-col]');
      function fill() { var t = T[ts.value], keep = c.value || c.dataset.sel; c.innerHTML = '<option value="">' + (t ? 'Choose…' : 'Choose the T-shirt first') + '</option>' + (t ? t.colours.map(function (n) { var o = document.createElement('option'); o.textContent = n; if (n === keep) o.setAttribute('selected', ''); return o.outerHTML; }).join('') : ''); }
      ts.addEventListener('change', fill); fill(); })();</script>
  <?php elseif (!$pdes): ?><p class="muted small">No products yet. They create them in their portal (or import from Shopify) — or add one here.</p>
  <?php else: ?>
    <div class="pm-products">
      <?php foreach ($pdes as $d): ?>
        <div class="pm-prod">
          <?php if ($d['images']): ?><img src="<?= h(base_url('image.php?f=' . urlencode(thumb_path($d['images'][0])))) ?>" alt="" loading="lazy"><?php else: ?><span class="pm-noimg">👕</span><?php endif; ?>
          <div><b><?= h($d['name']) ?></b><span class="small muted"><?= $d['product'] !== '' ? h($d['product'] . ' · ' . $d['gsm'] . ' · ' . (count((array)($d['extra']['colours'] ?? [])) > 1 ? implode(', ', $d['extra']['colours']) : $d['color'])) : 'No T-shirt set' ?></span>
            <span class="small"><?= !empty($d['extra']['print_link']) ? '<a href="' . h($d['extra']['print_link']) . '" target="_blank" rel="noopener">Print file ↗</a>' : '<span class="muted">No print file link</span>' ?></span></div>
          <div class="pm-prod-acts"><a class="btn small" href="?id=<?= $id ?>&product=<?= (int)$d['id'] ?>#products">Edit</a>
            <form method="post" onsubmit="return confirm('Delete “<?= h(addslashes($d['name'])) ?>”?')"><?= csrf_field() ?><input type="hidden" name="action" value="product_delete"><input type="hidden" name="design_id" value="<?= (int)$d['id'] ?>"><button class="btn small ghost">Delete</button></form></div>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php else: $all = partners_all(); $wait = array_filter($all, fn($x) => ($x['approval'] ?? 'approved') === 'pending'); $all = array_filter($all, fn($x) => ($x['approval'] ?? 'approved') !== 'pending'); ?>
<?php if ($wait): ?>
<section class="panel pm-wait">
  <h2>Waiting for approval <span class="count"><?= count($wait) ?></span> <small class="muted">signed up themselves at <?= h($portal) ?></small></h2>
  <?php foreach ($wait as $w): $wu = partner_users((int)$w['id'])[0] ?? null; ?>
    <div class="pm-row">
      <div><b><?= h($w['name']) ?></b> · <?= h($wu['name'] ?? '') ?> · <a href="tel:<?= h($w['phone']) ?>"><?= h($w['phone']) ?></a> · <?= h($w['email']) ?><?= $w['gstin'] !== '' ? ' · GSTIN ' . h($w['gstin']) : '' ?>
        <br><span class="small muted">Login <b><?= h($wu['username'] ?? '') ?></b> · <?= h(fmt_date($w['created_at'], true)) ?><?= trim((string)$w['signup_note']) !== '' ? ' · ' . h(preg_replace('/\s*— signed up.*$/s', '', (string)$w['signup_note'])) : '' ?></span></div>
      <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="pid" value="<?= (int)$w['id'] ?>">
        <label class="small">Customer ID <input name="code" value="<?= h($w['code']) ?>" style="max-width:150px"></label>
        <button class="btn small primary" name="action" value="approve">✓ Approve</button>
        <button class="btn small ghost" name="action" value="reject" onclick="return confirm('Reject this sign-up?')">Reject</button></form>
    </div>
  <?php endforeach; ?>
</section>
<?php endif; ?>
<section class="panel" id="paysetup">
  <h2>Partner payments <small class="muted">QR codes they scan to recharge · Looma WhatsApp for their questions</small></h2>
  <form method="post" enctype="multipart/form-data" class="stack"><?= csrf_field() ?><input type="hidden" name="action" value="pay_setup">
    <div class="pm-row-fields">
      <?php foreach (['qr_gst' => ['partner_qr_gst', 'QR for partners with GST'], 'qr_nogst' => ['partner_qr_nogst', 'QR for partners without GST']] as $field => [$k, $label]): $cur = (string)setting($k, ''); ?>
        <label class="field"><span class="lbl"><?= h($label) ?></span><input type="file" name="<?= $field ?>" accept="image/png,image/jpeg,image/webp">
          <?php if ($cur !== ''): ?><a href="<?= h(base_url('image.php?f=' . urlencode($cur))) ?>" target="_blank" rel="noopener"><img class="pm-qr" src="<?= h(base_url('image.php?f=' . urlencode($cur))) ?>" alt="<?= h($label) ?>"></a><?php else: ?><small class="muted">Not added yet</small><?php endif; ?></label>
      <?php endforeach; ?>
      <label class="field"><span class="lbl">Looma WhatsApp for partners</span><input name="partner_whatsapp" value="<?= h((string)setting('partner_whatsapp', '')) ?>" inputmode="tel" placeholder="<?= h(setting('slip_ret_phone', '') ?: '10-digit mobile') ?>"></label>
    </div>
    <p class="small muted" style="margin:0">A partner with a GSTIN sees the GST QR, others the non-GST QR — change it for one partner in their Prices &amp; payment.</p>
    <div class="status-btns"><button class="btn primary">Save</button></div>
  </form>
</section>
<?php if (!$all): ?><p class="empty">No partners yet. “＋ Add partner” for each print-on-demand company.</p><?php endif; ?>
<div class="cards">
  <?php foreach ($all as $x):
      $n = q("SELECT SUM(status = 'new') n, COUNT(*) t FROM partner_orders WHERE partner_id = ?", [$x['id']])->fetch(); ?>
    <article class="card">
      <a class="card-link" href="?id=<?= (int)$x['id'] ?>" aria-label="Open <?= h($x['name']) ?>"></a>
      <div class="row-between"><strong><?= h($x['name']) ?></strong><?= $x['active'] ? '' : '<span class="badge">off</span>' ?></div>
      <div class="small muted">Customer ID <?= h($x['code']) ?> · orders <?= h(partner_prefix($x)) ?>-…</div>
      <div class="small"><?= (int)$n['t'] ?> orders<?= (int)$n['n'] ? ' · <b class="err-text">' . (int)$n['n'] . ' waiting</b>' : '' ?> · <?= count(partner_users((int)$x['id'])) ?> login(s)</div>
    </article>
  <?php endforeach; ?>
</div>
<?php endif; ?>
<?php require __DIR__ . '/../inc/footer.php'; ?>
