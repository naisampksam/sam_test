'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { computeSalary, defaultWorkingDays, attendanceSummary, sessionMinutes } = require('../lib/calc');

const settings = { hoursPerDay: 9, incentivePercent: 1, workStart: '09:00' };
const row = (id, hours, basicSalary = 24000, leaveDays = 0) =>
  ({ id, name: id, position: '', basicSalary, totalMinutes: hours * 60, leaveDays, daysPresent: 0, openSessions: 0 });

test('session minutes adds up in/out periods', () => {
  assert.equal(sessionMinutes({ in: '09:00', out: '18:00' }), 540);
  assert.equal(sessionMinutes({ in: '09:00', out: null }), 0);
  assert.equal(sessionMinutes({ in: '09:00', out: null }, '10:30'), 90);
});

test('default working days exclude Sundays', () => {
  assert.equal(defaultWorkingDays('2026-09', [0]), 26); // Sept 2026 has 4 Sundays
  assert.equal(defaultWorkingDays('2026-02', [0]), 24);
});

test('incentive is shared by score = hours x (hours / required); the whole 1% is paid out', () => {
  // sales 10,00,000 -> pool 10,000; required 24 x 9 = 216h
  const r = computeSalary({ rows: [row('A', 240), row('B', 216), row('C', 190)], workingDays: 24, totalSales: 1000000, settings });
  const [a, b, c] = r.rows;
  assert.equal(r.pool, 10000);
  assert.equal(a.requiredHours, 216);
  assert.equal(a.achievement, 111.11);
  assert.equal(b.achievement, 100);
  assert.equal(c.achievement, 87.96);
  assert.equal(a.totalIncentive, 4103.85); // 240 x 240/216 = 266.67 of 649.80
  assert.equal(b.totalIncentive, 3324.12); // 216
  assert.equal(c.totalIncentive, 2572.03); // 190 x 190/216 = 167.13
  assert.equal(a.incentiveShare, 41.04);
  assert.equal(r.undistributed, 0);
  assert.ok(Math.abs(r.totals.totalIncentive - 10000) < 0.02);
});

test('incentive is paid out even when nobody reaches the required hours', () => {
  const r = computeSalary({ rows: [row('A', 100), row('B', 90)], workingDays: 24, totalSales: 100000, settings });
  assert.equal(r.pool, 1000);
  assert.equal(r.undistributed, 0);
  // 100^2 : 90^2 = 10000 : 8100
  assert.equal(r.rows[0].totalIncentive, 552.49);
  assert.equal(r.rows[1].totalIncentive, 447.51);
});

test('fewer required hours (leave) raise the score for the same hours worked', () => {
  const r = computeSalary({ rows: [row('A', 207), row('B', 207, 24000, 1)], workingDays: 24, totalSales: 100000, settings });
  const [a, b] = r.rows;
  assert.equal(b.requiredHours, 207);
  assert.equal(b.achievement, 100);
  assert.ok(b.totalIncentive > a.totalIncentive);
});

test('leave reduces salary and required hours by 9h per day', () => {
  const r = computeSalary({ rows: [row('A', 210, 24000, 1)], workingDays: 24, totalSales: 0, settings });
  const a = r.rows[0];
  assert.equal(a.requiredHours, 207); // 216 - 9
  assert.equal(a.extraHours, 3);
  assert.equal(a.leaveDeduction, 1000); // 24000 / 24
  assert.equal(a.salaryAfterLeave, 23000);
  assert.equal(a.netPay, 23000);
});

test('half-day leave', () => {
  const r = computeSalary({ rows: [row('A', 0, 26000, 0.5)], workingDays: 26, totalSales: 0, settings });
  assert.equal(r.rows[0].leaveDeduction, 500);
  assert.equal(r.rows[0].requiredHours, 229.5);
});

