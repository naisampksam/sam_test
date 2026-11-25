// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
// Partners sign up themselves: account waits for Looma's approval (can't log in, told why); admin approves (customer ID
// can be changed) or rejects; bots (hidden field) are refused; the team is told in the menu.
const { chromium } = require(process.env.PWPATH);
const { execSync } = require('child_process');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sql = s => execSync(`mysql --default-character-set=utf8mb4 -N ${process.env.DB_NAME || 'looma'} -e "${s.replace(/"/g, '\\"')}"`).toString().trim();
const U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
(async () => {
  const b = await chromium.launch(); const errs = [];
  const ctx = async (w = 390) => { const c = await b.newContext({ viewport: { width: w, height: 900 } }); const p = await c.newPage(); p.on('pageerror', e => errs.push(e.message)); return p; };
  const a = await ctx(1280);
  await a.goto(U + 'login.php'); await a.fill('[name=username]', 'admin'); await a.fill('[name=password]', 'admin12345'); await a.click('button.primary'); await a.waitForLoadState();
  await a.goto(U + 'admin/partners.php');
  for (const n of ['Sign Up Tees', 'Bad Bot Co', 'Nope Shirts']) {
    const id = sql(`SELECT IFNULL(GROUP_CONCAT(id), '') FROM partners WHERE name = '${n}'`);
    if (id) { sql(`DELETE FROM partner_users WHERE partner_id IN (${id})`); sql(`DELETE FROM partners WHERE id IN (${id})`); }
  }
  sql("DELETE FROM customers WHERE code IN ('SIGNUPTEES', 'SUT1')");
  const fill = async (p, o) => {
    await p.goto(U + 'partner/'); await p.click('a:has-text("Create a partner account")'); await p.waitForLoadState();
    for (const [k, v] of Object.entries(o)) await p.fill(`[name=${k}]`, v);
    await p.click('button:has-text("Create account")'); await p.waitForLoadState();
  };
  const base = { company: 'Sign Up Tees', name: 'Riya', phone: '9876512345', email: 'riya@signup.in', username: 'signuptees', password: 'signup1234', password2: 'signup1234', about: 'Shopify, 200 orders a month' };

  // 1. sign up: checks, then waiting for approval
  const t = await ctx();
  await fill(t, { ...base, password2: 'different1' });
  ok(/not the same/.test(await t.locator('.pt-note.err').innerText()), 'passwords must match');
  ok(await t.inputValue('[name=company]') === 'Sign Up Tees', 'what was typed stays');
  await fill(t, base);
  ok(/waiting for approval/.test(await t.locator('.pt-note').innerText()), 'signed up: “waiting for approval”');
  ok(sql("SELECT CONCAT_WS('|', code, active, approval, phone) FROM partners WHERE name = 'Sign Up Tees'") === 'SIGNUPTEES|0|pending|9876512345', 'partner saved switched off, pending, customer ID SIGNUPTEES');
  await t.goto(U + 'partner/'); await t.fill('[name=username]', 'signuptees'); await t.fill('[name=password]', 'signup1234'); await t.click('.pt-login button'); await t.waitForLoadState();
  ok(/waiting for Looma’s approval/.test(await t.locator('.pt-note.err').innerText()), 'login before approval: told it is waiting');
  // a bot fills the hidden field
  const bot = await ctx();
  await bot.goto(U + 'partner/register.php');
  for (const [k, v] of Object.entries({ ...base, company: 'Bad Bot Co', username: 'badbot1', email: 'b@b.in' })) await bot.fill(`[name=${k}]`, v);
  await bot.evaluate(() => { document.querySelector('[name=website]').value = 'http://spam'; });
  await bot.click('button:has-text("Create account")'); await bot.waitForLoadState();
  ok(sql("SELECT COUNT(*) FROM partners WHERE name = 'Bad Bot Co'") === '0', 'bot (hidden field filled) refused');

  // 2. admin: menu count, approve with a new customer ID
  await a.goto(U + 'admin/partners.php');
  ok(/Partners \(POD\) \(\d+ new\)/.test(await a.locator('body').textContent()), 'menu: “Partners (POD) (n new)”');
  const row = a.locator('.pm-wait .pm-row', { hasText: 'Sign Up Tees' });
  ok(/signuptees/.test(await row.innerText()) && /200 orders/.test(await row.innerText()), 'waiting list shows login, phone, email and what they wrote');
  await row.locator('[name=code]').fill('SUT1'); await row.locator('button:has-text("Approve")').click(); await a.waitForLoadState();
  ok(sql("SELECT CONCAT_WS('|', code, active, approval) FROM partners WHERE name = 'Sign Up Tees'") === 'SUT1|1|approved' && sql("SELECT COUNT(*) FROM customers WHERE code = 'SUT1'") === '1',
    'approved with customer ID SUT1 (also in the customer book)');
  ok(/#money/.test(a.url()), 'after approving: straight to their prices');
  await t.goto(U + 'partner/'); await t.fill('[name=username]', 'signuptees'); await t.fill('[name=password]', 'signup1234'); await t.click('.pt-login button'); await t.waitForLoadState();
  ok(/Sign Up Tees/.test(await t.locator('.pt-brand').innerText()), 'approved partner logs in');

  // 3. reject
  const r = await ctx();
  await fill(r, { ...base, company: 'Nope Shirts', username: 'nopeshirts', email: 'n@n.in', phone: '9876512399' });
  await a.goto(U + 'admin/partners.php');
  a.once('dialog', d => d.accept()); await a.locator('.pm-wait .pm-row', { hasText: 'Nope Shirts' }).locator('button:has-text("Reject")').click(); await a.waitForLoadState();
  await r.goto(U + 'partner/'); await r.fill('[name=username]', 'nopeshirts'); await r.fill('[name=password]', 'signup1234'); await r.click('.pt-login button'); await r.waitForLoadState();
  ok(/not approved/.test(await r.locator('.pt-note.err').innerText()), 'rejected sign-up: “not approved”');

  ok(!errs.length, 'no JS errors ' + errs.join('|'));
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
