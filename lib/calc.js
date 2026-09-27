'use strict';

const { toMinutes, monthDates, weekday } = require('./time');

// Minutes in one in/out session. An open session (no out time yet) counts
// up to `nowTime` when given (live view for today), otherwise 0.
function sessionMinutes(s, nowTime) {
  const end = s.out || nowTime;
  if (!end) return 0;
  return Math.max(0, toMinutes(end) - toMinutes(s.in));
}

// Default working days in a month: every day except the weekly off days
// (Sunday by default). The admin can override this per month.
function defaultWorkingDays(month, weeklyOffs = [0]) {
  return monthDates(month).filter((d) => !weeklyOffs.includes(weekday(d))).length;
}

// Per-employee, per-day attendance for a month.
// Only closed sessions count towards totals; open sessions from past days are
// flagged as missing a clock-out.
//
// A working day on which someone was present but worked `halfDayShortHours`
// (default 2) or more below the required hours counts as a half-day leave, and
// more than `fullDayShortHours` (default 4.5) below as a full-day leave, unless
// a leave is already recorded for that day. The hours they did work still
// count, so they also add to extra hours for the incentive.
function attendanceSummary(db, month, today) {
  const dates = monthDates(month);
  const inMonth = (d) => d.startsWith(month + '-');
  const { workStart, hoursPerDay, weeklyOffs = [] } = db.settings;
  const shortHours = db.settings.halfDayShortHours == null ? 2 : Number(db.settings.halfDayShortHours);
  const halfDayBelow = shortHours > 0 ? (Number(hoursPerDay) - shortHours) * 60 : null;
  const fullShort = db.settings.fullDayShortHours == null ? 4.5 : Number(db.settings.fullDayShortHours);
  const fullDayBelow = fullShort > 0 ? (Number(hoursPerDay) - fullShort) * 60 : null;

  const employees = db.employees
    .filter((e) => {
      if (db.sessions.some((s) => s.employeeId === e.id && inMonth(s.date))) return true;
      // skip people who were inactive, or had not joined yet, in this month
      return e.active && !(e.joinedOn && e.joinedOn > dates[dates.length - 1]);
    })
    .map((e) => {
      const sessions = db.sessions.filter((s) => s.employeeId === e.id && inMonth(s.date));
      const leaves = db.leaves.filter((l) => l.employeeId === e.id && inMonth(l.date));
      const days = {};
      let totalMinutes = 0;
      let daysPresent = 0;
      let openSessions = 0;

      for (const date of dates) {
        const daySessions = sessions
          .filter((s) => s.date === date)
          .sort((a, b) => a.in.localeCompare(b.in));
        const leave = leaves.find((l) => l.date === date) || null;
        if (!daySessions.length && !leave) continue;

        const minutes = daySessions.reduce((sum, s) => sum + sessionMinutes(s), 0);
        // an open session today just means the person is still in
        const running = date === today ? daySessions.filter((s) => !s.out).length : 0;
        const open = daySessions.filter((s) => !s.out).length - running;
        const firstIn = daySessions.length ? daySessions[0].in : null;
        const lastOut = daySessions.filter((s) => s.out).map((s) => s.out).sort().pop() || null;

        days[date] = {
          minutes,
          sessions: daySessions,
          leave,
          firstIn,
          lastOut,
          late: !!(firstIn && workStart && firstIn > workStart),
          open,
          running,
        };
        const eligible = !leave && daySessions.length && !open && !running
          && (!today || date < today) && !weeklyOffs.includes(weekday(date));
        days[date].autoFullDay = !!(eligible && fullDayBelow != null && minutes < fullDayBelow);
        days[date].autoHalfDay = !!(eligible && !days[date].autoFullDay && halfDayBelow != null && minutes <= halfDayBelow);
        totalMinutes += minutes;
        openSessions += open;
        if (daySessions.length) daysPresent++;
      }

      const recordedLeaveDays = leaves.reduce((sum, l) => sum + (Number(l.portion) || 0), 0);
      const autoHalfDays = Object.values(days).filter((d) => d.autoHalfDay).length;
      const autoFullDays = Object.values(days).filter((d) => d.autoFullDay).length;
      const leaveDays = recordedLeaveDays + autoHalfDays * 0.5 + autoFullDays;
      return {
        id: e.id,
        name: e.name,
        position: e.position,
        active: e.active,
        basicSalary: e.basicSalary,
        incentive: e.incentive !== false,
        totalMinutes,
        daysPresent,
        leaveDays,
        recordedLeaveDays,
        autoHalfDays,
        autoFullDays,
        openSessions,
        days,
      };
    });

  return { month, dates, employees };
}

