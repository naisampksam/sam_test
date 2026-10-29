<?php
require __DIR__ . '/inc/bootstrap.php';

require __DIR__ . '/inc/tasks.php';

// erp.loomaapparels.com: a home screen with Orders, Attendance and Tasks (orders.loomaapparels.com goes straight in).
$host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
if (empty($_GET['go']) && (str_starts_with($host, 'erp.') || setting('portal_home', '') === '1')) {
    require __DIR__ . '/inc/portal.php';
    exit;
}

require_login();
// Video editor, marketing … start on their tasks; the orders team on the dashboard or orders.
redirect(office_staff() ? tasks_path() : (cap('dashboard') ? 'dashboard.php' : 'orders.php'));
