<?php
// Router for PHP's built-in server (tests only): does what .htaccess does on the live site — /tasks → tasks.php.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (preg_match('~^/tasks/?$~', $path)) {
    $_SERVER['SCRIPT_NAME'] = '/tasks.php';
    $_SERVER['SCRIPT_FILENAME'] = dirname(__DIR__, 2) . '/tasks.php';
    chdir(dirname(__DIR__, 2));
    require dirname(__DIR__, 2) . '/tasks.php';
    return true;
}
return false;
