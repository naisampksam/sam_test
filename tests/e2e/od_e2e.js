// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
const { chromium } = require(process.env.PWPATH);
const { execSync } = require('child_process');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sql = s => execSync(`mysql --default-character-set=utf8mb4 -N ${process.env.DB_NAME || 'looma'} -e "${s}"`).toString().trim();
const U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
(async () => {
  const b = await chromium.launch(); const errs = [];
  const p = await (await b.newContext({ viewport: { width: 390, height: 900 } })).newPage();
  p.on('pageerror', e => errs.push(e.message));
  await p.goto(U + 'login.php'); await p.fill('[name=username]', 'admin'); await p.fill('[name=password]', 'admin12345'); await p.click('button'); await p.waitForLoadState();
  await p.goto(U + 'order.php?new=1');
  const ymd = d => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  const due2 = d => { d = new Date(d); let n = 2; while (n > 0) { d.setDate(d.getDate() + 1); if (d.getDay() !== 0) n--; } return ymd(d); };
  ok(await p.inputValue('[name=order_date]') === ymd(new Date()) && await p.inputValue('[name=due_date]') === due2(new Date()), 'defaults: today, +2 working days');
  await p.fill('[name=order_date]', '2026-10-09'); await p.dispatchEvent('[name=order_date]', 'change');
  ok(await p.inputValue('[name=due_date]') === '2026-10-12', 'Fri 9 Oct → dispatch Mon 12 (Sunday skipped): ' + await p.inputValue('[name=due_date]'));
  await p.fill('[name=order_date]', '2026-10-05'); await p.dispatchEvent('[name=order_date]', 'change');
  ok(await p.inputValue('[name=due_date]') === '2026-10-07', 'Mon 5 → Wed 7');
  await p.fill('[name=customer_id]', 'DATETEST');
  await p.evaluate(() => { const c = document.querySelector('#items .item-card'); c._setBlank({ gsm: '190 GSM', product: 'Regular Fit - Single Jersey', color: 'White', size: 'M' }); c.querySelector('select[data-cat=size]').dispatchEvent(new Event('change', { bubbles: true })); });
  await p.click('.sticky-actions .btn.primary');
  await p.waitForURL(/order\.php\?id=\d+/);
  const oid = new URL(p.url()).searchParams.get('id');
  const r = sql(`SELECT CONCAT_WS('|', DATE(created_at), due_date) FROM orders WHERE id=${oid}`);
  ok(r === '2026-10-05|2026-10-07', 'saved: ' + r);
  const bd = sql(`SELECT bill_date FROM bills WHERE order_id=${oid}`);
  ok(bd === '' || bd === '2026-10-05', 'bill dated with the order: ' + bd);
  // edit: move date, hand-set due kept
  await p.goto(U + `order.php?id=${oid}&edit=1`);
  ok(await p.inputValue('[name=order_date]') === '2026-10-05', 'edit shows order date');
  await p.fill('[name=due_date]', '2026-10-20');
  await p.fill('[name=order_date]', '2026-10-08'); await p.dispatchEvent('[name=order_date]', 'change');
  ok(await p.inputValue('[name=due_date]') === '2026-10-20', 'hand-set dispatch date is kept');
  await p.click('.sticky-actions .btn.primary'); await p.waitForURL(/order\.php\?id=\d+$/);
  ok(sql(`SELECT CONCAT_WS('|', DATE(created_at), due_date) FROM orders WHERE id=${oid}`) === '2026-10-08|2026-10-20', 'edit saved');
  // future date refused
  await p.goto(U + `order.php?id=${oid}&edit=1`);
  await p.evaluate(() => { const i = document.querySelector('[name=order_date]'); i.removeAttribute('max'); i.value = new Date(Date.now() + 60 * 864e5).toISOString().slice(0, 10); });
  await p.click('.sticky-actions .btn.primary'); await p.waitForLoadState();
  ok(/future/.test(await p.locator('.alert.err').innerText().catch(() => '')), 'future date refused');
  ok(!errs.length, 'no JS errors ' + errs.join('|'));
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
