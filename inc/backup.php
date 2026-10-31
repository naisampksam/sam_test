<?php
// Backups: one zip with the whole database (database.sql) and, if wanted, all photos (uploads/…).
// Made by hand or once a day by itself, kept for 7 days on the server and copied to Google Drive (last 7 there too).
// Restore puts every table back from a backup zip (a safety backup of the current data is made first).
declare(strict_types=1);

const BACKUP_KEEP = 7;
const BACKUP_APP = 'looma-orders';

function backup_dir(): string
{
    $d = APP_ROOT . '/backups';
    if (!is_dir($d)) {
        @mkdir($d, 0700, true);
    }
    if (!is_file($d . '/.htaccess')) {
        @file_put_contents($d . '/.htaccess', "Require all denied\n");
        @file_put_contents($d . '/index.html', '');
    }
    return $d;
}

/** A backup file name we made (looma-backup-2026-10-28-0215.zip / before-restore-…). */
function backup_name_ok(string $name): bool
{
    return (bool)preg_match('/^(looma-backup|before-restore)-\d{4}-\d{2}-\d{2}-\d{6}\.zip$/', $name);
}

/** The whole database as SQL, one statement per line (so restore can read it line by line). */
function backup_dump_sql(string $file): array
{
    $pdo = db();
    $fh = fopen($file, 'w');
    fwrite($fh, "-- Looma Orders backup " . date('Y-m-d H:i:s') . "\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n");
    $tables = $pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")->fetchAll(PDO::FETCH_COLUMN);
    $rows = 0;
    foreach ($tables as $t) {
        $create = $pdo->query('SHOW CREATE TABLE `' . str_replace('`', '', $t) . '`')->fetch(PDO::FETCH_NUM)[1];
        fwrite($fh, "DROP TABLE IF EXISTS `$t`;\n" . preg_replace('/\s*\n\s*/', ' ', $create) . ";\n");
        $cols = null;
        for ($off = 0; ; $off += 500) {
            $chunk = $pdo->query("SELECT * FROM `$t` LIMIT 500 OFFSET $off")->fetchAll(PDO::FETCH_ASSOC);
            if (!$chunk) {
                break;
            }
            $cols ??= '(`' . implode('`,`', array_keys($chunk[0])) . '`)';
            $vals = [];
            foreach ($chunk as $r) {
                $vals[] = '(' . implode(',', array_map(fn($v) => $v === null ? 'NULL' : $pdo->quote((string)$v), $r)) . ')';
            }
            fwrite($fh, "INSERT INTO `$t` $cols VALUES " . implode(',', $vals) . ";\n");
            $rows += count($chunk);
        }
    }
    fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
    fclose($fh);
    return ['tables' => count($tables), 'rows' => $rows];
}

/** The attendance app's data folder (attendance/data, or the data_dir set in attendance/config.php); null without the app. */
function attendance_data_dir(): ?string
{
    if (!is_dir(APP_ROOT . '/attendance')) {
        return null;
    }
    $dir = APP_ROOT . '/attendance/data';
    $c = attendance_config();
    if (!empty($c['data_dir'])) {
        $dir = rtrim((string)$c['data_dir'], '/');
    }
    return $dir;
}

/** attendance/config.php (optional). */
function attendance_config(): array
{
    $file = APP_ROOT . '/attendance/config.php';
    $c = is_file($file) ? (function ($f) { return include $f; })($file) : [];
    return is_array($c) ? $c : [];
}

/**
 * The attendance app's data file (attendance/data/db.php, or the data_dir set in attendance/config.php).
 * $mayBeMissing: also when the file is not there yet (restoring onto a fresh site). Null without the attendance app.
 */
function attendance_data_file(bool $mayBeMissing = false): ?string
{
    $dir = attendance_data_dir();
    if ($dir === null) {
        return null;
    }
    return is_file($dir . '/db.php') || ($mayBeMissing && (is_dir($dir) || @mkdir($dir, 0700, true))) ? $dir . '/db.php' : null;
}

/**
 * Make a backup zip in backups/. $photos: include uploads/ (mock-ups, designs). Returns ['name', 'path', 'size', 'tables', 'rows', 'photos'].
 */
