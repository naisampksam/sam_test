<?php
// Orders sent by print-on-demand partners: check, then ✓ Accept (becomes a normal order) or ✗ Not accept (with why).
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/shopify.php';
require __DIR__ . '/inc/partner_money.php';

require_login();
if (!cap('create')) {
    http_response_code(403);
    exit('You are not allowed to accept partner orders (needs “Create new orders”).');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $ids = array_map('intval', (array)($_POST['ids'] ?? [$_POST['id'] ?? 0]));
    if (($_POST['action'] ?? '') === 'accept') {
        $made = [];
        $fail = [];
        foreach ($ids as $pid) {
            $po = partner_order($pid);
            [$oid, $errs] = partner_order_accept($pid, !empty($_POST['override']));
            if ($oid) {
                $made[] = ($po['ref'] ?? '') . ' → ' . order_no($oid);
            } else {
                $fail[] = ($po['ref'] ?? '#' . $pid) . ': ' . implode(' ', $errs);
            }
        }
        if ($made) {
            flash('Accepted: ' . implode(', ', $made) . ($fail ? "\nNot done: " . implode("\n", $fail) : ''), $fail ? 'err' : 'ok');
        } else {
            flash('Not done: ' . implode("\n", $fail), 'err');
        }
    } elseif (($_POST['action'] ?? '') === 'link') {
        $err = shop_link_save((int)($_POST['partner_id'] ?? 0), (string)($_POST['title'] ?? ''), (int)($_POST['design_id'] ?? 0), (string)($_POST['tshirt'] ?? ''));
        flash($err ?? 'Linked. Every order with this Shopify product now uses that design.', $err ? 'err' : 'ok');
    } elseif (($_POST['action'] ?? '') === 'fix') {
        $err = shop_fix_items((int)$ids[0], (array)($_POST['fix'] ?? []));
        flash($err ?? 'Saved.', $err ? 'err' : 'ok');
    } elseif (in_array($_POST['action'] ?? '', ['change_done', 'change_declined'], true)) {
        $ok = partner_change_answer((int)$ids[0], $_POST['action'] === 'change_done' ? 'done' : 'declined', (string)($_POST['reply'] ?? ''));
        flash($ok ? 'Answered. The partner sees it.' : 'Already answered.', $ok ? 'ok' : 'err');
    } elseif (($_POST['action'] ?? '') === 'import') {
        require_once __DIR__ . '/inc/vyapar.php';
        $pp = partner_by_id((int)($_POST['partner_id'] ?? 0));
        $f = $_FILES['export'] ?? null;
        if (!$pp || !$f || $f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
            flash('Choose the partner and the Shopify export file (.csv).', 'err');
        } else {
            try {
                [$added, $skipped, $errs] = shop_import_export($pp, sheet_rows($f['tmp_name'], $f['name']));
                flash($errs ? implode(' ', $errs) : "$added order" . ($added === 1 ? '' : 's') . " added to Waiting; $skipped already here, cancelled or before the start number.", $errs ? 'err' : 'ok');
            } catch (Throwable $e) {
                flash($e->getMessage(), 'err');
            }
        }
    } elseif (($_POST['action'] ?? '') === 'reject') {
        $ok = partner_order_reject((int)$ids[0], (string)($_POST['reason'] ?? ''));
        flash($ok ? 'Marked as not accepted. The partner sees the reason.' : 'Type why it is not accepted.', $ok ? 'ok' : 'err');
    }
    redirect('partner_orders.php' . (!empty($_POST['tab']) ? '?tab=' . urlencode((string)$_POST['tab']) : ''));
}

