<?php
// Router for PHP's built-in server (tests only): a host where the .htaccess rewrite rules do nothing (as on the live erp site).
// Like Apache with no rewrite rules: real files only, folders open their index.php, anything else is 404.
$p = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
$f = $_SERVER['DOCUMENT_ROOT'] . $p;
if (is_dir($f)) { if (substr($p, -1) !== '/') { header('Location: ' . $p . '/'); exit; } $f .= 'index.php'; $_SERVER['SCRIPT_NAME'] = $p . 'index.php'; }
if (!is_file($f)) { http_response_code(404); echo 'HOSTING 404'; exit; }
if (substr($f, -4) !== '.php') return false;
$_SERVER['SCRIPT_NAME'] = $_SERVER['SCRIPT_NAME'] ?? $p; chdir(dirname($f)); require $f;
