<?php
// Shipping labels that are not tied to an order: make one from scratch, change anything on it, save, print again later.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/orders.php';
require __DIR__ . '/inc/billing.php';
require __DIR__ . '/inc/label.php';

require_login();
if (!cap('slips')) {
    http_response_code(403);
    exit('You are not allowed to print shipping labels.');
}

$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!empty($_POST['delete'])) {
        q('DELETE FROM ship_labels WHERE id = ?', [(int)$_POST['delete']]);
        flash('Label deleted.');
        redirect('labels.php');
    }
    $vals = (array)($_POST['f']['label'] ?? []);
    $row = [];
    $errors = [];
    foreach (LABEL_FIELDS as $k => $_) {
        [$row[$k], $err] = label_clean($k, $vals[$k] ?? '');
        if ($err) {
            $errors[] = $err;
        }
    }
    $row['payment_type'] = $row['payment_type'] ?: 'prepaid';
    if ($row['payment_type'] === 'cod' && $row['cod_amount'] === null && !$errors) {
        $errors[] = 'Enter the COD amount to collect.';
    }
    if ($row['payment_type'] !== 'cod') {
        $row['cod_amount'] = null;
    }
    if ($row['ship_name'] === '' && $row['ship_address'] === '') {
        $errors[] = 'Type at least the ship-to name or address.';
    }
    if ($errors) {
        flash(implode(' ', $errors), 'err');
        $_SESSION['label_draft'] = $row; // keep what was typed
        redirect('labels.php?' . ($id ? 'id=' . $id : 'new=1'));
    }
    $row['updated_at'] = now();
    if ($id && q('SELECT id FROM ship_labels WHERE id = ?', [$id])->fetch()) {
        update_row('ship_labels', $id, $row);
    } else {
        $row += ['created_by' => current_user()['id'], 'created_at' => now()];
        q('INSERT INTO ship_labels (' . implode(',', array_keys($row)) . ') VALUES (' . rtrim(str_repeat('?,', count($row)), ',') . ')', array_values($row));
        $id = (int)db()->lastInsertId();
    }
    flash('Label saved.');
    redirect('labels.php?id=' . $id . (!empty($_POST['print']) ? '&print=1' : ''));
}

// ------------------------------------------------------------------ editor
if ($id || !empty($_GET['new'])) {
    $defaults = label_defaults();
    $v = array_fill_keys(array_keys(LABEL_FIELDS), '');
    $v = array_merge($v, $defaults, ['payment_type' => 'prepaid', 'cod_amount' => null]);
    $saved = null;
    if ($id) {
        $saved = q('SELECT * FROM ship_labels WHERE id = ?', [$id])->fetch();
        if (!$saved) {
            flash('Label not found.', 'err');
            redirect('labels.php');
        }
    } elseif (!empty($_GET['copy'])) {
        $saved = q('SELECT * FROM ship_labels WHERE id = ?', [(int)$_GET['copy']])->fetch() ?: null;
    }
    if ($saved) {
        foreach (LABEL_FIELDS as $k => $_) {
            $v[$k] = $saved[$k] ?? '';
        }
    }
    start_session();
    if (!empty($_SESSION['label_draft'])) {
        $v = array_merge($v, $_SESSION['label_draft']);
        unset($_SESSION['label_draft']);
    }
    $brands = array_values(array_unique(array_filter(array_merge([$defaults['slip_brand']],
        array_column(q("SELECT DISTINCT slip_brand FROM ship_labels WHERE slip_brand <> '' ORDER BY slip_brand LIMIT 50")->fetchAll(), 'slip_brand')))));
    $f = flash();
    label_head(($id ? 'Shipping label L-' . $id : 'New shipping label'));
    ?>
<body class="slip-page">
<form method="post" id="labelForm" action="labels.php?<?= $id ? 'id=' . $id : 'new=1' ?>">
<?= csrf_field() ?>
<div class="slip-top">
  <a class="back" href="labels.php">← Shipping labels</a>
  <h1><?= $id ? 'Shipping label L-' . $id : 'New shipping label' ?></h1>
  <?php if ($f): ?><p class="alert <?= h($f['type']) ?>" style="margin-top:10px"><?= h($f['msg']) ?></p><?php endif; ?>
  <div class="slip-bar">
    <button class="btn primary" name="print" value="1">💾 Save &amp; print</button>
    <button class="btn" name="save" value="1">Save only</button>
    <button class="btn ghost" type="button" onclick="window.print()">🖨 Print without saving</button>
    <?php if ($id): ?><a class="btn ghost" href="labels.php?new=1&amp;copy=<?= $id ?>">⧉ Copy as new label</a><?php endif; ?>
    <div class="opts">
      <label><input type="checkbox" data-opt="items" checked> Contents</label>
    </div>
  </div>
  <p class="muted small">Every field can be changed. Printer: paper 75 × 125 mm (or label 3×5″), margins <b>none</b>, scale <b>100%</b>.</p>
</div>

<datalist id="dl_slip_brand"><?php foreach ($brands as $c): ?><option value="<?= h($c) ?>"><?php endforeach; ?></datalist>

<div class="label-blocks">
  <div class="label-block" data-label>
    <div class="label-form">
      <div class="field label-find"><span class="lbl">Find a saved customer <small class="muted">(fills the ship-to address)</small></span>
        <input type="search" id="labelFind" placeholder="Type customer ID, name or phone" autocomplete="off">
        <div class="suggest" id="labelFindList" hidden></div>
      </div>
      <?php label_form_fields('f[label]', $v, couriers(), 'dl_slip_brand'); ?>
    </div>
    <div class="label-preview">
      <?php label_card($v, ($id ? '<b>L-' . $id . '</b>' : '') . '<span class="s-ref">ORD- <b data-show="order_ref">' . h((string)$v['order_ref']) . '</b></span>'); ?>
    </div>
  </div>
</div>
</form>
<?php label_script(!empty($_GET['print'])); ?>
<script>
(function () {
  var inp = document.getElementById('labelFind'), list = document.getElementById('labelFindList'), t = null;
  function set(k, v) { var f = document.querySelector('[name="f[label][' + k + ']"]'); if (f && v) { f.value = v; f.dispatchEvent(new Event('input', { bubbles: true })); } }
  inp.addEventListener('input', function () {
    clearTimeout(t);
    var q = inp.value.trim();
    if (q.length < 2) { list.hidden = true; return; }
    t = setTimeout(function () {
      fetch('customer_search.php?q=' + encodeURIComponent(q), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (rows) {
        list.innerHTML = '';
        rows.forEach(function (c) {
          var b = document.createElement('button');
          b.type = 'button'; b.className = 'suggest-row';
          b.innerHTML = '<b></b><span></span>';
          b.firstChild.textContent = c.code;
          b.lastChild.textContent = [c.customer_name, c.name, c.phone, c.pincode].filter(Boolean).join(' · ');
          b.addEventListener('click', function () {
            set('ship_name', c.name || c.customer_name); set('ship_phone', c.phone); set('ship_address', c.address); set('ship_pincode', c.pincode); set('ship_email', c.email);
            if (c.customer_name) set('slip_brand', c.customer_name);
            list.hidden = true; inp.value = '';
          });
          list.appendChild(b);
        });
        list.hidden = !rows.length;
      }).catch(function () {});
    }, 250);
  });
})();
</script>
</body>
</html>
<?php
    exit;
}

