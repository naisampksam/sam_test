'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');

// By default these tests run the Node.js server. Set TEST_BASE_URL to run the
// same tests against another copy of the API instead, e.g. the WordPress
// plugin: TEST_BASE_URL=http://localhost:8080/attendance (with fresh data and
// no staff seed).
const REMOTE = process.env.TEST_BASE_URL;
let server = null;
if (!REMOTE) {
  process.env.DATA_DIR = fs.mkdtempSync(path.join(os.tmpdir(), 'looma-test-'));
  process.env.SEED_DIR = path.join(process.env.DATA_DIR, 'no-seed');
  server = require('../server');
}

let base = REMOTE;
// A tiny cookie jar, so the admin session and the approved-computer key both stick.
function makeJar() {
  const jar = {};
  return {
    header: () => Object.entries(jar).map(([k, v]) => `${k}=${v}`).join('; '),
    take(res) {
      for (const sc of res.headers.getSetCookie()) {
        const [pair, ...attrs] = sc.split(';');
        const i = pair.indexOf('=');
        const name = pair.slice(0, i).trim();
        const value = pair.slice(i + 1).trim();
        const expired = attrs.some((x) => /max-age=0\b/i.test(x.trim())) || value === '' || value === 'deleted';
        if (expired) delete jar[name]; else jar[name] = value;
      }
    },
    clear() { for (const k of Object.keys(jar)) delete jar[k]; },
    has: (name) => name in jar,
  };
}
const cookies = makeJar();
async function call(method, url, body, useCookie = true, jar = cookies) {
  const cookie = useCookie ? jar.header() : '';
  const res = await fetch(base + url, {
    method,
    headers: { 'Content-Type': 'application/json', ...(cookie ? { Cookie: cookie } : {}) },
    body: body ? JSON.stringify(body) : undefined,
  });
  if (useCookie) jar.take(res);
  return { status: res.status, data: await res.json() };
}

test.before(() => REMOTE || new Promise((r) => server.listen(0, '127.0.0.1', () => { base = `http://127.0.0.1:${server.address().port}`; r(); })));
test.after(() => { if (server) { server.close(); fs.rmSync(process.env.DATA_DIR, { recursive: true, force: true }); } });

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

  // manual entries only count once the admin approves them
  const waiting = (await call('GET', '/api/admin/manual-entries')).data.pending.filter((x) => x.employeeId === emp.id);
  assert.equal(waiting.length, 2);
  let early = (await call('GET', '/api/admin/salary?month=2026-01')).data.rows.find((r) => r.id === emp.id);
  assert.equal(early, undefined); // no counted hours in January yet
  for (const w of waiting) assert.equal((await call('POST', `/api/admin/sessions/${w.id}/approve`, {})).data.status, 'approved');

  // an earlier day left without a clock-out is flagged with its date
  await call('POST', '/api/admin/sessions', { employeeId: emp.id, date: '2026-01-07', in: '09:00' });
  const flagged = (await call('GET', '/api/admin/today')).data.employees.find((x) => x.id === emp.id);
  assert.equal(flagged.staleOpen, true);
  assert.deepEqual(flagged.staleDates, ['2026-01-07']);

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
  assert.equal(m.totalIncentive, 2000); // only person with hours in January

  // admin can switch the incentive off for a person
  const off = await call('PUT', `/api/admin/employees/${emp.id}`, { incentive: false });
  assert.equal(off.data.incentive, false);
  const sal2 = (await call('GET', '/api/admin/salary?month=2026-01')).data;
  assert.equal(sal2.rows.find((r) => r.id === emp.id).totalIncentive, 0);
  assert.equal(sal2.undistributed, 2000);

  // clearing attendance keeps employees
  const before = (await call('GET', '/api/admin/employees')).data.length;
  assert.equal((await call('POST', '/api/admin/clear-data', { password: 'wrong' })).status, 401);
  const cleared = await call('POST', '/api/admin/clear-data', { password: 'secret1' });
  assert.equal(cleared.status, 200);
  assert.ok(cleared.data.backup);
  if (!REMOTE) assert.ok(fs.existsSync(path.join(process.env.DATA_DIR, cleared.data.backup)));
  assert.equal((await call('GET', '/api/admin/employees')).data.length, before);
  const after = (await call('GET', '/api/admin/salary?month=2026-01')).data;
  assert.equal(after.totals.workedHours, 0);
  assert.equal(after.totalSales, 0);
  assert.equal((await call('GET', '/api/admin/leaves?month=2026-01')).data.length, 0);
});

