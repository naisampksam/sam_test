<?php
// Backup & restore (admin): back up now, daily automatic backups to Google Drive (last 7 kept), download,
// and restore everything from a backup on the server, in Google Drive, or a zip uploaded from the computer.
require __DIR__ . '/../inc/bootstrap.php';
require __DIR__ . '/../inc/orders.php';
require __DIR__ . '/../inc/backup.php';

require_admin();

// Google sends the admin back here after "Allow".
if (isset($_GET['code']) || isset($_GET['error'])) {
    start_session();
    if (($_GET['state'] ?? '') !== ($_SESSION['gdrive_state'] ?? '-')) {
        flash('Google Drive connection failed (the link expired). Try “Connect Google Drive” again.', 'err');
    } elseif (isset($_GET['error'])) {
        flash('Google Drive was not connected: ' . $_GET['error'], 'err');
    } else {
        try {
            gdrive_exchange_code((string)$_GET['code']);
            gdrive_folder();
            flash('✓ Google Drive connected. Backups go to the “Looma backups” folder.');
        } catch (Throwable $e) {
            flash($e->getMessage(), 'err');
        }
    }
    unset($_SESSION['gdrive_state']);
    redirect('admin/backup.php');
}

if (isset($_GET['download']) && backup_name_ok((string)$_GET['download']) && is_file($f = backup_dir() . '/' . $_GET['download'])) {
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . basename($f) . '"');
    header('Content-Length: ' . filesize($f));
    readfile($f);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        // The upload was bigger than the server accepts: PHP drops the whole form.
        flash('That zip is bigger than this server accepts in one upload (' . ini_get('post_max_size') . '). Restore it from Google Drive or from “Backups on this server” instead — you can put the zip in the backups folder with File Manager.', 'err');
        redirect('admin/backup.php');
    }
    csrf_check();
    $do = (string)($_POST['do'] ?? '');
    $restoreOk = strtoupper(trim((string)($_POST['confirm'] ?? ''))) === 'RESTORE';
    try {
        switch ($do) {
            case 'now':
                $st = backup_run('by hand');
                flash($st['ok'] ? '✓ Backup made: ' . $st['name'] . ' (' . round($st['size'] / 1048576, 1) . ' MB, ' . $st['msg'] . ')'
                    . ($st['drive'] === 'uploaded' ? ' and copied to Google Drive.' : ($st['drive'] ? ' — Google Drive ' . $st['drive'] : '.')) : 'Backup failed: ' . $st['msg'], $st['ok'] && (!$st['drive'] || $st['drive'] === 'uploaded') ? 'ok' : 'err');
                break;
            case 'settings':
                set_setting('backup_auto', !empty($_POST['backup_auto']) ? '1' : '0');
                set_setting('backup_photos', !empty($_POST['backup_photos']) ? '1' : '0');
                flash('Backup settings saved.');
                break;
            case 'google':
                set_setting('gdrive_client_id', trim((string)($_POST['client_id'] ?? '')));
                if (trim((string)($_POST['client_secret'] ?? '')) !== '') {
                    set_setting('gdrive_client_secret', trim((string)$_POST['client_secret']));
                }
                flash('Google details saved. Now tap “Connect Google Drive”.');
                break;
            case 'connect':
                start_session();
                $_SESSION['gdrive_state'] = bin2hex(random_bytes(16));
                redirect_abs(gdrive_auth_url($_SESSION['gdrive_state']));
            case 'disconnect':
                set_setting('gdrive_refresh_token', '');
                set_setting('gdrive_folder_id', '');
                flash('Google Drive disconnected. Backups stay on the server only.');
                break;
            case 'delete':
                if (backup_name_ok((string)($_POST['name'] ?? ''))) {
                    @unlink(backup_dir() . '/' . $_POST['name']);
                    flash('Backup deleted from the server.');
                }
                break;
            case 'restore_local':
            case 'restore_drive':
            case 'restore_upload':
                if (!$restoreOk) {
                    flash('Type RESTORE in the box to confirm — restoring replaces all current data.', 'err');
                    break;
                }
                $tmp = null;
                if ($do === 'restore_local') {
                    $name = (string)($_POST['name'] ?? '');
                    if (!backup_name_ok($name) || !is_file($path = backup_dir() . '/' . $name)) {
                        throw new RuntimeException('Backup not found on the server.');
                    }
                } elseif ($do === 'restore_drive') {
                    $path = $tmp = backup_dir() . '/tmp-drive-' . bin2hex(random_bytes(6)) . '.zip';
                    gdrive_download((string)($_POST['file_id'] ?? ''), $path);
                } else {
                    $f = $_FILES['backup'] ?? null;
                    if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
                        throw new RuntimeException(($f['error'] ?? 0) === UPLOAD_ERR_INI_SIZE || ($f['error'] ?? 0) === UPLOAD_ERR_FORM_SIZE
                            ? 'The zip is bigger than this server accepts (' . ini_get('upload_max_filesize') . '). Restore it from Google Drive or the server list instead, or upload it to the backups folder with File Manager.'
                            : 'Choose the backup zip first.');
                    }
                    $path = $tmp = backup_dir() . '/tmp-upload-' . bin2hex(random_bytes(6)) . '.zip';
                    move_uploaded_file($f['tmp_name'], $path);
                }
                try {
                    $r = backup_restore($path);
                } finally {
                    if ($tmp) {
                        @unlink($tmp);
                    }
                }
                flash('✓ Restored from the backup of ' . ($r['created'] ? date('d M Y, h:i A', strtotime($r['created'])) : 'the zip') . ': ' . $r['statements'] . ' database steps'
                    . ($r['photos'] ? ', ' . $r['photos'] . ' photos' : '') . '. The data from just before is kept as ' . $r['safety'] . ' (you can restore it if needed). If you are logged out, log in again.');
                break;
        }
    } catch (Throwable $e) {
        flash($e->getMessage(), 'err');
    }
    redirect('admin/backup.php');
}

