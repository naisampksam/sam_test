<?php
// Admin: print-on-demand partners (Outfitcrew, Mangatholi …) and their logins for the partner portal.
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/shopify.php';
require __DIR__ . '/../inc/partner_money.php';
require __DIR__ . '/../inc/partner_stock.php';

require_admin();
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
        $errors = save_partner_money($id, $_POST);
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
  <div class="table-wrap"><table class="table compact">
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
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="action" value="money">
    <div class="grid-2">
      <label class="field"><span class="lbl">How they pay</span><select name="pay_mode" data-paymode><?php foreach (PARTNER_PAY_MODES as $k => $l): ?><option value="<?= $k ?>" <?= $p['pay_mode'] === $k ? 'selected' : '' ?>><?= h($l) ?></option><?php endforeach; ?></select></label>
      <div class="grid-2" data-credit>
        <label class="field"><span class="lbl">Credit limit ₹ <small class="muted">0 = no limit</small></span><input name="credit_limit" inputmode="decimal" value="<?= h((string)(float)$p['credit_limit']) ?>"></label>
        <label class="field"><span class="lbl">Pay within (days)</span><input name="credit_days" inputmode="numeric" value="<?= (int)$p['credit_days'] ?>"></label>
      </div>
      <label class="field"><span class="lbl">Their GSTIN <small class="muted">empty = no invoice for them</small></span><input name="gstin" value="<?= h($p['gstin']) ?>" maxlength="15" style="text-transform:uppercase"></label>
      <label class="check"><input type="checkbox" name="gst_add" value="1" <?= $p['gst_add'] ? 'checked' : '' ?>> Add GST on their charges</label>
      <label class="check"><input type="checkbox" name="own_blanks" value="1" <?= partner_own_blanks($p) ? 'checked' : '' ?>> Print-only partner (sends their own blank T-shirts)</label>
    </div>
    <h3>T-shirt price <small class="muted">per piece · empty = your catalog price</small></h3>
    <div class="grid-3 pm-prices">
      <?php foreach (catalog() as $g) foreach ($g['products'] as $cp): $k = $g['gsm'] . '|' . $cp['name']; ?>
        <label class="field"><span class="lbl"><?= h($cp['name'] . ' · ' . $g['gsm']) ?></span><input name="tshirt[<?= h($k) ?>]" inputmode="decimal" value="<?= isset($pr['tshirt'][$k]) ? h((string)$pr['tshirt'][$k]) : '' ?>" placeholder="₹ <?= h((string)(catalog_price($g['gsm'], $cp['name'], 1) ?? '')) ?>"></label>
      <?php endforeach; ?>
    </div>
    <h3>Printing, packing, shipping</h3>
    <div class="grid-3">
      <label class="field"><span class="lbl">Printing per piece (default)</span><input name="print" inputmode="decimal" value="<?= h((string)$pr['print']) ?>"></label>
      <label class="field"><span class="lbl">Packing per order</span><input name="packing" inputmode="decimal" value="<?= h((string)$pr['packing']) ?>"></label>
      <label class="field"><span class="lbl">Shipping: first piece</span><input name="ship_first" inputmode="decimal" value="<?= h((string)$pr['ship_first']) ?>"></label>
      <label class="field"><span class="lbl">Shipping: each extra piece</span><input name="ship_extra" inputmode="decimal" value="<?= h((string)$pr['ship_extra']) ?>"></label>
      <label class="field"><span class="lbl">COD charge per COD order</span><input name="cod_fee" inputmode="decimal" value="<?= h((string)$pr['cod_fee']) ?>"></label>
    </div>
    <?php if ($pd): ?>
      <details><summary class="small">Printing price for a design (if different from the default) · <?= count($pd) ?> designs</summary>
        <div class="grid-3"><?php foreach ($pd as $dd): ?><label class="field"><span class="lbl"><?= h($dd['name']) ?></span><input name="design[<?= (int)$dd['id'] ?>]" inputmode="decimal" value="<?= isset($pr['design'][(string)$dd['id']]) ? h((string)$pr['design'][(string)$dd['id']]) : '' ?>" placeholder="₹ <?= h((string)$pr['print']) ?>"></label><?php endforeach; ?></div></details>
    <?php endif; ?>
    <div class="status-btns"><button class="btn primary">Save prices &amp; payment</button></div>
  </form>
  <script>(function () { var s = document.querySelector('[data-paymode]'), c = document.querySelector('[data-credit]'); function t() { c.hidden = s.value !== 'credit'; } s.addEventListener('change', t); t(); })();</script>
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
