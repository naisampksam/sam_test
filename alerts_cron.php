<?php
// Orders waiting to ship → a notification and the number on the production team's app icon, also when nobody has the
// app open; and at 9 am (Monday–Saturday) the morning summary: ship today, print today, delayed. Cron (every hour): curl -s "https://…/alerts_cron.php?key=KEY"  (or: php alerts_cron.php). Same key as backups.
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
$morning = morning_summary_check(!empty($_GET['morning']) && PHP_SAPI !== 'cli' ? true : false);
$evening = evening_summary_check(!empty($_GET['evening']) && PHP_SAPI !== 'cli');
echo $evening === null ? "Evening summary: not now (after " . (int)setting('evening_hour', '18') . ":00, Monday–Saturday, once a day).\n" : "Evening summary sent to $evening device(s).\n";
echo $morning === null ? "Morning summary: not now (after " . (int)setting('morning_hour', '9') . ":00, Monday–Saturday, once a day).\n" : "Morning summary sent to $morning device(s).\n";
