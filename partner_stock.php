<?php
// Partner stock (team): each partner's returned (RTO) T-shirts and their own blank T-shirts kept at Looma —
// record blanks received, correct counts, set the "running low" number. Not part of Looma's own stock.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/partner_stock.php';

require_login();
if (!cap('create') && !cap('stock') && !cap('partners')) {
    http_response_code(403);
    exit('You are not allowed to open partner stock.');
}
$pid = (int)($_GET['partner'] ?? 0);
$partners = partners_all();
if (!$pid && $partners) {
    $pid = (int)$partners[0]['id'];
}
$p = $pid ? partner_by_id($pid) : null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $p) {
    csrf_check();
    $act = (string)($_POST['action'] ?? '');
    if ($act === 'blanks') {
        $err = partner_blanks_in($pid, (string)($_POST['tshirt'] ?? ''), (string)($_POST['color'] ?? ''), (array)($_POST['qty'] ?? []), (string)($_POST['note'] ?? ''));
        flash($err ?? 'Blank T-shirts added to ' . $p['name'] . '’s stock.', $err ? 'err' : 'ok');
    } elseif ($act === 'set') {
        $err = partner_stock_set((int)($_POST['id'] ?? 0), (int)($_POST['qty'] ?? 0), (string)($_POST['note'] ?? ''));
        flash($err ?? 'Count saved.', $err ? 'err' : 'ok');
    } elseif ($act === 'low') {
        $s = json_decode((string)($p['settings'] ?? '') ?: '{}', true) ?: [];
        $s['blank_low'] = max(0, (int)($_POST['blank_low'] ?? 5));
        update_row('partners', $pid, ['settings' => json_encode($s, JSON_UNESCAPED_UNICODE)]);
        flash('Saved.');
    }
    redirect('partner_stock.php?partner=' . $pid);
}
$pageTitle = 'Partner stock';
$active = 'partner_stock';
require __DIR__ . '/inc/header.php';
?>
<div class="page-head">
  <div><h1>Partner stock</h1><p class="muted small">Their returned T-shirts and their own blank T-shirts kept at Looma (not part of Looma’s stock).</p></div>
</div>
<?php if (count($partners) > 1): ?>
<nav class="date-chips" aria-label="Partner"><?php foreach ($partners as $x): ?><a href="?partner=<?= (int)$x['id'] ?>" class="<?= $pid === (int)$x['id'] ? 'on' : '' ?>"><?= h($x['name']) ?></a><?php endforeach; ?></nav>
<?php endif; ?>
<?php if (!$p): ?><p class="empty">No partners yet.</p><?php else:
    $ret = partner_stock_rows($pid, 'return', true); $blank = partner_stock_rows($pid, 'blank', true); $low = array_column(partner_blanks_low($p), 'id');
    $sett = json_decode((string)($p['settings'] ?? '') ?: '{}', true) ?: []; ?>
<?php if (!partner_own_blanks($p)): ?>
  <p class="hint"><?= h($p['name']) ?> is not a print-only partner. To take their own blank T-shirts, tick “Print-only partner” in <a href="<?= h(base_url('admin/partners.php?id=' . $pid . '#money')) ?>">Partners → Prices &amp; payment</a>.</p>
<?php else: ?>
<section class="panel">
  <h2>Blank T-shirts received from <?= h($p['name']) ?></h2>
  <form method="post" class="stack" id="blankForm"><?= csrf_field() ?><input type="hidden" name="action" value="blanks">
    <div class="grid-3">
      <label class="field"><span class="lbl">T-shirt</span><select name="tshirt" required data-bt><option value="">Choose…</option>
        <?php $cat = []; foreach (catalog() as $g) foreach ($g['products'] as $cp): $k = $g['gsm'] . '|' . $cp['name']; $cat[$k] = $cp; ?><option value="<?= h($k) ?>"><?= h($cp['name'] . ' · ' . $g['gsm']) ?></option><?php endforeach; ?></select></label>
      <label class="field"><span class="lbl">Colour</span><input name="color" required list="dl_bcol" placeholder="e.g. Black"></label>
      <label class="field"><span class="lbl">Note</span><input name="note" placeholder="e.g. courier, date" maxlength="255"></label>
    </div>
    <div class="size-qty" data-bsizes></div>
    <div class="status-btns"><button class="btn primary">Add to their stock</button></div>
  </form>
  <datalist id="dl_bcol"></datalist>
  <script>(function () {
    var CAT = <?= json_encode($cat, JSON_UNESCAPED_UNICODE) ?>, sel = document.querySelector('[data-bt]'), box = document.querySelector('[data-bsizes]'), dl = document.getElementById('dl_bcol');
    function draw() { var p = CAT[sel.value]; box.innerHTML = p ? p.sizes.map(function (s) { return '<label class="sq"><b>' + s + '</b><input type="number" name="qty[' + s + ']" min="-9999" step="1" inputmode="numeric" placeholder="0"></label>'; }).join('') : '';
      dl.innerHTML = p ? p.colors.map(function (c) { return '<option value="' + c.name.replace(/"/g, '&quot;') + '">'; }).join('') : ''; }
    sel.addEventListener('change', draw); draw(); })();</script>
  <form method="post" class="inline-form" style="margin-top:10px"><?= csrf_field() ?><input type="hidden" name="action" value="low">
    <span class="small">Warn when a size has</span><input name="blank_low" type="number" min="0" value="<?= (int)($sett['blank_low'] ?? 5) ?>" style="max-width:90px"><span class="small">or fewer left</span><button class="btn small">Save</button></form>
</section>

<?php endif; ?>
<?php foreach (array_filter(['blank' => ['Their own blank T-shirts', $blank], 'return' => ['Returned T-shirts (RTO)', $ret]], fn($k) => $k === 'return' || partner_own_blanks($p), ARRAY_FILTER_USE_KEY) as $kind => [$title, $rows]): ?>
<section class="panel">
  <h2><?= h($title) ?> <small class="muted"><?= array_sum(array_column($rows, 'qty')) ?> pcs</small></h2>
  <?php if (!$rows): ?><p class="muted">None.</p><?php else: ?>
  <div class="table-wrap"><table class="table compact"><thead><tr><?php if ($kind === 'return'): ?><th>Design</th><?php endif; ?><th>T-shirt</th><th>Colour</th><th>Size</th><th class="num">Left</th><th>Correct the count</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr class="<?= in_array($r['id'], $low) ? 'below-min' : '' ?>"><?php if ($kind === 'return'): ?><td><?= h($r['design_name']) ?></td><?php endif; ?><td><?= h($r['product'] . ' · ' . $r['gsm']) ?></td><td><?= h($r['color']) ?></td><td><?= h($r['size']) ?></td><td class="num"><b><?= (int)$r['qty'] ?></b></td>
    <td><form method="post" class="inline-form"><?= csrf_field() ?><input type="hidden" name="action" value="set"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><input name="qty" type="number" min="0" value="<?= (int)$r['qty'] ?>" style="max-width:80px"><input name="note" placeholder="why" maxlength="255"><button class="btn small">Save</button></form></td></tr><?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</section>
<?php endforeach; endif; ?>
<?php require __DIR__ . '/inc/footer.php'; ?>
