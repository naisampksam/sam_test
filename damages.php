<?php
// Damaged T-shirts: record T-shirt damage, printing mistakes and embroidery mistakes (product → colour → pieces per size,
// the same way as adding stock). The pieces come out of stock; mistakes are marked red on the person's performance.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/orders.php';
require __DIR__ . '/inc/damages.php';

require_login();
if (!damage_access()) {
    http_response_code(403);
    exit('Not allowed: damaged T-shirts are for the production team and stock keepers.');
}
$month = preg_match('/^\d{4}-\d{2}$/', $_GET['m'] ?? '') ? $_GET['m'] : date('Y-m');
$from = $month . '-01';
$to = date('Y-m-t', strtotime($from));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $do = $_POST['do'] ?? '';
    if ($do === 'delete' && damage_can_delete()) {
        flash(damage_delete((int)($_POST['id'] ?? 0)) ? 'Deleted. Those pieces are back in stock.' : 'Already deleted.');
        redirect('damages.php?m=' . $month);
    }
    if ($do === 'record') {
        $p = q('SELECT p.*, g.label AS gsm FROM products p JOIN gsm_options g ON g.id = p.gsm_id WHERE p.id = ?', [(int)($_POST['pid'] ?? 0)])->fetch();
        $orderNo = trim((string)($_POST['order'] ?? ''));
        $orderId = $orderNo !== '' ? (int)preg_replace('/\D/', '', $orderNo) : null;
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $_POST['date'] ?? '') && $_POST['date'] <= today() ? $_POST['date'] : today();
        try {
            if (!$p) {
                throw new InvalidArgumentException('Pick the product.');
            }
            if ($orderId && !q('SELECT 1 FROM orders WHERE id = ?', [$orderId])->fetchColumn()) {
                throw new InvalidArgumentException('There is no order ' . $orderNo . '.');
            }
            $n = damage_record((string)($_POST['kind'] ?? ''), $p, trim((string)($_POST['color'] ?? '')), (array)($_POST['qty'] ?? []),
                $orderId ?: null, trim((string)($_POST['note'] ?? '')), $date);
            if (!$n) {
                throw new InvalidArgumentException('Type how many pieces under at least one size.');
            }
            $kind = DAMAGE_KINDS[$_POST['kind']];
            flash("✓ $n damaged pcs recorded ($kind) and taken out of stock" . (in_array($_POST['kind'], DAMAGE_MISTAKES, true) ? ' · red mark on your performance' : '') . '.');
            redirect('damages.php?m=' . substr($date, 0, 7));
        } catch (InvalidArgumentException $e) {
            flash($e->getMessage(), 'err');
            $_SESSION['damage_form'] = $_POST;
            redirect('damages.php?m=' . $month . '#record');
        }
    }
    redirect('damages.php');
}

// Products for the form: colours, sizes and stock now (same as "Add stock").
$products = [];
$stockNow = [];
foreach (q("SELECT gsm, product, color, size, qty FROM stock_items WHERE category = 'Blank T-shirt' AND track = 1 AND active = 1")->fetchAll() as $it) {
    $stockNow[$it['gsm'] . '|' . $it['product']][strtolower($it['color']) . '|' . strtoupper($it['size'])] = (float)$it['qty'];
}
$colors = [];
foreach (q('SELECT * FROM product_colors WHERE active = 1 ORDER BY sort, id')->fetchAll() as $c) {
    $colors[$c['product_id']][] = ['name' => $c['name'], 'hex' => $c['hex']];
}
foreach (q('SELECT p.*, g.label AS gsm FROM products p JOIN gsm_options g ON g.id = p.gsm_id WHERE p.active = 1 ORDER BY g.sort, g.id, p.sort, p.id')->fetchAll() as $p) {
    $products[] = ['id' => (int)$p['id'], 'gsm' => $p['gsm'], 'name' => $p['name'], 'colors' => $colors[$p['id']] ?? [],
        'sizes' => array_values(array_filter(array_map('trim', explode(',', (string)$p['sizes'])), 'strlen')), 'stock' => (object)($stockNow[$p['gsm'] . '|' . $p['name']] ?? [])];
}
$old = $_SESSION['damage_form'] ?? [];
unset($_SESSION['damage_form']);