test('staff request planned leave; admin approves or rejects', async () => {
  const emp = (await call('POST', '/api/admin/employees', { name: 'Sara', position: 'Sales', basicSalary: 26000, pin: '4321' })).data;
  const { today } = (await call('GET', '/api/public/status', null, false)).data;
  // pick future working days in next month
  const [y, m] = today.split('-').map(Number);
  const next = new Date(Date.UTC(y, m, 1));
  const days = [];
  for (let d = 3; days.length < 3; d++) { // from the 3rd: always at least 2 days ahead
    const dt = new Date(Date.UTC(next.getUTCFullYear(), next.getUTCMonth(), d));
    if (dt.getUTCDay() !== 0) days.push(dt.toISOString().slice(0, 10));
  }
  const sunday = (() => { for (let d = 1; ; d++) { const dt = new Date(Date.UTC(next.getUTCFullYear(), next.getUTCMonth(), d)); if (dt.getUTCDay() === 0) return dt.toISOString().slice(0, 10); } })();
  const req = (body) => call('POST', '/api/leave-requests', { employeeId: emp.id, pin: '4321', reason: 'Family function', ...body }, false);

  assert.equal((await req({ dates: days, pin: '0000' })).status, 401);
  assert.equal((await req({ dates: ['2020-01-02'] })).status, 400); // past
  // planned leave needs 2 days' notice
  const addDays = (date, n) => { const [a, b, c] = date.split('-').map(Number); return new Date(Date.UTC(a, b - 1, c + n)).toISOString().slice(0, 10); };
  const short = await req({ dates: [addDays(today, 1)] });
  assert.equal(short.status, 400);
  assert.match(short.data.error, /2 days/);
  const cal0 = (await call('POST', '/api/my/leave', { employeeId: emp.id, pin: '4321' }, false)).data;
  assert.equal(cal0.earliest, addDays(today, 2));
  assert.equal((await req({ dates: [sunday] })).status, 400); // weekly off
  assert.equal((await req({ dates: days, reason: '' })).status, 400);
  const r1 = await req({ dates: days.slice(0, 2) });
  assert.equal(r1.status, 200);
  assert.equal(r1.data.status, 'pending');
  assert.equal((await req({ dates: [days[1]] })).status, 400); // already requested
  const r2 = (await req({ dates: [days[2]], portion: 0.5 })).data;

  // staff can see and cancel their own pending request
  const mine = (await call('POST', '/api/my/leave', { employeeId: emp.id, pin: '4321', month: days[0].slice(0, 7) }, false)).data;
  assert.equal(mine.requests.length, 2);
  assert.equal(mine.days[days[0]], 'pending');
  assert.equal((await call('POST', `/api/leave-requests/${r2.id}/cancel`, { employeeId: emp.id, pin: '4321' }, false)).data.status, 'cancelled');

  // admin: pending listed first; approve turns it into leave days
  assert.equal((await call('POST', `/api/admin/leave-requests/${r1.data.id}/approve`, {}, false)).status, 401);
  const list = (await call('GET', '/api/admin/leave-requests')).data;
  assert.equal(list[0].id, r1.data.id);
  assert.equal((await call('POST', `/api/admin/leave-requests/${r1.data.id}/approve`, { note: 'OK' })).data.status, 'approved');
  const leaves = (await call('GET', `/api/admin/leaves?month=${days[0].slice(0, 7)}`)).data.filter((l) => l.employeeId === emp.id);
  assert.deepEqual(leaves.map((l) => l.date), days.slice(0, 2));
  assert.equal((await call('POST', `/api/admin/leave-requests/${r1.data.id}/reject`, {})).status, 400);

  const r3 = (await req({ dates: [days[2]] })).data;
  assert.equal((await call('POST', `/api/admin/leave-requests/${r3.id}/reject`, { note: 'Busy week' })).data.status, 'rejected');
  assert.equal((await call('GET', `/api/admin/leaves?month=${days[0].slice(0, 7)}`)).data.filter((l) => l.employeeId === emp.id).length, 2);
});