$tabs = ['new' => 'Waiting', 'changes' => 'Change requests', 'accepted' => 'Accepted', 'closed' => 'Not accepted / cancelled'];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'new';
$partnerF = (int)($_GET['partner'] ?? 0);
$st = ['new' => "po.status = 'new'", 'changes' => "po.change_state = 'open'", 'accepted' => "po.status = 'accepted'", 'closed' => "po.status IN ('rejected', 'cancelled')"];
$counts = [];
foreach ($st as $k => $w) {
    $counts[$k] = (int)q("SELECT COUNT(*) FROM partner_orders po WHERE $w" . ($partnerF ? ' AND po.partner_id = ' . $partnerF : ''))->fetchColumn();
}
$rows = q('SELECT po.*, p.name AS partner_name, p.code FROM partner_orders po JOIN partners p ON p.id = po.partner_id WHERE ' . $st[$tab]
    . ($partnerF ? ' AND po.partner_id = ' . $partnerF : '') . ' ORDER BY ' . ($tab === 'new' ? 'po.urgent DESC, po.id ASC' : 'po.id DESC') . ' LIMIT 200')->fetchAll();
// Designs (with first photo) of the listed orders.
$dids = [];
foreach ($rows as &$r) {
    $r['items'] = json_decode($r['items'] ?: '[]', true) ?: [];
    foreach ($r['items'] as $it) {
        $dids[(int)$it['design_id']] = true;
    }
}
unset($r);
$des = [];
if ($dids) {
    foreach (q('SELECT d.id, d.name, d.extra, (SELECT filename FROM design_images i WHERE i.design_id = d.id ORDER BY i.id LIMIT 1) img FROM designs d WHERE d.id IN (' . implode(',', array_keys($dids)) . ')')->fetchAll() as $d) {
        $d['extra'] = json_decode($d['extra'] ?: '{}', true) ?: [];
        $des[(int)$d['id']] = $d;
    }
}

$pageTitle = 'Partner orders';
$active = 'partner_orders';
require __DIR__ . '/inc/header.php';
?>
<div class="page-head">
  <div><h1>Partner orders</h1><p class="muted small">Sent by print-on-demand partners. ✓ Accept makes a normal order (customer = the partner, their brand on the label).</p></div>
  <div class="actions"><?php if (is_admin()): ?><a class="btn" href="<?= h(base_url('admin/partners.php')) ?>">Partners & logins</a><?php endif; ?></div>
</div>
<nav class="tabs" aria-label="Partner orders">
  <?php foreach ($tabs as $k => $l): ?><a href="?tab=<?= $k ?><?= $partnerF ? '&partner=' . $partnerF : '' ?>" class="<?= $tab === $k ? 'on' : '' ?> <?= in_array($k, ['new', 'changes'], true) && $counts[$k] ? 'warn' : '' ?>"><?= h($l) ?> <span class="count"><?= $counts[$k] ?></span></a><?php endforeach; ?>
</nav>
<?php $plist = partners_all(); if (count($plist) > 1): ?>
<nav class="date-chips" aria-label="Partner"><span class="muted small">Partner:</span>
  <a href="?tab=<?= $tab ?>" class="<?= !$partnerF ? 'on' : '' ?>">All</a>
  <?php foreach ($plist as $x): ?><a href="?tab=<?= $tab ?>&partner=<?= (int)$x['id'] ?>" class="<?= $partnerF === (int)$x['id'] ? 'on' : '' ?>"><?= h($x['name']) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<?php foreach (array_filter($plist, fn($x) => $x['shop_on'] && (!$partnerF || (int)$x['id'] === $partnerF)) as $sp): $miss = shop_missing_numbers($sp); if ($miss): ?>
  <div class="alert warn">⚠ <?= h($sp['name']) ?>: Shopify order<?= count($miss) === 1 ? '' : 's' ?> <b><?= h(implode(', ', array_map(fn($n) => '#' . $n, array_slice($miss, 0, 12)))) ?><?= count($miss) > 12 ? ' …' : '' ?></b> did not arrive (maybe cancelled or deleted in Shopify). Missing real orders? Upload their Shopify export below.</div>