// ------------------------------------------------------------------ list
$s = trim((string)($_GET['q'] ?? ''));
$where = '';
$args = [];
if ($s !== '') {
    $where = 'WHERE ship_name LIKE ? OR ship_email LIKE ? OR ship_phone LIKE ? OR ship_pincode LIKE ? OR order_ref LIKE ? OR tracking_no LIKE ? OR slip_brand LIKE ?';
    $args = array_fill(0, 7, '%' . $s . '%');
    if (preg_match('/^L-?(\d+)$/i', $s, $m)) {
        $where .= ' OR id = ?';
        $args[] = (int)$m[1];
    }
}
$rows = q("SELECT * FROM ship_labels $where ORDER BY id DESC LIMIT 100", $args)->fetchAll();

$pageTitle = 'Shipping labels';
$active = 'labels';
require __DIR__ . '/inc/header.php';
?>
<div class="page-head">
  <div><h1>Shipping labels</h1>
    <p class="muted small">Make a label for any parcel — not only orders. Everything on the label can be changed. Labels for orders are printed from the order (🖨 Shipping label).</p></div>
  <div class="actions"><a class="btn primary" href="labels.php?new=1">+ New label</a></div>
</div>

<form class="filters" method="get" style="margin-top:14px">
  <input type="search" name="q" value="<?= h($s) ?>" placeholder="Search name, phone, PIN, ORD-, tracking…">
  <button class="btn">Search</button>
</form>

<?php if (!$rows): ?>
  <p class="empty"><?= $s !== '' ? 'No labels match.' : 'No labels yet — tap “+ New label”.' ?></p>
<?php else: ?>
<section class="panel"><div class="table-wrap">
  <table class="table labels-table">
    <thead><tr><th>Label</th><th>Ship to</th><th>Courier</th><th>Payment</th><th>Made</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r): ?>
      <tr>
        <td><a href="labels.php?id=<?= (int)$r['id'] ?>"><b>L-<?= (int)$r['id'] ?></b></a><?= $r['order_ref'] !== '' ? '<br><small class="muted">ORD- ' . h($r['order_ref']) . '</small>' : '' ?></td>
        <td><b><?= h($r['ship_name']) ?></b><br><small class="muted"><?= h(implode(' · ', array_filter([$r['ship_pincode'], $r['ship_phone']], 'strlen'))) ?></small></td>
        <td><?= h($r['courier']) ?><?= $r['tracking_no'] !== '' ? '<br><small class="muted">' . h($r['tracking_no']) . '</small>' : '' ?></td>
        <td><?= payment_badge($r) ?></td>
        <td class="nowrap"><small><?= h(fmt_date($r['created_at'], true)) ?><br><span class="muted"><?= h(user_name($r['created_by'])) ?></span></small></td>
        <td class="nowrap">
          <a class="btn small" href="labels.php?id=<?= (int)$r['id'] ?>&amp;print=1">🖨 Print</a>
          <a class="btn small ghost" href="labels.php?id=<?= (int)$r['id'] ?>">✎ Edit</a>
          <a class="btn small ghost" href="labels.php?new=1&amp;copy=<?= (int)$r['id'] ?>" title="Copy as a new label">⧉</a>
          <form method="post" style="display:inline" onsubmit="return confirm('Delete label L-<?= (int)$r['id'] ?>?')">
            <?= csrf_field() ?><button class="btn small ghost danger" name="delete" value="<?= (int)$r['id'] ?>" title="Delete">🗑</button>
          </form>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
</div></section>
<?php endif; ?>
<?php require __DIR__ . '/inc/footer.php'; ?>