test('the staff page can be limited to approved computers', async () => {
  const emp = (await call('POST', '/api/admin/employees', { name: 'Kiosk Test', basicSalary: 1000 })).data;
  let st = (await call('GET', '/api/admin/devices')).data;
  assert.equal(st.restrict, false);
  assert.equal(st.currentApproved, false);

  // switched off: anyone can use the staff page
  assert.equal((await call('GET', '/api/public/status', null, false)).status, 200);

  await call('PUT', '/api/admin/settings', { restrictDevices: true });
  const blocked = await call('GET', '/api/public/status', null, false);
  assert.equal(blocked.status, 403);
  assert.equal(blocked.data.code, 'device_not_approved');
  assert.equal((await call('POST', '/api/punch', { employeeId: emp.id, action: 'in' }, false)).status, 403);

  // approve this "computer" (the admin's cookie jar)
  st = (await call('POST', '/api/admin/devices', { name: 'Front desk' })).data;
  assert.equal(st.currentApproved, true);
  assert.equal(st.devices.length, 1);
  assert.ok(cookies.has('looma_device'));
  assert.equal((await call('GET', '/api/public/status')).status, 200);
  assert.equal((await call('POST', '/api/punch', { employeeId: emp.id, action: 'in' })).status, 200);
  assert.ok((await call('GET', '/api/admin/devices')).data.devices[0].lastSeen);

  // approving the same computer again only renames it
  st = (await call('POST', '/api/admin/devices', { name: 'Front desk PC' })).data;
  assert.equal(st.devices.length, 1);
  assert.equal(st.devices[0].name, 'Front desk PC');

  // another computer, even with the admin logged in, is still refused
  const other = makeJar();
  assert.equal((await call('POST', '/api/admin/login', { password: 'secret1' }, true, other)).status, 200);
  assert.equal((await call('GET', '/api/public/status', null, true, other)).status, 403);

  // removing the approval blocks this computer again
  assert.equal((await call('DELETE', `/api/admin/devices/${st.devices[0].id}`)).data.devices.length, 0);
  assert.equal((await call('GET', '/api/public/status')).status, 403);

  await call('PUT', '/api/admin/settings', { restrictDevices: false });
  assert.equal((await call('GET', '/api/public/status', null, false)).status, 200);
});

test('manual entries need the admin\'s approval before they count', async () => {
  const emp = (await call('POST', '/api/admin/employees', { name: 'Approval Test', basicSalary: 9000, pin: '2468' })).data;
  const add = (b) => call('POST', '/api/manual', { employeeId: emp.id, pin: '2468', date: '2026-02-02', ...b }, false);
  assert.equal((await add({ in: '09:00', out: '13:00' })).data.pending, true);
  assert.equal((await add({ in: '12:00', out: '14:00' })).status, 400); // overlaps the pending entry
  assert.equal((await add({ in: '14:00', out: '18:00', note: 'forgot to clock in' })).status, 200);

  const mine = async () => (await call('POST', '/api/my', { employeeId: emp.id, pin: '2468', month: '2026-02' }, false)).data;
  let m = await mine();
  assert.equal(m.totalMinutes, 0);
  assert.deepEqual(m.manual.map((x) => x.status), ['pending', 'pending']);

  const [first, second] = (await call('GET', '/api/admin/manual-entries')).data.pending.filter((x) => x.employeeId === emp.id);
  assert.equal(second.note, 'forgot to clock in');
  assert.equal((await call('POST', `/api/admin/sessions/${first.id}/approve`, {}, false)).status, 401); // admin only
  await call('POST', `/api/admin/sessions/${first.id}/approve`, {});
  await call('POST', `/api/admin/sessions/${second.id}/reject`, { note: 'Was on leave that afternoon' });
  assert.equal((await call('POST', `/api/admin/sessions/${second.id}/approve`, {})).status, 400); // already decided

  m = await mine();
  assert.equal(m.totalMinutes, 240); // only the approved 4 hours
  assert.deepEqual(m.manual.map((x) => x.status), ['approved', 'rejected']);
  assert.equal(m.manual[1].adminNote, 'Was on leave that afternoon');

  // a rejected entry no longer blocks that time
  assert.equal((await add({ in: '14:30', out: '17:00' })).status, 200);
  const decided = (await call('GET', '/api/admin/manual-entries')).data.decided;
  assert.ok(decided.some((x) => x.id === second.id && x.status === 'rejected'));
});

