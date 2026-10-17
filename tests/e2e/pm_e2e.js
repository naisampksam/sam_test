// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
const { chromium } = require(process.env.PWPATH);
const { execSync } = require('child_process');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sql = s => execSync(`mysql --default-character-set=utf8mb4 -N ${process.env.DB_NAME || 'looma'} -e "${s}"`).toString().trim();
const D = __dirname + '/', U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
(async () => {
  const b = await chromium.launch(); const errs = [];
  const p = await (await b.newContext({ viewport: { width: 390, height: 844 } })).newPage();
  p.on('pageerror', e => errs.push(e.message)); p.on('dialog', d => d.accept());
  await p.goto(U + 'login.php'); await p.fill('[name=username]', 'admin'); await p.fill('[name=password]', 'admin12345'); await p.click('button'); await p.waitForLoadState();
  await p.goto(U + 'order.php?new=1');
  await p.fill('[name=customer_id]', 'C1214');
  await p.evaluate(() => {
    const card = document.querySelector('#items .item-card');
    card._setBlank({ gsm: '250 GSM', product: 'Oversized Fit - French Terry', color: 'Black', size: 'M' });
    const q = card.querySelector('[name$="[quantity]"]'); q.value = 10; q.dispatchEvent(new Event('input', { bubbles: true }));
    card.querySelector('.print-details').open = true;
  });
  const place = k => p.locator('.item-card').first().locator('.print-place', { has: p.locator(`[name$="[${k}]"]`) });
  for (const k of ['front_print', 'back_print', 'chest_print', 'custom_print']) await p.locator('.item-card').first().locator(`[data-pp-toggle=${k}]`).click();
  const back = place('back_print'), chest = place('chest_print'), front = place('front_print');
  await back.locator('[data-ps]').selectOption({ index: 2 }); // A3
  await back.locator('textarea').fill('Mountain');
  ok(/DTF A3: ₹135 per print \(₹100 for 10\+/.test(await back.locator('[data-pp-hint]').innerText()), 'DTF hint: ' + await back.locator('[data-pp-hint]').innerText());
  await chest.locator('[data-pm]').selectOption('emb');
  ok(await chest.locator('[data-ps]').isHidden() && await chest.locator('[data-pst]').isVisible(), 'embroidery: size hidden, stitch count asked');
  await chest.locator('[data-pst]').fill('8000');
  await chest.locator('input[name$="[dg]"]').fill('300');
  await chest.locator('textarea').fill('Logo');
  const eh = await chest.locator('[data-pp-hint]').innerText();
  ok(/8,000 stitches = ₹48 per piece for 10 pcs \(₹48 for 10\+, ₹32 for 50\+\)/.test(eh), 'embroidery hint: ' + eh);
  await front.locator('[data-pm]').selectOption('puff');
  ok(/Type the price per print/.test(await front.locator('[data-pp-hint]').innerText()) && await front.locator('[data-ppr]').isVisible(), 'puff: asks price per print');
  await front.locator('[data-ppr]').fill('80'); await front.locator('textarea').fill('Name');
  // screen print min qty warning on custom place
  const custom = place('custom_print');
  await custom.locator('[data-pm]').selectOption('screen');
  ok(/Minimum 60 pieces/.test(await custom.locator('[data-pp-hint]').innerText()), 'screen print warns: minimum 60 pieces');
  await custom.locator('[data-pm]').selectOption('dtf');
  await chest.scrollIntoViewIfNeeded(); await p.screenshot({ path: D + 'shots/v80_print_methods.png' });
  await p.click('.sticky-actions .btn.primary'); await p.waitForURL(/order\.php\?id=\d+/);
  console.log('flash:', (await p.locator('.alert').first().innerText()).replace(/\s+/g, ' '));
  const oid = new URL(p.url()).searchParams.get('id');
  const lines = sql(`SELECT CONCAT(i.description,' | ',i.qty,' x ',i.rate) FROM bill_items i JOIN bills b ON b.id=i.bill_id WHERE b.order_id=${oid} ORDER BY i.sort`);
  console.log(lines);
  ok(/\| 10\.00 x 265\.00/.test(lines) && /Printing – Front Puff print \+ Back A3 DTF \+ Chest embroidery 8,000 st \| 10\.00 x 228\.00/.test(lines) && /Embroidery digitizing \(one time\) \| 1\.00 x 300\.00/.test(lines),
    'bill: T-shirt ₹265 + printing ₹228 (puff 80 + A3 100 + embroidery 48) + digitizing ₹300');
  const view = await p.locator('main').innerText();
  ok(/Embroidery · 8,000 stitches/.test(view) && /Puff print/.test(view), 'order page shows the print methods');
  // edit keeps methods
  await p.goto(U + `order.php?id=${oid}&edit=1`);
  ok(await place('chest_print').locator('[data-pm]').inputValue() === 'emb' && await place('chest_print').locator('[data-pst]').inputValue() === '8000', 'edit form keeps method & stitches');
  ok(!errs.length, 'no JS errors ' + errs.join('|'));
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
