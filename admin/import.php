<?php
// Import customers from Vyapar (Party report). Safe to run again: customers already here are updated, not doubled.
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/orders.php';
require __DIR__ . '/../inc/billing.php';
require __DIR__ . '/../inc/vyapar.php';

require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $f = $_FILES['parties'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
        flash('Choose the party report file first.', 'err');
        redirect('admin/import.php');
    }
    try {
        $r = import_vyapar_parties(sheet_rows($f['tmp_name'], $f['name']));
        flash("✓ Imported. {$r['added']} new customers" . ($r['updated'] ? ", {$r['updated']} updated" : '') . '.');
    } catch (Throwable $e) {
        flash('Import failed: ' . $e->getMessage(), 'err');
    }
    redirect('admin/import.php');
}

$pageTitle = 'Import customers';
$active = 'import';
require __DIR__ . '/../inc/header.php';
?>
<div class="page-head"><div><h1>Import customers from Vyapar</h1>
  <p class="muted small">Bring your Vyapar parties in as customers. You can run it again any time — customers already here are updated, not doubled.</p></div></div>

<form method="post" enctype="multipart/form-data" class="panel">
  <?= csrf_field() ?>
  <h2>Party report <small class="muted">(Vyapar → Reports → Party report, “PartyReport.xlsx”)</small></h2>
  <p class="small">Each party becomes a customer: name, phone, address, email, GSTIN and balance. A party called “C1003” is customer ID C1003.</p>
  <label class="field"><span class="lbl">Party report file (.xlsx or .csv)</span><input type="file" name="parties" accept=".xlsx,.csv" required></label>
  <p class="hint">Products and stock are not imported — your T-shirt products are in <a href="<?= h(base_url('stock.php')) ?>">Products &amp; stock</a>, where you add stock by hand.</p>
  <div class="sticky-actions"><button class="btn primary">⬆ Import customers</button></div>
</form>
<?php require __DIR__ . '/../inc/footer.php'; ?>