test('holidays: set per month, change working days, block leave on those days', async () => {
  const emp = (await call('POST', '/api/admin/employees', { name: 'Holiday Test', basicSalary: 26000 })).data;
  const { today } = (await call('GET', '/api/public/status', null, false)).data;
  const [y, m] = today.split('-').map(Number);
  const next = new Date(Date.UTC(y, m, 1));
  const month = next.toISOString().slice(0, 7);
  let day = null; // first working day (not Sunday) of next month
  for (let d = 1; !day; d++) {
    const dt = new Date(Date.UTC(next.getUTCFullYear(), next.getUTCMonth(), d));
    if (dt.getUTCDay() !== 0) day = dt.toISOString().slice(0, 10);
  }
  const before = (await call('GET', `/api/admin/holidays?month=${month}`)).data;
  assert.deepEqual(before.holidays, []);

  assert.equal((await call('POST', '/api/admin/holidays', { date: day, name: '' })).status, 400);
  const h = await call('POST', '/api/admin/holidays', { date: day, name: 'Onam' });
  assert.equal(h.status, 200);
  assert.equal((await call('POST', '/api/admin/holidays', { date: day, name: 'Again' })).status, 400);

  const after = (await call('GET', `/api/admin/holidays?month=${month}`)).data;
  assert.equal(after.holidays.length, 1);
  assert.equal(after.defaultWorkingDays, before.defaultWorkingDays - 1);
  const sal = (await call('GET', `/api/admin/salary?month=${month}`)).data;
  assert.equal(sal.defaultWorkingDays, before.defaultWorkingDays - 1);

  // staff can't request leave on a holiday; the calendar shows it
  const req = await call('POST', '/api/leave-requests', { employeeId: emp.id, dates: [day], reason: 'Trip' }, false);
  assert.equal(req.status, 400);
  assert.match(req.data.error, /holiday/);
  const cal = (await call('POST', '/api/my/leave', { employeeId: emp.id, month }, false)).data;
  assert.deepEqual(cal.holidays, [{ date: day, name: 'Onam' }]);

  assert.equal((await call('DELETE', `/api/admin/holidays/${h.data.id}`)).status, 200);
  assert.equal((await call('GET', `/api/admin/holidays?month=${month}`)).data.defaultWorkingDays, before.defaultWorkingDays);
  assert.equal((await call('DELETE', `/api/admin/holidays/${h.data.id}`)).status, 404);
});

test('staff add company expenses; approved ones are paid back with the salary; salary paid tick', async () => {
  const month = '2025-04';
  const emp = (await call('POST', '/api/admin/employees', { name: 'Exp Test', basicSalary: 25000, joinedOn: '2025-01-01', pin: '1357', phone: '98765 43210', email: 'exp@example.com' })).data;
  assert.equal(emp.phone, '98765 43210');
  assert.equal((await call('PUT', `/api/admin/employees/${emp.id}`, { phone: '12ab' })).status, 400);
  assert.equal((await call('PUT', `/api/admin/employees/${emp.id}`, { email: 'nope' })).status, 400);
  // contact details never reach the staff page
  const pub = (await call('GET', '/api/public/status', null, false)).data.employees.find((x) => x.id === emp.id);
  assert.equal(pub.phone, undefined);
  assert.equal(pub.email, undefined);

  const add = (b, pin = '1357') => call('POST', '/api/expenses', { employeeId: emp.id, pin, ...b }, false);
  assert.equal((await add({ date: `${month}-05`, description: 'Courier', amount: 250 }, '0000')).status, 401);
  assert.equal((await add({ date: '2999-01-01', description: 'Future', amount: 10 })).status, 400);
  assert.equal((await add({ date: `${month}-05`, description: '', amount: 10 })).status, 400);
  assert.equal((await add({ date: `${month}-05`, description: 'Zero', amount: 0 })).status, 400);
  const a = (await add({ date: `${month}-05`, description: 'Courier', amount: 250 })).data;
  const b = (await add({ date: `${month}-09`, description: 'Fabric samples', amount: 1200.5 })).data;
  const c = (await add({ date: `${month}-12`, description: 'Taxi', amount: 300 })).data;
  assert.equal(a.status, 'pending');

  let mine = (await call('POST', '/api/my/expenses', { employeeId: emp.id, pin: '1357', month }, false)).data;
  assert.equal(mine.items.length, 3);
  assert.equal(mine.pendingTotal, 1750.5);
  assert.equal(mine.approvedTotal, 0);

  // pending expenses don't count yet
  let row = (await call('GET', `/api/admin/salary?month=${month}`)).data.rows.find((r) => r.id === emp.id);
  assert.equal(row.expenses, 0);
  assert.equal(row.phone, '98765 43210');

  const list = (await call('GET', `/api/admin/expenses?month=${month}`)).data;
  assert.ok(list.pending.some((x) => x.id === a.id));
  assert.equal((await call('POST', `/api/admin/expenses/${a.id}/approve`, {})).data.status, 'approved');
  assert.equal((await call('POST', `/api/admin/expenses/${b.id}/approve`, {})).data.status, 'approved');
  assert.equal((await call('POST', `/api/admin/expenses/${b.id}/reject`, {})).status, 400); // already decided
  const rej = (await call('POST', `/api/admin/expenses/${c.id}/reject`, { note: 'No bill' })).data;
  assert.equal(rej.adminNote, 'No bill');
  // staff can only remove expenses still waiting
  assert.equal((await call('POST', `/api/expenses/${a.id}/cancel`, { employeeId: emp.id, pin: '1357' }, false)).status, 400);
  const d = (await add({ date: `${month}-15`, description: 'Oops', amount: 99 })).data;
  assert.equal((await call('POST', `/api/expenses/${d.id}/cancel`, { employeeId: emp.id, pin: '1357' }, false)).status, 200);
  // admin can add one directly (approved straight away)
  const e = (await call('POST', '/api/admin/expenses', { employeeId: emp.id, date: `${month}-20`, description: 'Stationery', amount: 49.5 })).data;
  assert.equal(e.status, 'approved');

  mine = (await call('POST', '/api/my/expenses', { employeeId: emp.id, pin: '1357', month }, false)).data;
  assert.equal(mine.approvedTotal, 1500);
  assert.equal(mine.items.find((x) => x.id === c.id).status, 'rejected');

  const sal = (await call('GET', `/api/admin/salary?month=${month}`)).data;
  row = sal.rows.find((r) => r.id === emp.id);
  assert.equal(row.expenses, 1500);
  assert.equal(row.expenseItems.length, 3);
  assert.equal(row.payable, Math.round((row.netPay + 1500) * 100) / 100);
  assert.equal(row.paidAt, null);
  assert.ok(sal.totals.payable >= row.payable);

  // salary paid tick
  const paid = (await call('POST', `/api/admin/salary/${month}/${emp.id}/paid`, { paid: true })).data;
  assert.ok(paid.paidAt);
  assert.ok((await call('GET', `/api/admin/salary?month=${month}`)).data.rows.find((r) => r.id === emp.id).paidAt);
  await call('POST', `/api/admin/salary/${month}/${emp.id}/paid`, { paid: false });
  assert.equal((await call('GET', `/api/admin/salary?month=${month}`)).data.rows.find((r) => r.id === emp.id).paidAt, null);

  assert.equal((await call('DELETE', `/api/admin/expenses/${e.id}`)).status, 200);
  assert.equal((await call('DELETE', `/api/admin/expenses/${e.id}`)).status, 404);
});