function backup_create(bool $photos, string $prefix = 'looma-backup'): array
{
    @set_time_limit(0);
    @ignore_user_abort(true);
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('This server cannot make zip files (PHP zip extension missing).');
    }
    $dir = backup_dir();
    $name = $prefix . '-' . date('Y-m-d-His') . '.zip';
    $path = $dir . '/' . $name;
    $sql = $dir . '/tmp-' . bin2hex(random_bytes(6)) . '.sql';
    try {
        $info = backup_dump_sql($sql);
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the backup file.');
        }
        $zip->addFile($sql, 'database.sql');
        $nPhotos = 0;
        if ($photos && is_dir(APP_ROOT . '/uploads')) {
            $base = APP_ROOT . '/uploads/';
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $f) {
                if ($f->isFile()) {
                    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($base)));
                    $zip->addFile($f->getPathname(), 'uploads/' . $rel);
                    $zip->setCompressionName('uploads/' . $rel, ZipArchive::CM_STORE); // photos are already compressed
                    $nPhotos++;
                }
            }
        }
        // Attendance & salary data (staff, clock-ins, leave, salaries) — always, it is small.
        $att = attendance_data_file();
        if ($att) {
            $zip->addFile($att, 'attendance/db.php');
        }
        $manifest = ['attendance' => (bool)$att, 'app' => BACKUP_APP, 'created' => date('c'), 'site' => $_SERVER['HTTP_HOST'] ?? '', 'tables' => $info['tables'],
            'rows' => $info['rows'], 'photos' => $nPhotos, 'with_photos' => $photos];
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT));
        if (!$zip->close()) {
            throw new RuntimeException('Could not finish the backup file (disk full?).');
        }
    } finally {
        @unlink($sql);
    }
    return ['name' => $name, 'path' => $path, 'size' => filesize($path)] + $info + ['photos' => $nPhotos];
}

/** Backups on the server, newest first: [['name', 'path', 'size', 'time']]. */
function backup_list(): array
{
    $out = [];
    foreach (glob(backup_dir() . '/*.zip') ?: [] as $p) {
        if (backup_name_ok(basename($p))) {
            $out[] = ['name' => basename($p), 'path' => $p, 'size' => filesize($p), 'time' => filemtime($p)];
        }
    }
    usort($out, fn($a, $b) => strcmp($b['name'], $a['name']));
    return $out;
}

/** Keep the newest $keep daily backups (and the newest 3 "before restore" safety copies). */
function backup_prune(int $keep = BACKUP_KEEP): int
{
    $n = 0;
    foreach (['looma-backup', 'before-restore'] as $prefix) {
        $list = array_values(array_filter(backup_list(), fn($b) => str_starts_with($b['name'], $prefix . '-')));
        foreach (array_slice($list, $prefix === 'looma-backup' ? $keep : 3) as $b) {
            @unlink($b['path']) && $n++;
        }
    }
    return $n;
}

/** Check a zip is one of our backups. Returns its manifest or throws. */
function backup_check(string $zipPath): array
{
    $zip = new ZipArchive();
    if ($zip->open($zipPath) !== true) {
        throw new RuntimeException('That file is not a zip file.');
    }
    $m = json_decode((string)$zip->getFromName('manifest.json'), true);
    $hasSql = $zip->locateName('database.sql') !== false;
    $zip->close();
    if (!is_array($m) || ($m['app'] ?? '') !== BACKUP_APP || !$hasSql) {
        throw new RuntimeException('This is not a Looma Orders backup zip (no database.sql / manifest.json).');
    }
    return $m;
}

/**
 * Restore everything from a backup zip: every table is replaced by the backup's copy; photos in it are put back
 * (photos added since are kept). A safety backup of the current data is made first. Returns a summary.
 */
