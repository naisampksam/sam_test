// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
// ERP home (erp.*): log in first, then the boxes this person uses. Clock-in stays its own PIN page (no ERP login).
// An admin opens Attendance admin with no second password. The old orders.* address goes straight to the orders app.
const { chromium } = require(process.env.PWPATH);
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
const port = new URL(U).port || '80';
const ERP = `http://erp.looma.test:${port}/`;
(async () => {
  const b = await chromium.launch({ args: [`--host-resolver-rules=MAP erp.looma.test 127.0.0.1`] }); const errs = [];
  const page = async w => { const p = await (await b.newContext({ viewport: { width: w, height: 900 } })).newPage(); p.on('pageerror', e => errs.push(e.message)); return p; };
  const login = async (p, u, pw) => { await p.fill('[name=username]', u); await p.fill('[name=password]', pw); await p.click('button.primary'); await p.waitForLoadState(); };
  const doors = async p => (await p.locator('.door h2').allInnerTexts()).join(' | ');

  // admin
  const a = await page(1280);
  await a.goto(ERP);
  ok(/login\.php/.test(a.url()) && /ERP · staff login/.test(await a.locator('body').innerText()), 'erp asks to log in first');
  await login(a, 'admin', 'admin12345');
  ok(new URL(a.url()).pathname === '/' && await doors(a) === 'Orders | Tasks | Attendance', 'admin home: ' + await doors(a));
  await a.screenshot({ path: __dirname + '/shots/erp_home.png' });
  await a.click('[data-door=orders]'); await a.waitForLoadState();
  ok(/dashboard\.php/.test(a.url()), 'Orders opens the orders app');
  await a.goto(ERP); await a.locator('.door.tasks').click({ position: { x: 40, y: 140 } }); await a.waitForLoadState();
  ok(/tasks\.php/.test(a.url()) && await a.locator('.task-board').count() === 1, 'Tasks opens the Task Manager');
  ok(await a.locator('.tm-switch', { hasText: 'Attendance' }).count() === 1, 'Task Manager links to Attendance for the admin');
  await a.goto(ERP); await a.click('[data-door=attendance]'); await a.waitForLoadState();
  await a.waitForSelector('[data-tab=salary]:visible', { timeout: 8000 }).catch(() => {});
  ok(/\/attendance\/admin/.test(a.url()) && await a.locator('[data-tab=salary]:visible').count() === 1, 'admin opens Attendance admin with no second password');
  ok(await a.locator('a[href="/index.php"]').count() === 1, 'attendance admin links back to Orders & Tasks');
  await a.goto(ERP + 'dashboard.php');
  ok(/Attendance admin/.test(await a.locator('.topbar').innerHTML()), 'orders menu has Attendance admin for the admin');

  // production staff (rahul): Orders, My tasks, Clock in
  const r = await page(390);
  await r.goto(ERP); await login(r, 'rahul', 'pass1234');
  ok(await doors(r) === 'Orders | My tasks | Clock in', 'production staff home: ' + await doors(r));
  await r.screenshot({ path: __dirname + '/shots/erp_home_phone.png', fullPage: true });
  ok(await r.evaluate(() => document.documentElement.scrollWidth) <= 390, 'fits a phone');
  // their ERP login is not an attendance admin login
  const st = await r.evaluate(async () => (await fetch('/attendance/api/admin/state')).json());
  ok(st.loggedIn === false, 'staff are not attendance admins through the ERP');

  // creative staff (vedit): Tasks and Clock in, no Orders
  const v = await page(1280);
  await v.goto(ERP); await login(v, 'vedit', 'pass1234');
  ok(await doors(v) === 'Tasks | Clock in', 'creative staff home: ' + await doors(v));

  // clock-in page needs no ERP login (shared office computer: name + PIN)
  const k = await page(1280);
  await k.goto(ERP + 'attendance/'); await k.waitForLoadState();
  ok(!/login\.php/.test(k.url()) && /Clock in|No employees/i.test(await k.locator('body').innerText()), 'clock-in page opens without an ERP login');

  // the old address still goes straight to the orders app
  const o = await page(1280); await o.goto(U); await o.waitForLoadState();
  await login(o, 'admin', 'admin12345');
  ok(/dashboard\.php/.test(o.url()) && await o.locator('.door').count() === 0, 'orders address: straight into the orders app');
  ok(!errs.length, 'no JS errors ' + errs.join('|'));
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
