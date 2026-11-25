<?php
// Orders sent by print-on-demand partners: check, then ✓ Accept (becomes a normal order) or ✗ Not accept (with why).
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/partners.php';

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
            [$oid, $errs] = partner_order_accept($pid);
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
    } elseif (($_POST['action'] ?? '') === 'reject') {
        $ok = partner_order_reject((int)$ids[0], (string)($_POST['reason'] ?? ''));
        flash($ok ? 'Marked as not accepted. The partner sees the reason.' : 'Type why it is not accepted.', $ok ? 'ok' : 'err');
    }
    redirect('partner_orders.php' . (!empty($_POST['tab']) ? '?tab=' . urlencode((string)$_POST['tab']) : ''));
}

$tabs = ['new' => 'Waiting', 'accepted' => 'Accepted', 'closed' => 'Not accepted / cancelled'];
$tab = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'new';
$partnerF = (int)($_GET['partner'] ?? 0);
$st = ['new' => "po.status = 'new'", 'accepted' => "po.status = 'accepted'", 'closed' => "po.status IN ('rejected', 'cancelled')"];
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
  <?php foreach ($tabs as $k => $l): ?><a href="?tab=<?= $k ?><?= $partnerF ? '&partner=' . $partnerF : '' ?>" class="<?= $tab === $k ? 'on' : '' ?> <?= $k === 'new' && $counts[$k] ? 'warn' : '' ?>"><?= h($l) ?> <span class="count"><?= $counts[$k] ?></span></a><?php endforeach; ?>
</nav>
<?php $plist = partners_all(); if (count($plist) > 1): ?>
<nav class="date-chips" aria-label="Partner"><span class="muted small">Partner:</span>
  <a href="?tab=<?= $tab ?>" class="<?= !$partnerF ? 'on' : '' ?>">All</a>
  <?php foreach ($plist as $x): ?><a href="?tab=<?= $tab ?>&partner=<?= (int)$x['id'] ?>" class="<?= $partnerF === (int)$x['id'] ? 'on' : '' ?>"><?= h($x['name']) ?></a><?php endforeach; ?>
</nav>
<?php endif; ?>
<?php if (!$rows): ?><p class="empty"><?= $tab === 'new' ? 'Nothing waiting. New partner orders show up here (and on your phone).' : 'No orders here.' ?></p><?php endif; ?>

<?php if ($tab === 'new' && count($rows) > 1): ?>
<form method="post" id="acceptAll" class="status-btns"><?= csrf_field() ?><input type="hidden" name="action" value="accept"><input type="hidden" name="tab" value="new">
  <?php foreach ($rows as $r): ?><input type="hidden" name="ids[]" value="<?= (int)$r['id'] ?>"><?php endforeach; ?>
  <button class="btn primary" onclick="return confirm('Accept all <?= count($rows) ?> orders?')">✓ Accept all <?= count($rows) ?></button></form>
<?php endif; ?>

<div class="cards po-cards">
<?php foreach ($rows as $r): $pcs = array_sum(array_column($r['items'], 'qty')); ?>
  <article class="card po-card <?= $r['urgent'] ? 'is-delayed' : '' ?>" data-po="<?= (int)$r['id'] ?>">
    <div class="row-between"><strong><?= h($r['partner_name']) ?> · <?= h($r['ref']) ?></strong>
      <span class="badge pt-<?= h($r['status']) ?>"><?= h(PARTNER_STATUS[$r['status']]) ?></span></div>
    <div class="small muted"><?= h(fmt_date($r['created_at'], true)) ?><?= $r['urgent'] ? ' · <b class="err-text">⚡ Urgent</b>' : '' ?></div>
    <div class="po-cust"><b><?= h($r['ship_name']) ?></b> · <?= h($r['ship_phone']) ?><?= $r['ship_phone2'] !== '' ? ' / ' . h($r['ship_phone2']) : '' ?><br>
      <span class="small"><?= nl2br(h($r['ship_address'])) ?><?= $r['landmark'] !== '' ? '<br>Landmark: ' . h($r['landmark']) : '' ?> · <b><?= h($r['ship_pincode']) ?></b></span></div>
    <ul class="pt-lines">
      <?php foreach ($r['items'] as $it): $d = $des[(int)$it['design_id']] ?? null; ?>
        <li><?php if ($d && $d['img']): ?><img src="image.php?f=<?= h(urlencode(thumb_path($d['img']))) ?>" alt="" loading="lazy"><?php endif; ?>
          <span><b><?= h($it['design']) ?></b><?= $d && !empty($d['extra']['partner_new']) ? ' <span class="mini-tag warn">new design · check it</span>' : '' ?><br>
          <?= h($it['gsm'] . ' · ' . $it['product'] . ' · ' . $it['color'] . ' · ' . $it['size']) ?> <b>× <?= (int)$it['qty'] ?></b>
          <?php if ($d && !empty($d['extra']['partner_note'])): ?><br><small class="muted">Note: <?= h($d['extra']['partner_note']) ?></small><?php endif; ?></span></li>
      <?php endforeach; ?>
    </ul>
    <div class="meta"><b class="qty"><?= plural((int)$pcs, 'pc', 'pcs') ?></b><?= $r['payment_type'] === 'cod' ? ' <span class="badge cod">COD ₹' . h(inr_number((float)$r['cod_amount'])) . '</span>' : ' <span>Prepaid</span>' ?>
      <?= $r['courier'] !== '' ? '<span>' . h($r['courier']) . '</span>' : '' ?></div>
    <?php if ($r['notes'] !== ''): ?><p class="small">📝 <?= nl2br(h($r['notes'])) ?></p><?php endif; ?>
    <?php if ($r['status'] === 'new'): ?>
      <div class="po-actions">
        <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="accept"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input type="hidden" name="tab" value="new"><button class="btn primary">✓ Accept</button></form>
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