/** Redirect to an outside address (Google sign-in). */
function redirect_abs(string $url): void
{
    header('Location: ' . $url);
    exit;
}

$status = json_decode((string)setting('backup_status', ''), true) ?: null;
$local = backup_list();
$driveFiles = null;
$driveErr = '';
if (gdrive_connected()) {
    try {
        $driveFiles = gdrive_list();
    } catch (Throwable $e) {
        $driveErr = $e->getMessage();
    }
}
if (setting('backup_cron_key', '') === '') {
    set_setting('backup_cron_key', bin2hex(random_bytes(16)));
}
$cronUrl = preg_replace('#admin/backup\.php$#', 'backup_cron.php', gdrive_redirect_uri()) . '?key=' . setting('backup_cron_key', '');
$mb = fn($b) => $b >= 1048576 ? round($b / 1048576, 1) . ' MB' : max(1, round($b / 1024)) . ' KB';

$pageTitle = 'Backup & restore';
$active = 'backup';
require __DIR__ . '/../inc/header.php';
?>
<div class="page-head">
  <div><h1>Backup &amp; restore</h1>
    <p class="muted small">One zip with all your data — orders, bills, payments, customers, stock, production, tasks, staff, settings — and the photos. Made every day by itself, the last <?= BACKUP_KEEP ?> are kept on the server and in your Google Drive.</p></div>
  <div class="actions"><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="now"><button class="btn primary" onclick="this.textContent='⏳ Backing up… (keep this page open)'">💾 Back up now</button></form></div>
</div>

<div class="stats">
  <div class="stat <?= $status && !$status['ok'] ? 'stat-alert' : '' ?>"><span class="stat-label">Last backup</span>
    <span class="stat-num"><small><?= $status ? h(fmt_date($status['at'], true)) : 'Never' ?></small></span>
    <span class="stat-sub"><?= $status ? ($status['ok'] ? '✓ ' . h($status['msg']) . ' · ' . h($mb($status['size'])) : '<b class="err-text">Failed: ' . h($status['msg']) . '</b>') : 'Tap “Back up now”' ?></span></div>
  <div class="stat <?= $status && $status['drive'] && $status['drive'] !== 'uploaded' ? 'stat-alert' : '' ?>"><span class="stat-label">Google Drive</span>
    <span class="stat-num"><small><?= gdrive_connected() ? '✓ Connected' : 'Not connected' ?></small></span>
    <span class="stat-sub"><?= $status && $status['drive'] ? ($status['drive'] === 'uploaded' ? 'last backup copied' : '<b class="err-text">' . h($status['drive']) . '</b>') : (gdrive_connected() ? 'folder “Looma backups”' : 'set it up below') ?></span></div>
  <div class="stat"><span class="stat-label">Daily backup</span><span class="stat-num"><small><?= setting('backup_auto', '1') === '1' ? 'On' : 'Off' ?></small></span>
    <span class="stat-sub"><?= setting('backup_photos', '1') === '1' ? 'with photos' : 'data only (no photos)' ?> · keeps <?= BACKUP_KEEP ?></span></div>
