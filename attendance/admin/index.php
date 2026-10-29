<?php
// /attendance/admin on servers that don't apply the rewrite rules in attendance/.htaccess.
header('Cache-Control: no-store');
header('Location: ../index.php?page=admin');
