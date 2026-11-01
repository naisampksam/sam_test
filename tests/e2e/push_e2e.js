// Browser test — see tests/e2e/README.md. Run against a TEST copy of the app and database only.
// Task notifications on phones / computers (Web Push). A stand-in push service on 127.0.0.1:8096 receives what the
// server sends; this test decrypts it with its own code (RFC 8291) and checks the server's signature (RFC 8292).
// Needs the app started with LOOMA_PUSH_TEST=http://127.0.0.1:8096 (lets the server send to the stand-in).
const { chromium } = require(process.env.PWPATH);
const { execSync } = require('child_process');
const http = require('http');
const crypto = require('crypto');
let fails = 0; const ok = (c, m) => { console.log((c ? 'PASS ' : 'FAIL ') + m); if (!c) fails++; };
const sql = s => execSync(`mysql --default-character-set=utf8mb4 -N ${process.env.DB_NAME || 'looma'} -e "${s}"`).toString().trim();
const U = (process.env.BASE_URL || 'http://127.0.0.1:8080/');
const PUSH = 'http://127.0.0.1:8096';
const b64u = b => Buffer.from(b).toString('base64url');

// A pretend phone: its own key pair and secret, like a browser makes.
const device = name => { const ecdh = crypto.createECDH('prime256v1'); ecdh.generateKeys(); const auth = crypto.randomBytes(16);
  return { name, ecdh, auth, sub: { endpoint: `${PUSH}/send/${name}`, keys: { p256dh: b64u(ecdh.getPublicKey()), auth: b64u(auth) } } }; };
// RFC 8291 decryption, written separately from the server's code.
const decrypt = (body, d) => {
  const salt = body.subarray(0, 16), idlen = body[20], asPublic = body.subarray(21, 21 + idlen), ct = body.subarray(21 + idlen);
  const hk = (ikm, salt2, info, n) => Buffer.from(crypto.hkdfSync('sha256', ikm, salt2, info, n));
  const ikm = hk(d.ecdh.computeSecret(asPublic), d.auth, Buffer.concat([Buffer.from('WebPush: info\0'), d.ecdh.getPublicKey(), asPublic]), 32);
  const dec = crypto.createDecipheriv('aes-128-gcm', hk(ikm, salt, Buffer.from('Content-Encoding: aes128gcm\0'), 16), hk(ikm, salt, Buffer.from('Content-Encoding: nonce\0'), 12));
  dec.setAuthTag(ct.subarray(-16));
  const pt = Buffer.concat([dec.update(ct.subarray(0, -16)), dec.final()]);
  return JSON.parse(pt.subarray(0, pt.lastIndexOf(2)).toString());
};
// RFC 8292: "vapid t=<JWT>, k=<public key>", signed ES256 by the server's key, for this push service.
const vapidOk = auth => {
  const m = /^vapid t=([^,]+), k=(\S+)$/.exec(auth || ''); if (!m) return 'no vapid header';
  const [h, c, s] = m[1].split('.'), k = Buffer.from(m[2], 'base64url');
  const key = crypto.createPublicKey({ key: { kty: 'EC', crv: 'P-256', x: b64u(k.subarray(1, 33)), y: b64u(k.subarray(33)) }, format: 'jwk' });
  if (!crypto.verify('sha256', Buffer.from(h + '.' + c), { key, dsaEncoding: 'ieee-p1363' }, Buffer.from(s, 'base64url'))) return 'bad signature';
  const claims = JSON.parse(Buffer.from(c, 'base64url'));
  return claims.aud === PUSH && claims.exp > Date.now() / 1000 && /^mailto:/.test(claims.sub) ? true : 'bad claims ' + JSON.stringify(claims);
};

