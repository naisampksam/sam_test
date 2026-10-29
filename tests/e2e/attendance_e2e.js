// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
// Attendance (/attendance, inside the orders app): the salary page takes the month's total sales from the orders app
// (invoices before GST and shipping) by itself; the admin can type a different figure and go back to the orders total.
const { chromium } = require(process.env.PWPATH);
const { execSync } = require('child_process');
const fs = require('fs');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sql = s => execSync(`mysql --default-character-set=utf8mb4 -N ${process.env.DB_NAME || 'looma'} -e "${s}"`).toString().trim();
const U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
const DATA = __dirname + '/../../attendance/data';
const PASS = 'attend123';
(async () => {
  const month = new Date().toISOString().slice(0, 7);
  const sales = +sql(`SELECT IFNULL(SUM(taxable),0) FROM bills WHERE status='final' AND converted_to IS NULL AND bill_date >= '${month}-01' AND bill_date <= LAST_DAY('${month}-01')`);
  const b = await chromium.launch(); const errs = [];
  const p = await (await b.newContext({ viewport: { width: 1280, height: 900 } })).newPage();
  p.on('pageerror', e => errs.push(e.message));
  await p.goto(U + 'attendance/admin'); await p.waitForSelector('#auth:not(.hidden)');
  // first run: choose the admin password (with the setup code from the data folder); later runs: log in
  if (await p.locator('[data-confirm]:not(.hidden)').count()) {
    const code = (fs.readFileSync(DATA + '/setup-code.php', 'utf8').match(/[A-Z0-9]{4,}(-[A-Z0-9]{4,})*/) || [''])[0];
    await p.fill('[name=password]', PASS); await p.fill('[name=confirm]', PASS);
    if (await p.locator('[data-setup-code]:not(.hidden)').count()) await p.fill('[name=setupCode]', code);
  } else await p.fill('[name=password]', PASS);
  await p.click('#auth button[type=submit], #auth .primary');
  await p.waitForSelector('[data-tab=salary]:visible');
  // start the month from the orders figure
  await p.evaluate(m => fetch(`api/admin/months/${m}`, { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ totalSales: null }) }), month);
  await p.click('[data-tab=salary]'); await p.waitForSelector('#month-form');
  ok(+(await p.inputValue('[name=totalSales]')) === sales && sales > 0, `total sales filled from the orders app: ${await p.inputValue('[name=totalSales]')} = ${sales}`);
  ok(/From the orders app/.test(await p.locator('[data-sales-note]').innerText()), 'says it comes from the orders app');
  const stat = await p.locator('.stat', { hasText: 'Total sales' }).innerText();
  ok(stat.replace(/[^\d.]/g, '').startsWith(String(Math.round(sales)).slice(0, 3)), 'salary uses it: ' + stat.replace(/\s+/g, ' '));
  // the admin types a different figure
  await p.fill('[name=totalSales]', '123456'); await p.click('#month-form button.primary'); await p.waitForTimeout(800);
  ok(+(await p.inputValue('[name=totalSales]')) === 123456 && /Typed by you/.test(await p.locator('[data-sales-note]').innerText()), 'typed figure is kept and marked');
  await p.reload(); await p.click('[data-tab=salary]'); await p.waitForSelector('#month-form');
  ok(+(await p.inputValue('[name=totalSales]')) === 123456, 'typed figure stays after reload');
  // working days change does not undo the typed sales
  await p.fill('[name=workingDays]', '25'); await p.click('#month-form button.primary'); await p.waitForTimeout(800);
  ok(+(await p.inputValue('[name=totalSales]')) === 123456, 'changing working days keeps the typed sales');
  await p.screenshot({ path: __dirname + '/shots/attendance_sales.png' });
  // back to the orders figure
  await p.click('[data-use-orders]'); await p.waitForTimeout(800);
  ok(+(await p.inputValue('[name=totalSales]')) === sales && /From the orders app/.test(await p.locator('[data-sales-note]').innerText()), 'Use orders total goes back to the orders figure');
  await p.evaluate(m => fetch(`api/admin/months/${m}`, { method: 'PUT', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ workingDays: null }) }), month);

  // ---- the calculation, checked by hand (two staff who don't clock in: they count as working their hours)
  const api = (method, url, body) => p.evaluate(async ([m, u, b]) => { const r = await fetch(u, { method: m, headers: { 'Content-Type': 'application/json' }, body: b ? JSON.stringify(b) : undefined }); return r.json(); }, [method, url, body]);
  const made = [];
  for (const [name, sal] of [['Calc Test A', 30000], ['Calc Test B', 18000]]) {
    const r = await api('POST', 'api/admin/employees', { name, basicSalary: sal, attendance: false, incentive: true, joinedOn: month + '-01' });
    made.push(r.id || (r.employee && r.employee.id));
  }
  ok(made.every(Boolean), 'two test staff added');
  const near = (x, y) => Math.abs(x - y) < 0.06;
  const check = (d, label) => {
    const rows = d.rows.filter(r => made.includes(r.id));
    ok(near(d.pool, Math.round(d.totalSales * d.incentivePercent) / 100), `${label}: pool = ${d.incentivePercent}% of ${d.totalSales} = ${d.pool}`);
    ok(near(d.rows.reduce((t, r) => t + r.totalIncentive, 0), d.pool - d.undistributed), `${label}: incentives add up to the pool`);
    ok(d.rows.every(r => near(r.netPay, r.salaryAfterLeave + r.totalIncentive) && near(r.leaveDeduction, Math.min(r.basicSalary, r.basicSalary / d.workingDays * r.leaveDays))), `${label}: every net pay = salary after leave + incentive; leave cut = basic ÷ working days × leave days`);
    return rows;
  };
  const full = await api('GET', `api/admin/salary?month=${month}`);
  ok(near(full.totalSales, sales), 'full month uses the orders sales');
  const fr = check(full, 'full month');
  ok(fr.length === 2 && fr.every(r => r.leaveDays === 0 && near(r.salaryAfterLeave, r.basicSalary)), 'staff without clock-in: full salary');
  // ---- till a date: sales from the 1st till that date, and salary only till then
  const till = month + '-15';
  const tillSales = +sql(`SELECT IFNULL(SUM(taxable),0) FROM bills WHERE status='final' AND converted_to IS NULL AND bill_date BETWEEN '${month}-01' AND '${till}'`);
  await p.reload(); await p.click('[data-tab=salary]'); await p.waitForSelector('#till-form');
  await p.fill('#till-form [name=until]', till); await p.locator('#till-form [name=until]').dispatchEvent('change');
  ok(await p.inputValue('#till-form [name=sales]') === '', 'picking a date clears the old sales figure');
  await p.click('#till-form button.primary'); await p.waitForTimeout(800);
  ok(near(+(await p.inputValue('#till-form [name=sales]')), tillSales) && /From the orders app: 1–15/.test(await p.locator('[data-until-note]').innerText()), `till ${till}: sales from orders = ${await p.inputValue('#till-form [name=sales]')} (counted ${tillSales})`);
  const td = await api('GET', `api/admin/salary?month=${month}&until=${till}`);
  ok(near(td.totalSales, tillSales), 'till-date payslips use the sales till that date');
  const tr = check(td, 'till date');
  ok(tr.every(r => near(r.salaryAfterLeave, r.basicSalary - r.basicSalary / td.workingDays * td.remainingDays)), `salary only till then (${td.remainingDays} working days after it not paid)`);
  // a typed figure for the till date is used
  await p.fill('#till-form [name=sales]', '50000'); await p.click('#till-form button.primary'); await p.waitForTimeout(800);
  ok(+(await p.inputValue('#till-form [name=sales]')) === 50000 && /Typed by you/.test(await p.locator('[data-until-note]').innerText()), 'a typed till-date sales figure is used');
  const typed = await api('GET', `api/admin/salary?month=${month}&until=${till}&sales=50000`);
  ok(near(typed.pool, 500 * typed.incentivePercent), 'pool from the typed figure');
  await p.screenshot({ path: __dirname + '/shots/attendance_till.png', fullPage: true });
  for (const id of made) await api('DELETE', `api/admin/employees/${id}`);
  ok(!errs.length, 'no JS errors ' + errs.join('|'));
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
