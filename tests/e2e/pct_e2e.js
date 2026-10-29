// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
const { chromium } = require(process.env.PWPATH);
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
(async () => {
  const b = await chromium.launch(); const errs = [];
  const login = async (u, pw, w) => { const p = await (await b.newContext({ viewport: { width: w, height: 800 } })).newPage(); p.on('pageerror', e => errs.push(e.message));
    await p.goto(U + 'login.php'); await p.fill('[name=username]', u); await p.fill('[name=password]', pw); await p.click('button'); await p.waitForLoadState(); return p; };
  const v = await login('vedit', 'pass1234', 390);
  for (const pg of ['tasks.php', 'performance.php', 'account.php']) {
    await v.goto(U + pg);
    const t = await v.locator('.tm-bar .task-pct').innerText();
    ok(/33%/.test(t) && /⚠ 1/.test(t), pg + ': badge "' + t.replace(/\s+/g, ' ') + '"');
  }
  ok(/1 of 3 tasks done this month · 1 late/.test(await v.locator('.task-pct').getAttribute('title')), 'tooltip explains it');
  await v.locator('.tm-bar').screenshot({ path: __dirname + '/shots/pct_phone.png' });
  await v.click('.task-pct'); await v.waitForLoadState();
  ok(/performance\.php/.test(v.url()), 'tap opens Your performance');
  const a = await login('admin', 'admin12345', 1280);
  for (const pg of ['dashboard.php', 'orders.php', 'bills.php', 'stock.php']) {
    await a.goto(U + pg);
    ok(await a.locator('.topbar .task-pct').count() === 1, pg + ': badge shown (' + (await a.locator('.topbar .task-pct').innerText()).replace(/\s+/g, ' ') + ')');
  }
  await a.locator('.topbar').screenshot({ path: __dirname + '/shots/pct_desk.png' });
  const n = await login('rahul', 'pass1234', 390).catch(() => null);
  ok(!errs.length, 'no JS errors ' + errs.join('|'));
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