test('attendance summary adds multiple in/out periods per day', () => {
  const db = {
    settings,
    employees: [{ id: 'e1', name: 'A', position: 'x', basicSalary: 1, active: true }],
    sessions: [
      { id: 1, employeeId: 'e1', date: '2026-09-01', in: '09:00', out: '13:00' },
      { id: 2, employeeId: 'e1', date: '2026-09-01', in: '13:45', out: '18:15' },
      { id: 3, employeeId: 'e1', date: '2026-09-02', in: '09:20', out: '18:00' },
      { id: 4, employeeId: 'e1', date: '2026-09-03', in: '09:00', out: null },
      { id: 5, employeeId: 'e1', date: '2026-10-01', in: '09:00', out: '18:00' },
    ],
    leaves: [{ id: 'l1', employeeId: 'e1', date: '2026-09-04', portion: 1 }],
  };
  const s = attendanceSummary(db, '2026-09').employees[0];
  assert.equal(s.days['2026-09-01'].minutes, 4 * 60 + 4 * 60 + 30);
  assert.equal(s.days['2026-09-02'].late, true);
  assert.equal(s.days['2026-09-03'].open, 1);
  assert.equal(s.totalMinutes, 510 + 520);
  assert.equal(s.daysPresent, 3);
  assert.equal(s.leaveDays, 1);
  assert.equal(s.openSessions, 1);
});

test('employees are left out of months before they joined', () => {
  const db = {
    settings,
    employees: [
      { id: 'a', name: 'A', active: true, joinedOn: '2026-04-01' },
      { id: 'b', name: 'B', active: true, joinedOn: '2026-07-01' },
    ],
    sessions: [], leaves: [],
  };
  assert.deepEqual(attendanceSummary(db, '2026-06').employees.map((e) => e.id), ['a']);
  assert.deepEqual(attendanceSummary(db, '2026-07').employees.map((e) => e.id), ['a', 'b']);
});

test('2+ hours short on a working day counts as a half-day leave', () => {
  const s = (date, tin, tout) => ({ id: date + tin, employeeId: 'e1', date, in: tin, out: tout });
  const db = {
    settings: { ...settings, weeklyOffs: [0] },
    employees: [{ id: 'e1', name: 'A', active: true }],
    sessions: [
      s('2026-09-01', '09:00', '18:00'), // 9h: full day
      s('2026-09-02', '09:10', '16:00'), // 6h50: half day
      s('2026-09-03', '09:00', '16:00'), // exactly 7h (2h short): half day
      s('2026-09-04', '09:00', '16:01'), // 7h01: not a half day
      s('2026-09-06', '10:00', '13:00'), // Sunday: never a half day
      s('2026-09-07', '09:00', null), // missing clock-out: flagged, not a half day
      s('2026-09-08', '09:00', '12:00'), // recorded leave that day: not counted twice
      s('2026-09-09', '09:00', '12:00'), // today: day not finished yet
    ],
    leaves: [{ id: 'l', employeeId: 'e1', date: '2026-09-08', portion: 0.5 }],
  };
  const e = attendanceSummary(db, '2026-09', '2026-09-09').employees[0];
  const half = Object.keys(e.days).filter((d) => e.days[d].autoHalfDay);
  assert.deepEqual(half, ['2026-09-02', '2026-09-03']);
  assert.equal(e.autoHalfDays, 2);
  assert.equal(e.recordedLeaveDays, 0.5);
  assert.equal(e.leaveDays, 1.5);

  // the half days feed into salary: 1.5 days deducted, 1.5 x 9h off the requirement
  const r = computeSalary({ rows: [{ ...e, basicSalary: 26000 }], workingDays: 26, totalSales: 0, settings });
  assert.equal(r.rows[0].leaveDeduction, 1500);
  assert.equal(r.rows[0].requiredHours, 220.5);

  db.settings.halfDayShortHours = 0; // rule switched off
  assert.equal(attendanceSummary(db, '2026-09', '2026-09-09').employees[0].autoHalfDays, 0);
});