</div>

<section class="panel">
  <h2>Backups on this server <small class="muted"><?= count($local) ?></small></h2>
  <?php if (!$local): ?><p class="muted">No backups yet.</p><?php else: ?>
  <div class="table-wrap"><table class="table compact">
    <thead><tr><th>Backup</th><th class="num">Size</th><th></th></tr></thead><tbody>
    <?php foreach ($local as $b): ?>
      <tr><td><b><?= h(date('d M Y, h:i A', $b['time'])) ?></b><br><small class="muted"><?= h($b['name']) ?><?= str_starts_with($b['name'], 'before-restore') ? ' · safety copy made before a restore' : '' ?></small></td>
        <td class="num"><?= h($mb($b['size'])) ?></td>
        <td class="nowrap">
          <a class="btn small" href="backup.php?download=<?= h(urlencode($b['name'])) ?>">⬇ Download</a>
          <button type="button" class="btn small" data-restore='<?= h(json_encode(['do' => 'restore_local', 'name' => $b['name'], 'label' => date('d M Y, h:i A', $b['time'])])) ?>'>↺ Restore</button>
          <form method="post" style="display:inline" onsubmit="return confirm('Delete this backup from the server?')"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="name" value="<?= h($b['name']) ?>"><button class="btn small ghost danger" title="Delete">🗑</button></form>
        </td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</section>

<section class="panel">
  <h2>In Google Drive</h2>
  <?php if (!gdrive_connected()): ?>
    <p class="muted">Not connected yet — see “Connect Google Drive” below.</p>
  <?php elseif ($driveErr): ?>
    <p class="alert err"><?= h($driveErr) ?></p>
  <?php elseif (!$driveFiles): ?>
    <p class="muted">No backups in Drive yet — tap “Back up now”.</p>
  <?php else: ?>
  <div class="table-wrap"><table class="table compact">
    <thead><tr><th>Backup</th><th class="num">Size</th><th></th></tr></thead><tbody>
    <?php foreach ($driveFiles as $df): ?>
      <tr><td><b><?= h(fmt_date(date('Y-m-d H:i:s', strtotime($df['createdTime'])), true)) ?></b><br><small class="muted"><?= h($df['name']) ?></small></td>
        <td class="num"><?= h($mb((int)($df['size'] ?? 0))) ?></td>
        <td class="nowrap"><?php if (!empty($df['webViewLink'])): ?><a class="btn small" href="<?= h($df['webViewLink']) ?>" target="_blank" rel="noopener">Open ↗</a><?php endif; ?>
          <button type="button" class="btn small" data-restore='<?= h(json_encode(['do' => 'restore_drive', 'file_id' => $df['id'], 'label' => $df['name']])) ?>'>↺ Restore</button></td></tr>
    <?php endforeach; ?>
  </tbody></table></div>
  <?php endif; ?>
</section>

<section class="panel" id="upload-restore">
  <h2>↺ Restore from a backup zip on your computer</h2>
  <p class="small">If the data is lost: upload a backup zip (from Google Drive or a download). <b>Everything in the app is replaced</b> by the data in the backup. A safety copy of the current data is made first.</p>
  <form method="post" enctype="multipart/form-data" class="grid" data-plain-submit onsubmit="return window.confirm('Replace ALL current data with this backup?')">
    <?= csrf_field() ?><input type="hidden" name="do" value="restore_upload">
    <label class="field"><span class="lbl">Backup zip <small class="muted">(up to <?= h(ini_get('upload_max_filesize')) ?>)</small></span><input type="file" name="backup" accept=".zip,application/zip" required></label>
    <label class="field"><span class="lbl">Type RESTORE to confirm</span><input name="confirm" autocomplete="off" placeholder="RESTORE" required></label>
    <div class="field"><span class="lbl">&nbsp;</span><button class="btn danger">↺ Restore everything</button></div>
  </form>
  <p class="hint">Big backup with photos? Upload the zip into the <code>backups</code> folder with File Manager — it then shows in “Backups on this server” to restore from there.</p>
</section>

