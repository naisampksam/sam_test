<?php
// Page frame of the partner portal (/partner/, partners.loomaapparels.com): a light, phone-first customer app —
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
        'designs' => ['Products', 'partner/designs.php', '<rect x="3" y="3" width="7" height="7" rx="1.5"/><rect x="14" y="3" width="7" height="7" rx="1.5"/><rect x="3" y="14" width="7" height="7" rx="1.5"/><rect x="14" y="14" width="7" height="7" rx="1.5"/>']];
    // Stock only when it means something for them: returns, or their own blanks switched on by Looma.
    if ($pu) {
        require_once __DIR__ . '/partner_stock.php';
        if (!partner_has_stock_tab($pu['partner']) && $active !== 'stock') {
            unset($tabs['stock']);
        }
    }
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
<link rel="manifest" href="<?= h(base_url('partner/manifest.webmanifest')) ?>">
<meta name="theme-color" content="#f4f1ea" media="(prefers-color-scheme: light)">
<meta name="theme-color" content="#0b1020" media="(prefers-color-scheme: dark)">
<meta name="apple-mobile-web-app-status-bar-style" content="default">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,400..700&display=swap">
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
// A link to #export (or another closed section) opens it.
if (location.hash) { var t = document.getElementById(location.hash.slice(1)); if (t && t.tagName === 'DETAILS') t.open = true; }
document.addEventListener('click', function (e) { var c = e.target.closest('[data-copy]'); if (!c || !navigator.clipboard) return; e.preventDefault();
  navigator.clipboard.writeText(c.dataset.copy).then(function () { var t = c.textContent; c.textContent = 'Copied ✓'; setTimeout(function () { c.textContent = t; }, 1400); }); });</script>
</body>
</html><?php
}

/**
 * "Recharge your wallet" pop-up: amount (at least what is needed, rounded up to the next ₹100, can be raised),
 * the payment QR code to scan, then "I've paid" (UTR / screenshot). No QR set → WhatsApp chat (Looma sends the QR).
 */
function partner_recharge_dialog(array $p, float $need, float $orderTotal, float $wallet, string $ref = '', int $poId = 0, bool $open = false): void
{
    $min = round($need, 2);
    $amt = partner_round_up($min);
    $qr = partner_qr_file($p) !== '';
    $paid = base_url('partner/money.php?' . ($poId ? 'po=' . $poId . '&' : '') . 'amount=');
    ?>
<dialog class="pt-dialog" id="ptRecharge" aria-labelledby="ptRechargeT" data-min="<?= h((string)$min) ?>" data-paid="<?= h($paid) ?>">
  <div class="pt-dialog-ico" aria-hidden="true">👛</div>
  <h2 id="ptRechargeT">Recharge your wallet</h2>
  <p><?= $wallet < 0 ? 'You owe Looma <b>₹' . h(inr_number(-$wallet, 2)) . '</b>' : 'Your wallet has <b>₹' . h(inr_number($wallet, 2)) . '</b>' ?><?= $orderTotal > 0 ? '. This order needs <b>₹' . h(inr_number($orderTotal, 2)) . '</b>' : '' ?>.</p>
  <label class="pt-amount"><span><i class="pt-stepn">1</i> How much? (₹)</span><input type="number" inputmode="decimal" id="ptRAmt" min="<?= h((string)$min) ?>" step="1" value="<?= h((string)$amt) ?>"></label>
  <p class="pt-hint" id="ptRHint"><?= $min > 0 ? 'At least ₹' . h(inr_number($min, 2)) . '. The rest stays in your wallet for the next orders.' : 'Any amount — it stays in your wallet for your orders.' ?></p>
  <?php if ($qr): ?>
    <p class="pt-dstep"><i class="pt-stepn">2</i> Scan with GPay, PhonePe, Paytm or any UPI app and pay <b id="ptRPay">₹<?= h(inr_number($amt)) ?></b></p>
    <img class="pt-qr" src="<?= h(base_url('partner/qr.php')) ?>" alt="Looma payment QR code" width="240" height="240">
    <p class="pt-dstep"><i class="pt-stepn">3</i> Send the screenshot — Looma adds it to your wallet</p>
    <a class="pt-btn filled wide" id="ptRPaid" href="<?= h($paid . $amt) ?>">I’ve paid — add the screenshot</a>
    <a class="pt-wa-link" href="<?= h(partner_recharge_link($p, $amt, $ref)) ?>" target="_blank" rel="noopener">Need help? WhatsApp Looma</a>
  <?php else: ?>
    <p class="pt-dstep"><i class="pt-stepn">2</i> Get the payment QR on WhatsApp and pay</p>
    <a class="pt-btn filled wide pt-wa" id="ptRWa" href="<?= h(partner_recharge_link($p, $amt, $ref)) ?>" target="_blank" rel="noopener">Recharge on WhatsApp</a>
    <p class="pt-dstep"><i class="pt-stepn">3</i> Send the screenshot — Looma adds it to your wallet</p>
    <a class="pt-btn tinted wide" id="ptRPaid" href="<?= h($paid . $amt) ?>">I’ve paid — add the screenshot</a>
  <?php endif; ?>
  <form method="dialog"><button class="pt-btn plain wide">Later</button></form>
</dialog>
<script>(function () {
  var d = document.getElementById('ptRecharge'), a = document.getElementById('ptRAmt'), min = parseFloat(d.dataset.min) || 0;
  function sync() {
    var v = parseFloat(a.value) || 0, ok = v >= min - 0.004 && v > 0;
    a.setCustomValidity(ok ? '' : 'At least ₹' + min);
    d.classList.toggle('bad', !ok);
    var pay = document.getElementById('ptRPay'); if (pay) pay.textContent = '₹' + Math.round(v * 100) / 100;
    var paid = document.getElementById('ptRPaid'); if (paid) paid.href = d.dataset.paid + (ok ? v : min);
    var wa = document.getElementById('ptRWa'); if (wa) wa.href = wa.href.replace(/with%20%E2%82%B9[0-9.,]+/, 'with%20%E2%82%B9' + (ok ? v : min));
  }
  a.addEventListener('input', sync);
  a.addEventListener('change', function () { if ((parseFloat(a.value) || 0) < min) { a.value = Math.ceil(min); } sync(); });
  [].forEach.call(document.querySelectorAll('[data-recharge]'), function (b) { b.addEventListener('click', function (e) { e.preventDefault(); d.showModal ? d.showModal() : d.setAttribute('open', ''); }); });
  <?php if ($open): ?>if (d.showModal) d.showModal(); else d.setAttribute('open', '');
  if (history.replaceState) history.replaceState(null, '', location.pathname + location.search.replace(/[?&]recharge=1/, '').replace(/^&/, '?'));<?php endif; ?>
})();</script>
<?php
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