(async () => {
  const got = []; // what the stand-in push service received
  const srv = http.createServer((req, res) => { const parts = []; req.on('data', p => parts.push(p)); req.on('end', () => {
    got.push({ path: req.url, headers: req.headers, body: Buffer.concat(parts) }); res.statusCode = /gone/.test(req.url) ? 410 : 201; res.end(); }); });
  await new Promise(r => srv.listen(8096, '127.0.0.1', r));
  const b = await chromium.launch(); const errs = [];
  const login = async (u, pw) => { const ctx = await b.newContext({ viewport: { width: 1280, height: 900 } }); const p = await ctx.newPage(); p.on('pageerror', e => errs.push(e.message));
    await p.goto(U + 'login.php'); await p.fill('[name=username]', u); await p.fill('[name=password]', pw); await p.click('button.primary'); await p.waitForLoadState(); return p; };
  const post = (p, data) => p.evaluate(d => { const f = new FormData(); f.append('csrf', window.CSRF); for (const k in d) f.append(k, d[k]);
    return fetch(window.BASE + 'push.php', { method: 'POST', body: f }).then(r => r.json()); }, data);
  const rahulId = sql("SELECT id FROM users WHERE username='rahul'"), anuId = sql("SELECT id FROM users WHERE username='anu'");
  sql(`DELETE FROM push_subs WHERE user_id IN (${rahulId}, ${anuId})`);
  sql("REPLACE INTO settings (k, v) VALUES ('morning_sent', CURDATE())"); // no morning summary in the middle of this test

  // the switch and the service worker
  const r = await login('rahul', 'pass1234');
  await r.goto(U + 'tasks.php');
  await r.waitForFunction(() => document.querySelector('[data-push-toggle]') && !document.querySelector('[data-push-toggle]').hidden, null, { timeout: 8000 }).catch(() => {});
  ok(/Notifications: off/.test(await r.locator('.me .menu [data-push-toggle]').textContent()), 'account menu has “🔕 Notifications: off”');
  ok(await r.locator('[data-push-banner]').isVisible(), 'My tasks shows the “Get a notification … Turn on” banner');
  const scope = await r.evaluate(() => navigator.serviceWorker.getRegistration().then(g => g && g.scope));
  ok(scope === U, 'service worker (sw.js) is installed for the whole app: ' + scope);
  ok((await r.evaluate(() => fetch(window.BASE + 'push.php').then(x => x.json()))).key.length === 87, 'server gives its public key');
  // the service worker turns a pushed message into a notification (headless Chromium can't display it, so record the call)
  const sw = r.context().serviceWorkers()[0] || await r.context().waitForEvent('serviceworker');
  await sw.evaluate(() => { self.__shown = []; Object.defineProperty(self.registration, 'showNotification', { value: (t, o) => { self.__shown.push([t, o.body, o.data.url, o.tag]); return Promise.resolve(); } }); });
  const cdp = await r.context().newCDPSession(r);
  await cdp.send('ServiceWorker.enable');
  const regId = await new Promise(res => { cdp.on('ServiceWorker.workerRegistrationUpdated', e => { const x = e.registrations.find(g => g.scopeURL === U); if (x) res(x.registrationId); }); });
  await cdp.send('ServiceWorker.deliverPushMessage', { origin: new URL(U).origin, registrationId: regId,
    data: JSON.stringify({ title: 'New task from Founder', body: 'Check the SW', url: '/tasks.php?id=7', tag: 'task-7' }) });
  let shown = [];
  for (let i = 0; i < 30 && !shown.length; i++) { await new Promise(z => setTimeout(z, 100)); shown = await sw.evaluate(() => self.__shown); }
  ok(shown.length === 1 && shown[0][0] === 'New task from Founder' && shown[0][1] === 'Check the SW' && shown[0][2] === '/tasks.php?id=7' && shown[0][3] === 'task-7', 'the service worker shows it as a notification: ' + JSON.stringify(shown));

  // this device saved for Rahul (as the browser would after “Allow”)
  const phone = device('rahul-phone'), anuPc = device('anu-pc'), gone = device('gone');
  ok((await post(r, { do: 'subscribe', sub: JSON.stringify(phone.sub) })).ok, 'Rahul’s phone saved');
  ok(!(await post(r, { do: 'subscribe', sub: JSON.stringify({ ...phone.sub, endpoint: 'https://evil.example.com/x' }) })).ok, 'an address that is not a push service is refused');
  ok((await post(r, { do: 'subscribe', sub: JSON.stringify({ ...gone.sub, endpoint: `${PUSH}/gone/1` }) })).ok, 'a second (old) device saved');
  ok(sql(`SELECT COUNT(*) FROM push_subs WHERE user_id = ${rahulId}`) === '2', 'two devices for Rahul');
  const n = await login('anu', 'pass1234');
  await post(n, { do: 'subscribe', sub: JSON.stringify(anuPc.sub) });

  // the admin gives Rahul a task → a notification on his phone
  got.length = 0;
  const a = await login('admin', 'admin12345');
  await a.goto(U + 'tasks.php?new=1');
  await a.fill('[name=title]', 'PUSHTEST: print 20 hoodies'); await a.selectOption('[name=assigned_to]', rahulId);
  await a.fill('[name=due_date]', '2026-12-05').catch(() => {});
  await a.click('form button.primary >> nth=0'); await a.waitForLoadState();
  const tid = sql("SELECT MAX(id) FROM tasks WHERE title='PUSHTEST: print 20 hoodies'");
  const toPhone = got.find(g => g.path === '/send/rahul-phone');
  ok(!!toPhone, 'a message went to Rahul’s phone');
  ok(toPhone && toPhone.headers['content-encoding'] === 'aes128gcm' && +toPhone.headers.ttl > 0, 'encrypted (aes128gcm) with a TTL');
  ok(toPhone && vapidOk(toPhone.headers.authorization) === true, 'signed by the server’s key for this push service: ' + (toPhone && vapidOk(toPhone.headers.authorization)));
  const msg = toPhone ? decrypt(toPhone.body, phone) : {};
  ok(msg.title === 'New task from Founder' && /PUSHTEST: print 20 hoodies/.test(msg.body) && new URL(msg.url, U).href === U + 'tasks.php?id=' + tid, 'the phone reads it: ' + JSON.stringify(msg));
  ok(!got.some(g => g.path === '/send/anu-pc'), 'nobody else is told');
  ok(sql(`SELECT COUNT(*) FROM push_subs WHERE endpoint LIKE '%/gone/%'`) === '0', 'a device the push service says is gone is removed');

  // given to Anu instead → Anu is told
  got.length = 0;
  await a.goto(U + 'tasks.php?id=' + tid);
  const res = await a.evaluate(([t, to]) => { const f = new FormData(); f.append('csrf', window.CSRF); f.append('do', 'prop'); f.append('ajax', '1'); f.append('task', t); f.append('key', 'assigned_to'); f.append('value', to);
    return fetch(location.pathname, { method: 'POST', body: f }).then(x => x.json()); }, [tid, anuId]);
  const toAnu = got.find(g => g.path === '/send/anu-pc');
  ok(res.ok && toAnu && decrypt(toAnu.body, anuPc).title === 'Task given to you from Founder', 'reassigned: Anu gets “Task given to you”');

  // the test button, and turning it off
  got.length = 0;
  ok((await post(r, { do: 'test' })).sent === 1 && decrypt(got[0].body, phone).title === '🔔 Notifications are on', '“Notifications are on” test message');
  await post(r, { do: 'unsubscribe', sub: JSON.stringify(phone.sub) });
  ok(sql(`SELECT COUNT(*) FROM push_subs WHERE user_id = ${rahulId}`) === '0', 'turning off removes the device');
  // the same device, now used by Anu: it moves to her
  await post(r, { do: 'subscribe', sub: JSON.stringify(phone.sub) });
  await post(n, { do: 'subscribe', sub: JSON.stringify(phone.sub) });
  ok(sql(`SELECT COUNT(*) FROM push_subs WHERE user_id = ${rahulId}`) === '0' && sql(`SELECT COUNT(*) FROM push_subs WHERE user_id = ${anuId}`) === '2', 'a device moves to whoever logs in on it');

  // orders waiting to ship (over 24 h, not shipped): a notification with the number for the production team
  sql(`DELETE FROM push_subs WHERE user_id IN (${rahulId}, ${anuId})`);
  await post(r, { do: 'subscribe', sub: JSON.stringify(phone.sub) });
  const waiting = () => +sql("SELECT COUNT(*) FROM orders WHERE deleted_at IS NULL AND shipped = 0 AND created_at <= NOW() - INTERVAL 24 HOUR");
  const mkOrder = h => sql(`INSERT INTO orders (customer_id, due_date, created_at) VALUES ('SHIPTEST', CURDATE(), NOW() - INTERVAL ${h} HOUR); SELECT LAST_INSERT_ID()`);
  sql("REPLACE INTO settings (k, v) VALUES ('backup_cron_key', 'testkey'), ('ship_alert_last', '0'), ('ship_alert_at', '2000-01-01 00:00:00')");
  mkOrder(30); mkOrder(2);
  const n0 = waiting();
  got.length = 0;
  const cron = () => fetch(U + 'alerts_cron.php?key=testkey').then(x => x.text()); // not execSync: the stand-in push service must keep answering
  ok((await (await fetch(U + 'alerts_cron.php?key=wrong')).status) === 403, 'the scheduled job needs the key');
 ok(new RegExp('^' + n0 + ' orders waiting').test(await cron()), 'scheduled job counts ' + n0 + ' orders waiting (the 2-hour-old one is not counted yet)');
  const alert = got.find(g => g.path === '/send/rahul-phone');
  const am = alert ? decrypt(alert.body, phone) : {};
  ok(am.badge === n0 && am.title === `📦 ${n0} order${n0 === 1 ? '' : 's'} waiting to ship` && am.tag === 'ship-waiting', 'production team gets “📦 N orders waiting to ship” with the number: ' + JSON.stringify(am));
  got.length = 0; await cron();
  ok(got.length === 0, 'same number: no second notification');
  mkOrder(26); got.length = 0; await cron();
  const am2 = got[0] ? decrypt(got[0].body, phone) : {};
  ok(am2.badge === n0 + 1 && /1 more/.test(am2.body), 'one more order passes 24 hours: notified again with ' + (n0 + 1));
  // the number in the app (footer) for production staff only
  await r.goto(U + 'orders.php');
  ok(await r.evaluate(() => window.SHIP_WAITING) === n0 + 1, 'Rahul’s app sets the icon number to ' + (n0 + 1));
  await a.goto(U + 'orders.php');
  ok(await a.evaluate(() => window.SHIP_WAITING) === undefined, 'the admin gets no icon number');
  // the service worker puts the number on the icon when the notification comes
  await sw.evaluate(() => { self.__badge = null; Object.defineProperty(self.navigator, 'setAppBadge', { value: n => { self.__badge = n; return Promise.resolve(); }, configurable: true }); });
  await cdp.send('ServiceWorker.deliverPushMessage', { origin: new URL(U).origin, registrationId: regId, data: JSON.stringify({ title: '📦 4 orders waiting to ship', badge: 4, tag: 'ship-waiting' }) });
  let badge = null; for (let i = 0; i < 30 && badge === null; i++) { await new Promise(z => setTimeout(z, 100)); badge = await sw.evaluate(() => self.__badge); }
  ok(badge === 4, 'service worker sets the app icon number to 4: ' + badge);
  // 9 am summary: ship today, print today, delayed (counted separately here)
  const addOrder = (due, items) => { const id = sql(`INSERT INTO orders (customer_id, due_date, created_at) VALUES ('SHIPTEST', ${due}, NOW()); SELECT LAST_INSERT_ID()`);
    items.forEach(([q, printed, plain]) => sql(`INSERT INTO order_items (order_id, item_type, plain, printed, quantity, extra, created_at) VALUES (${id}, '${plain ? 'plain' : 'print'}', ${plain}, ${printed}, ${q}, '{}', NOW())`)); };
  addOrder('CURDATE()', [[5, 0, 0], [2, 1, 0]]); addOrder('CURDATE()', [[3, 0, 1]]); addOrder('CURDATE() - INTERVAL 2 DAY', [[4, 0, 0]]);
  const live = "o.deleted_at IS NULL AND o.shipped = 0", pcs = "(SELECT IFNULL(SUM(quantity), 0) FROM order_items it WHERE it.order_id = o.id AND it.item_type <> 'dtf_roll')";
  const [shipN, shipP] = sql(`SELECT COUNT(*), IFNULL(SUM(${pcs}), 0) FROM orders o WHERE ${live} AND o.due_date = CURDATE()`).split('\t').map(Number);
  const [lateN, lateP] = sql(`SELECT COUNT(*), IFNULL(SUM(${pcs}), 0) FROM orders o WHERE ${live} AND o.due_date < CURDATE()`).split('\t').map(Number);
  const printP = +sql(`SELECT IFNULL(SUM(it.quantity), 0) FROM order_items it JOIN orders o ON o.id = it.order_id WHERE ${live} AND o.due_date <= CURDATE() AND it.plain = 0 AND it.printed = 0 AND it.item_type <> 'dtf_roll'`);
  got.length = 0;
  ok(/Morning summary sent/.test(await fetch(U + 'alerts_cron.php?key=testkey&morning=1').then(x => x.text())), 'morning summary sent');
  const mm = got.find(g => g.path === '/send/rahul-phone'), mb = mm ? decrypt(mm.body, phone) : {};
  const s = (n, a, b2) => n + ' ' + (n === 1 ? a : b2);
  const want = `Ship today: ${s(shipN, 'order', 'orders')} (${s(shipP, 'T-shirt', 'T-shirts')})\nPrint today: ${s(printP, 'T-shirt', 'T-shirts')}\n` + (lateN ? `⚠ Delayed: ${s(lateN, 'order', 'orders')} (${s(lateP, 'T-shirt', 'T-shirts')})` : '✓ No delayed orders');
  ok(mb.title === '☀️ Good morning — today’s work' && mb.body === want, 'production team’s phone: ' + JSON.stringify(mb.body) + (mb.body === want ? '' : ' wanted ' + JSON.stringify(want)));
  ok(shipP >= 10 && printP >= 9 && lateP >= 4, `counts include the test orders (ship ${shipP} pcs, print ${printP}, late ${lateP} pcs; plain and printed pieces not “to print”)`);
  got.length = 0;
  ok(/not now/.test(await cron()) && !got.some(g => /Good morning/.test(decrypt(g.body, phone).title || '')), 'only once a day');
  sql("UPDATE orders SET deleted_at = NOW() WHERE customer_id = 'SHIPTEST'; DELETE FROM settings WHERE k IN ('backup_cron_key')");

  sql(`DELETE FROM tasks WHERE title='PUSHTEST: print 20 hoodies'; DELETE FROM push_subs WHERE user_id IN (${rahulId}, ${anuId})`);
  ok(!errs.length, 'no JS errors ' + errs.join('|'));
  await b.close(); srv.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
