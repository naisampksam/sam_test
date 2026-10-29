<?php
/** @var string $pageTitle */
$me = current_user();
$active = $active ?? '';
$f = flash();

/** Small line icons (inline SVG, inherit text color). */
function icon(string $name): string
{
    $paths = [
        'orders' => '<path d="M9 5h10M9 12h10M9 19h10"/><circle cx="4.5" cy="5" r="1.2"/><circle cx="4.5" cy="12" r="1.2"/><circle cx="4.5" cy="19" r="1.2"/>',
        'plus' => '<path d="M12 5v14M5 12h14"/>',
        'chart' => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'more' => '<circle cx="5" cy="12" r="1.3"/><circle cx="12" cy="12" r="1.3"/><circle cx="19" cy="12" r="1.3"/>',
        'shirt' => '<path d="M8 3 3 6l2 4 3-1v12h8V9l3 1 2-4-5-3a4 4 0 0 1-8 0Z"/>',
        'box' => '<path d="M3 7l9-4 9 4-9 4-9-4Zm0 0v10l9 4 9-4V7M12 11v10"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0M16 4.5a3.5 3.5 0 0 1 0 7M18 14a6 6 0 0 1 3.5 6"/>',
        'gear' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>',
        'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'out' => '<path d="M15 4h4v16h-4M10 8l-4 4 4 4M6 12h10"/>',
        'printer' => '<path d="M7 9V3h10v6M7 17H4v-6a2 2 0 0 1 2-2h12a2 2 0 0 1 2 2v6h-3"/><path d="M7 14h10v7H7z"/>',
        'calc' => '<rect x="5" y="2.5" width="14" height="19" rx="2"/><path d="M8 6.5h8M8.5 11h.01M12 11h.01M15.5 11h.01M8.5 14.5h.01M12 14.5h.01M15.5 14.5h.01M8.5 18h.01M12 18h3.5"/>',
        'bill' => '<path d="M6 2.5h12v19l-3-2-3 2-3-2-3 2v-19Z"/><path d="M9 7.5h6M9 11h6M9 14.5h4"/>',
        'star' => '<path d="m12 3 2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9L12 3Z"/>',
    ];
    return '<svg class="ico" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
        . ($paths[$name] ?? '') . '</svg>';
}