<?php endif; endforeach; ?>
<?php if ($shopPartners = array_filter($plist, fn($x) => $x['shop_on'])): ?>
<details class="po-import"><summary class="small">⬆ Upload a Shopify orders export (adds any order that did not arrive)</summary>
  <form method="post" enctype="multipart/form-data" class="inline-form" data-plain-submit><?= csrf_field() ?><input type="hidden" name="action" value="import"><input type="hidden" name="tab" value="new">
    <select name="partner_id" required><?php foreach ($shopPartners as $sp): ?><option value="<?= (int)$sp['id'] ?>" <?= $partnerF === (int)$sp['id'] ? 'selected' : '' ?>><?= h($sp['name']) ?></option><?php endforeach; ?></select>
    <input type="file" name="export" accept=".csv,.xlsx" required><button class="btn small">Upload</button></form>
  <p class="hint">Partner's Shopify → Orders → Export → “CSV for Excel”. Orders already here are skipped.</p></details>
<?php endif; ?>
<?php if (!$rows): ?><p class="empty"><?= $tab === 'new' ? 'Nothing waiting. New partner orders show up here (and on your phone).' : 'No orders here.' ?></p><?php endif; ?>

<?php if ($tab === 'new' && count($rows) > 1): ?>
<form method="post" id="acceptAll" class="status-btns"><?= csrf_field() ?><input type="hidden" name="action" value="accept"><input type="hidden" name="tab" value="new">
  <?php $readyIds = array_filter($rows, function ($x) { if (!partner_order_ready($x)) return false; $pp = partner_by_id((int)$x['partner_id']); [, $qt] = partner_quote($pp, $x['items'], (string)$x['payment_type']); return !partner_money_block($pp, (float)$qt['total']); }); foreach ($readyIds as $r): ?><input type="hidden" name="ids[]" value="<?= (int)$r['id'] ?>"><?php endforeach; ?>
  <button class="btn primary" <?= $readyIds ? '' : 'disabled' ?> onclick="return confirm('Accept all <?= count($readyIds) ?> ready orders?')">✓ Accept all <?= count($readyIds) ?> ready</button></form>
<?php endif; ?>

