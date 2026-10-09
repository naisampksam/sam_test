<?php
// Import from Vyapar: Items export → stock & services, Party report → customers. Safe to run again (updates, never doubles).
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/orders.php';
require __DIR__ . '/../inc/billing.php';
require __DIR__ . '/../inc/vyapar.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $msgs = [];
    try {
        $f = $_FILES['items'] ?? null;
        if ($f && $f['error'] === UPLOAD_ERR_OK) {
            $r = import_vyapar_items(sheet_rows($f['tmp_name'], $f['name']), !empty($_POST['set_qty']));
            $msgs[] = "Items: {$r['shirts']} T-shirts (stock), {$r['services']} printing / services (no stock), {$r['shipping']} shipping charges"
                . ($r['updated'] ? " — {$r['updated']} were already here and were updated" : '') . '.';
            $c = $r['catalog'];
            $msgs[] = "Order catalog: {$c['linked']} T-shirts linked" . ($c['products_added'] ? ", {$c['products_added']} products added" : '') . ($c['colors_added'] ? ", {$c['colors_added']} colours added" : '') . '.';
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
      <li><b>T-shirts</b> go to Stock. A tax invoice with them takes them out of stock.</li>
      <li><b>Printing</b> (DTF print, HD print, embroidery, labels…) are kept as services with their price. They <b>never</b> change stock.</li>
      <li><b>Shipping</b> (“Packing and shipping…”) is not an item: on a bill it goes to the shipping charge.</li>
    </ul>
    <div class="grid">
      <label class="field"><span class="lbl">Items file (.xlsx or .csv)</span><input type="file" name="items" accept=".xlsx,.csv"></label>
      <label class="field check"><input type="checkbox" name="set_qty" value="1" checked> Set T-shirt stock to the “Current stock quantity” in the file</label>
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
<?php require __DIR__ . '/../inc/footer.php'; ?>
