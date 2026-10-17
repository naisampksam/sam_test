// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
const { chromium } = require(process.env.PWPATH);
const { execSync } = require('child_process');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sql = s => execSync(`mysql --default-character-set=utf8mb4 -N ${process.env.DB_NAME || 'looma'} -e "${s}"`).toString().trim();
const U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
(async () => {
  const b = await chromium.launch(); const errs = [];
  const p = await (await b.newContext({ viewport: { width: 1200, height: 900 } })).newPage();
  p.on('pageerror', e => errs.push(e.message)); p.on('dialog', d => d.accept());
  await p.goto(U + 'login.php'); await p.fill('[name=username]', 'admin'); await p.fill('[name=password]', 'admin12345'); await p.click('button'); await p.waitForLoadState();
  sql("DELETE FROM production_batches; DELETE FROM settings WHERE k IN ('piece_weights','fabric_rates')");
  const open = async () => { await p.goto(U + 'stock.php'); await p.selectOption('#asProduct', '27'); await p.click('#asColors .chip[data-v="Black"]'); };
  const fill = async (o) => { for (const [k, v] of Object.entries(o)) await p.fill(k, String(v)); };
  // bundle 1
  await open();
  await fill({ '#asSizes [name="qty[XS]"]': 40, '#asSizes [name="qty[S]"]': 120, '#asSizes [name="qty[M]"]': 180, '#asSizes [name="qty[L]"]': 160, '#asSizes [name="qty[XL]"]': 100, '#asSizes [name="qty[XXL]"]': 50,
    '[name=fabric_kg]': 200, '[name=rib_kg]': 8, '[name=fabric_rate]': 420, '[name=rib_rate]': 450, '[name=other_waste_kg]': 5, '[name=cutting_cost]': 3250, '[name=cmt_cost]': 26000, '[name=transport_cost]': 1300 });
  await p.click('.as-pw summary');
  await fill({ '#asWeights [name="piece_g[XS]"]': 220, '#asWeights [name="piece_g[S]"]': 235, '#asWeights [name="piece_g[M]"]': 250, '#asWeights [name="piece_g[L]"]': 265, '#asWeights [name="piece_g[XL]"]': 280, '#asWeights [name="piece_g[XXL]"]': 295 });
  ok(await p.inputValue('[name=cut_waste_kg]') === '35.85', 'cutting waste auto: 208 − 167.15 − 5 = 35.85 → ' + await p.inputValue('[name=cut_waste_kg]'));
  const live = await p.locator('#asBatchLive').innerText();
  console.log('live:', live.replace(/\s+/g, ' '));
  ok(/₹1,18,150/.test(live) && /₹181\.77 per piece/.test(live), 'live: making cost ₹1,18,150 = ₹181.77/pc');
  await p.click('.as-save'); await p.waitForLoadState();
  const flash = await p.locator('.alert').first().innerText();
  ok(/cost ₹181\.77 per piece/.test(flash), 'saved: ' + flash);
  ok(sql("SELECT ROUND(cost_price,2) FROM stock_items WHERE gsm='250 GSM' AND product='Oversized Fit - French Terry' AND color='Black' AND size='M'") === '181.77', 'stock cost price set from the batch');
  // bundle 2: rates remembered, cutting waste auto
  await open();
  ok(await p.inputValue('[name=fabric_rate]') === '420' && await p.inputValue('[name=rib_rate]') === '450', 'fabric & rib ₹/kg remembered for the GSM');
  await fill({ '#asSizes [name="qty[XS]"]': 30, '#asSizes [name="qty[S]"]': 100, '#asSizes [name="qty[M]"]': 140, '#asSizes [name="qty[L]"]': 120, '#asSizes [name="qty[XL]"]': 70, '#asSizes [name="qty[XXL]"]': 30,
    '[name=fabric_kg]': 150, '[name=rib_kg]': 6, '[name=other_waste_kg]': 4, '[name=cutting_cost]': 2450, '[name=cmt_cost]': 19600, '[name=transport_cost]': 980 });
  ok(await p.inputValue('[name=cut_waste_kg]') === '26.65', 'cutting waste auto with remembered weights: 26.65');
  // typing own value keeps it
  await p.fill('[name=cut_waste_kg]', '25'); await p.fill('[name=other_waste_kg]', '4.5');
  ok(await p.inputValue('[name=cut_waste_kg]') === '25', 'typed cutting waste is kept');
  await p.click('.as-save'); await p.waitForLoadState();
  ok(sql("SELECT CONCAT_WS('|', cut_waste_kg, other_waste_kg, cmt_cost, transport_cost) FROM production_batches ORDER BY id DESC LIMIT 1") === '25.000|4.500|19600.00|980.00', 'bundle 2 saved with costs');
  // dashboard
  await p.goto(U + 'dashboard.php');
  const sum = await p.locator('#production .prod-sum tbody tr').first().innerText();
  console.log('summary:', sum.replace(/\s+/g, ' '));
  ok(/₹181\.47/.test(sum), 'colour row: cost per piece ₹181.47 (206,880 / 1,140)');
  const latest = await p.locator('#production .batch').first().innerText();
  ok(/MAKING COST PER PIECE/i.test(latest) && /₹181\.08/.test(latest) && /vs ₹181\.77/.test(latest) && /CMT ₹40\.00/.test(latest), 'batch: ₹181.08/pc vs earlier ₹181.77, parts listed');
  await p.locator('#production .batch').first().screenshot({ path: __dirname + '/shots/cost_batch.png' });
  // balance sheet
  await p.goto(U + 'accounts.php');
  const mk = await p.locator('section:has(h2:has-text("Making cost per piece"))').innerText();
  console.log('accounts:', mk.replace(/\s+/g, ' ').slice(0, 300));
  ok(/250 GSM/.test(mk) && /\b2\b/.test(mk) && /1140/.test(mk) && /₹181\.47/.test(mk) && /batch average ₹181\.43/.test(mk), 'balance sheet: 250 GSM avg ₹181.47 (batch average ₹181.43)');
  ok(/Stock at cost uses each GSM's average/.test(mk) && /250 GSM ₹181\.47/.test(mk), 'stock valued at the GSM average');
  ok(!/230 GSM ₹/.test(mk), 'other GSMs not mixed in');
  await p.locator('section:has(h2:has-text("Making cost per piece"))').screenshot({ path: __dirname + '/shots/cost_bs.png' });
  ok(!errs.length, 'no JS errors ' + errs.join('|'));
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
