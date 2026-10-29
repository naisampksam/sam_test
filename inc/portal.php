<?php
// ERP home (erp.loomaapparels.com), after logging in: the apps this person uses, as big boxes.
// Admin: Orders, Tasks, Attendance admin. Production team: Orders, My tasks. Creative team: Tasks.
// Clock-in is not tied to this login: the office computers open /attendance (pick your name, enter your PIN).
$me = current_user();
$company = setting('company_name', 'Looma Apparels');
$admin = is_admin($me);
$doors = [];
if (!office_staff($me)) {
    $doors[] = ['orders', 'Orders', 'Order management', 'New orders, printing, packing & shipping' . (cap('billing') ? ', bills, stock and accounts.' : '.'),
        base_url('index.php?go=orders'), array_values(array_filter([can_view('printed') ? ['Print list', base_url('print_list.php')] : null, cap('billing') ? ['Bills', base_url('bills.php')] : null]))];
}
$doors[] = ['tasks', task_page_cap($me) ? 'Tasks' : 'My tasks', 'Task Manager',
    $admin ? 'Give tasks, approve work and see everyone\'s progress.' : (task_page_cap($me) ? 'Your tasks, scripts and briefs, done work and your points.' : 'Tasks from the admin, your done work and your points.'),
    tasks_url(), [[$admin ? 'Staff performance' : 'Your performance', base_url('performance.php')]]];
$doors[] = $admin
    ? ['attendance', 'Attendance', 'Attendance & salary', 'Attendance, leave, expenses, salary, incentive and payslips. No second password.', base_url('attendance/admin'),
        [['Clock-in page', base_url('attendance/')]]]
    : ['attendance', 'Clock in', 'Attendance', 'On the office clock-in computer: pick your name and enter your PIN.', base_url('attendance/'), []];