test('a working day with no attendance and no leave applied counts as unplanned leave', async () => {
  const st = (await call('GET', '/api/admin/settings')).data;
  assert.equal(st.sickLeaveFrom, st.today); // starts on the day this version first ran
  const month = '2025-05';
  const emp = (await call('POST', '/api/admin/employees', { name: 'Sick Test', basicSalary: 26000, joinedOn: '2025-01-01' })).data;
  await call('POST', '/api/admin/sessions', { employeeId: emp.id, date: `${month}-02`, in: '09:00', out: '18:00' });
  await call('POST', '/api/admin/leaves', { employeeId: emp.id, date: `${month}-05`, portion: 1 });
  // before the start date nothing changes
  let e = (await call('GET', `/api/admin/attendance?month=${month}`)).data.employees.find((x) => x.id === emp.id);
  assert.equal(e.sickDays, 0);

  assert.equal((await call('PUT', '/api/admin/settings', { sickLeaveFrom: 'x' })).status, 400);
  await call('PUT', '/api/admin/settings', { sickLeaveFrom: `${month}-01` });
  e = (await call('GET', `/api/admin/attendance?month=${month}`)).data.employees.find((x) => x.id === emp.id);
  // May 2025: 31 days, 4 Sundays = 27 working days; 1 present, 1 leave -> 25 sick
  assert.equal(e.sickDays, 25);
  assert.equal(e.leaveDays, 26);
  assert.equal(e.days[`${month}-01`].sick, true);
  assert.equal(e.days[`${month}-04`], undefined); // Sunday
  const my = (await call('POST', '/api/my', { employeeId: emp.id, month }, false)).data;
  assert.equal(my.days.find((x) => x.date === `${month}-01`).sick, true);

  await call('PUT', '/api/admin/settings', { sickLeaveFrom: '' });
  e = (await call('GET', `/api/admin/attendance?month=${month}`)).data.employees.find((x) => x.id === emp.id);
  assert.equal(e.sickDays, 0);
  await call('PUT', '/api/admin/settings', { sickLeaveFrom: st.sickLeaveFrom });
});