$tot = damage_totals($from, $to);
$mistakes = damage_mistakes($from, $to);
uasort($mistakes, fn($a, $b) => $b['pcs'] <=> $a['pcs']);
$list = damage_list($from, $to);
$canDel = damage_can_delete();

$pageTitle = 'Damaged T-shirts';
$active = 'damages';
require __DIR__ . '/inc/header.php';
$prevM = date('Y-m', strtotime($from . ' -1 month'));
$nextM = date('Y-m', strtotime($from . ' +1 month'));
?>
<div class="page-head">
  <div><h1>Damaged T-shirts</h1>
    <p class="muted small">Plain T-shirt damage (holes, fabric), printing mistakes and embroidery mistakes. The pieces are taken out of stock. A printing or embroidery mistake is a red mark on the performance of the person who enters it.</p></div>
  <div class="actions">
    <a class="btn" href="damages.php?m=<?= h($prevM) ?>">‹</a>
    <b class="nowrap"><?= h(date('F Y', strtotime($from))) ?></b>
    <?php if ($nextM <= date('Y-m')): ?><a class="btn" href="damages.php?m=<?= h($nextM) ?>">›</a><?php endif; ?>
  </div>
</div>

<div class="stats">
  <div class="stat <?= $tot['pcs'] ? 'stat-alert' : '' ?>"><span class="stat-label">Damaged in <?= h(date('F', strtotime($from))) ?></span><span class="stat-num"><?= $tot['pcs'] ?> <small>pcs</small></span><span class="stat-sub"><?= $tot['n'] ?> record<?= $tot['n'] === 1 ? '' : 's' ?></span></div>
  <?php foreach (DAMAGE_KINDS as $k => $l): ?>
    <div class="stat <?= $tot['by'][$k] && $k !== 'damage' ? 'stat-warn' : '' ?>"><span class="stat-label"><?= DAMAGE_ICONS[$k] ?> <?= h($l) ?></span><span class="stat-num"><?= $tot['by'][$k] ?> <small>pcs</small></span><span class="stat-sub"><?= $k === 'damage' ? 'holes / fabric — no one’s mistake' : 'red mark on the person who entered it' ?></span></div>
  <?php endforeach; ?>
</div>

