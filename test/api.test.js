'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');

process.env.DATA_DIR = fs.mkdtempSync(path.join(os.tmpdir(), 'looma-test-'));
process.env.SEED_DIR = path.join(process.env.DATA_DIR, 'no-seed');
const server = require('../server');

let base;
let cookie = '';
async function call(method, url, body, useCookie = true) {
  const res = await fetch(base + url, {
    method,
    headers: { 'Content-Type': 'application/json', ...(useCookie && cookie ? { Cookie: cookie } : {}) },
    body: body ? JSON.stringify(body) : undefined,
  });
  const sc = res.headers.get('set-cookie');
  if (sc) cookie = sc.split(';')[0];
  return { status: res.status, data: await res.json() };
}

test.before(() => new Promise((r) => server.listen(0, '127.0.0.1', () => { base = `http://127.0.0.1:${server.address().port}`; r(); })));
test.after(() => { server.close(); fs.rmSync(process.env.DATA_DIR, { recursive: true, force: true }); });

test('admin endpoints require a password; clock in/out works', async () => {
  assert.equal((await call('GET', '/api/admin/employees')).status, 401);
  assert.equal((await call('GET', '/api/admin/state')).data.setupRequired, true);
  assert.equal((await call('POST', '/api/admin/setup', { password: 'secret1' })).status, 200);
  assert.equal((await call('POST', '/api/admin/setup', { password: 'another' })).status, 403);

  const emp = (await call('POST', '/api/admin/employees', { name: 'Anu', position: 'Sales', basicSalary: 20000 })).data;
  const pinned = (await call('POST', '/api/admin/employees', { name: 'Ravi', position: 'Store', basicSalary: 18000, pin: '1234' })).data;
  assert.equal(pinned.hasPin, true);

  // logged-out requests are rejected
  await call('POST', '/api/admin/logout');
  assert.equal((await call('GET', '/api/admin/employees')).status, 401);
  assert.equal((await call('POST', '/api/admin/login', { password: 'wrong' })).status, 401);
  assert.equal((await call('POST', '/api/admin/login', { password: 'secret1' })).status, 200);

  assert.equal((await call('POST', '/api/punch', { employeeId: emp.id, action: 'in' })).data.status, 'in');
  assert.equal((await call('POST', '/api/punch', { employeeId: emp.id, action: 'in' })).status, 400);
  assert.equal((await call('POST', '/api/punch', { employeeId: emp.id, action: 'out' })).data.status, 'out');

  assert.equal((await call('POST', '/api/punch', { employeeId: pinned.id, action: 'in', pin: '0000' })).status, 401);
  assert.equal((await call('POST', '/api/punch', { employeeId: pinned.id, action: 'in', pin: '1234' })).status, 200);

  const status = (await call('GET', '/api/public/status', null, false)).data;
  assert.equal(status.employees.length, 2);
  assert.ok(!('basicSalary' in status.employees[0]), 'salary must not be public');
});

test('manual entries, leaves and salary', async () => {
  const emp = (await call('POST', '/api/admin/employees', { name: 'Meera', position: 'Design', basicSalary: 24000 })).data;
  const ok = await call('POST', '/api/manual', { employeeId: emp.id, date: '2026-01-05', in: '09:00', out: '18:00' });
  assert.equal(ok.status, 200);
  const overlap = await call('POST', '/api/manual', { employeeId: emp.id, date: '2026-01-05', in: '17:00', out: '19:00' });
  assert.equal(overlap.status, 400);
  assert.equal((await call('POST', '/api/manual', { employeeId: emp.id, date: '2026-01-06', in: '18:00', out: '09:00' })).status, 400);
  await call('POST', '/api/manual', { employeeId: emp.id, date: '2026-01-05', in: '19:00', out: '21:00' });

  const lv = await call('POST', '/api/admin/leaves', { employeeId: emp.id, date: '2026-01-10', toDate: '2026-01-12', portion: 1 });
  assert.equal(lv.data.length, 2); // Jan 11 2026 is a Sunday

  await call('PUT', '/api/admin/months/2026-01', { workingDays: 24, totalSales: 200000 });
  const sal = (await call('GET', '/api/admin/salary?month=2026-01')).data;
  const m = sal.rows.find((r) => r.id === emp.id);
  assert.equal(m.workedHours, 11);
  assert.equal(m.leaveDays, 2);
  assert.equal(m.requiredHours, 198);
  assert.equal(m.leaveDeduction, 2000);
  assert.equal(sal.pool, 2000);
  assert.equal(m.hoursIncentive, 1500); // only person with hours in January

  // admin can switch the incentive off for a person
  const off = await call('PUT', `/api/admin/employees/${emp.id}`, { incentive: false });
  assert.equal(off.data.incentive, false);
  const sal2 = (await call('GET', '/api/admin/salary?month=2026-01')).data;
  assert.equal(sal2.rows.find((r) => r.id === emp.id).totalIncentive, 0);
  assert.equal(sal2.undistributed, 2000);
});
