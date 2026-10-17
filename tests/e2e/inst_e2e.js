// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
const { chromium } = require(process.env.PWPATH);
const { execSync } = require('child_process');
const fs = require('fs');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sql = s => execSync(`mysql --default-character-set=utf8mb4 -N ${process.env.DB_NAME || 'looma'} -e "${s}"`).toString().trim();
const U = (process.env.BASE_URL || 'http://127.0.0.1:8080/'), UP = '/home/user/sam_test/uploads/';
(async () => {
  const b = await chromium.launch(); const errs = [];
  const p = await (await b.newContext({ viewport: { width: 390, height: 900 } })).newPage();
  p.on('pageerror', e => errs.push(e.message));
  let posted = -1;
  p.on('request', r => { if (r.method() === 'POST' && /order\.php/.test(r.url())) posted = (r.postDataBuffer() || Buffer.alloc(0)).length; });
  await p.goto(U + 'login.php'); await p.fill('[name=username]', 'admin'); await p.fill('[name=password]', 'admin12345'); await p.click('button'); await p.waitForLoadState();
  const before = +sql('SELECT COUNT(*) FROM temp_uploads');
  await p.goto(U + 'order.php?new=1');
  await p.fill('[name=customer_id]', 'INSTTEST');
  await p.evaluate(() => { const c = document.querySelector('#items .item-card'); c._setBlank({ gsm: '190 GSM', product: 'Regular Fit - Single Jersey', color: 'White', size: 'M' }); c.querySelector('select[data-cat=size]').dispatchEvent(new Event('change', { bubbles: true })); });
  await p.setInputFiles('.item-card input[name^="item_mockups"]', [__dirname + '/fixtures/m1.jpg', __dirname + '/fixtures/m1.jpg']);
  await p.waitForFunction(() => document.querySelectorAll('.up-tile.done').length === 2, null, { timeout: 20000 });
  ok(true, '2 tiles uploaded right away');
  ok(+sql('SELECT COUNT(*) FROM temp_uploads') === before + 2, 'temp_uploads has 2 new rows');
  ok(await p.evaluate(() => document.querySelector('.item-card input[name^="item_mockups"]').files.length === 0), 'file input cleared');
  // ✕ the second one
  const id2 = await p.evaluate(() => document.querySelectorAll('.up-tile.done')[1].dataset.tmpId);
  const fn2 = sql(`SELECT filename FROM temp_uploads WHERE id=${id2}`);
  await p.locator('.up-tile.done .up-x').nth(1).click();
  await p.waitForTimeout(800);
  ok(sql(`SELECT COUNT(*) FROM temp_uploads WHERE id=${id2}`) === '0', '✕ removed the temp row');
  ok(!fs.existsSync(UP + fn2), '✕ deleted the file ' + fn2);
  // neck label upload too
  const neckInp = p.locator('.item-card input[name^="item_neck"]');
  if (await neckInp.count()) {
    await p.evaluate(() => { const t = document.querySelector('.item-card [name$="[neck_label_on]"]'); if (t && !t.checked) t.click(); });
    await neckInp.setInputFiles(__dirname + '/fixtures/m2.jpg');
    await p.waitForFunction(() => document.querySelectorAll('.up-tile.done').length === 2, null, { timeout: 15000 });
  }
  // pick another and submit immediately (pending wait)
  await p.setInputFiles('.item-card input[name^="item_mockups"]', __dirname + '/fixtures/m3.jpg');
  const t0 = Date.now();
  await p.click('.sticky-actions .btn.primary');
  await p.waitForURL(/order\.php\?id=\d+/, { timeout: 30000 });
  await p.waitForSelector('.alert');
  const oid = new URL(p.url()).searchParams.get('id');
  console.log('saved in', Date.now() - t0, 'ms, order POST', Math.round(posted / 1024), 'KB');
  ok(posted >= 0 && posted < 60000, 'order POST carries no photo data');
  const imgs = sql(`SELECT CONCAT(kind,':',filename) FROM order_images WHERE order_id=${oid}`).split('\n').filter(Boolean);
  console.log(imgs);
  ok(imgs.filter(x => /^:/.test(x)).length === 2, '2 mock-ups attached (1 removed)');
  ok(neckInp && imgs.some(x => /^neck:/.test(x)) || !(await neckInp.count()), 'neck label attached');
  ok(+sql('SELECT COUNT(*) FROM temp_uploads') === before, 'temp rows consumed');
  ok(await p.locator('img[src*="image.php"]').count() >= 2, 'images visible on the order');
  ok(!errs.length, 'no JS errors ' + errs.join('|'));
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
