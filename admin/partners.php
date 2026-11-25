<?php
// Admin: print-on-demand partners (Outfitcrew, Mangatholi …) and their logins for the partner portal.
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/partners.php';

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
$portal = is_erp_site() ? 'https://orders.' . preg_replace('/^(erp|www)\./', '', strtolower((string)($_SERVER['HTTP_HOST'] ?? 'loomaapparels.com'))) . '/' : base_url('partner/');
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

<?php else: $all = partners_all(); ?>
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
