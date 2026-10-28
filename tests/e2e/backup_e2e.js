// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
const { chromium } = require(process.env.PWPATH);
const { execSync } = require('child_process');
const fs = require('fs');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sql = s => execSync(`mysql --default-character-set=utf8mb4 -N ${process.env.DB_NAME || 'looma'} -e "${s}"`).toString().trim();
const U = process.env.BASE_URL || 'http://127.0.0.1:8080/', APP = require('path').resolve(__dirname, '../..');
const FD = __dirname + '/fixtures/fakedrive/db.json'; // stand-in Google Drive (fixtures/fake_drive.php)
const drive = () => fs.existsSync(FD) ? Object.values(JSON.parse(fs.readFileSync(FD)).files).filter(f => /\.zip$/.test(f.name)) : [];
const zips = () => fs.existsSync(APP + '/backups') ? fs.readdirSync(APP + '/backups').filter(f => /^looma-backup-.*\.zip$/.test(f)) : [];
const sleep = ms => new Promise(r => setTimeout(r, ms));
(async () => {
  sql("DELETE FROM settings WHERE k LIKE 'gdrive\\\\_%' OR k LIKE 'backup\\\\_%'");
  if (fs.existsSync(FD)) fs.unlinkSync(FD); // fresh stand-in Drive
  for (const f of zips()) fs.unlinkSync(APP + '/backups/' + f);
  const b = await chromium.launch(); const errs = [];
  const ctx = await b.newContext({ viewport: { width: 1200, height: 900 }, acceptDownloads: true }); const p = await ctx.newPage();
  p.on('pageerror', e => errs.push(e.message)); p.on('dialog', d => d.accept());
  await p.goto(U + 'login.php'); await p.fill('[name=username]', 'admin'); await p.fill('[name=password]', 'admin12345'); await p.click('button'); await p.waitForLoadState();
  await sleep(1500); // the first page of the day may run the daily backup after it is sent
  for (const f of zips()) fs.unlinkSync(APP + '/backups/' + f);
  sql("UPDATE settings SET v='' WHERE k='backup_last_day'");
  // ---- back up now (no Drive)
  await p.goto(U + 'admin/backup.php');
  await p.click('button:has-text("Back up now")'); await p.waitForLoadState();
  const fl = await p.locator('.alert').first().innerText();
  ok(/Backup made/.test(fl) && zips().length === 1, 'backup made: ' + fl.slice(0, 90));
  const z1 = zips()[0];
  const list = execSync(`unzip -l ${APP}/backups/${z1}`).toString();
  ok(/database\.sql/.test(list) && /manifest\.json/.test(list) && (list.match(/uploads\//g) || []).length >= 80, 'zip has database.sql, manifest and the photos');
  // ---- connect Google Drive (stand-in server)
  await p.fill('[name=client_id]', 'CID.apps.googleusercontent.com'); await p.fill('[name=client_secret]', 'SECRET');
  await p.click('button:has-text("Save Google details")'); await p.waitForLoadState();
  await p.click('button:has-text("Connect Google Drive")');
  await p.waitForSelector('.alert:has-text("Google Drive connected")', { timeout: 15000 }).then(() => ok(true, 'Google Drive connected'), async () => ok(false, 'Google Drive connected: ' + await p.locator('.alert').first().innerText().catch(() => '')));
  await sleep(1100);
  await p.click('button:has-text("Back up now")'); await p.waitForLoadState();
  ok(/copied to Google Drive/.test(await p.locator('.alert').first().innerText()), 'backup copied to Google Drive');
  const d1 = drive();
  ok(d1.length === 1 && d1[0].chunks >= 2 && +d1[0].size === fs.statSync(APP + '/backups/' + zips().sort().pop()).size, `in Drive, uploaded in ${d1[0] && d1[0].chunks} pieces, same size`);
  ok(/In Google Drive[\s\S]*looma-backup-/.test(await p.locator('body').innerText()), 'Drive backups listed on the page');
  // ---- stock to zero
  const nonzero = +sql("SELECT COUNT(*) FROM stock_items WHERE qty <> 0");
  await p.goto(U + 'stock.php');
  await p.click('#zero summary'); await p.fill('#zero [name=confirm]', 'zero'); await p.click('#zero button.danger'); await p.waitForLoadState();
  ok(sql("SELECT COUNT(*) FROM stock_items WHERE qty <> 0") === '0' && +sql("SELECT COUNT(*) FROM stock_moves WHERE note='All stock set to zero'") >= nonzero, `all ${nonzero} items set to zero, history kept`);
  // ---- restore from server backup
  await p.goto(U + 'admin/backup.php');
  await p.locator('table').first().locator('[data-restore]').first().click();
  await p.fill('#restoreDlg [name=confirm]', 'RESTORE'); await p.click('#restoreDlg button.danger'); await p.waitForLoadState();
  const rf = await p.locator('.alert').first().innerText();
  ok(/Restored from the backup/.test(rf), 'restore message: ' + rf.slice(0, 100));
  ok(+sql("SELECT COUNT(*) FROM stock_items WHERE qty <> 0") === nonzero, 'stock is back as it was');
  ok(fs.readdirSync(APP + '/backups').some(f => /^before-restore-/.test(f)), 'safety copy made before restoring');
  ok(sql("SELECT v FROM settings WHERE k='gdrive_refresh_token'") === 'REFRESH1', 'Drive stays connected after restore');
  // ---- restore from Google Drive
  const orders = sql('SELECT COUNT(*) FROM orders'); const maxId = sql('SELECT MAX(id) FROM orders');
  sql(`DELETE FROM orders WHERE id=${maxId}`);
  await p.goto(U + 'admin/backup.php');
  ok(/Last backup/.test(await p.locator('.stats').innerText()), 'still logged in after restore');
  await p.locator('section:has(h2:has-text("In Google Drive")) [data-restore]').first().click();
  await p.fill('#restoreDlg [name=confirm]', 'RESTORE'); await p.click('#restoreDlg button.danger'); await p.waitForLoadState();
  ok(sql('SELECT COUNT(*) FROM orders') === orders, 'deleted order is back (restored from Google Drive)');
  // ---- restore from an uploaded zip
  const [dl] = await Promise.all([p.waitForEvent('download'), p.locator('a:has-text("Download")').first().click()]);
  const dlPath = __dirname + '/restore-upload.zip'; await dl.saveAs(dlPath);
  const cust = sql('SELECT COUNT(*) FROM customers'); sql("DELETE FROM customers WHERE code=(SELECT code FROM (SELECT code FROM customers ORDER BY id DESC LIMIT 1) x)");
  await p.setInputFiles('#upload-restore [name=backup]', dlPath); await p.fill('#upload-restore [name=confirm]', 'RESTORE');
  await p.click('#upload-restore button.danger');
  await p.waitForSelector('.alert:has-text("Restored from the backup")', { timeout: 60000 }).catch(() => {});
  ok(sql('SELECT COUNT(*) FROM customers') === cust, 'uploaded zip restored (customer back)');
  await p.goto(U + 'admin/backup.php');
  fs.writeFileSync(__dirname + '/not-a-backup.zip', 'hello');
  await p.setInputFiles('#upload-restore [name=backup]', __dirname + '/not-a-backup.zip'); await p.fill('#upload-restore [name=confirm]', 'RESTORE');
  await p.click('#upload-restore button.danger');
  await p.waitForSelector('.alert.err', { timeout: 30000 }).catch(() => {});
  ok(/not a zip|not a Looma/i.test(await p.locator('.alert.err').first().innerText().catch(() => '')), 'wrong file refused');
  // ---- keep only 7 (server and Drive)
  execSync(`cd ${APP} && LOOMA_GDRIVE_TEST=${process.env.LOOMA_GDRIVE_TEST || 'http://127.0.0.1:8090'} php -r 'require "inc/bootstrap.php"; require "inc/backup.php"; for ($i = 0; $i < 7; $i++) { sleep(1); backup_run("test"); }'`);
  ok(zips().length === 7, 'server keeps 7 backups: ' + zips().length);
  ok(drive().filter(f => /^looma-backup-/.test(f.name)).length === 7, 'Google Drive keeps 7: ' + drive().length);
  // ---- daily automatic + cron
  sql("UPDATE settings SET v=DATE_SUB(CURDATE(), INTERVAL 1 DAY) WHERE k='backup_last_day'; UPDATE settings SET v='2000-01-01 00:00:00' WHERE k='backup_tried_at'");
  await p.goto(U + 'orders.php'); await sleep(4000);
  ok(JSON.parse(sql("SELECT v FROM settings WHERE k='backup_status'")).why === 'daily' && sql("SELECT v FROM settings WHERE k='backup_last_day'") === new Date().toISOString().slice(0, 10), 'daily backup ran by itself after a page was opened');
  const key = sql("SELECT v FROM settings WHERE k='backup_cron_key'");
  ok(/already done/.test(execSync(`curl -s "${U}backup_cron.php?key=${key}"`).toString()), 'cron link: already done today');
  ok(execSync(`curl -s -o /dev/null -w "%{http_code}" "${U}backup_cron.php?key=wrong"`).toString() === '403', 'cron link with a wrong key is refused');
  await p.goto(U + 'admin/backup.php'); await p.screenshot({ path: __dirname + '/shots/backup_page.png', fullPage: true });
  ok(!errs.length, 'no JS errors ' + errs.join('|'));
  await b.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
