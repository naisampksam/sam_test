// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
// Staff are added in one place (Staff page): the login and, in its own section, the Attendance details.
// Also the three links the owner confirmed: Nadeem Anshin ↔ Nadeem Anshin, Fayis ↔ Fayiz, Vyshnav ↔ Vyshnav.
const { chromium } = require(process.env.PWPATH);
const { execSync } = require('child_process');
const fs = require('fs');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sql = s => execSync(`mysql --default-character-set=utf8mb4 -N ${process.env.DB_NAME || 'looma'} -e "${s}"`).toString().trim();
const U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
const DATA = __dirname + '/../../attendance/data/db.php';
const att = () => JSON.parse(fs.readFileSync(DATA, 'utf8').replace(/^<\?php exit; \?>\n/, ''));
const NEW = ['nadeem', 'fayiz', 'vyshnav', 'tjoiner', 'tbadpin'];
(async () => {
  sql(`DELETE FROM users WHERE username IN ('${NEW.join("','")}'); DELETE FROM settings WHERE k='staff_links'`);
  const b = await chromium.launch(); const errs = [];
  const p = await (await b.newContext({ viewport: { width: 1280, height: 900 } })).newPage(); p.on('pageerror', e => errs.push(e.message));
  await p.goto(U + 'login.php'); await p.fill('[name=username]', 'admin'); await p.fill('[name=password]', 'admin12345'); await p.click('button.primary'); await p.waitForLoadState();
  const add = async (name, user, attDetails) => {
    await p.goto(U + 'admin/users.php');
    await p.fill('#add [name=name]', name); await p.fill('#add [name=username]', user); await p.fill('#add [name=password]', 'pass1234');
    if (!attDetails) await p.uncheck('#add [name=add_attendance]');
    else for (const [k, v] of Object.entries(attDetails)) await p.fill(`#add [name="att[${k}]"]`, v);
    await p.click('#add button.primary'); await p.waitForLoadState();
  };
  // the three people already in Attendance get accounts (without adding them again)
  const before = att().employees.length;
  await add('Nadeem Anshin', 'nadeem'); await add('Fayiz', 'fayiz'); await add('Vyshnav', 'vyshnav');
  ok(att().employees.length === before, 'existing attendance people are not added twice');
  await p.goto(U + 'admin/staff_links.php');
  const row = name => p.locator('tbody tr', { hasText: name }).first().innerText();
  ok(/Nadeem Anshin[\s\S]*Nadeem Anshin[\s\S]*confirmed by you/i.test(await row('@nadeem')), 'Nadeem Anshin ↔ Nadeem Anshin');
  ok(/Fayiz[\s\S]*Fayis[\s\S]*confirmed by you/i.test(await row('@fayiz')), 'Fayiz ↔ Fayis (confirmed, no “check”)');
  ok(/Vyshnav[\s\S]*Vyshnav[\s\S]*confirmed by you/i.test(await row('@vyshnav')), 'Vyshnav ↔ Vyshnav');
  await p.screenshot({ path: __dirname + '/shots/staff_links.png', fullPage: true });
  // a new person: login and attendance together
  await add('Test Joiner', 'tjoiner', { position: 'Printer', basicSalary: '15000', phone: '9876543210', pin: '4321' });
  ok(/added to Attendance/.test(await p.locator('.alert').first().innerText()), 'new staff: account created and added to Attendance');
  const e = att().employees.find(x => x.name === 'Test Joiner');
  ok(e && e.position === 'Printer' && e.basicSalary === 15000 && e.phone === '9876543210' && !!e.pinHash && e.joinedOn, 'attendance has their details and PIN: ' + JSON.stringify(e && { position: e.position, basicSalary: e.basicSalary, pin: !!e.pinHash }));
  const tid = sql("SELECT id FROM users WHERE username='tjoiner'");
  ok(JSON.parse(sql("SELECT v FROM settings WHERE k='staff_links'"))[tid] === e.id, 'and linked to the new account');
  // edit their attendance details from the Staff page
  await p.goto(U + 'admin/user_edit.php?id=' + tid);
  ok(/Linked to\s+Test Joiner/.test(await p.locator('#attendance').innerText()), 'edit page shows the linked attendance person');
  await p.fill('[name="att[basicSalary]"]', '16000'); await p.uncheck('[name="att[incentive]"]');
  await p.click('#permForm button.primary >> nth=-1'); await p.waitForLoadState();
  const e2 = att().employees.find(x => x.name === 'Test Joiner');
  ok(e2.basicSalary === 16000 && e2.incentive === false && !!e2.pinHash, 'salary and incentive changed in Attendance, PIN kept');
  await p.screenshot({ path: __dirname + '/shots/staff_attendance.png', fullPage: true });
  // a wrong PIN: nothing is created
  await add('Test Badpin', 'tbadpin', { pin: '12' });
  const blocked = await p.locator('#add [name="att[pin]"]').evaluate(el => !el.checkValidity()).catch(() => false);
  ok((blocked || /PIN must be 4–6 digits/.test(await p.locator('#add').innerText())) && sql("SELECT COUNT(*) FROM users WHERE username='tbadpin'") === '0', 'a wrong PIN stops it before anything is created');
  // and if the browser check is bypassed, the server refuses it too
  await p.goto(U + 'admin/users.php');
  await p.fill('#add [name=name]', 'Test Badpin'); await p.fill('#add [name=username]', 'tbadpin'); await p.fill('#add [name=password]', 'pass1234');
  await p.evaluate(() => { const i = document.querySelector('#add [name="att[pin]"]'); i.removeAttribute('pattern'); i.value = '12'; });
  await p.click('#add button.primary'); await p.waitForLoadState();
  ok(/PIN must be 4–6 digits/.test(await p.locator('#add').innerText()) && sql("SELECT COUNT(*) FROM users WHERE username='tbadpin'") === '0', 'the server refuses a wrong PIN too');
  // Attendance admin: adding is on the Staff page
  await p.goto(U + 'attendance/admin'); await p.click('[data-tab=employees]'); await p.waitForSelector('[data-add-staff]');
  await p.click('[data-add-staff]'); await p.waitForLoadState();
  ok(/admin\/users\.php/.test(p.url()), 'Attendance “Add staff” opens the Staff page');
  // clean up
  await p.goto(U + 'attendance/admin');
  await p.evaluate(async id => fetch('/attendance/api/admin/employees/' + id, { method: 'DELETE' }), e.id);
  ok(!att().employees.some(x => x.name === 'Test Joiner'), 'test employee removed');
  sql(`DELETE FROM users WHERE username IN ('${NEW.join("','")}'); DELETE FROM settings WHERE k='staff_links'`);
  ok(!errs.length, 'no JS errors ' + errs.join('|'));
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