<section class="panel add-stock" id="record">
  <h2>＋ Record damaged T-shirts</h2>
  <?php if (!$products): ?>
    <p class="muted">Add a product on Products &amp; stock first.</p>
  <?php else: ?>
  <form method="post" id="damageForm" data-products="<?= h(json_encode($products, JSON_UNESCAPED_UNICODE)) ?>" data-color="<?= h((string)($old['color'] ?? '')) ?>">
    <?= csrf_field() ?><input type="hidden" name="do" value="record">
    <label class="field"><span class="lbl">1 · Product</span>
      <select name="pid" id="dmgProduct">
        <?php $lastG = null; foreach ($products as $d): if ($d['gsm'] !== $lastG): ?><?= $lastG !== null ? '</optgroup>' : '' ?><optgroup label="<?= h($d['gsm']) ?>"><?php $lastG = $d['gsm']; endif; ?>
          <option value="<?= $d['id'] ?>" <?= (int)($old['pid'] ?? 0) === $d['id'] ? 'selected' : '' ?>><?= h($d['gsm'] . ' · ' . $d['name']) ?></option>
        <?php endforeach; ?></optgroup>
      </select></label>
    <div class="field"><span class="lbl">2 · Colour</span><div class="chips" id="dmgColors"></div><input type="hidden" name="color" id="dmgColor"></div>
    <div class="field"><span class="lbl">3 · Damaged pieces for each size <small class="muted">(leave empty to skip)</small></span><div class="size-qty" id="dmgSizes"></div></div>
    <div class="field"><span class="lbl">4 · Why is it damaged?</span>
      <div class="seg-toggle dmg-kind">
        <?php foreach (DAMAGE_KINDS as $k => $l): ?>
          <label><input type="radio" name="kind" value="<?= $k ?>" required <?= ($old['kind'] ?? '') === $k ? 'checked' : '' ?>><span><?= DAMAGE_ICONS[$k] ?> <?= h($l) ?></span></label>
        <?php endforeach; ?>
      </div></div>
    <p class="dmg-who note-mistake" id="dmgWho" hidden>✖ This is <b>your</b> mistake: it shows as a red mark on your performance (<?= h(current_user()['name'] ?: current_user()['username']) ?>).</p>
    <div class="grid">
      <label class="field"><span class="lbl">Order no. <small class="muted">(optional)</small></span><input name="order" placeholder="e.g. LA00125" value="<?= h((string)($old['order'] ?? '')) ?>"></label>
      <label class="field"><span class="lbl">Date</span><input type="date" name="date" value="<?= h((string)($old['date'] ?? today())) ?>" max="<?= h(today()) ?>"></label>
      <label class="field"><span class="lbl">Note <small class="muted">(optional)</small></span><input name="note" maxlength="200" placeholder="e.g. print shifted, hole near collar" value="<?= h((string)($old['note'] ?? '')) ?>"></label>
    </div>
    <button class="btn primary as-save">💾 Save &amp; take out of stock</button>
  </form>
  <?php endif; ?>
</section>

<?php if ($mistakes): ?>
<section class="panel panel-alert">
  <h2>✖ Mistakes by person · <?= h(date('F Y', strtotime($from))) ?></h2>
  <div class="table-wrap"><table class="table compact">
    <thead><tr><th>Staff</th><th class="num">🖨 Printing</th><th class="num">🧵 Embroidery</th><th class="num">Times</th><th class="num">Pieces</th></tr></thead>
    <tbody>
    <?php foreach ($mistakes as $sid => $m): ?>
      <tr class="mistake-row"><td><a href="performance.php?p=month&amp;user=<?= (int)$sid ?>"><b><?= h(user_name($sid)) ?></b></a></td>
        <td class="num"><?= $m['print'] ?: '·' ?></td><td class="num"><?= $m['embroidery'] ?: '·' ?></td><td class="num"><?= $m['times'] ?></td><td class="num"><b class="err-text"><?= $m['pcs'] ?></b></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</section>
<?php endif; ?>

<section class="panel">
  <h2>Records · <?= h(date('F Y', strtotime($from))) ?> <small class="muted"><?= count($list) ?></small></h2>
  <?php if (!$list): ?><p class="muted">No damaged T-shirts recorded this month.</p><?php else: ?>
  <div class="table-wrap"><table class="table compact dmg-table">
    <thead><tr><th>Date</th><th>What</th><th>Pieces</th><th>Mistake of</th><th>Order</th><th>Note</th><th>Recorded by</th><?= $canDel ? '<th></th>' : '' ?></tr></thead>
    <tbody>
    <?php foreach ($list as $d): $mis = in_array($d['kind'], DAMAGE_MISTAKES, true); ?>
      <tr class="<?= $mis ? 'mistake-row' : '' ?>">
        <td class="nowrap"><?= h(fmt_date($d['damage_date'])) ?></td>
        <td class="nowrap"><span class="badge <?= $mis ? 'dmg-mistake' : 'plain' ?>"><?= DAMAGE_ICONS[$d['kind']] ?? '' ?> <?= h(DAMAGE_KINDS[$d['kind']] ?? $d['kind']) ?></span></td>
        <td><?= h(damage_line($d)) ?></td>
        <td><?= $d['staff_id'] ? '<b class="err-text">' . h(user_name($d['staff_id'])) . '</b>' : '<span class="muted">—</span>' ?></td>
        <td><?= $d['order_id'] ? '<a href="order.php?id=' . (int)$d['order_id'] . '">' . h(order_no($d['order_id'])) . '</a>' : '<span class="muted">—</span>' ?></td>
        <td><?= h($d['note']) ?></td>
        <td class="muted small"><?= h(user_name($d['created_by'])) ?></td>
        <?php if ($canDel): ?><td><form method="post" onsubmit="return confirm('Delete this record? The <?= (int)$d['qty'] ?> pcs go back into stock.');"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>"><button class="btn small danger" title="Entered by mistake: delete and put the pieces back in stock">Delete</button></form></td><?php endif; ?>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
  <?php endif; ?>
