<?php
// Page frame of the partner portal (/partner/, orders.loomaapparels.com): a light, phone-first customer app —
// translucent top bar, large titles, bottom tab bar on phones. No staff menu or staff data.
declare(strict_types=1);

function partner_head(string $title, string $active = '', ?array $pu = null, string $bigTitle = ''): void
{
    $company = setting('company_name', 'Looma Apparels');
    $f = flash();
    $tabs = ['orders' => ['Orders', 'partner/index.php', '<path d="M8 6h13M8 12h13M8 18h13"/><circle cx="3.5" cy="6" r="1"/><circle cx="3.5" cy="12" r="1"/><circle cx="3.5" cy="18" r="1"/>'],
        'new' => ['New order', 'partner/order.php?new=1', '<path d="M12 5v14M5 12h14"/>'],
        'money' => ['Money', 'partner/money.php', '<rect x="2.5" y="6" width="19" height="13" rx="2.5"/><path d="M2.5 10h19M16 15h2"/>'],
        'stock' => ['Stock', 'partner/stock.php', '<path d="M3 7.5 12 3l9 4.5v9L12 21l-9-4.5z"/><path d="M3 7.5 12 12l9-4.5M12 12v9"/>'],
        'designs' => ['Designs', 'partner/designs.php', '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>']];
    ?><!doctype html>
<html lang="en"<?= theme_attr() ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="color-scheme" content="light dark">
<meta name="robots" content="noindex, nofollow">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="<?= h(explode(' ', $company)[0]) ?> Orders">
<title><?= h($title) ?> · <?= h($company) ?></title>
<link rel="icon" href="<?= h(asset('assets/icons/icon-32.png')) ?>" sizes="32x32" type="image/png">
<link rel="apple-touch-icon" href="<?= h(asset('assets/icons/icon-180.png')) ?>">
<link rel="stylesheet" href="<?= h(asset('assets/style.css')) ?>">
<link rel="stylesheet" href="<?= h(asset('assets/partner.css')) ?>">
</head>
<body class="pt<?= $pu ? ' pt-in' : '' ?>">
<?php if ($pu): ?>
<header class="pt-bar">
  <a class="pt-brand" href="<?= h(base_url('partner/index.php')) ?>">
    <img src="<?= h(asset('assets/icons/icon-192.png')) ?>" alt="" width="30" height="30">
    <span><b><?= h($pu['partner']['name']) ?></b><small>with <?= h($company) ?></small></span>
  </a>
  <nav class="pt-tabs" aria-label="Main">
    <?php foreach ($tabs as $k => [$l, $u, $svg]): ?>
      <a href="<?= h(base_url($u)) ?>" class="<?= $active === $k ? 'on' : '' ?> <?= $k === 'new' ? 'is-new' : '' ?>" <?= $active === $k ? 'aria-current="page"' : '' ?>>
        <svg viewBox="0 0 24 24" aria-hidden="true"><?= $svg ?></svg><span><?= h($l) ?></span></a>
    <?php endforeach; ?>
  </nav>
  <details class="pt-me">
    <summary aria-label="Account"><span class="pt-avatar"><?= h(strtoupper(mb_substr($pu['name'] ?: $pu['username'], 0, 1))) ?></span></summary>
    <div class="pt-menu">
      <b><?= h($pu['name'] ?: $pu['username']) ?></b><small>@<?= h($pu['username']) ?> · <?= h($pu['partner']['name']) ?></small>
      <?= theme_picker() ?>
      <form method="post" action="<?= h(base_url('partner/logout.php')) ?>"><?= csrf_field() ?><button class="pt-btn plain">Log out</button></form>
    </div>
  </details>
</header>
<?php endif; ?>
<main class="pt-main">
<?php if ($bigTitle !== ''): ?><h1 class="pt-title"><?= h($bigTitle) ?></h1><?php endif; ?>
<?php if ($f): ?><div class="pt-note <?= h($f['type']) ?>" role="status"><?= nl2br(h($f['msg'])) ?></div><?php endif;
}

function partner_foot(): void
{
    ?></main>
<script>window.CSRF = <?= json_encode(csrf_token()) ?>;
// Light / dark / auto (same as the staff app's account menu)
document.addEventListener('click', function (e) { var b = e.target.closest('[data-theme-set]'); if (!b) return;
  var t = b.getAttribute('data-theme-set'); document.cookie = 'looma_theme=' + t + ';path=/;max-age=31536000;samesite=lax';
  if (t === 'auto') document.documentElement.removeAttribute('data-theme'); else document.documentElement.setAttribute('data-theme', t);
  [].forEach.call(document.querySelectorAll('[data-theme-set]'), function (x) { x.setAttribute('aria-pressed', x === b ? 'true' : 'false'); }); });
document.addEventListener('click', function (e) { var c = e.target.closest('[data-copy]'); if (!c || !navigator.clipboard) return; e.preventDefault();
  navigator.clipboard.writeText(c.dataset.copy).then(function () { var t = c.textContent; c.textContent = 'Copied ✓'; setTimeout(function () { c.textContent = t; }, 1400); }); });</script>
</body>
</html><?php
}

/** URL of one of the partner's images (served only to them by partner/image.php). */
function partner_img(string $file, bool $thumb = true): string
{
    return base_url('partner/image.php?f=' . urlencode($thumb ? thumb_path($file) : $file));
}

/** Steps of an order for the progress line: [label, done?]. */
function partner_steps(array $po, ?array $o): array
{
    return [
        ['Sent', true],
        ['Accepted', $po['status'] === 'accepted'],
        ['Printing', (bool)($o['print_processed'] ?? 0) || (bool)($o['printed'] ?? 0)],
        ['Packed', (bool)($o['packed'] ?? 0)],
        ['Shipped', (bool)($o['shipped'] ?? 0)],
    ];
}