const round2 = (n) => Math.round((n + Number.EPSILON) * 100) / 100;

/*
 * Salary + incentive for a month.
 *
 *  Monthly salary  = basic - (basic / workingDays) * leaveDays
 *  Required hours  = (workingDays - leaveDays) * hoursPerDay
 *  Extra hours     = max(0, workedHours - requiredHours)
 *
 *  Incentive pool  = totalSales * incentivePercent%            (default 1%)
 *    Hours pool    = pool * hoursPoolPercent%                  (default 75%)
 *                    shared in proportion to hours worked
 *    Extra pool    = the rest                                  (default 25%)
 *                    shared in proportion to extra hours; only people who
 *                    worked beyond their required hours receive it
 */
function computeSalary({ rows, workingDays, totalSales, settings }) {
  const hoursPerDay = Number(settings.hoursPerDay) || 0;
  const pool = (Number(totalSales) || 0) * (Number(settings.incentivePercent) || 0) / 100;
  const hoursPool = pool * (Number(settings.hoursPoolPercent) || 0) / 100;
  const extraPool = pool - hoursPool;

  const base = rows.map((r) => {
    const workedHours = r.totalMinutes / 60;
    const leaveDays = Math.min(r.leaveDays, workingDays);
    const requiredHours = Math.max(0, workingDays - leaveDays) * hoursPerDay;
    const extraHours = Math.max(0, workedHours - requiredHours);
    const shortHours = Math.max(0, requiredHours - workedHours);
    const perDay = workingDays > 0 ? r.basicSalary / workingDays : 0;
    const leaveDeduction = Math.min(r.basicSalary, perDay * leaveDays);
    return { ...r, workedHours, leaveDays, requiredHours, extraHours, shortHours, perDay, leaveDeduction };
  });

  // Only people eligible for the incentive share the pool; others' hours
  // are left out so the whole pool goes to the eligible staff.
  const eligible = (r) => r.incentive !== false;
  const totalWorked = base.filter(eligible).reduce((s, r) => s + r.workedHours, 0);
  const totalExtra = base.filter(eligible).reduce((s, r) => s + r.extraHours, 0);

  const out = base.map((r) => {
    const ok = eligible(r);
    const hoursIncentive = ok && totalWorked > 0 ? hoursPool * r.workedHours / totalWorked : 0;
    const extraIncentive = ok && totalExtra > 0 ? extraPool * r.extraHours / totalExtra : 0;
    const salaryAfterLeave = r.basicSalary - r.leaveDeduction;
    return {
      id: r.id,
      name: r.name,
      position: r.position,
      basicSalary: round2(r.basicSalary),
      daysPresent: r.daysPresent,
      leaveDays: r.leaveDays,
      workedHours: round2(r.workedHours),
      requiredHours: round2(r.requiredHours),
      extraHours: round2(r.extraHours),
      shortHours: round2(r.shortHours),
      incentive: ok,
      hoursShare: ok && totalWorked > 0 ? round2(100 * r.workedHours / totalWorked) : 0,
      extraShare: ok && totalExtra > 0 ? round2(100 * r.extraHours / totalExtra) : 0,
      perDay: round2(r.perDay),
      leaveDeduction: round2(r.leaveDeduction),
      salaryAfterLeave: round2(salaryAfterLeave),
      hoursIncentive: round2(hoursIncentive),
      extraIncentive: round2(extraIncentive),
      totalIncentive: round2(hoursIncentive + extraIncentive),
      netPay: round2(salaryAfterLeave + hoursIncentive + extraIncentive),
      openSessions: r.openSessions,
    };
  });

  const sum = (k) => round2(out.reduce((s, r) => s + r[k], 0));
  return {
    workingDays,
    totalSales: Number(totalSales) || 0,
    pool: round2(pool),
    hoursPool: round2(hoursPool),
    extraPool: round2(extraPool),
    undistributed: round2((totalWorked > 0 ? 0 : hoursPool) + (totalExtra > 0 ? 0 : extraPool)),
    rows: out,
    totals: {
      basicSalary: sum('basicSalary'),
      leaveDeduction: sum('leaveDeduction'),
      salaryAfterLeave: sum('salaryAfterLeave'),
      workedHours: sum('workedHours'),
      extraHours: sum('extraHours'),
      hoursIncentive: sum('hoursIncentive'),
      extraIncentive: sum('extraIncentive'),
      totalIncentive: sum('totalIncentive'),
      netPay: sum('netPay'),
    },
  };
}

module.exports = { sessionMinutes, defaultWorkingDays, attendanceSummary, computeSalary, round2 };
