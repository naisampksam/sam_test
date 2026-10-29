<?php
// orders.loomaapparels.com/tasks → the Tasks page (works on any server, no rewrite rule needed).
$q = (string)($_SERVER['QUERY_STRING'] ?? '');
header('Location: ../tasks.php' . ($q !== '' ? '?' . $q : ''), true, 302);
exit;