</section>

<script>
// Product → colour chips → a box per size (with what is in stock now) → why it is damaged.
(function () {
  var form = document.getElementById('damageForm');
  if (!form) return;
  var data = JSON.parse(form.dataset.products || '[]'), sel = document.getElementById('dmgProduct');
  var colorsBox = document.getElementById('dmgColors'), sizesBox = document.getElementById('dmgSizes'), colorIn = document.getElementById('dmgColor');
  var who = document.getElementById('dmgWho'), whoSel = who.querySelector('select');
  var esc = function (v) { return String(v).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); };
  function product() { return data.find(function (d) { return String(d.id) === sel.value; }) || data[0]; }
  function drawSizes() {
    var p = product(), col = colorIn.value.toLowerCase();
    sizesBox.innerHTML = p.sizes.map(function (sz) {
      var have = p.stock[col + '|' + sz.toUpperCase()];
      return '<label class="sq"><b>' + esc(sz) + '</b><input type="number" inputmode="numeric" min="0" step="1" name="qty[' + esc(sz) + ']" placeholder="0">'
        + '<small class="' + (have !== undefined && have <= 0 ? 'err-text' : 'muted') + '">' + (have === undefined ? 'none in stock' : have + ' in stock') + '</small></label>';
    }).join('') || '<span class="muted small">This product has no sizes.</span>';
  }
  function pickColor(name) {
    colorIn.value = name;
    colorsBox.querySelectorAll('.chip').forEach(function (c) { c.classList.toggle('on', c.dataset.v === name); });
    drawSizes();
  }
  function drawColors(want) {
    var p = product();
    colorsBox.innerHTML = p.colors.map(function (c) {
      return '<button type="button" class="chip" data-v="' + esc(c.name) + '"><span class="dot" style="background:' + esc(c.hex || '#ccc') + '"></span>' + esc(c.name) + '</button>';
    }).join('') || '<span class="muted small">No colours for this product.</span>';
    var first = p.colors.find(function (c) { return c.name === want; }) || p.colors[0];
    pickColor(first ? first.name : '');
  }
  function syncKind() {
    var k = (form.querySelector('[name=kind]:checked') || {}).value;
    var mistake = k === 'print' || k === 'embroidery';
    who.hidden = !mistake;
  }
  colorsBox.addEventListener('click', function (e) { var c = e.target.closest('.chip'); if (c) pickColor(c.dataset.v); });
  sel.addEventListener('change', function () { drawColors(''); });
  form.addEventListener('change', function (e) { if (e.target.name === 'kind') syncKind(); });
  form.addEventListener('submit', function (e) {
    if (![].some.call(sizesBox.querySelectorAll('input'), function (i) { return (parseInt(i.value, 10) || 0) > 0; })) { e.preventDefault(); alert('Type how many pieces under at least one size.'); return; }
    if (!form.querySelector('[name=kind]:checked')) { e.preventDefault(); alert('Pick why it is damaged: plain T-shirt damage, printing mistake or embroidery mistake.'); }
  });
  drawColors(form.dataset.color);
  syncKind();
})();
</script>
<?php require __DIR__ . '/inc/footer.php'; ?>
