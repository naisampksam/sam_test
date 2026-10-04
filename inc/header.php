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
$more = [];
if (cap('designs') || cap('create')) {
    $more['designs'] = ['Designs', 'designs.php', 'star'];
}
if (cap('estimate')) {
    $more['estimate'] = ['Estimate', 'estimate.php', 'calc'];
}
if (cap('customers')) {
    $more['customers'] = ['Customers', 'admin/customers.php', 'user'];
}
if (cap('catalog')) {
    $more['catalog'] = ['Catalog', 'admin/catalog.php', 'shirt'];
}
if (cap('cleanup')) {
    $more['storage'] = ['Storage', 'admin/storage.php', 'box'];
}
if (is_admin()) {
    $more['users'] = ['Staff', 'admin/users.php', 'users'];
    $more['settings'] = ['Settings', 'admin/settings.php', 'gear'];
}
$initials = strtoupper(mb_substr(trim($me['name'] ?: $me['username']), 0, 1));
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#0f1629">
<meta name="robots" content="noindex, nofollow">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<title><?= h($pageTitle ?? 'Orders') ?> · <?= h($company) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<link rel="stylesheet" href="<?= h(asset('assets/style.css')) ?>">
</head>
<body>
<header class="topbar">
  <a class="brand" href="<?= h(base_url('index.php')) ?>">
    <span class="brand-mark">L</span>
    <span class="brand-text"><b><?= h(strtoupper(explode(' ', $company)[0])) ?></b><small>Orders</small></span>
  </a>
  <nav class="nav" aria-label="Main">
    <?php foreach ($links + $more as $k => [$label, $url]): ?>
      <a href="<?= h(base_url($url)) ?>" class="<?= $active === $k ? 'on' : '' ?> <?= $k === 'new' ? 'nav-new' : '' ?>"><?= $k === 'new' ? '+ ' : '' ?><?= h($label) ?></a>
    <?php endforeach; ?>
  </nav>
  <details class="me">
    <summary aria-label="Account"><span class="avatar"><?= h($initials) ?></span><span class="me-name"><?= h($me['name'] ?: $me['username']) ?></span></summary>
    <div class="menu">
      <div class="menu-head"><b><?= h($me['name']) ?></b><small>@<?= h($me['username']) ?> · <?= $me['role'] === 'admin' ? 'Admin' : 'Staff' ?></small></div>
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
      <?php foreach ($more as $k => [$label, $url, $ic]): ?>
        <a href="<?= h(base_url($url)) ?>" class="<?= $active === $k ? 'on' : '' ?>"><?= icon($ic) ?> <?= h($label) ?></a>
      <?php endforeach; ?>
      <a href="<?= h(base_url('account.php')) ?>"><?= icon('user') ?> My account</a>
      <form method="post" action="<?= h(base_url('logout.php')) ?>"><?= csrf_field() ?><button><?= icon('out') ?> Log out</button></form>
    </div>
  </details>
</nav>

<main class="wrap">
<?php if ($f): ?><p class="alert <?= h($f['type']) ?>" role="status"><?= h($f['msg']) ?></p><?php endif; ?>
