<?php
// Import from Vyapar: Items export → stock & services, Party report → customers. Safe to run again (updates, never doubles).
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/orders.php';
require __DIR__ . '/../inc/billing.php';
require __DIR__ . '/../inc/vyapar.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['do'] ?? '') === 'add_products') {
        try {
            $r = add_unmatched_as_products((array)($_POST['grp'] ?? []));
            flash($r['shirts'] ? "✓ Added {$r['products']} new product(s) to the catalog and {$r['shirts']} T-shirts to stock." : 'Tick the products you want to add.', $r['shirts'] ? 'ok' : 'err');
        } catch (Throwable $e) {
            flash('Could not add: ' . $e->getMessage(), 'err');
        }
        redirect('admin/import.php#new-products');
    }
    if (isset($_POST['gsm_same'])) {
        set_setting('gsm_same', preg_replace('/[^\d=, ]/', '', (string)$_POST['gsm_same']));
    }
    $msgs = [];
    try {
        $f = $_FILES['items'] ?? null;
        if ($f && $f['error'] === UPLOAD_ERR_OK) {
            $r = import_vyapar_items(sheet_rows($f['tmp_name'], $f['name']), !empty($_POST['set_qty']));
            $msgs[] = "Items: {$r['shirts']} T-shirts (stock), {$r['services']} printing / services (no stock), {$r['shipping']} shipping charges"
                . ($r['updated'] ? " — {$r['updated']} were already here and were updated" : '') . '.';
            if ($r['not_in_catalog']) {
                $msgs[] = "{$r['not_in_catalog']} T-shirts are not in your catalog and were not added — see the list below to add them as new products.";
            }
        }
        $f = $_FILES['parties'] ?? null;
        if ($f && $f['error'] === UPLOAD_ERR_OK) {
            $r = import_vyapar_parties(sheet_rows($f['tmp_name'], $f['name']));
            $msgs[] = "Parties: {$r['added']} new customers" . ($r['updated'] ? ", {$r['updated']} updated" : '') . '.';
        }
        flash($msgs ? '✓ Imported. ' . implode(' ', $msgs) : 'Choose a file to import.', $msgs ? 'ok' : 'err');
    } catch (Throwable $e) {
        flash('Import failed: ' . $e->getMessage(), 'err');
    }
    redirect('admin/import.php');
}

$groups = unmatched_groups();
$counts = q('SELECT SUM(track = 1) shirts, SUM(track = 0) services FROM stock_items WHERE active = 1')->fetch();
$pageTitle = 'Import from Vyapar';
$active = 'import';
require __DIR__ . '/../inc/header.php';
?>
<div class="page-head"><div><h1>Import from Vyapar</h1>
  <p class="muted small">Bring your Vyapar items and parties into the app. You can run it again any time — items and customers already here are updated, not doubled.</p></div></div>

<form method="post" enctype="multipart/form-data">
  <?= csrf_field() ?>
  <section class="panel">
    <h2>1 · Items <small class="muted">(Vyapar → Items → Export, “Export_Items.xlsx”)</small></h2>
    <ul class="small">
      <li><b>T-shirts</b> are added only if they are one of your products (same GSM, product, colour and size — 240 counts as 250). Their price is your product price, not the Vyapar price. A tax invoice takes them out of stock. Others are listed below to add as new products.</li>
      <li><b>Printing</b> (DTF print, HD print, embroidery, labels…) are kept as services with their price. They <b>never</b> change stock.</li>
      <li><b>Shipping</b> (“Packing and shipping…”) is not an item: on a bill it goes to the shipping charge.</li>
    </ul>
    <div class="grid">
      <label class="field"><span class="lbl">Items file (.xlsx or .csv)</span><input type="file" name="items" accept=".xlsx,.csv"></label>
      <label class="field check"><input type="checkbox" name="set_qty" value="1" checked> Set T-shirt stock to the “Current stock quantity” in the file</label>
      <label class="field"><span class="lbl">Same GSM <small class="muted">(file GSM = catalog GSM)</small></span><input name="gsm_same" value="<?= h(setting('gsm_same', '240=250')) ?>" placeholder="240=250"></label>
    </div>
    <p class="hint">Now in the app: <?= (int)$counts['shirts'] ?> stock items, <?= (int)$counts['services'] ?> services.</p>
  </section>
  <section class="panel">
    <h2>2 · Parties <small class="muted">(Vyapar → Reports → Party report, “PartyReport.xlsx”)</small></h2>
    <p class="small">Each party becomes a customer: name, phone, address, email, GSTIN and balance. A party called “C1003” is customer ID C1003.</p>
    <label class="field"><span class="lbl">Party report file (.xlsx or .csv)</span><input type="file" name="parties" accept=".xlsx,.csv"></label>
  </section>
  <div class="sticky-actions"><button class="btn primary">⬆ Import</button></div>
</form>

<?php if ($groups): ?>
<form method="post" class="panel" id="new-products">
  <?= csrf_field() ?><input type="hidden" name="do" value="add_products">
  <h2>T-shirts not in your catalog <small class="muted">(<?= array_sum(array_map(fn($g) => count($g['items']), $groups)) ?> items from the last import)</small></h2>
  <p class="small">These were <b>not</b> added. Tick the ones you sell to add them as new products (with their colours, sizes and stock), or leave them out.</p>
  <div class="new-products">
  <?php foreach ($groups as $k => $g): $pr = $g['prices'] ? max($g['prices']) : ''; ?>
    <div class="new-product">
      <label class="check"><input type="checkbox" name="grp[<?= h($k) ?>][add]" value="1"> <b><?= h($g['gsm']) ?> · <?= h($g['product']) ?></b></label>
      <div class="muted small"><?= count($g['items']) ?> items · <?= h(implode(', ', array_keys($g['colors'])) ?: 'no colour') ?> · <?= h(implode(', ', array_keys($g['sizes'])) ?: 'no size') ?></div>
      <div class="grid np-fields">
        <label class="field"><span class="lbl">GSM</span><input name="grp[<?= h($k) ?>][gsm]" value="<?= h($g['gsm']) ?>"></label>
        <label class="field"><span class="lbl">Product name</span><input name="grp[<?= h($k) ?>][product]" value="<?= h($g['product']) ?>"></label>
        <label class="field"><span class="lbl">Price ₹ per piece</span><input type="number" step="any" min="0" name="grp[<?= h($k) ?>][price]" placeholder="<?= $pr !== '' ? 'Vyapar: ₹' . h((string)$pr) : 'Set price' ?>"></label>
      </div>
    </div>
  <?php endforeach; ?>
  </div>
  <div class="sticky-actions"><button class="btn primary">＋ Add ticked as new products</button></div>
</form>
<?php endif; ?>
<?php require __DIR__ . '/../inc/footer.php'; ?>
