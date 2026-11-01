<?php
// Orders waiting to ship → a notification and the number on the production team's app icon, also when nobody has the
// app open. Cron (every hour): curl -s "https://…/alerts_cron.php?key=KEY"  (or: php alerts_cron.php). Same key as backups.
require __DIR__ . '/inc/bootstrap.php';
require __DIR__ . '/inc/push.php';

header('Content-Type: text/plain');
$key = setting('backup_cron_key', '');
if (PHP_SAPI !== 'cli' && ($key === '' || !hash_equals($key, (string)($_GET['key'] ?? '')))) {
    http_response_code(403);
    exit("Not allowed.\n");
}
ensure_schema();
$sent = ship_alert_check(true);
echo ship_waiting_count() . " orders waiting to ship · notifications sent: " . (int)$sent . "\n";