function backup_restore(string $zipPath): array
{
    @set_time_limit(0);
    @ignore_user_abort(true);
    $m = backup_check($zipPath);
    $safety = backup_create(false, 'before-restore');
    $pdo = db();
    // The Google Drive connection, backup settings and the notification key stay as they are now (an older backup may not have them).
    $keep = $pdo->query("SELECT k, v FROM settings WHERE k LIKE 'gdrive\\_%' OR k LIKE 'backup\\_%' OR k = 'vapid_private'")->fetchAll(PDO::FETCH_KEY_PAIR);
    $in = fopen('zip://' . $zipPath . '#database.sql', 'r');
    if (!$in) {
        throw new RuntimeException('Could not read database.sql from the backup.');
    }
    $n = 0;
    $stmt = '';
    try {
        while (($line = fgets($in)) !== false) {
            if ($stmt === '' && (str_starts_with($line, '--') || trim($line) === '')) {
                continue;
            }
            $stmt .= $line;
            if (str_ends_with(rtrim($line, "\r\n"), ';')) {
                $pdo->exec($stmt);
                $stmt = '';
                $n++;
            }
        }
    } finally {
        fclose($in);
        $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
    }
    // Photos
    $photos = 0;
    $zip = new ZipArchive();
    if ($zip->open($zipPath) === true) {
        $base = APP_ROOT . '/uploads/';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $e = (string)$zip->getNameIndex($i);
            if (!str_starts_with($e, 'uploads/') || str_ends_with($e, '/') || str_contains($e, '..') || str_contains($e, "\0")) {
                continue;
            }
            $dest = $base . substr($e, 8);
            if (!is_dir(dirname($dest))) {
                @mkdir(dirname($dest), 0755, true);
            }
            $src = $zip->getStream($e);
            $out = $src ? fopen($dest, 'w') : false;
            if ($src && $out) {
                stream_copy_to_stream($src, $out);
                fclose($out);
                $photos++;
            }
            if ($src) {
                fclose($src);
            }
        }
        $zip->close();
    }
    // Attendance data: the current file is kept next to it first (db-before-restore-….php), then replaced.
    $attRestored = false;
    $att = attendance_data_file(true);
    if ($att && ($data = @file_get_contents('zip://' . $zipPath . '#attendance/db.php')) !== false && $data !== '') {
        if (is_file($att)) {
            @copy($att, dirname($att) . '/db-before-restore-' . date('Y-m-d-His') . '.php');
        }
        $tmp = $att . '.restore-' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $data) === strlen($data) && rename($tmp, $att)) {
            $attRestored = true;
        } else {
            @unlink($tmp);
        }
    }
    foreach ($keep as $k => $v) {
        set_setting($k, (string)$v);
    }
    // Bring the restored database up to this version of the app on the next page.
    set_setting('schema_stamp', '');
    set_setting('backup_restored', json_encode(['at' => now(), 'from' => basename($zipPath), 'made' => $m['created'] ?? '']));
    return ['statements' => $n, 'photos' => $photos, 'attendance' => $attRestored, 'safety' => $safety['name'], 'created' => $m['created'] ?? ''];
}

// ---------------------------------------------------------------- Google Drive (OAuth, files this app made only)

function gdrive_urls(): array
{
    $test = getenv('LOOMA_GDRIVE_TEST'); // local fake Drive for the tests
    return $test ? ['auth' => "$test/auth", 'token' => "$test/token", 'api' => "$test/drive/v3", 'upload' => "$test/upload/drive/v3"]
        : ['auth' => 'https://accounts.google.com/o/oauth2/v2/auth', 'token' => 'https://oauth2.googleapis.com/token',
            'api' => 'https://www.googleapis.com/drive/v3', 'upload' => 'https://www.googleapis.com/upload/drive/v3'];
}

/** Full address Google sends the admin back to after "Allow" (must be listed in the Google Cloud OAuth client). */
function gdrive_redirect_uri(): string
{
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $host = $_SERVER['HTTP_HOST'] ?? setting('site_host', 'localhost');
    return ($https ? 'https' : 'http') . '://' . $host . base_url('admin/backup.php');
}

function gdrive_connected(): bool
{
    return setting('gdrive_refresh_token', '') !== '' && setting('gdrive_client_id', '') !== '';
}

function gdrive_auth_url(string $state): string
{
    return gdrive_urls()['auth'] . '?' . http_build_query([
        'client_id' => setting('gdrive_client_id', ''), 'redirect_uri' => gdrive_redirect_uri(), 'response_type' => 'code',
        'scope' => 'https://www.googleapis.com/auth/drive.file', 'access_type' => 'offline', 'prompt' => 'consent', 'state' => $state,
    ]);
}

