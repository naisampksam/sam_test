'use strict';

// Runs only when TEST_DATABASE_URL points at a PostgreSQL database that may
// be wiped, e.g. TEST_DATABASE_URL=postgres://user:pass@localhost/looma_test
const test = require('node:test');
const assert = require('node:assert/strict');
const path = require('path');
const { spawn } = require('child_process');

const url = process.env.TEST_DATABASE_URL;
const PORT = 3990;

function start() {
  return new Promise((resolve, reject) => {
    const child = spawn(process.execPath, [path.join(__dirname, '..', 'server.js')], {
      env: { ...process.env, DATABASE_URL: url, PORT: String(PORT), HOST: '127.0.0.1' },
    });
    child.stdout.on('data', (d) => { if (/running on/.test(d)) resolve(child); });
    child.stderr.on('data', (d) => process.stderr.write(d));
    child.on('exit', (code) => reject(new Error('server exited ' + code)));
  });
}
function stop(child) {
  return new Promise((resolve) => { child.removeAllListeners('exit'); child.on('exit', resolve); child.kill('SIGTERM'); });
}

test('data survives a restart when stored in PostgreSQL', { skip: !url && 'TEST_DATABASE_URL not set' }, async () => {
  const { Pool } = require('pg');
  const pool = new Pool({ connectionString: url });
  await pool.query('DROP TABLE IF EXISTS app_state');

  let cookie = '';
  const call = async (method, u, body) => {
    const r = await fetch(`http://127.0.0.1:${PORT}${u}`, {
      method, headers: { 'Content-Type': 'application/json', ...(cookie ? { Cookie: cookie } : {}) },
      body: body ? JSON.stringify(body) : undefined,
    });
    const sc = r.headers.get('set-cookie');
    if (sc) cookie = sc.split(';')[0];
    return { status: r.status, data: await r.json() };
  };

  let srv = await start();
  // staff from seed/ are loaded on first start
  const status = (await call('GET', '/api/public/status')).data;
  assert.ok(status.employees.length >= 1);
  await call('POST', '/api/admin/setup', { password: 'secret1' });
  const emp = status.employees[0];
  await call('POST', '/api/punch', { employeeId: emp.id, action: 'in' });
  await stop(srv);

  srv = await start();
  cookie = '';
  assert.equal((await call('GET', '/api/admin/state')).data.setupRequired, false);
  assert.equal((await call('POST', '/api/admin/login', { password: 'secret1' })).status, 200);
  const after = (await call('GET', '/api/public/status')).data;
  assert.equal(after.employees.find((e) => e.id === emp.id).status, 'in');
  await stop(srv);

  const { rows } = await pool.query("SELECT count(*)::int AS n FROM app_state WHERE key = 'main'");
  assert.equal(rows[0].n, 1);
  await pool.query('DROP TABLE IF EXISTS app_state');
  await pool.end();
});
