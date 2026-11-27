// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
// “Partners (POD)” permission: only the admin gives it (Staff → edit → tick). A staff member with it manages every
// partner like the admin: partner details, logins, prices & payment, products (add / edit / delete), payment QR /
// WhatsApp, partner orders (also “Accept anyway”), money, stock. Staff without it can't open those pages.
// Needs partner_e2e first (partner PTEST1).
const { chromium } = require(process.env.PWPATH);
const { execSync } = require('child_process');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sql = s => execSync(`mysql --default-character-set=utf8mb4 -N ${process.env.DB_NAME || 'looma'} -e "${s.replace(/"/g, '\\"')}"`).toString().trim();
const U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
const IMG = __dirname + '/shots/pperm-mock.png';
(async () => {
  execSync(`php -r '$i = imagecreatetruecolor(1600, 1600); imagefill($i, 0, 0, imagecolorallocate($i, 20, 120, 200)); imagepng($i, "${IMG}");'`);
  const hash = execSync(`php -r 'echo password_hash("pmgr12345", PASSWORD_DEFAULT);'`).toString().replace(/\$/g, '\\$');
  sql("DELETE FROM users WHERE username IN ('pmgr', 'nopart')");
  sql(`INSERT INTO users (username, name, password_hash, role, active, perms, caps, created_at) VALUES ('pmgr', 'Partner Manager', '${hash}', 'staff', 1, '{}', '{}', NOW()), ('nopart', 'No Partners', '${hash}', 'staff', 1, '{}', '{"create":1}', NOW())`);
  const pid = sql("SELECT id FROM partners WHERE code = 'PTEST1'");
  sql("UPDATE partners SET active = 1 WHERE code = 'PTEST1'");
  sql("UPDATE designs SET active = 0 WHERE name LIKE 'Staff Made Tee%'");
  const b = await chromium.launch(); const errs = [];
  const ctx = async (w = 1280) => { const c = await b.newContext({ viewport: { width: w, height: 900 } }); const p = await c.newPage(); p.on('pageerror', e => errs.push(e.message)); return p; };
  const login = async (p, u, pw) => { await p.goto(U + 'login.php'); await p.fill('[name=username]', u); await p.fill('[name=password]', pw); await p.click('button.primary'); await p.waitForLoadState(); };

  // 1. only the admin gives it: Staff → edit → “Partners (POD)”
  const a = await ctx(); await login(a, 'admin', 'admin12345');
  const uid = sql("SELECT id FROM users WHERE username = 'pmgr'");
  await a.goto(U + 'admin/user_edit.php?id=' + uid);
  const box = a.locator('label.check:has-text("Partners (POD)") input');
  ok(await box.count() === 1, 'Staff edit page has the “Partners (POD)” permission');
  await box.check(); await a.click('form button.primary >> nth=0'); await a.waitForLoadState();
  ok(/"partners":1/.test(sql(`SELECT caps FROM users WHERE id = ${uid}`)), 'admin gives “Partners (POD)” to the staff member');

  // 2. staff without it: no access
  const n = await ctx(); await login(n, 'nopart', 'pmgr12345');
  ok((await n.goto(U + 'admin/partners.php')).status() === 403, 'staff without the permission: Partners page refused (403)');
  ok((await n.goto(U + 'partner_money.php')).status() === 403, '…and Partner money refused');
  await n.goto(U + 'orders.php');
  ok(!/Partners \(POD\)/.test(await n.locator('body').textContent()), '…and no “Partners (POD)” in their menu');

  // 3. staff with it: menu + every partner page
  const m = await ctx(); await login(m, 'pmgr', 'pmgr12345');
  await m.goto(U + 'orders.php');
  const menu = await m.locator('body').textContent();
  ok(/Partners \(POD\)/.test(menu) && /Partner orders/.test(menu) && /Partner money/.test(menu) && /Partner stock/.test(menu), 'menu: Partners (POD), Partner orders, Partner money, Partner stock');
  for (const pg of ['admin/partners.php', 'admin/partners.php?id=' + pid, 'partner_orders.php', 'partner_money.php', 'partner_stock.php']) {
    const r = await m.goto(U + pg); ok(r.status() === 200 && !/Not allowed/.test(await m.locator('body').innerText()), 'opens ' + pg);
  }
  ok((await m.goto(U + 'admin/users.php')).status() === 403, 'but not the admin-only Staff page');

  // 4. partner details, prices & payment
  await m.goto(U + 'admin/partners.php?id=' + pid);
  await m.fill('[name=email]', 'crew@example.com'); await Promise.all([m.waitForNavigation(), m.click('button:has-text("Save partner")')]);
  ok(sql(`SELECT email FROM partners WHERE id = ${pid}`) === 'crew@example.com', 'edits partner details');
  await m.goto(U + 'admin/partners.php?id=' + pid);
  await m.fill('[name=print]', '111'); await m.fill('[name=ship_first_kl]', '60'); await Promise.all([m.waitForNavigation(), m.click('button:has-text("Save prices")')]);
  ok(/"print":111/.test(sql(`SELECT settings FROM partners WHERE id = ${pid}`)) && /"ship_first_kl":60/.test(sql(`SELECT settings FROM partners WHERE id = ${pid}`)), 'edits prices & payment (printing ₹111, Kerala shipping ₹60)');

  // 5. products: add (T-shirt, colour, photo), edit, delete
  await m.goto(U + 'admin/partners.php?id=' + pid + '#products');
  await Promise.all([m.waitForNavigation(), m.click('#products a:has-text("Add product")')]);
  const tsh = await m.locator('[data-pm-ts] option').nth(1).getAttribute('value');
  await m.fill('#products [name=name]', 'Staff Made Tee'); await m.selectOption('[data-pm-ts]', tsh);
  await m.selectOption('[data-pm-col]', { index: 1 });
  await m.setInputFiles('#products [name="photos[]"]', IMG); await m.fill('#products [name=print_link]', 'https://drive.google.com/file/d/staff/view');
  await Promise.all([m.waitForNavigation(), m.click('#products button:has-text("Save product")')]);
  const did = sql("SELECT d.id FROM designs d JOIN design_customers dc ON dc.design_id = d.id WHERE dc.customer_code = 'PTEST1' AND d.name = 'Staff Made Tee' AND d.active = 1");
  ok(did && sql(`SELECT CONCAT(gsm, '|', product) FROM designs WHERE id = ${did}`) === tsh && sql(`SELECT COUNT(*) FROM design_images WHERE design_id = ${did}`) === '1'
    && /Looma · Partner Manager/.test(sql(`SELECT JSON_UNQUOTE(JSON_EXTRACT(extra, '$.partner_by')) FROM designs WHERE id = ${did}`)), 'adds a product for the partner (T-shirt, colour, photo), noted as made by Looma staff');
  ok(await m.locator(`.pm-prod:has-text("Staff Made Tee") img`).count() === 1, 'products list shows it with its picture');
  await m.goto(new URL(await m.locator('.pm-prod:has-text("Staff Made Tee") a:has-text("Edit")').getAttribute('href'), m.url()).href);
  await m.fill('#products [name=name]', 'Staff Made Tee 2'); await Promise.all([m.waitForNavigation(), m.click('#products button:has-text("Save product")')]);
  ok(sql(`SELECT name FROM designs WHERE id = ${did}`) === 'Staff Made Tee 2' && /staff\/view/.test(sql(`SELECT JSON_UNQUOTE(JSON_EXTRACT(extra, '$.print_link')) FROM designs WHERE id = ${did}`)), 'edits it (name), print file link kept');
  // the partner sees it in their portal
  const t = await ctx(390); await t.goto(U + 'partner/'); await t.fill('[name=username]', 'tcrew'); await t.fill('[name=password]', 'crew12345'); await t.click('.pt-login button'); await t.waitForLoadState();
  await t.goto(U + 'partner/designs.php');
  ok(await t.locator('.pt-dwrap:has-text("Staff Made Tee 2")').count() === 1, 'the partner sees the product in their Products');
  m.once('dialog', d => d.accept());
  await Promise.all([m.waitForNavigation(), m.locator('.pm-prod:has-text("Staff Made Tee 2") button:has-text("Delete")').click()]);
  ok(sql(`SELECT active FROM designs WHERE id = ${did}`) === '0', 'deletes it');

  // 6. payment QR / WhatsApp, logins
  await m.goto(U + 'admin/partners.php');
  await m.fill('#paysetup [name=partner_whatsapp]', '9000012345'); await Promise.all([m.waitForNavigation(), m.click('#paysetup button.primary')]);
  ok(sql("SELECT v FROM settings WHERE k = 'partner_whatsapp'") === '9000012345', 'sets the Looma WhatsApp for partners (Partner payments)');
  await m.goto(U + 'admin/settings.php');
  ok(/Only admins/.test(await m.locator('body').innerText()), 'Settings stays admin only');
  await m.goto(U + 'admin/partners.php?id=' + pid + '#logins');
  sql(`DELETE FROM partner_users WHERE username = 'crew2'`);
  await m.fill('.pt-newlogin [name=username]', 'crew2'); await m.fill('.pt-newlogin [name=password]', 'crew2pass12'); await Promise.all([m.waitForNavigation(), m.click('.pt-newlogin button')]);
  ok(sql("SELECT COUNT(*) FROM partner_users WHERE username = 'crew2'") === '1', 'adds a login for the partner');

  // 7. phone: the partner page fits
  const mp = await ctx(390); await login(mp, 'pmgr', 'pmgr12345');
  await mp.goto(U + 'admin/partners.php?id=' + pid);
  ok(await mp.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1), 'partner page fits a phone');
  ok(!errs.length, 'no page errors ' + errs.join(' | '));
  sql("DELETE FROM partner_users WHERE username = 'crew2'");
  await b.close();
  console.log(fails ? `${fails} FAILED` : 'ALL PASSED');
  process.exit(fails ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
