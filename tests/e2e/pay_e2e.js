// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
const { chromium } = require(process.env.PWPATH);
const { execSync } = require('child_process');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sql = s => execSync(`mysql --default-character-set=utf8mb4 -N ${process.env.DB_NAME || 'looma'} -e "${s}"`).toString().trim();
const D = __dirname + '/', U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
(async () => {
  const b = await chromium.launch(); const errs = [];
  const p = await (await b.newContext({ viewport: { width: 390, height: 900 } })).newPage();
  p.on('pageerror', e => errs.push(e.message)); p.on('dialog', d => d.accept());
  await p.goto(U + 'login.php'); await p.fill('[name=username]', 'admin'); await p.fill('[name=password]', 'admin12345'); await p.click('button'); await p.waitForLoadState();
  const newOrder = async (cust, qty, extra) => {
    await p.goto(U + 'order.php?new=1');
    await p.fill('[name=customer_id]', cust);
    await p.fill('[name=ship_phone]', '9847012345');
    await p.evaluate(qty => { const c = document.querySelector('#items .item-card'); c._setBlank({ gsm: '250 GSM', product: 'Oversized Fit - French Terry', color: 'Black', size: 'M' }); c.querySelector('select[data-cat=size]').dispatchEvent(new Event('change', { bubbles: true })); const q = c.querySelector('[name$="[quantity]"]'); q.value = qty; q.dispatchEvent(new Event('input', { bubbles: true })); }, qty);
    await p.waitForFunction(() => /Bill ₹[1-9]/.test((document.getElementById('billTotalBar') || {}).textContent || ''));
    if (extra) await extra();
    await p.click('.sticky-actions .btn.primary'); await p.waitForURL(/order\.php\?id=\d+/);
    return new URL(p.url()).searchParams.get('id');
  };
  // 1. advance 50%
  const o1 = await newOrder('PAYTEST1', 10, async () => {
    ok(/50% advance ₹1,392/.test(await p.locator('[data-pay-half]').innerText()), '50% button shows half of ₹2,783: ' + await p.locator('[data-pay-half]').innerText());
    await p.click('[data-pay-half]');
    ok(await p.inputValue('[name="bill[paid]"]') === '1392', 'advance filled ₹1,392');
    await p.locator('#step-bill').scrollIntoViewIfNeeded(); await p.screenshot({ path: D + 'shots/v100_advance.png' });
  });
  ok(sql(`SELECT CONCAT(b.total,'|',b.paid,'|',(SELECT note FROM bill_payments WHERE bill_id=b.id LIMIT 1)) FROM bills b WHERE order_id=${o1}`) === '2783.00|1392.00|Advance', 'bill ₹2,783, advance ₹1,392 recorded');
  // 2. order page payment panel → full balance
  ok(/Balance\s*₹1,391/i.test(await p.locator('#payment').innerText()), 'order page shows balance ₹1,391');
  await p.locator('#payment').scrollIntoViewIfNeeded(); await p.screenshot({ path: D + 'shots/v100_payment_panel.png' });
  await p.locator('#payment [data-pay-fill]').first().click();
  await p.locator('#payment select[name=mode]').selectOption('Cash');
  await p.locator('#payment button.primary').click(); await p.waitForURL(/#payment/);
  ok(sql(`SELECT paid FROM bills WHERE order_id=${o1}`) === '2783.00' && /Paid/i.test(await p.locator('#payment .badge').innerText()), 'full balance received → Paid');
  // 3. defaulter: shipped 10 days ago, nothing paid
  const o2 = await newOrder('PAYTEST2', 2);
  sql(`UPDATE orders SET shipped=1, shipped_at=NOW() - INTERVAL 10 DAY WHERE id=${o2}`);
  await p.goto(U + 'dues.php');
  const t = await p.locator('main').innerText();
  ok(/PAYTEST2/.test(t) && !/PAYTEST1/.test(t), 'defaulters list: PAYTEST2 (shipped 10 days, unpaid), not PAYTEST1 (paid)');
  await p.screenshot({ path: D + 'shots/v100_dues.png', fullPage: true });
  await p.locator('a', { hasText: 'PAYTEST2' }).first().click(); await p.waitForURL(/customer=/);
  ok(/Defaulter/i.test(await p.locator('h1').innerText()) && await p.locator('a.wa-btn').count() === 1, 'customer page: Defaulter badge + WhatsApp reminder');
  await p.goto(U + 'dues.php?customer=PAYTEST1');
  ok(/All paid/i.test(await p.locator('h1').innerText()) && /Advance/.test(await p.locator('main').innerText()) && /Full payment|Final payment/.test(await p.locator('main').innerText()), 'customer history: advance + final payment');
  await p.goto(U + 'dues.php?view=paid');
  ok(/PAYTEST1/.test(await p.locator('main').innerText()), 'payments received list');
  ok(!errs.length, 'no JS errors ' + errs.join('|'));
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
