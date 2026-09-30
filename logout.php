<?php
require __DIR__ . '/inc/bootstrap.php';

start_session();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $_SESSION = [];
    session_destroy();
}
redirect('login.php');
