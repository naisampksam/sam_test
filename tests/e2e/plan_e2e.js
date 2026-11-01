// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
// Plan production: minimum stock per size (and colour), below-minimum highlighted on Products & stock,
// fabric kg per colour → pieces per size in the minimum-stock ratio, and the PDF download.
const { chromium } = require(process.env.PWPATH);
const { execSync } = require('child_process');
const fs = require('fs');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sql = s => execSync(`mysql --default-character-set=utf8mb4 -N ${process.env.DB_NAME || 'looma'} -e "${s.replace(/"/g, '\\"')}"`).toString().trim();
const U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
const ROOT = __dirname + '/../..';
(async () => {
  const [pid, gsm, pname] = sql("SELECT p.id, g.label, p.name FROM products p JOIN gsm_options g ON g.id = p.gsm_id WHERE p.active = 1 AND p.sizes LIKE '%XL%' ORDER BY g.sort, g.id, p.sort, p.id LIMIT 1").split('\t');
  const colors = sql(`SELECT name FROM product_colors WHERE product_id = ${pid} AND active = 1 ORDER BY sort, id LIMIT 2`).split('\n');
  const [c1, c2] = colors;
  sql(`DELETE FROM stock_min WHERE product_id = ${pid}`);
  // known stock for colour 1: S 3, M 50, L 50, XL 2
  const setStock = (c, sz, q) => sql(`UPDATE stock_items SET qty = ${q} WHERE category = 'Blank T-shirt' AND gsm = '${gsm}' AND product = '${pname}' AND color = '${c}' AND size = '${sz}'`);
  const before = sql(`SELECT GROUP_CONCAT(CONCAT(id, ':', qty)) FROM stock_items WHERE gsm = '${gsm}' AND product = '${pname}'`);
  [['S', 3], ['M', 50], ['L', 50], ['XL', 2]].forEach(([sz, q]) => setStock(c1, sz, q));
  const b = await chromium.launch(); const errs = [];
  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 }, acceptDownloads: true });
  const p = await ctx.newPage(); p.on('pageerror', e => errs.push(e.message));
  await p.goto(U + 'login.php'); await p.fill('[name=username]', 'admin'); await p.fill('[name=password]', 'admin12345'); await p.click('button.primary'); await p.waitForLoadState();

  // 1. minimum stock: all colours S 10, M 20, L 20, XL 10; colour 2 has its own M 30
  await p.goto(U + 'stock.php');
  await p.click('.page-head a:has-text("Plan production")'); await p.waitForLoadState();
  ok(/production_plan\.php/.test(p.url()), 'Products & stock → “🧵 Plan production”');
  await p.goto(U + 'production_plan.php?pid=' + pid);
  for (const [sz, v] of [['S', 10], ['M', 20], ['L', 20], ['XL', 10]]) await p.fill(`[name="all[${sz}]"]`, String(v));
  await p.fill(`[name="col[${c2}][M]"]`, '30');
  // a size the product does not list
  await p.fill('#minNewSize', '3xl'); await p.click('#minAddSize');
  ok(await p.locator('[name="all[3XL]"]').count() === 1, '“+ Size” adds a 3XL column to the minimum stock');
  await p.fill('[name="all[3XL]"]', '0');
  await p.click('#mins button.primary'); await p.waitForLoadState();
  ok(await p.locator('#mins th:has-text("3XL")').count() === 1 && await p.locator('[data-g="3XL"]').count() === 1, '3XL stays after saving and is in the plan');
  sql(`DELETE FROM stock_min WHERE product_id = ${pid} AND size = '3XL'`);
  ok(sql(`SELECT GROUP_CONCAT(CONCAT(IF(color = '', '*', color), size, '=', qty) ORDER BY color, FIELD(size, 'S', 'M', 'L', 'XL')) FROM stock_min WHERE product_id = ${pid}`) === `*S=10,*M=20,*L=20,*XL=10,${c2}M=30`,
    'minimums saved (all colours + one colour’s own M)');

  // 2. below minimum: red on Products & stock with a Plan production bar
  await p.goto(U + 'stock.php');
  const card = p.locator(`#p${pid}`);
  ok(await card.locator('.plan-alert').count() === 1 && /below minimum stock/.test(await card.locator('.plan-alert').innerText()), 'product card: “⚠ … below minimum stock · 🧵 Plan production”');
  const rowRed = await card.locator('tr', { hasText: c1 }).locator('td.below-min').count();
  ok(rowRed === 2, `${c1}: S (3 < 10) and XL (2 < 10) are red, M and L are not (${rowRed} red)`);

  // 3a. simple way (default): 3 pieces per kg — 20 kg ≈ 60 T-shirts — split by the minimum ratio
  await p.goto(U + 'production_plan.php?pid=' + pid);
  ok(await p.inputValue('#planPerKg') === '3' && await p.locator('.plan-grams').isHidden(), 'default: pieces per kg = 3 (grams per size hidden)');
  await p.fill('[data-kg="0"]', '20'); await p.fill('[data-kg="1"]', '5'); await p.waitForTimeout(150);
  const simple = await p.locator('.plan-result tbody tr').evaluateAll(trs => trs.map(tr => [...tr.cells].map(td => td.querySelector('input') ? td.querySelector('input').value : (td.querySelector('b') || td).textContent.trim())));
  const cnt = (n, r) => JSON.parse(execSync(`php -r 'require "${ROOT}/inc/bootstrap.php"; require "${ROOT}/inc/plan.php"; echo json_encode(plan_pieces_count(${n}, ${r}));'`).toString());
  const k1 = cnt(60, '["S" => 10, "M" => 20, "L" => 20, "XL" => 10]'), k2 = cnt(15, '["S" => 10, "M" => 30, "L" => 20, "XL" => 10]');
  ok(JSON.stringify(simple[0]) === JSON.stringify([c1, '20 kg', '10', '20', '20', '10', '60']) && JSON.stringify(k1) === '{"S":10,"M":20,"L":20,"XL":10}', `${c1} 20 kg → 60 T-shirts: S 10, M 20, L 20, XL 10 (${JSON.stringify(simple[0])})`);
  ok(JSON.stringify(simple[1]) === JSON.stringify([c2, '5 kg', String(k2.S), String(k2.M), String(k2.L), String(k2.XL), '15']), `${c2} 5 kg → 15 pieces in its own ratio: ${JSON.stringify(simple[1])}`);
  const need0 = await p.locator('[data-need="0"]').innerText();
  ok(/kg/.test(need0), `“To reach minimum” (pieces per kg): ${need0}`);
  await p.fill('#planPerKg', '2.5'); await p.waitForTimeout(150);
  ok((await p.locator('.plan-result tbody tr >> nth=0 >> td >> nth=-1').innerText()).trim() === '50', 'changing pieces per kg to 2.5 → 20 kg = 50 pieces');
  // change a planned number by hand: totals follow, the PDF uses it; “Back to the calculated numbers” undoes it
  await p.fill('#planPerKg', '3'); await p.waitForTimeout(100);
  await p.fill(`[data-edit="0|M"]`, '25'); await p.waitForTimeout(100);
  ok(await p.locator('[data-rowtotal="0"]').innerText() === '65' && await p.locator('[data-coltotal="M"]').innerText() === String(25 + k2.M) && /edited/.test(await p.getAttribute('[data-edit="0|M"]', 'class')),
    'hand-edited M 20 → 25: row total 65, column total follows, shown yellow');
  const [dl0] = await Promise.all([p.waitForEvent('download'), p.click('#planPdf')]);
  ok(/\(65\) Tj|\(65\)/.test(fs.readFileSync(await dl0.path()).toString('latin1')), 'the PDF has the hand-edited numbers (65)');
  await p.click('[data-reset-edits]');
  ok(await p.inputValue(`[data-edit="0|M"]`) === '20', '“↺ Back to the calculated numbers” → M 20 again');
  await p.fill('[data-kg="0"]', ''); await p.fill('[data-kg="1"]', '');

  // 3b. exact way: grams per piece and waste, then kg per colour
  await p.check('[name=method][value=grams]');
  for (const [sz, g] of [['S', 200], ['M', 220], ['L', 240], ['XL', 260]]) await p.fill(`[data-g="${sz}"]`, String(g));
  await p.fill('#planWaste', '10');
  const needTxt = await p.locator('[data-need="0"]').innerText();
  ok(/kg/.test(needTxt), `“To reach minimum” for ${c1}: ${needTxt}`);
  await p.fill('[data-kg="0"]', '10'); await p.fill('[data-kg="1"]', '5');
  await p.waitForTimeout(200);
  const rows = await p.locator('.plan-result tbody tr').evaluateAll(trs => trs.map(tr => [...tr.cells].map(td => td.querySelector('input') ? td.querySelector('input').value : (td.querySelector('b') || td).textContent.trim())));
  // same plan worked out on the server (inc/plan.php)
  const php = c => JSON.parse(execSync(`php -r 'require "${ROOT}/inc/bootstrap.php"; require "${ROOT}/inc/plan.php"; $f = ["S" => 200 / 0.9, "M" => 220 / 0.9, "L" => 240 / 0.9, "XL" => 260 / 0.9];
    echo json_encode(plan_pieces(${c === c1 ? 10 : 5}, ${c === c1 ? '["S" => 10, "M" => 20, "L" => 20, "XL" => 10]' : '["S" => 10, "M" => 30, "L" => 20, "XL" => 10]'}, $f));'`).toString());
  const s1 = php(c1), s2 = php(c2);
  const want = (c, kg, s) => [c, kg + ' kg', String(s.S), String(s.M), String(s.L), String(s.XL), String(s.S + s.M + s.L + s.XL)];
  ok(JSON.stringify(rows[0]) === JSON.stringify(want(c1, 10, s1)), `${c1} 10 kg → ${JSON.stringify(rows[0])} (server: ${JSON.stringify(s1)})`);
  ok(JSON.stringify(rows[1]) === JSON.stringify(want(c2, 5, s2)), `${c2} 5 kg uses its own ratio (M 30) → ${JSON.stringify(rows[1])}`);
  // the ratio and the fabric are right: 1:2:2:1 within one piece, fabric used ≤ 10 kg and nearly all of it
  const used = (s1.S * 200 + s1.M * 220 + s1.L * 240 + s1.XL * 260) / 0.9;
  ok(Math.abs(s1.M - 2 * s1.S) <= 1 && Math.abs(s1.L - s1.M) <= 1 && Math.abs(s1.XL - s1.S) <= 1, 'pieces follow the 1 : 2 : 2 : 1 ratio of the minimums');
  ok(used <= 10000 && used > 10000 - 300, `fabric used ${Math.round(used)} g of 10000 g (less than one piece left)`);
  // tapping the suggestion fills the kg
  await p.fill('[data-kg="0"]', ''); await p.click('[data-need="0"] [data-use]');
  { const v = await p.inputValue('[data-kg="0"]'); ok(v === needTxt.replace(' kg', ''), 'tapping “To reach minimum” fills that kg: ' + v + ' vs ' + needTxt); }
  await p.fill('[data-kg="0"]', '10');

  // 4. PDF
  const [dl] = await Promise.all([p.waitForEvent('download'), p.click('#planPdf')]);
  const file = await dl.path(); const head = fs.readFileSync(file).subarray(0, 5).toString();
  ok(/^production-plan-.*\.pdf$/.test(dl.suggestedFilename()) && head === '%PDF-' && fs.statSync(file).size > 1500, 'PDF downloaded: ' + dl.suggestedFilename());
  await p.screenshot({ path: __dirname + '/shots/plan.png', fullPage: true });

  // put things back
  sql(`DELETE FROM stock_min WHERE product_id = ${pid}`);
  before.split(',').filter(Boolean).forEach(x => { const [id, q] = x.split(':'); sql(`UPDATE stock_items SET qty = ${q} WHERE id = ${id}`); });
  ok(!errs.length, 'no JS errors ' + errs.join('|'));
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
