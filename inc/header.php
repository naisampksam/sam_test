<?php
/** @var string $pageTitle */
$me = current_user();
$active = $active ?? '';
$f = flash();
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#1f2a44">
<meta name="robots" content="noindex, nofollow">
<title><?= h($pageTitle ?? 'Orders') ?> · <?= h(setting('company_name', 'Looma Apparels')) ?></title>
<link rel="stylesheet" href="<?= h(base_url('assets/style.css')) ?>?v=1">
</head>
<body>
<header class="topbar">
  <a class="brand" href="<?= h(base_url('index.php')) ?>"><?= h(setting('company_name', 'Looma Apparels')) ?></a>
  <nav class="nav">
    <?php if (cap('dashboard')): ?>
      <a href="<?= h(base_url('dashboard.php')) ?>" class="<?= $active === 'dashboard' ? 'on' : '' ?>">Dashboard</a>
    <?php endif; ?>
    <a href="<?= h(base_url('orders.php')) ?>" class="<?= $active === 'orders' ? 'on' : '' ?>">Orders</a>
    <?php if (cap('create')): ?>
      <a href="<?= h(base_url('order.php?new=1')) ?>" class="<?= $active === 'new' ? 'on' : '' ?>">+ New</a>
    <?php endif; ?>
    <?php if (cap('catalog')): ?>
      <a href="<?= h(base_url('admin/catalog.php')) ?>" class="<?= $active === 'catalog' ? 'on' : '' ?>">Catalog</a>
    <?php endif; ?>
    <?php if (cap('cleanup')): ?>
      <a href="<?= h(base_url('admin/storage.php')) ?>" class="<?= $active === 'storage' ? 'on' : '' ?>">Storage</a>
    <?php endif; ?>
    <?php if (is_admin()): ?>
      <a href="<?= h(base_url('admin/users.php')) ?>" class="<?= $active === 'users' ? 'on' : '' ?>">Staff</a>
      <a href="<?= h(base_url('admin/settings.php')) ?>" class="<?= $active === 'settings' ? 'on' : '' ?>">Settings</a>
    <?php endif; ?>
  </nav>
  <div class="me">
    <a href="<?= h(base_url('account.php')) ?>" title="My account"><?= h($me['name'] ?: $me['username']) ?></a>
    <form method="post" action="<?= h(base_url('logout.php')) ?>" class="inline"><?= csrf_field() ?><button class="linkbtn">Log out</button></form>
  </div>
</header>
<main class="wrap">
<?php if ($f): ?><p class="alert <?= h($f['type']) ?>"><?= h($f['msg']) ?></p><?php endif; ?>