/** One HTTP call. $body: string, or ['file' => path, 'offset', 'length'] for an upload chunk. Returns [status, body, headers]. */
function gdrive_http(string $method, string $url, array $headers = [], $body = null): array
{
    $ch = curl_init($url);
    $respHeaders = [];
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 600, CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_HEADERFUNCTION => function ($c, $h) use (&$respHeaders) {
            if (str_contains($h, ':')) {
                [$k, $v] = explode(':', $h, 2);
                $respHeaders[strtolower(trim($k))] = trim($v);
            }
            return strlen($h);
        }]);
    if (is_array($body)) {
        $fh = fopen($body['file'], 'rb');
        fseek($fh, $body['offset']);
        $data = fread($fh, $body['length']);
        fclose($fh);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    } elseif ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    } elseif ($method === 'PUT' || $method === 'POST') {
        $headers[] = 'Content-Length: 0';
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        throw new RuntimeException('Google Drive: ' . $err);
    }
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    return [$status, (string)$resp, $respHeaders];
}

/** First connection: swap the code Google sent back for a refresh token (kept in settings). */
function gdrive_exchange_code(string $code): void
{
    [$st, $b] = gdrive_http('POST', gdrive_urls()['token'], ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'code' => $code, 'client_id' => setting('gdrive_client_id', ''), 'client_secret' => setting('gdrive_client_secret', ''),
        'redirect_uri' => gdrive_redirect_uri(), 'grant_type' => 'authorization_code']));
    $j = json_decode($b, true) ?: [];
    if ($st !== 200 || empty($j['refresh_token'])) {
        throw new RuntimeException('Google did not give access: ' . ($j['error_description'] ?? $j['error'] ?? "HTTP $st"));
    }
    set_setting('gdrive_refresh_token', $j['refresh_token']);
    set_setting('gdrive_folder_id', '');
}

function gdrive_token(): string
{
    static $tok = null;
    if ($tok) {
        return $tok;
    }
    [$st, $b] = gdrive_http('POST', gdrive_urls()['token'], ['Content-Type: application/x-www-form-urlencoded'], http_build_query([
        'client_id' => setting('gdrive_client_id', ''), 'client_secret' => setting('gdrive_client_secret', ''),
        'refresh_token' => setting('gdrive_refresh_token', ''), 'grant_type' => 'refresh_token']));
    $j = json_decode($b, true) ?: [];
    if ($st !== 200 || empty($j['access_token'])) {
        throw new RuntimeException('Google Drive login expired or was removed — connect Google Drive again. (' . ($j['error'] ?? "HTTP $st") . ')');
    }
    return $tok = $j['access_token'];
}

function gdrive_api(string $method, string $path, ?array $json = null, string $base = 'api'): array
{
    $h = ['Authorization: Bearer ' . gdrive_token()];
    if ($json !== null) {
        $h[] = 'Content-Type: application/json; charset=UTF-8';
    }
    [$st, $b, $hd] = gdrive_http($method, gdrive_urls()[$base] . $path, $h, $json !== null ? json_encode($json) : null);
    if ($st >= 400) {
        $j = json_decode($b, true);
        throw new RuntimeException('Google Drive: ' . ($j['error']['message'] ?? "HTTP $st"));
    }
    return [$st, json_decode($b, true) ?: [], $hd];
}

/** The "Looma backups" folder in the admin's Drive (made once). */
function gdrive_folder(): string
{
    $id = setting('gdrive_folder_id', '');
    if ($id !== '') {
        return $id;
    }
    [, $j] = gdrive_api('POST', '/files?fields=id', ['name' => 'Looma backups', 'mimeType' => 'application/vnd.google-apps.folder']);
    set_setting('gdrive_folder_id', $j['id']);
    return $j['id'];
}

