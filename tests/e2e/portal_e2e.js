// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
// ERP home: erp.* shows three boxes (Orders, Attendance, Tasks) that open each app; the old orders.* address goes straight in.
const { chromium } = require(process.env.PWPATH);
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
const port = new URL(U).port || '80';
const ERP = `http://erp.looma.test:${port}/`;
(async () => {
  const b = await chromium.launch({ args: [`--host-resolver-rules=MAP erp.looma.test 127.0.0.1`] }); const errs = [];
  const page = async w => { const p = await (await b.newContext({ viewport: { width: w, height: 900 } })).newPage(); p.on('pageerror', e => errs.push(e.message)); return p; };
  const p = await page(1280);
  await p.goto(ERP);
  ok(await p.locator('.door').count() === 3 && /Orders[\s\S]*Attendance[\s\S]*Tasks/.test(await p.locator('.doors').innerText()), 'erp home: Orders, Attendance, Tasks');
  await p.screenshot({ path: __dirname + '/shots/erp_home.png' });
  // Orders → login → straight into the orders app
  await p.click('[data-door=orders]'); await p.waitForLoadState();
  ok(/login\.php/.test(p.url()), 'Orders asks to log in');
  await p.fill('[name=username]', 'admin'); await p.fill('[name=password]', 'admin12345'); await p.click('button'); await p.waitForLoadState();
  ok(/dashboard\.php/.test(p.url()), 'after login: the orders app (' + p.url() + ')');
  await p.goto(ERP);
  ok(/Signed in as/.test(await p.locator('.head').innerText()), 'home shows who is signed in');
  await p.locator('.door.tasks').click({ position: { x: 40, y: 140 } }); await p.waitForLoadState();
  ok(/tasks\.php/.test(p.url()) && await p.locator('.task-board').count() === 1, 'the Tasks box opens the Task Manager');
  await p.goto(ERP); await p.click('[data-door=attendance]'); await p.waitForLoadState();
  ok(/\/attendance\/$/.test(p.url()) && /Attendance/i.test(await p.title()), 'the Attendance box opens clock-in');
  await p.goto(ERP); await p.click('.door.attendance .more a'); await p.waitForLoadState();
  ok(/\/attendance\/admin/.test(p.url()), 'attendance admin link');
  // phone
  const m = await page(390); await m.goto(ERP); await m.screenshot({ path: __dirname + '/shots/erp_home_phone.png', fullPage: true });
  ok(await m.evaluate(() => document.documentElement.scrollWidth) <= 390, 'fits a phone');
  // the old address still goes straight to the orders app
  const o = await page(1280); await o.goto(U); await o.waitForLoadState();
  ok(/login\.php/.test(o.url()) && await o.locator('.door').count() === 0, 'orders address: no home screen, straight to login');
  ok(!errs.length, 'no JS errors ' + errs.join('|'));
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
