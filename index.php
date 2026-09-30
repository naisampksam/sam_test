<?php
require __DIR__ . '/inc/bootstrap.php';

require_login();
redirect(cap('dashboard') ? 'dashboard.php' : 'orders.php');
