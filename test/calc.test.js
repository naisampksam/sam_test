'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const { computeSalary, defaultWorkingDays, attendanceSummary, sessionMinutes } = require('../lib/calc');

const settings = { hoursPerDay: 9, incentivePercent: 1, hoursPoolPercent: 75, workStart: '09:00' };
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

test('75% of the pool is shared by hours worked (100h vs 90h)', () => {
  // sales 1,00,000 -> pool 1,000 -> hours pool 750
  const r = computeSalary({ rows: [row('A', 100), row('B', 90)], workingDays: 24, totalSales: 100000, settings });
  assert.equal(r.pool, 1000);
  assert.equal(r.hoursPool, 750);
  assert.equal(r.rows[0].hoursIncentive, 394.74); // 750 * 100/190
  assert.equal(r.rows[1].hoursIncentive, 355.26); // 750 * 90/190
  // nobody reached 216h, so the 25% is not distributed
  assert.equal(r.rows[0].extraIncentive, 0);
  assert.equal(r.undistributed, 250);
});

test('25% of the pool goes to extra hours beyond 24 x 9 = 216h (250h vs 260h)', () => {
  const r = computeSalary({ rows: [row('A', 250), row('B', 260), row('C', 200)], workingDays: 24, totalSales: 100000, settings });
  const [a, b, c] = r.rows;
  assert.equal(a.requiredHours, 216);
  assert.equal(a.extraHours, 34);
  assert.equal(b.extraHours, 44);
  assert.equal(c.extraHours, 0);
  // extra pool 250 split 34:44
  assert.equal(a.extraIncentive, 108.97);
  assert.equal(b.extraIncentive, 141.03);
  assert.equal(c.extraIncentive, 0);
  assert.equal(r.undistributed, 0);
  const totalIncentive = r.rows.reduce((s, x) => s + x.totalIncentive, 0);
  assert.ok(Math.abs(totalIncentive - 1000) < 0.02);
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
