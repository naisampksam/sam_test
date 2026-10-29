<?php
require __DIR__ . '/inc/bootstrap.php';

require __DIR__ . '/inc/tasks.php';

require_login();
// erp.loomaapparels.com: after logging in, a home screen with the apps this person uses (orders.loomaapparels.com goes straight in).
if (empty($_GET['go']) && is_erp_site()) {
    require __DIR__ . '/inc/portal.php';
    exit;
}

// Video editor, marketing … start on their tasks; the orders team on the dashboard or orders.
redirect(office_staff() ? tasks_path() : (cap('dashboard') ? 'dashboard.php' : 'orders.php'));
