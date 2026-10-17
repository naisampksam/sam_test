// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
const { chromium } = require(process.env.PWPATH);
const { execSync } = require('child_process');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sql = s => execSync(`mysql --default-character-set=utf8mb4 -N ${process.env.DB_NAME || 'looma'} -e "${s}"`).toString().trim();
const D = __dirname + '/', U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
(async () => {
  const b = await chromium.launch(); const errs = [];
  const ctx = await b.newContext({ viewport: { width: 390, height: 900 } });
  const p = await ctx.newPage();
  p.on('pageerror', e => errs.push(e.message)); p.on('dialog', d => d.accept());
  await p.goto(U + 'login.php'); await p.fill('[name=username]', 'admin'); await p.fill('[name=password]', 'admin12345'); await p.click('button'); await p.waitForLoadState();
  sql("INSERT INTO settings (k,v) VALUES ('inv_gstin','32ABCDE1234F1Z5') ON DUPLICATE KEY UPDATE v='32ABCDE1234F1Z5'");
  const make = async (cust, gst) => {
    await p.goto(U + 'order.php?new=1');
    ok(await p.locator('[data-bill-type][value=proforma]').count() === 0, 'no proforma choice on the order');
    await p.fill('[name=customer_id]', cust);
    await p.evaluate(() => { const c = document.querySelector('#items .item-card'); c._setBlank({ gsm: '190 GSM', product: 'Regular Fit - Single Jersey', color: 'White', size: 'M' }); c.querySelector('select[data-cat=size]').dispatchEvent(new Event('change', { bubbles: true })); const q = c.querySelector('[name$="[quantity]"]'); q.value = 2; q.dispatchEvent(new Event('input', { bubbles: true })); });
    if (gst) await p.locator('.gst-switch input').check();
    await p.locator('#step-bill').scrollIntoViewIfNeeded(); await p.screenshot({ path: D + `shots/v110_bill_${gst ? 'gst' : 'plain'}.png` });
    await p.click('.sticky-actions .btn.primary'); await p.waitForURL(/order\.php\?id=\d+/);
    console.log('flash:', (await p.locator('.alert').first().innerText()).replace(/\s+/g, ' '));
    return sql(`SELECT CONCAT(id,'|',type,'|',branding,'|',tax_total,'|',seller_gstin) FROM bills WHERE order_id=${new URL(p.url()).searchParams.get('id')}`).split('|');
  };
  const a = await make('GSTT1', false);
  ok(a[1] === 'invoice' && a[2] === 'plain' && +a[3] > 0 && a[4] === '', 'default: invoice, no branding, GST added (' + a[3] + ')');
  const pr = await ctx.newPage(); await pr.goto(U + 'bill_print.php?id=' + a[0]);
  const t1 = await pr.locator('.sheet').innerText();
  ok(/^INVOICE$/m.test(t1) && !/Looma/i.test(t1) && /CGST/.test(t1), 'print: "INVOICE", no Looma, CGST/SGST shown');
  const g = await make('GSTT2', true);
  ok(g[2] === 'looma' && g[4] === '32ABCDE1234F1Z5', 'GST bill: company name & GSTIN');
  await pr.goto(U + 'bill_print.php?id=' + g[0]);
  const t2 = await pr.locator('.sheet').innerText();
  ok(/TAX INVOICE/.test(t2) && /32ABCDE1234F1Z5/.test(t2), 'print: "TAX INVOICE" with GSTIN');
  await p.goto(U + 'bills.php');
  ok(await p.locator('a', { hasText: '+ Proforma' }).count() === 0 && await p.locator('a', { hasText: 'New quotation' }).count() === 1, 'bills page: only "New quotation"');
  ok(!errs.length, 'no JS errors ' + errs.join('|'));
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