<div class="two-col">
<section class="panel">
  <h2>Daily backup</h2>
  <form method="post" class="stack">
    <?= csrf_field() ?><input type="hidden" name="do" value="settings">
    <label class="field check full"><input type="checkbox" name="backup_auto" value="1" <?= setting('backup_auto', '1') === '1' ? 'checked' : '' ?>> Back up automatically every day (keeps the last <?= BACKUP_KEEP ?>)</label>
    <label class="field check full"><input type="checkbox" name="backup_photos" value="1" <?= setting('backup_photos', '1') === '1' ? 'checked' : '' ?>> Include photos (mock-ups, designs) — bigger zip, but a full restore</label>
    <div><button class="btn">Save</button></div>
  </form>
  <p class="small"><b>When it runs:</b> on the first page anyone opens each day (in the background), or exactly on time with a cron job.
    For a fixed time, add this in Hostinger <b>hPanel → Advanced → Cron Jobs</b> (e.g. every day at 2 am):</p>
  <pre class="code-box">curl -s "<?= h($cronUrl) ?>" &gt;/dev/null</pre>
  <p class="hint">Keep this link private — it can only start a backup.</p>
</section>

<section class="panel">
  <h2>Connect Google Drive</h2>
  <?php if (gdrive_connected()): ?>
    <p>✓ Connected. Backups are copied to the <b>“Looma backups”</b> folder in your Google Drive and only the last <?= BACKUP_KEEP ?> are kept there. The app can only see the files it puts there.</p>
    <form method="post" onsubmit="return confirm('Disconnect Google Drive?')"><?= csrf_field() ?><input type="hidden" name="do" value="disconnect"><button class="btn small danger">Disconnect</button></form>
  <?php else: ?>
    <ol class="small setup-steps">
      <li>Open <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">console.cloud.google.com</a> with the Google account whose Drive should keep the backups, and create a project (e.g. “Looma backups”).</li>
      <li><b>APIs &amp; Services → Library</b> → search <b>Google Drive API</b> → <b>Enable</b>.</li>
      <li><b>OAuth consent screen</b> → External → app name “Looma backups”, your email → add your email as a test user → <b>Publish app</b> (so the connection does not expire after 7 days).</li>
      <li><b>Credentials → Create credentials → OAuth client ID</b> → Web application → under <b>Authorised redirect URIs</b> add exactly:<br><code class="copy"><?= h(gdrive_redirect_uri()) ?></code></li>
      <li>Copy the <b>Client ID</b> and <b>Client secret</b> into the boxes below, save, then tap <b>Connect Google Drive</b> and allow.</li>
    </ol>
  <?php endif; ?>
  <form method="post" class="stack" style="margin-top:10px">
    <?= csrf_field() ?><input type="hidden" name="do" value="google">
    <label class="field full"><span class="lbl">Client ID</span><input name="client_id" value="<?= h(setting('gdrive_client_id', '')) ?>" placeholder="….apps.googleusercontent.com" autocomplete="off"></label>
    <label class="field full"><span class="lbl">Client secret <?= setting('gdrive_client_secret', '') !== '' ? '<small class="muted">(saved — leave empty to keep)</small>' : '' ?></span><input name="client_secret" type="password" autocomplete="new-password"></label>
    <div><button class="btn">Save Google details</button></div>
  </form>
  <?php if (!gdrive_connected() && setting('gdrive_client_id', '') !== ''): ?>
    <form method="post" style="margin-top:8px"><?= csrf_field() ?><input type="hidden" name="do" value="connect"><button class="btn primary">🔗 Connect Google Drive</button></form>
  <?php endif; ?>
</section>
</div>

<dialog id="restoreDlg" class="restore-dlg">
  <form method="post">
    <?= csrf_field() ?><input type="hidden" name="do"><input type="hidden" name="name"><input type="hidden" name="file_id">
    <h2>↺ Restore everything?</h2>
    <p>All current data will be replaced by the backup <b data-label></b>. A safety copy of the current data is made first.</p>
    <label class="field"><span class="lbl">Type RESTORE to confirm</span><input name="confirm" autocomplete="off" required placeholder="RESTORE"></label>
    <div class="status-btns"><button class="btn danger" onclick="this.textContent='⏳ Restoring…'">↺ Restore</button><button type="button" class="btn ghost" onclick="this.closest('dialog').close()">Cancel</button></div>
  </form>
</dialog>
<script>
document.addEventListener('click', function (e) {
  var b = e.target.closest('[data-restore]'); if (!b) return;
  var d = JSON.parse(b.dataset.restore), dlg = document.getElementById('restoreDlg'), f = dlg.querySelector('form');
  var el = function (n) { return f.elements[n]; };
  el('do').value = d.do; el('name').value = d.name || ''; el('file_id').value = d.file_id || ''; el('confirm').value = '';
  dlg.querySelector('[data-label]').textContent = d.label;
  dlg.showModal();
});
</script>
<?php require __DIR__ . '/../inc/footer.php'; ?>
