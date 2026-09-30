'use strict';

// The WordPress plugin re-implements lib/calc.js in PHP. This checks both give
// identical results on randomly generated months. Skipped when PHP is missing.
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { execFileSync, spawnSync } = require('child_process');
const { attendanceSummary, computeSalary } = require('../lib/calc');

const hasPhp = spawnSync('php', ['-v']).status === 0;

function makeCases() {
  let seed = 42;
  const rnd = () => { seed = (seed * 1103515245 + 12345) % 2147483648; return seed / 2147483648; };
  const pad = (n) => String(n).padStart(2, '0');
  const t = (m) => `${pad(Math.floor(m / 60))}:${pad(m % 60)}`;
  const cases = [];
  for (let c = 0; c < 40; c++) {
    const emps = [];
    for (let i = 0, n = 1 + Math.floor(rnd() * 5); i < n; i++) {
      emps.push({ id: 'e' + i, name: 'E' + i, position: 'P', basicSalary: Math.round(rnd() * 40000), active: rnd() > 0.15,
        incentive: rnd() > 0.3, joinedOn: rnd() > 0.8 ? '2026-09-10' : '2026-01-01' });
    }
    const sessions = [];
    const leaves = [];
    for (const e of emps) {
      for (let d = 1; d <= 30; d++) {
        const date = `2026-09-${pad(d)}`;
        if (rnd() < 0.08) {
          leaves.push({ id: `l${e.id}${d}`, employeeId: e.id, date, portion: rnd() > 0.5 ? 1 : 0.5 });
          if (rnd() < 0.7) continue;
        }
        let cur = 480 + Math.floor(rnd() * 120);
        for (let j = 0, k = Math.floor(rnd() * 3); j < k && cur < 1400; j++) {
          const out = Math.min(1439, cur + 60 + Math.floor(rnd() * 400));
          const r = rnd();
          const status = r < 0.05 ? 'pending' : r < 0.1 ? 'rejected' : r < 0.15 ? 'approved' : undefined;
          sessions.push({ id: `s${e.id}${d}${j}`, employeeId: e.id, date, in: t(cur), out: rnd() < 0.05 ? null : t(out), ...(status ? { status } : {}) });
          cur = out + 10;
        }
      }
    }
    const settings = { workStart: '09:00', hoursPerDay: [9, 8, 8.5][c % 3], weeklyOffs: c % 4 ? [0] : [0, 6],
      incentivePercent: [1, 2, 0.5][c % 3], hoursPoolPercent: [75, 60, 100][c % 3],
      halfDayShortHours: [2, 0, 1.5][c % 3], fullDayShortHours: [4.5, 4, 0][c % 3] };
    const holidays = c % 2 ? [{ id: 'h1', date: '2026-09-15', name: 'H' }, { id: 'h2', date: `2026-09-${pad(1 + (c % 28))}`, name: 'H2' }] : [];
    cases.push({ db: { settings, employees: emps, sessions, leaves, holidays }, month: '2026-09',
      today: ['2026-09-20', '2026-10-05', null][c % 3], workingDays: 20 + (c % 7), totalSales: Math.round(rnd() * 2e6) });
  }
  return cases;
}

function same(a, b, where) {
  if (typeof a === 'number' || typeof b === 'number') {
    assert.ok(Math.abs(Number(a) - Number(b)) < 1e-9, `${where}: ${a} vs ${b}`);
  } else if (a && b && typeof a === 'object') {
    const keys = new Set([...Object.keys(a), ...Object.keys(b)]);
    for (const k of keys) same(a[k], b[k], `${where}.${k}`);
  } else {
    assert.equal(a ?? null, b ?? null, where);
  }
}

test('WordPress plugin (PHP) calculates exactly like the Node.js app', { skip: !hasPhp && 'php not installed' }, () => {
  const cases = makeCases();
  const file = path.join(fs.mkdtempSync(path.join(os.tmpdir(), 'looma-php-')), 'cases.json');
  fs.writeFileSync(file, JSON.stringify(cases));
  const php = JSON.parse(execFileSync('php', [path.join(__dirname, 'php-calc.php'), file], { maxBuffer: 64 << 20 }));
  fs.rmSync(path.dirname(file), { recursive: true, force: true });

  cases.forEach((c, i) => {
    const s = attendanceSummary(c.db, c.month, c.today || undefined);
    const { defaultWorkingDays, holidayDates } = require('../lib/calc');
    const js = { workingDays: defaultWorkingDays(c.month, c.db.settings.weeklyOffs, holidayDates(c.db)), summary: s.employees, salary: computeSalary({ rows: s.employees, workingDays: c.workingDays, totalSales: c.totalSales, settings: c.db.settings }) };
    same(js, php[i], `case ${i}`);
  });
});