<div class="cards po-cards">
<?php $catOpts = [];
foreach (catalog() as $g) { foreach ($g['products'] as $pr) { $catOpts[$g['gsm'] . '|' . $pr['name']] = $pr; } }
$pdes = [];
$pcache = [];
foreach ($rows as $r): $block = null; $pcs = array_sum(array_column($r['items'], 'qty'));
    $isShop = $r['source'] !== 'portal';
    $open = $r['status'] === 'new' ? shop_open_points($r['items']) : ['link' => [], 'fix' => []];
    $ready = !$open['link'] && !$open['fix']; ?>
  <article class="card po-card <?= $r['urgent'] ? 'is-delayed' : '' ?>" data-po="<?= (int)$r['id'] ?>">
    <div class="row-between"><strong><?= h($r['partner_name']) ?> · <?= h($r['ref']) ?><?= $isShop ? ' <span class="mini-tag">Shopify</span>' : '' ?></strong>
      <span class="badge pt-<?= h($r['status']) ?>"><?= h(PARTNER_STATUS[$r['status']]) ?></span></div>
    <div class="small muted"><?= h(fmt_date($r['created_at'], true)) ?><?= $r['urgent'] ? ' · <b class="err-text">⚡ Urgent</b>' : '' ?></div>
    <div class="po-cust"><b><?= h($r['ship_name']) ?></b> · <?= h($r['ship_phone']) ?><?= $r['ship_phone2'] !== '' ? ' / ' . h($r['ship_phone2']) : '' ?><br>
      <span class="small"><?= nl2br(h($r['ship_address'])) ?><?= $r['landmark'] !== '' ? '<br>Landmark: ' . h($r['landmark']) : '' ?> · <b><?= h($r['ship_pincode']) ?></b></span></div>
    <ul class="pt-lines">
      <?php foreach ($r['items'] as $it): $d = $des[(int)$it['design_id']] ?? null; ?>
        <li><?php if ($d && $d['img']): ?><img src="image.php?f=<?= h(urlencode(thumb_path($d['img']))) ?>" alt="" loading="lazy"><?php endif; ?>
          <span><b><?= h($it['design']) ?></b><?= $isShop && ($it['shop_title'] ?? '') !== '' && $it['shop_title'] !== $it['design'] ? ' <small class="muted">(' . h($it['shop_title']) . ')</small>' : '' ?><?= $d && !empty($d['extra']['partner_new']) ? ' <span class="mini-tag warn">new design · check it</span>' : '' ?><br>
          <?= h(implode(' · ', array_filter([$it['gsm'], $it['product'], $it['color'] ?: '<colour?>', $it['size'] ?: '<size?>']))) ?><?= $isShop && ($it['variant'] ?? '') !== '' ? ' <small class="muted">— Shopify: ' . h($it['variant']) . '</small>' : '' ?> <b>× <?= (int)$it['qty'] ?></b>
          <?php if ($d && !empty($d['extra']['partner_note'])): ?><br><small class="muted">Note: <?= h($d['extra']['partner_note']) ?></small><?php endif; ?></span></li>
      <?php endforeach; ?>
    </ul>
    <div class="meta"><b class="qty"><?= plural((int)$pcs, 'pc', 'pcs') ?></b><?= $r['payment_type'] === 'cod' ? ' <span class="badge cod">COD ₹' . h(inr_number((float)$r['cod_amount'])) . '</span>' : ' <span>Prepaid</span>' ?>
      <?= $r['courier'] !== '' ? '<span>' . h($r['courier']) . '</span>' : '' ?></div>
    <?php if ($r['notes'] !== ''): ?><p class="small">📝 <?= nl2br(h($r['notes'])) ?></p><?php endif; ?>
    <?php foreach ($open['link'] as $title): $pdes[$r['partner_id']] ??= partner_designs(['code' => $r['code']]); ?>
      <form method="post" class="po-link"><?= csrf_field() ?><input type="hidden" name="action" value="link"><input type="hidden" name="partner_id" value="<?= (int)$r['partner_id'] ?>"><input type="hidden" name="title" value="<?= h($title) ?>"><input type="hidden" name="tab" value="new">
        <b>🔗 Link “<?= h($title) ?>”</b> <small class="muted">once — later orders with it are automatic</small>
        <div class="inline-form">
          <select name="design_id" required><option value="">Saved design…</option><?php foreach ($pdes[$r['partner_id']] as $dd): ?><option value="<?= (int)$dd['id'] ?>" <?= shop_title_key($dd['name']) === shop_title_key($title) ? 'selected' : '' ?>><?= h($dd['name']) ?></option><?php endforeach; ?></select>
          <select name="tshirt" required><option value="">T-shirt…</option><?php foreach ($catOpts as $ck => $cp): ?><option value="<?= h($ck) ?>"><?= h($cp['name'] . ' · ' . explode('|', $ck)[0]) ?></option><?php endforeach; ?></select>
          <button class="btn small primary">Link</button></div>
        <?php if (!$pdes[$r['partner_id']]): ?><p class="hint">No saved designs for <?= h($r['partner_name']) ?> yet: add one in <a href="designs.php">Saved designs</a> with customer ID <?= h($r['code']) ?>.</p><?php endif; ?>
      </form>
    <?php endforeach; ?>
    <?php if ($open['fix']): ?>
      <form method="post" class="po-link"><?= csrf_field() ?><input type="hidden" name="action" value="fix"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="tab" value="new">
        <b>Pick what Shopify didn't make clear</b>
        <?php foreach ($open['fix'] as $i => $miss): $it = $r['items'][$i]; $cp = $catOpts[$it['gsm'] . '|' . $it['product']] ?? null; ?>
          <div class="inline-form"><span class="small"><?= h($it['design'] . ' — ' . ($it['variant'] ?? '')) ?></span>
            <?php if (in_array('colour', $miss, true)): ?><select name="fix[<?= (int)$i ?>][color]" required><option value="">Colour…</option><?php foreach ($cp['colors'] ?? [] as $cc): ?><option><?= h($cc['name']) ?></option><?php endforeach; ?></select><?php endif; ?>
            <?php if (in_array('size', $miss, true)): ?><select name="fix[<?= (int)$i ?>][size]" required><option value="">Size…</option><?php foreach ($cp['sizes'] ?? [] as $sz): ?><option><?= h($sz) ?></option><?php endforeach; ?></select><?php endif; ?></div>
        <?php endforeach; ?>
        <button class="btn small primary">Save</button></form>
    <?php endif; ?>
    <?php if ($r['change_state'] !== ''): ?>
      <div class="po-change <?= h($r['change_state']) ?>"><b>🔁 Change asked <?= $r['change_at'] ? h(fmt_date($r['change_at'], true)) : '' ?>:</b> <?= nl2br(h((string)$r['change_req'])) ?>
        <?php if ($r['change_state'] === 'open'): ?>
          <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="tab" value="changes">
            <input name="reply" maxlength="255" placeholder="Reply for the partner (optional)">
            <button class="btn small primary" name="action" value="change_done">✓ Done</button><button class="btn small ghost" name="action" value="change_declined">Not possible</button></form>
          <?php if ($r['order_id']): ?><p class="hint">Change the order itself first: <a href="order.php?id=<?= (int)$r['order_id'] ?>">open <?= h(order_no($r['order_id'])) ?></a>.</p><?php endif; ?>
        <?php else: ?><span class="small muted"> — <?= $r['change_state'] === 'done' ? 'done' : 'not possible' ?><?= $r['change_reply'] !== '' ? ': ' . h($r['change_reply']) : '' ?></span><?php endif; ?>
      </div>
    <?php endif; ?>
    <?php if ($r['status'] === 'new' && $ready):
        $pp = $pcache[$r['partner_id']] ??= partner_by_id((int)$r['partner_id']);
        [, $qt] = partner_quote($pp, $r['items'], (string)$r['payment_type']);
        $block = partner_money_block($pp, (float)$qt['total']); ?>
      <div class="po-money <?= $block ? 'warn' : '' ?>">Charge <b>₹<?= h(inr_number((float)$qt['total'], 2)) ?></b> · <?= h(PARTNER_PAY_MODES[$pp['pay_mode']] ?? '') ?><?= $block ? '<br>⚠ ' . h($block) : ' · ✓ money OK' ?></div>
    <?php endif; ?>
    <?php if ($r['status'] === 'new'): ?>
      <div class="po-actions">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="accept"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="tab" value="new">
          <?php if (!empty($block) && is_admin()): ?><label class="check small"><input type="checkbox" name="override" value="1"> Accept anyway</label><?php endif; ?>
          <button class="btn primary" <?= $ready && (empty($block) || is_admin()) ? '' : 'disabled title="' . ($ready ? 'Money first' : 'Link the Shopify product first') . '"' ?>>✓ Accept</button></form>
        <details><summary class="btn ghost">✗ Not accept</summary>
          <form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="reject"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="tab" value="new">
            <input name="reason" required maxlength="255" placeholder="Why? The partner sees this" list="dl_po_reasons"><button class="btn small danger">Send</button></form></details>
      </div>
    <?php elseif ($r['status'] === 'accepted' && $r['order_id']): ?>
      <p><a class="btn small" href="order.php?id=<?= (int)$r['order_id'] ?>">Open <?= h(order_no($r['order_id'])) ?> →</a></p>
    <?php elseif ($r['reason'] !== ''): ?><p class="small muted">Why: <?= h($r['reason']) ?></p><?php endif; ?>
  </article>
<?php endforeach; ?>
</div>
<datalist id="dl_po_reasons"><option value="Design not clear — send a better mock-up"><option value="Address incomplete"><option value="Pincode not serviceable"><option value="Size / colour out of stock"><option value="Duplicate order"></datalist>
<?php require __DIR__ . '/inc/footer.php'; ?>
