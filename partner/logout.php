<?php
// Partner log out (the staff login in the same browser, if any, stays).
require __DIR__ . '/../inc/bootstrap.php';

start_session();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    unset($_SESSION['pu'], $_SESSION['psv']);
    session_regenerate_id(true);
}
redirect('partner/index.php');
