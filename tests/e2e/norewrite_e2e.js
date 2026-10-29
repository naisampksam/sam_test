// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
// A host where the .htaccess rewrite rules do nothing (the live erp site): Attendance opens from every link,
// and "Salary till a date" fills the sales from the orders app and calculates the incentive on them.
const { chromium } = require(process.env.PWPATH);
const { spawn } = require('child_process');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const ROOT = __dirname + '/../..', PORT = 8093, U = `http://127.0.0.1:${PORT}/`;
(async () => {
  const srv = spawn('php', ['-S', '127.0.0.1:' + PORT, '-t', ROOT, __dirname + '/norewrite_router.php'], { stdio: 'ignore', env: { ...process.env } });
  await new Promise(r => setTimeout(r, 800));
  const b = await chromium.launch(); const errs = [], bad = [];
  const p = await (await b.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  p.on('pageerror', e => errs.push(e.message)); p.on('response', r => { if (r.status() >= 400 && !/\/api\//.test(r.url())) bad.push(r.status() + ' ' + r.url()); });
  await p.goto(U + 'login.php'); await p.fill('[name=username]', 'admin'); await p.fill('[name=password]', 'admin12345'); await p.click('button.primary'); await p.waitForLoadState();
  await p.goto(U + 'tasks.php'); await p.click('a.tm-switch:has-text("Attendance")'); await p.waitForLoadState('networkidle');
  ok(await p.locator('[data-tab=salary]').count() === 1, 'Attendance admin opens from the Task Manager: ' + p.url());
  await p.goto(U + 'attendance/admin'); await p.waitForLoadState('networkidle');
  ok(/page=admin/.test(p.url()) && await p.locator('[data-tab=salary]').count() === 1, 'the old /attendance/admin address still works');
  await p.click('[data-tab=salary]'); await p.waitForSelector('#till-form'); await p.waitForTimeout(1200);
  const sales = () => p.inputValue('#till-form [name=sales]');
  ok(Number(await sales()) > 0, 'sales till the shown date are filled straight away: ' + await sales());
  await p.fill('#till-form [name=until]', '2026-10-10'); await p.dispatchEvent('#till-form [name=until]', 'change'); await p.waitForTimeout(1200);
  const s10 = Number(await sales());
  ok(/1–10 October/.test(await p.locator('[data-until-note]').innerText()), 'picking a date fills its sales from the orders app: ' + s10);
  const stat = async label => { const t = (await p.locator('body').innerText()).replace(/\s+/g, ' '); const m = t.match(new RegExp(label + ' ₹ ?([\\d,.]+)', 'i')); return m ? Number(m[1].replace(/,/g, '')) : NaN; };
  await p.click('#till-form button.primary'); await p.waitForTimeout(1500);
  ok(/Calculated till 10 October/.test(await p.locator('body').innerText()), 'Calculate till date works');
  ok(await stat('Sales till date') === s10 && Math.abs(await stat('Incentive pool \\(1% of sales\\)') - s10 / 100) < 0.01, 'incentive pool is 1% of those sales: ' + await stat('Incentive pool \\(1% of sales\\)'));
  await p.fill('#till-form [name=sales]', '10000'); await p.click('#till-form button.primary'); await p.waitForTimeout(1500);
  ok(await stat('Sales till date') === 10000 && await stat('Incentive pool \\(1% of sales\\)') === 100, 'a typed figure is used instead');
  await p.click('[data-full-month]'); await p.waitForTimeout(1000);
  ok(!/Calculated till/.test(await p.locator('body').innerText()), 'back to the full month');
  ok(!errs.length && !bad.length, 'no browser errors or missing pages ' + errs.concat(bad).join(' | '));
  await b.close(); srv.kill(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