$company = setting('company_name', 'Looma Apparels');
$links = [];
if (cap('dashboard')) {
    $links['dashboard'] = ['Dashboard', 'dashboard.php', 'chart'];
}
$links['orders'] = ['Orders', 'orders.php', 'orders'];
if (cap('create')) {
    $links['new'] = ['New order', 'order.php?new=1', 'plus'];
}
if (can_view('printed')) {
    $links['printlist'] = ['Print list', 'print_list.php', 'printer'];
}
$more = []; // [label, url, icon, group]
// Tasks page: only for people the admin gave it (video editor, marketing …); everyone has Your performance (their work score).
// Staff outside the orders team see only these up top.
require_once __DIR__ . '/tasks.php';
$jobOf = $me['job'] ?? 'production';
$hasTasks = has_task_page($me);
if ($me['role'] !== 'admin' && $jobOf !== 'production' && $hasTasks) {
    $links = ['tasks' => ['My tasks', tasks_path(), 'orders'], 'perf' => ['Your performance', 'performance.php', 'chart']];
} else {
    if ($hasTasks) {
        $more['tasks'] = ['Tasks', tasks_path(), 'orders', 'Work'];
    }
    $more['perf'] = [is_admin($me) ? 'Staff performance' : 'Your performance', 'performance.php', 'chart', 'Work'];
}
if (cap('designs') || cap('create')) {
    $more['designs'] = ['Saved designs', 'designs.php', 'star', 'Work'];
}
if (is_admin($me) || cap('stock') || $jobOf === 'production') {
    $more['damages'] = ['Damaged T-shirts', 'damages.php', 'shirt', 'Work'];
}
if (cap('slips')) {
    $more['labels'] = ['Shipping labels', 'labels.php', 'box', 'Work'];
}
if (cap('estimate')) {
    $more['estimate'] = ['Cost estimate', 'estimate.php', 'calc', 'Work'];
}
if (cap('billing')) {
    $more['bills'] = ['Bills', 'bills.php', 'bill', 'Money'];
    $more['dues'] = ['Payments due', 'dues.php', 'chart', 'Money'];
    $more['quotes'] = ['Quotations', 'quotes.php', 'bill', 'Money'];
}
if (cap('stock') || cap('catalog')) {
    $more['stock'] = ['Products & stock', 'stock.php', 'shirt', 'Money'];
}
if (cap('accounts')) {
    $more['expenses'] = ['Expenses', 'expenses.php', 'bill', 'Money'];
    $more['accounts'] = ['Profit & balance', 'accounts.php', 'chart', 'Money'];
}
if (cap('customers')) {
    $more['customers'] = ['Customers', 'admin/customers.php', 'user', 'Setup'];
}
if (cap('catalog')) {
    $more['catalog'] = ['Order form options', 'admin/catalog.php', 'gear', 'Setup'];
}
if (cap('cleanup')) {
    $more['storage'] = ['Free up space', 'admin/storage.php', 'box', 'Setup'];
}
if (is_admin()) {
    $more['users'] = ['Staff', 'admin/users.php', 'users', 'Setup'];
    $more['import'] = ['Import customers', 'admin/import.php', 'users', 'Setup'];
    $more['settings'] = ['Settings', 'admin/settings.php', 'gear', 'Setup'];
    $more['backup'] = ['Backup & restore', 'admin/backup.php', 'box', 'Setup'];
    $more['attendance'] = ['Attendance admin', 'attendance/index.php?page=admin', 'users', 'Setup'];
    $more['stafflinks'] = ['Staff links', 'admin/staff_links.php', 'users', 'Setup'];
}
// Computer: Bills, Stock and Shipping labels sit in the top bar; everything else is under "More".
$topExtra = array_intersect_key($more, array_flip(['bills', 'stock', 'labels', 'tasks']));
$moreTop = array_diff_key($more, $topExtra);
$navGroupsOf = fn(array $navItems) => array_reduce(array_keys($navItems), function ($acc, $key) use ($navItems) { $acc[$navItems[$key][3]][$key] = $navItems[$key]; return $acc; }, []);
// Top-right badge. Tasks page: done this month ÷ (done this month + still open). Orders team: this month's work points.
$taskPct = null;
$myScore = null;
try {
    if (is_admin($me)) {
        throw new LogicException('admin'); // admins give tasks and are not scored: no badge
    }
    if (!task_page_cap($me)) {
        throw new RuntimeException('score'); // orders team: their work score (even when given a task)
    }
    $tk = q("SELECT IFNULL(SUM(status = 'done' AND done_at >= ?), 0) d, IFNULL(SUM(status <> 'done'), 0) o,
             IFNULL(SUM(status <> 'done' AND due_date < ?), 0) late FROM tasks WHERE assigned_to = ?", [date('Y-m-01 00:00:00'), today(), $me['id']])->fetch();
    if ((int)$tk['d'] + (int)$tk['o'] > 0) {
        $taskPct = ['d' => (int)$tk['d'], 'all' => (int)$tk['d'] + (int)$tk['o'], 'late' => (int)$tk['late']];
        $taskPct['pct'] = (int)round($taskPct['d'] / $taskPct['all'] * 100);
    }
} catch (Throwable $e) {
    // tasks table not created yet (first load after an update), or no Tasks page: show the score
    try {
        $myScore = task_page_cap($me) || !perf_tracked($me) ? null : my_month_score();
    } catch (Throwable $e) {
    }
}
$initials = strtoupper(mb_substr(trim($me['name'] ?: $me['username']), 0, 1));
// Task Manager: the Tasks pages (and every page for staff who only work on tasks) use their own dark app shell.
$taskApp = $taskApp ?? office_staff($me);
$ordersHome = base_url(cap('dashboard') ? 'dashboard.php' : 'orders.php');
?><!doctype html>
<html lang="en"<?= theme_attr() ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="theme-color" content="<?= theme_choice() === 'dark' || ($taskApp && theme_choice() !== 'light') ? '#0b0c0f' : '#ffffff' ?>">
<meta name="robots" content="noindex, nofollow">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<title><?= h($pageTitle ?? 'Orders') ?> · <?= h($company) ?></title>
<?= app_icon_tags() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= h(asset('assets/style.css')) ?>">
</head>
<body<?= $taskApp ? ' class="tm"' : '' ?>>
<?php if ($taskApp): $tmView = $active === 'tasks' ? ($_GET['view'] ?? 'board') : ''; ?>
<header class="tm-bar">
  <a class="tm-brand" href="<?= h(is_erp_site() ? base_url('index.php') : tasks_url()) ?>" title="<?= is_erp_site() ? 'Home' : 'Task Manager' ?>">
    <img class="brand-logo tm-logo" src="<?= h(asset('assets/icons/icon-192.png')) ?>" alt="Looma Apparels" width="34" height="34"><span class="tm-brand-text"><b>Task Manager</b><small><?= h($company) ?></small></span>
  </a>
  <nav class="tm-nav" aria-label="Task Manager">
    <?php if (!task_page_cap($me)): ?>
      <a href="<?= h(tasks_url()) ?>" class="<?= $active === 'tasks' && empty($_GET['id']) ? 'on' : '' ?>">📋 My tasks</a>
    <?php else: ?>
      <?php foreach (['board' => '▦ Board', 'table' => '☰ Table', 'calendar' => '📅 Calendar'] as $vk => $vl): ?>
        <a href="<?= h(tasks_url($vk === 'board' ? '' : 'view=' . $vk)) ?>" class="<?= $tmView === $vk && empty($_GET['id']) ? 'on' : '' ?>"><?= $vl ?></a>
      <?php endforeach; ?>
    <?php endif; ?>
    <a href="<?= h(base_url('performance.php')) ?>" class="<?= $active === 'perf' ? 'on' : '' ?>">📈 <?= is_admin($me) ? 'Staff performance' : 'Performance' ?></a>
  </nav>
  <?php if (is_admin($me)): ?>
    <span class="task-pct-gap"></span>
  <?php elseif ($myScore !== null): ?>
    <a class="task-pct score" href="<?= h(base_url('performance.php')) ?>" title="<?= h('Your work points this month' . ($myScore['of'] > 1 ? ' · #' . $myScore['rank'] . ' of ' . $myScore['of'] . ' in the team' : '')) ?>">⭐ <b><?= (int)$myScore['points'] ?></b><span class="tp-txt">pts<?= $myScore['of'] > 1 ? ' · #' . (int)$myScore['rank'] : '' ?></span></a>
  <?php elseif ($taskPct !== null): ?>
    <a class="task-pct <?= $taskPct['pct'] >= 80 ? 'good' : ($taskPct['pct'] >= 50 ? 'mid' : 'low') ?>" href="<?= h(base_url('performance.php')) ?>" title="<?= h($taskPct['d'] . ' of ' . $taskPct['all'] . ' tasks done this month' . ($taskPct['late'] ? ' · ' . $taskPct['late'] . ' late' : '')) ?>">
      <span class="tp-ring" style="--p: <?= $taskPct['pct'] ?>"></span><b><?= $taskPct['pct'] ?>%</b><span class="tp-txt"><?= $taskPct['d'] ?>/<?= $taskPct['all'] ?> tasks</span><?= $taskPct['late'] ? '<span class="tp-late">⚠ ' . $taskPct['late'] . '</span>' : '' ?></a>
  <?php else: ?>
    <a class="task-pct none" href="<?= h(base_url('performance.php')) ?>" title="No tasks this month">📋 <span class="tp-txt">No tasks</span></a>
  <?php endif; ?>
  <?php if (is_erp_site()): ?><a class="tm-switch" href="<?= h(base_url('index.php')) ?>" title="All apps">⌂ Home</a><?php endif; ?>
  <?php if (!office_staff($me)): ?><a class="tm-switch" href="<?= h($ordersHome) ?>" title="Back to the orders app">Orders app ↗</a><?php endif; ?>
  <?php if (is_admin($me)): ?><a class="tm-switch" href="<?= h(base_url('attendance/index.php?page=admin')) ?>">Attendance ↗</a><?php endif; ?>
  <details class="me">
    <summary aria-label="Account"><span class="avatar"><?= h($initials) ?></span><span class="me-name"><?= h($me['name'] ?: $me['username']) ?></span></summary>
    <div class="menu">
      <div class="menu-head"><b><?= h($me['name']) ?></b><small>@<?= h($me['username']) ?> · <?= $me['role'] === 'admin' ? 'Admin' : 'Staff' ?></small></div>
      <?= theme_picker() ?>
      <?php if (is_erp_site()): ?><a href="<?= h(base_url('index.php')) ?>">⌂ Home (all apps)</a><?php endif; ?>
      <a href="<?= h(base_url('account.php')) ?>"><?= icon('user') ?> My account</a>
      <form method="post" action="<?= h(base_url('logout.php')) ?>"><?= csrf_field() ?><button><?= icon('out') ?> Log out</button></form>
    </div>
  </details>
</header>
<?php else: ?>
<header class="topbar">
  <a class="brand" href="<?= h(base_url('index.php')) ?>">
    <img class="brand-logo" src="<?= h(asset('assets/icons/icon-192.png')) ?>" alt="Looma Apparels" width="36" height="36">
    <span class="brand-text"><b><?= h(strtoupper(explode(' ', $company)[0])) ?></b><small>Orders</small></span>
  </a>
  <nav class="nav" aria-label="Main">
    <?php foreach ($links + $topExtra as $k => [$label, $url]): ?>
      <a href="<?= h(base_url($url)) ?>" class="<?= $active === $k ? 'on' : '' ?> <?= $k === 'new' ? 'nav-new' : '' ?>"><?= $k === 'new' ? '+ ' : '' ?><?= h($label) ?></a>
    <?php endforeach; ?>
    <?php if ($moreTop): ?>
      <details class="nav-more">
        <summary class="<?= isset($moreTop[$active]) ? 'on' : '' ?>"><?= isset($moreTop[$active]) ? h($moreTop[$active][0]) : 'More' ?> ▾</summary>
        <div class="menu">
          <?php foreach ($navGroupsOf($moreTop) as $navGroup => $navItems): ?>
            <div class="menu-group"><?= h($navGroup) ?></div>
            <?php foreach ($navItems as $k => [$label, $url, $ic]): ?>
              <a href="<?= h(base_url($url)) ?>" class="<?= $active === $k ? 'on' : '' ?>"><?= icon($ic) ?> <?= h($label) ?></a>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </div>
      </details>
    <?php endif; ?>
  </nav>
  <?php if (is_admin($me)): ?>
  <span class="task-pct-gap"></span>
  <?php elseif ($myScore !== null): ?>
  <a class="task-pct score" href="<?= h(base_url('performance.php')) ?>" title="<?= h('Your work points this month' . ($myScore['of'] > 1 ? ' · #' . $myScore['rank'] . ' of ' . $myScore['of'] . ' in the team' : '')) ?>">⭐ <b><?= (int)$myScore['points'] ?></b><span class="tp-txt">pts<?= $myScore['of'] > 1 ? ' · #' . (int)$myScore['rank'] : '' ?></span></a>
  <?php else: ?>
  <a class="task-pct <?= $taskPct === null ? 'none' : ($taskPct['pct'] >= 80 ? 'good' : ($taskPct['pct'] >= 50 ? 'mid' : 'low')) ?>" href="<?= h(base_url('performance.php')) ?>"
     title="<?= $taskPct === null ? 'No tasks this month' : h($taskPct['d'] . ' of ' . $taskPct['all'] . ' tasks done this month' . ($taskPct['late'] ? ' · ' . $taskPct['late'] . ' late' : '')) ?>">
    <?php if ($taskPct === null): ?>📋 <span class="tp-txt">No tasks</span>
    <?php else: ?><span class="tp-ring" style="--p: <?= $taskPct['pct'] ?>"></span><b><?= $taskPct['pct'] ?>%</b><span class="tp-txt"><?= $taskPct['d'] ?>/<?= $taskPct['all'] ?> tasks</span><?= $taskPct['late'] ? '<span class="tp-late">⚠ ' . $taskPct['late'] . '</span>' : '' ?><?php endif; ?>
  </a>
  <?php endif; ?>
  <details class="me">
    <summary aria-label="Account"><span class="avatar"><?= h($initials) ?></span><span class="me-name"><?= h($me['name'] ?: $me['username']) ?></span></summary>
    <div class="menu">
      <div class="menu-head"><b><?= h($me['name']) ?></b><small>@<?= h($me['username']) ?> · <?= $me['role'] === 'admin' ? 'Admin' : 'Staff' ?></small></div>
      <?= theme_picker() ?>
      <?php if (is_erp_site()): ?><a href="<?= h(base_url('index.php')) ?>">⌂ Home (all apps)</a><?php endif; ?>
      <a href="<?= h(base_url('account.php')) ?>"><?= icon('user') ?> My account</a>
      <form method="post" action="<?= h(base_url('logout.php')) ?>"><?= csrf_field() ?><button><?= icon('out') ?> Log out</button></form>
    </div>
  </details>
</header>

<!-- Phone: bottom tab bar -->
<nav class="tabbar" aria-label="Main">
  <?php foreach ($links as $k => [$label, $url, $ic]): ?>
    <a href="<?= h(base_url($url)) ?>" class="<?= $active === $k ? 'on' : '' ?> <?= $k === 'new' ? 'tab-new' : '' ?>">
      <?= icon($ic) ?><span><?= $k === 'new' ? 'New' : ($k === 'printlist' ? 'Print' : h($label)) ?></span>
    </a>
  <?php endforeach; ?>
  <details class="tab-more <?= isset($more[$active]) ? 'on' : '' ?>">
    <summary><?= icon('more') ?><span>More</span></summary>
    <div class="sheet">
      <?php foreach ($navGroupsOf($more) as $navGroup => $navItems): ?>
        <div class="menu-group"><?= h($navGroup) ?></div>
        <div class="sheet-grid">
        <?php foreach ($navItems as $k => [$label, $url, $ic]): ?>
          <a href="<?= h(base_url($url)) ?>" class="<?= $active === $k ? 'on' : '' ?>"><?= icon($ic) ?> <?= h($label) ?></a>
        <?php endforeach; ?>
        </div>
      <?php endforeach; ?>
      <div class="menu-group">You</div>
      <?= theme_picker() ?>
      <?php if (is_erp_site()): ?><a href="<?= h(base_url('index.php')) ?>">⌂ Home (all apps)</a><?php endif; ?>
      <a href="<?= h(base_url('account.php')) ?>"><?= icon('user') ?> My account</a>
      <form method="post" action="<?= h(base_url('logout.php')) ?>"><?= csrf_field() ?><button><?= icon('out') ?> Log out</button></form>
    </div>
  </details>
</nav>
<?php endif; ?>

<main class="wrap">
<?php if ($f): ?><p class="alert <?= h($f['type']) ?>" role="status"><?= h($f['msg']) ?></p><?php endif; ?>