/** Upload a backup zip in 8 MB pieces (resumable upload). Returns the Drive file id. */
function gdrive_upload(string $path, string $name): string
{
    $size = filesize($path);
    [$st, , $hd] = gdrive_http('POST', gdrive_urls()['upload'] . '/files?uploadType=resumable&fields=id', [
        'Authorization: Bearer ' . gdrive_token(), 'Content-Type: application/json; charset=UTF-8',
        'X-Upload-Content-Type: application/zip', 'X-Upload-Content-Length: ' . $size],
        json_encode(['name' => $name, 'parents' => [gdrive_folder()], 'mimeType' => 'application/zip']));
    $session = $hd['location'] ?? '';
    if ($st !== 200 || $session === '') {
        throw new RuntimeException("Google Drive did not start the upload (HTTP $st).");
    }
    $chunk = 8 * 1024 * 1024;
    for ($off = 0; $off < $size || $size === 0;) {
        $len = min($chunk, $size - $off);
        [$st, $b] = gdrive_http('PUT', $session, ['Content-Type: application/zip', 'Content-Range: bytes ' . ($len ? $off . '-' . ($off + $len - 1) : '*') . '/' . $size],
            ['file' => $path, 'offset' => $off, 'length' => $len]);
        if ($st === 200 || $st === 201) {
            return (string)(json_decode($b, true)['id'] ?? '');
        }
        if ($st !== 308) {
            throw new RuntimeException("Google Drive upload stopped (HTTP $st).");
        }
        $off += $len;
    }
    throw new RuntimeException('Google Drive upload did not finish.');
}

/** Backups in the Drive folder, newest first. */
function gdrive_list(): array
{
    $q = rawurlencode("'" . gdrive_folder() . "' in parents and trashed = false");
    [, $j] = gdrive_api('GET', "/files?q=$q&orderBy=createdTime%20desc&pageSize=100&fields=files(id,name,size,createdTime,webViewLink)");
    return array_values(array_filter($j['files'] ?? [], fn($f) => str_ends_with($f['name'], '.zip')));
}

/** Keep the newest $keep daily backups in Drive. */
function gdrive_prune(int $keep = BACKUP_KEEP): int
{
    $n = 0;
    $daily = array_values(array_filter(gdrive_list(), fn($f) => str_starts_with($f['name'], 'looma-backup-')));
    foreach (array_slice($daily, $keep) as $f) {
        gdrive_api('DELETE', '/files/' . rawurlencode($f['id']));
        $n++;
    }
    return $n;
}

function gdrive_download(string $fileId, string $dest): void
{
    $fh = fopen($dest, 'w');
    $ch = curl_init(gdrive_urls()['api'] . '/files/' . rawurlencode($fileId) . '?alt=media');
    curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . gdrive_token()], CURLOPT_TIMEOUT => 1800, CURLOPT_FOLLOWLOCATION => true]);
    $ok = curl_exec($ch);
    $st = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    fclose($fh);
    if (!$ok || $st !== 200) {
        @unlink($dest);
        throw new RuntimeException("Could not download the backup from Google Drive (HTTP $st).");
    }
}

// ---------------------------------------------------------------- the daily run

/** Make a backup, keep 7, copy to Drive (keep 7 there). Records the result in settings. Returns the status. */
function backup_run(string $why): array
{
    $status = ['at' => now(), 'why' => $why, 'ok' => false, 'msg' => '', 'name' => '', 'size' => 0, 'drive' => null];
    try {
        $b = backup_create(setting('backup_photos', '1') === '1');
        $status = array_merge($status, ['ok' => true, 'name' => $b['name'], 'size' => $b['size'],
            'msg' => $b['tables'] . ' tables, ' . $b['rows'] . ' rows' . ($b['photos'] ? ', ' . $b['photos'] . ' photos' : '')]);
        backup_prune();
        if (gdrive_connected()) {
            try {
                gdrive_upload($b['path'], $b['name']);
                gdrive_prune();
                $status['drive'] = 'uploaded';
            } catch (Throwable $e) {
                $status['drive'] = 'failed: ' . $e->getMessage();
            }
        }
    } catch (Throwable $e) {
        $status['msg'] = $e->getMessage();
    }
    set_setting('backup_status', json_encode($status));
    if ($status['ok']) {
        set_setting('backup_last_day', today());
    }
    return $status;
}

/** Called once a day (cron or the first page of the day): back up if today's backup is not done yet. */
function backup_daily(): ?array
{
    if (setting('backup_auto', '1') !== '1' || setting('backup_last_day', '') === today()) {
        return null;
    }
    // One run at a time (two people opening the app at once).
    $lock = fopen(backup_dir() . '/.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
        return null;
    }
    try {
        $last = (string)q("SELECT v FROM settings WHERE k = 'backup_last_day'")->fetchColumn();
        return $last === today() ? null : backup_run('daily');
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
