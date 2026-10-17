<?php
require __DIR__ . '/inc/bootstrap.php';

require __DIR__ . '/inc/tasks.php';

require_login();
// Video editor, marketing … start on their tasks; the orders team on the dashboard or orders.
redirect(office_staff() ? 'tasks.php' : (cap('dashboard') ? 'dashboard.php' : 'orders.php'));
