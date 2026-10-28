<?php
// Daily backup at a fixed time. Cron: curl -s "https://…/backup_cron.php?key=KEY"  (or: php backup_cron.php)
// The key is shown on More → Backup & restore. It can only start today's backup (once a day).
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/backup.php';

header('Content-Type: text/plain');
$cli = PHP_SAPI === 'cli';
$key = setting('backup_cron_key', '');
if (!$cli && ($key === '' || !hash_equals($key, (string)($_GET['key'] ?? '')))) {
    http_response_code(403);
    exit("Not allowed.\n");
}
ensure_schema();
$st = backup_daily();
echo $st === null ? "Today's backup is already done (or daily backup is off).\n"
    : ($st['ok'] ? "OK {$st['name']} ({$st['msg']})" . ($st['drive'] ? " · Google Drive: {$st['drive']}" : '') . "\n" : "FAILED: {$st['msg']}\n");
