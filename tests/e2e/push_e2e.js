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

  sql(`DELETE FROM tasks WHERE title='PUSHTEST: print 20 hoodies'; DELETE FROM push_subs WHERE user_id IN (${rahulId}, ${anuId})`);
  ok(!errs.length, 'no JS errors ' + errs.join('|'));
  await b.close(); srv.close(); console.log(fails ? fails + ' FAILED' : 'ALL PASSED');
})().catch(e => { console.error(e); process.exit(1); });