?><!doctype html>
<html lang="en"<?= theme_attr() ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="theme-color" content="#000000">
<meta name="robots" content="noindex, nofollow">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<title><?= h($company) ?> · ERP</title>
<?= app_icon_tags() ?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap">
<style>
:root { color-scheme: dark; --bg: #07080a; --glow: #17191f; --card: #121317; --card-2: #181a1f; --line: #24262c; --text: #f2f2f0; --muted: #8d919b;
  --orders: #6d8dff; --attendance: #3fcfbf; --tasks: #b996ff; --on-accent: #07080a; --spring: cubic-bezier(.22, 1, .36, 1); --spring-bounce: cubic-bezier(.34, 1.36, .64, 1); }
/* Dark unless Light is picked (same choice as the rest of the app). */
:root[data-theme="light"] { color-scheme: light; --bg: #f2f2f0; --glow: #ffffff; --card: #ffffff; --card-2: #fbfbfa; --line: #e3e3e0; --text: #121316; --muted: #6b6f78;
  --orders: #2b55d6; --attendance: #0d8a7d; --tasks: #7340d8; --on-accent: #ffffff; }
* { box-sizing: border-box; }
html, body { margin: 0; min-height: 100%; }
body { background: radial-gradient(1200px 600px at 50% -10%, var(--glow) 0%, var(--bg) 60%) fixed, var(--bg); color: var(--text);
  font: 15px/1.5 Inter, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; -webkit-font-smoothing: antialiased;
  padding: max(28px, env(safe-area-inset-top)) max(16px, env(safe-area-inset-right)) max(28px, env(safe-area-inset-bottom)) max(16px, env(safe-area-inset-left)); }
.wrap { max-width: 1120px; margin: 0 auto; display: grid; gap: 34px; }
.head { display: flex; align-items: center; gap: 16px; flex-wrap: wrap; }
.head img { width: 64px; height: 64px; border-radius: 50%; box-shadow: 0 0 0 1px var(--line); }
.head h1 { margin: 0; font-size: clamp(1.5rem, 4vw, 2.1rem); font-weight: 800; letter-spacing: -.02em; line-height: 1.1; }
.head p { margin: 2px 0 0; color: var(--muted); }
.who { margin-left: auto; font-size: .86rem; color: var(--muted); display: flex; gap: 10px; align-items: center; }
.who form { margin: 0; } .who button { background: none; border: 1px solid var(--line); color: var(--text); border-radius: 999px; padding: 6px 12px; font: inherit; cursor: pointer; }
.doors { display: grid; grid-template-columns: repeat(var(--n, 3), minmax(0, 1fr)); gap: 16px; }
@media (max-width: 860px) { .doors { grid-template-columns: 1fr; } .who { margin-left: 0; width: 100%; } }
.door { --c: var(--orders); position: relative; display: grid; grid-template-rows: auto 1fr auto; gap: 18px; min-height: 340px; padding: 26px;
  border-radius: 22px; background: linear-gradient(180deg, var(--card-2), var(--card)); border: 1px solid var(--line); color: var(--text); text-decoration: none;
  overflow: hidden; transition: transform 460ms var(--spring-bounce), border-color .2s ease; -webkit-tap-highlight-color: transparent; touch-action: manipulation; }
.door:active { transform: scale(.98); transition-duration: 90ms; transition-timing-function: ease-out; }
.door::after { content: ""; position: absolute; inset: auto -30% -55% -30%; height: 70%; background: radial-gradient(closest-side, color-mix(in srgb, var(--c) 30%, transparent), transparent); pointer-events: none; }
.door:hover, .door:focus-visible { transform: translateY(-3px); border-color: color-mix(in srgb, var(--c) 60%, var(--line)); outline: none; }
.door.attendance { --c: var(--attendance); } .door.tasks { --c: var(--tasks); }
.icon { width: 64px; height: 64px; border-radius: 18px; display: grid; place-items: center; background: color-mix(in srgb, var(--c) 16%, transparent); color: var(--c); }
.icon svg { width: 34px; height: 34px; }
.door h2 { margin: 0; font-size: clamp(1.9rem, 4vw, 2.5rem); font-weight: 800; letter-spacing: -.03em; line-height: 1; }
.door .sub { color: var(--c); font-weight: 700; font-size: .82rem; text-transform: uppercase; letter-spacing: .08em; margin: 0 0 10px; }
.door .desc { color: var(--muted); margin: 10px 0 0; max-width: 34ch; }
.go { display: flex; align-items: center; justify-content: space-between; gap: 10px; position: relative; z-index: 1; }
.open { text-decoration: none; display: inline-flex; align-items: center; gap: 8px; background: var(--c); color: var(--on-accent); font-weight: 700; padding: 11px 18px; border-radius: 999px; }
.more { display: flex; flex-wrap: wrap; gap: 6px 12px; position: relative; z-index: 1; }
.more a { color: var(--muted); font-size: .84rem; text-decoration: none; border-bottom: 1px solid var(--line); }
.more a:hover { color: var(--text); }
.foot { color: var(--muted); font-size: .8rem; text-align: center; display: grid; gap: 12px; justify-items: center; }
.theme-seg { display: inline-grid; grid-template-columns: repeat(3, auto); gap: 2px; padding: 3px; border-radius: 10px; background: var(--card); border: 1px solid var(--line); }
.theme-seg button { background: none; border: 0; color: var(--muted); font: 600 .8rem/1 Inter, system-ui, sans-serif; padding: 8px 12px; border-radius: 8px; cursor: pointer; transition: transform 420ms var(--spring-bounce); }
.theme-seg button:active { transform: scale(.94); transition-duration: 90ms; }
.theme-seg button[aria-pressed="true"] { background: var(--card-2); color: var(--text); box-shadow: 0 1px 3px rgba(0,0,0,.25); }
.theme-pick .menu-group { display: none; }
html.theme-anim, html.theme-anim * { transition: background-color .32s ease, color .32s ease, border-color .32s ease !important; }
@media (prefers-reduced-motion: reduce) { .door { transition: none; } }
</style>
</head>
<body>
<main class="wrap">
  <header class="head">
    <img src="<?= h(asset('assets/icons/icon-192.png')) ?>" alt="<?= h($company) ?>" width="64" height="64">
    <div><h1><?= h($company) ?></h1><p>Hello <?= h(explode(' ', trim($me['name'] ?: $me['username']))[0]) ?> — pick where you want to go</p></div>
    <div class="who">Signed in as <b><?= h($me['name'] ?: $me['username']) ?></b>
      <form method="post" action="<?= h(base_url('logout.php')) ?>"><?= csrf_field() ?><button>Log out</button></form></div>
  </header>

  <nav class="doors" aria-label="Apps" style="--n: <?= count($doors) ?>">
    <?php foreach ($doors as [$key, $title, $sub, $desc, $url, $more]): ?>
      <div class="door <?= $key ?>">
        <span class="icon" aria-hidden="true"><?php if ($key === 'orders'): ?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M8 3 3 6l2 4 3-1v12h8V9l3 1 2-4-5-3a4 4 0 0 1-8 0Z"/></svg>
          <?php elseif ($key === 'attendance'): ?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
          <?php else: ?><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3.5" width="16" height="17" rx="2.5"/><path d="m8 9 1.5 1.5L12 8M8 15l1.5 1.5L12 14M14 9.5h3M14 15.5h3"/></svg><?php endif; ?></span>
        <div><p class="sub"><?= h($sub) ?></p><h2><?= h($title) ?></h2><p class="desc"><?= h($desc) ?></p></div>
        <div class="go"><a class="open" href="<?= h($url) ?>" data-door="<?= $key ?>">Open <?= h($title) ?> →</a></div>
        <div class="more"><?php foreach ($more as [$ml, $mu]): ?><a href="<?= h($mu) ?>"><?= h($ml) ?></a><?php endforeach; ?></div>
      </div>
    <?php endforeach; ?>
  </nav>
  <div class="foot"><?= theme_picker() ?><span><?= h($company) ?> · on a shared computer, log out when you finish</span></div>
</main>
<script src="<?= h(asset('assets/app.js')) ?>" defer></script>
<script>
// The whole box opens its app (links inside it keep working on their own).
document.querySelectorAll('.door').forEach(function (d) {
  d.addEventListener('click', function (e) { if (!e.target.closest('a')) d.querySelector('.open').click(); });
  d.style.cursor = 'pointer';
});
</script>
</body>
</html>
