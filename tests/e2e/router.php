<?php
// Router for PHP's built-in server (tests only): does what .htaccess does on the live site — /attendance/… → attendance/index.php.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
// /attendance/... → attendance/index.php (its own .htaccess does this on the live site)
if (preg_match('~^/attendance(/|$)~', $path) && !is_file(dirname(__DIR__, 2) . $path)) {
    $_SERVER['SCRIPT_NAME'] = '/attendance/index.php';
    $_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__, 2) . '/attendance/index.php';
    chdir(dirname(__DIR__, 2) . '/attendance');
    require dirname(__DIR__, 2) . '/attendance/index.php';
    return true;
}
return false;