test('under 4 hours worked (more than 5 short) is a full-day leave; hours worked still count as extra', () => {
  const s = (date, tin, tout) => ({ id: date, employeeId: 'e1', date, in: tin, out: tout });
  const db = {
    settings: { ...settings, weeklyOffs: [0] },
    employees: [{ id: 'e1', name: 'A', active: true }],
    sessions: [
      s('2026-09-01', '09:00', '12:59'), // 3h59 (5h01 short): full day
      s('2026-09-02', '09:00', '13:00'), // exactly 4h (5h short): half day
      s('2026-09-03', '09:00', '12:00'), // 3h: full day
    ],
    leaves: [],
  };
  const e = attendanceSummary(db, '2026-09', '2026-09-30').employees[0];
  assert.equal(e.days['2026-09-01'].autoFullDay, true);
  assert.equal(e.days['2026-09-02'].autoHalfDay, true);
  assert.equal(e.days['2026-09-02'].autoFullDay, false);
  assert.equal(e.days['2026-09-03'].autoFullDay, true);
  assert.equal(e.autoFullDays, 2);
  assert.equal(e.leaveDays, 2.5);

  // Someone who works 10h a day for the rest of the month still gets the
  // hours from the short days counted towards extra hours (the 25% pool).
  for (let d = 4; d <= 30; d++) {
    const date = `2026-09-${String(d).padStart(2, '0')}`;
    if (new Date(date + 'T00:00Z').getUTCDay() !== 0) db.sessions.push(s(date, '08:00', '18:00'));
  }
  const m = attendanceSummary(db, '2026-09', '2026-10-01').employees[0];
  const r = computeSalary({ rows: [{ ...m, basicSalary: 26000 }], workingDays: 26, totalSales: 100000, settings }).rows[0];
  // 23 full days x 10h + 3h59 + 4h + 3h = 240.98h worked; required (26 - 2.5) x 9 = 211.5h
  assert.equal(r.requiredHours, 211.5);
  assert.equal(r.workedHours, 240.98);
  assert.equal(r.extraHours, 29.48);
  assert.equal(r.leaveDeduction, 2500);
});

test('staff without incentive get none, and the pool is shared among the rest', () => {
  const rows = [row('A', 250), row('B', 260), { ...row('V', 300), incentive: false }];
  const r = computeSalary({ rows, workingDays: 24, totalSales: 100000, settings });
  const [a, b, v] = r.rows;
  assert.equal(v.incentive, false);
  assert.equal(v.totalIncentive, 0);
  assert.equal(v.netPay, 24000); // salary unaffected
  assert.equal(v.extraHours, 84); // hours still shown
  assert.equal(v.incentiveShare, 0);
  // A and B share all of it by 250^2 : 260^2
  assert.equal(a.totalIncentive, 480.4);
  assert.equal(b.totalIncentive, 519.6);
  assert.ok(Math.abs(r.totals.totalIncentive - 1000) < 0.02);
});

test('holidays reduce working days and are never counted as short days', () => {
  const { defaultWorkingDays, holidayDates } = require('../lib/calc');
  const db = {
    settings: { ...settings, weeklyOffs: [0] },
    employees: [{ id: 'e1', name: 'A', active: true }],
    sessions: [
      { id: 1, employeeId: 'e1', date: '2026-08-25', in: '10:00', out: '12:00' }, // holiday: 2 h worked, no leave
      { id: 2, employeeId: 'e1', date: '2026-08-27', in: '10:00', out: '12:00' }, // normal day: full-day leave
    ],
    leaves: [],
    holidays: [{ id: 'h1', date: '2026-08-25', name: 'Onam' }, { id: 'h2', date: '2026-08-26', name: 'Onam' }],
  };
  assert.equal(defaultWorkingDays('2026-08', [0]), 26);
  assert.equal(defaultWorkingDays('2026-08', [0], holidayDates(db)), 24);
  const e = attendanceSummary(db, '2026-08', '2026-09-01').employees[0];
  assert.equal(e.days['2026-08-25'].autoFullDay, false);
  assert.equal(e.days['2026-08-27'].autoFullDay, true);
  assert.equal(e.leaveDays, 1);
  assert.equal(e.totalMinutes, 240); // hours on the holiday still count
});
