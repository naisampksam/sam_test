// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
// Every partner portal page at phone, tablet and computer widths, light and dark: opens (200), no PHP warning,
// no JS / console error, nothing wider than the screen, the bottom tab bar on phones and the top tabs on computers.
// Also: "My own T-shirt" never shows unless the admin switched it on for that partner.
const { chromium } = require(process.env.PWPATH);
const { execSync } = require('child_process');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sql = s => execSync(`mysql --default-character-set=utf8mb4 -N ${process.env.DB_NAME || 'looma'} -e "${s.replace(/["$`\\]/g, c => '\\' + c)}"`).toString().trim();
const U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
const ROOT = __dirname + '/../..';
(async () => {
  // a partner with a design and an order (set up straight in the test database)
  execSync(`curl -s -o /dev/null ${U}login.php`);
  const b = await chromium.launch();
  const adm = await (await b.newContext()).newPage();
  await adm.goto(U + 'login.php'); await adm.fill('[name=username]', 'admin'); await adm.fill('[name=password]', 'admin12345'); await adm.click('button.primary'); await adm.waitForLoadState();
  await adm.goto(U + 'partner_orders.php'); // tables up to date
  const old = sql("SELECT IFNULL(GROUP_CONCAT(id), '') FROM partners WHERE code = 'RESP'");
  if (old) { for (const t of ['partner_orders', 'partner_users', 'partner_stock']) sql(`DELETE FROM ${t} WHERE partner_id IN (${old})`); sql(`DELETE FROM partners WHERE id IN (${old})`); }
  sql("INSERT INTO partners (name, code, prefix, active, approval, created_at) VALUES ('Very Long Brand Name Clothing Company', 'RESP', 'RS', 1, 'approved', NOW())");
  const pid = sql("SELECT id FROM partners WHERE code = 'RESP'");
  const hash = execSync(`php -r 'echo password_hash("resp12345", PASSWORD_DEFAULT);'`).toString();
  sql(`INSERT INTO partner_users (partner_id, username, name, password_hash, created_at) VALUES (${pid}, 'resptest', 'Resp Tester', '${hash}', NOW())`);
  sql(`INSERT INTO designs (name, code, extra, active, created_at) VALUES ('A very long design name that should wrap nicely', '', '{}', 1, NOW())`);
  const did = sql("SELECT MAX(id) FROM designs WHERE name LIKE 'A very long design name%'");
  sql(`INSERT IGNORE INTO design_customers (design_id, customer_code, created_at) VALUES (${did}, 'RESP', NOW())`);
  const [gsm, prod, col, size] = JSON.parse(execSync(`php -r 'require "${ROOT}/inc/bootstrap.php"; foreach (catalog() as $g) foreach ($g["products"] as $p) if ($p["colors"]) { echo json_encode([$g["gsm"], $p["name"], $p["colors"][0]["name"], $p["sizes"][0]]); exit; }'`).toString());
  const items = JSON.stringify([{ design_id: +did, design: 'A very long design name that should wrap nicely', gsm, product: prod, color: col, size, qty: 2, src: 'new' }]).replace(/'/g, "''");
  sql(`INSERT INTO partner_orders (partner_id, ref, status, ship_name, ship_phone, ship_address, ship_pincode, payment_type, cod_amount, items, created_at) VALUES (${pid}, 'RS-0001', 'new', 'Customer With A Really Long Name', '9876543210', 'House 12, A long street name, Some Area, Kochi, Kerala', '682001', 'cod', 999, '${items}', NOW())`);
  const poId = sql(`SELECT id FROM partner_orders WHERE partner_id = ${pid} AND ref = 'RS-0001'`);

  const pages = ['partner/index.php', 'partner/index.php?tab=new', 'partner/order.php?new=1', 'partner/order.php?id=' + poId, 'partner/order.php?id=' + poId + '&edit=1',
    'partner/money.php', 'partner/money.php?po=' + poId, 'partner/stock.php', 'partner/designs.php', 'partner/designs.php?new=1'];
  const out = ['partner/index.php', 'partner/register.php'];
  for (const theme of ['light', 'dark']) {
    for (const [w, h] of [[360, 740], [390, 844], [768, 1024], [1024, 768], [1440, 900]]) {
      const c = await b.newContext({ viewport: { width: w, height: h }, colorScheme: theme });
      await c.addCookies([{ name: 'looma_theme', value: theme, url: U }]);
      const p = await c.newPage(); const errs = [];
      p.on('pageerror', e => errs.push(e.message)); p.on('console', m => { if (m.type() === 'error' && !/favicon|fonts\.g/.test(m.text())) errs.push(m.text()); });
      const check = async (path) => {
        const r = await p.goto(U + path); await p.waitForLoadState('networkidle').catch(() => {});
        const html = await p.content();
        const wide = await p.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        const bad = [r.status() !== 200 && 'HTTP ' + r.status(), /(Warning|Notice|Deprecated|Fatal error|Parse error)<\/b>:|Uncaught|Stack trace/.test(html) && 'PHP message', wide > 1 && `${wide}px too wide`].filter(Boolean);
        return bad;
      };
      const problems = [];
      for (const path of out) { const bad = await check(path); if (bad.length) problems.push(path + ': ' + bad.join(', ')); }
      await p.goto(U + 'partner/'); await p.fill('[name=username]', 'resptest'); await p.fill('[name=password]', 'resp12345'); await p.click('.pt-login button'); await p.waitForLoadState();
      for (const path of pages) { const bad = await check(path); if (bad.length) problems.push(path + ': ' + bad.join(', ')); }
      // navigation: bottom bar on phones, top tabs on computers
      await p.goto(U + 'partner/index.php');
      const tabs = await p.locator('.pt-tabs').boundingBox();
      const navOk = w <= 720 ? tabs && tabs.y > h - 120 : tabs && tabs.y < 80;
      ok(!problems.length && !errs.length && navOk, `${theme} ${w}px: ${pages.length + out.length} pages fine` + (problems.length ? ' — ' + problems.join(' | ') : '') + (errs.length ? ' — JS: ' + errs.slice(0, 3).join(' | ') : '') + (navOk ? '' : ' — tab bar in the wrong place'));
      if (w === 390 || w === 1440) await p.screenshot({ path: `${__dirname}/shots/presp-${theme}-${w}.png`, fullPage: false });
      await c.close();
    }
  }
  // own T-shirts are hidden unless switched on
  const c = await b.newContext({ viewport: { width: 390, height: 844 } }); const p = await c.newPage();
  sql(`INSERT INTO partner_stock (partner_id, kind, gsm, product, color, size, qty, updated_at) VALUES (${pid}, 'blank', '${gsm}', '${prod}', '${col}', '${size}', 5, NOW())`);
  await p.goto(U + 'partner/'); await p.fill('[name=username]', 'resptest'); await p.fill('[name=password]', 'resp12345'); await p.click('.pt-login button'); await p.waitForLoadState();
  ok(await p.locator('.pt-tabs a:has-text("Stock")').count() === 0, 'own T-shirts off, no returns: no Stock tab');
  await p.goto(U + 'partner/order.php?new=1');
  ok(await p.locator('text=My own T-shirt').count() === 0, 'own T-shirts off: “My own T-shirt” not in the order form');
  await p.goto(U + 'partner/stock.php');
  ok(!/own T-shirts/i.test(await p.locator('main').innerText()), 'own T-shirts off: not mentioned on the Stock page');
  sql(`UPDATE partners SET settings = '{"own_blanks":1}' WHERE id = ${pid}`);
  await p.goto(U + 'partner/order.php?new=1');
  ok(await p.locator('.pt-tabs a:has-text("Stock")').count() === 1 && await p.locator('text=My own T-shirt').count() === 1, 'switched on by the admin: Stock tab and “My own T-shirt” appear');
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
