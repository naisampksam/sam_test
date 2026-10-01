'use strict';

const { toMinutes, monthDates, weekday } = require('./time');

// Manual entries made on the clock-in page wait for the admin's approval
// (status 'pending'); until approved, and if rejected, they do not count.
const counts = (s) => !s.status || s.status === 'approved';
const notRejected = (s) => s.status !== 'rejected';

// Minutes in one in/out session. An open session (no out time yet) counts
// up to `nowTime` when given (live view for today), otherwise 0.
function sessionMinutes(s, nowTime) {
  const end = s.out || nowTime;
  if (!end) return 0;
  return Math.max(0, toMinutes(end) - toMinutes(s.in));
}

// Holiday dates (YYYY-MM-DD) from the admin's holiday list.
const holidayDates = (db) => new Set((db.holidays || []).map((h) => h.date));

// Default working days in a month: every day except the weekly off days
// (Sunday by default) and holidays. The admin can override this per month.
function defaultWorkingDays(month, weeklyOffs = [0], holidays = new Set()) {
  return monthDates(month).filter((d) => !weeklyOffs.includes(weekday(d)) && !holidays.has(d)).length;
}

// Per-employee, per-day attendance for a month.
// Only closed sessions count towards totals; open sessions from past days are
// flagged as missing a clock-out.
//
// A working day on which someone was present but worked `halfDayShortHours`
// (default 2) or more below the required hours counts as a half-day leave, and
// more than `fullDayShortHours` (default 5, i.e. under 4 h of 9) below as a full-day leave, unless
// a leave is already recorded for that day. The hours they did work still
// count, so they also add to extra hours for the incentive.
//
// A past working day with no attendance at all, no recorded leave and no leave
// request waiting for approval counts as a full-day unplanned leave, from the
// `sickLeaveFrom` date in the settings (empty = off) and not before the person
// joined. Manual entries waiting for approval count as attendance here.
function attendanceSummary(db, month, today) {
  const dates = monthDates(month);
  const inMonth = (d) => d.startsWith(month + '-');
  const { workStart, hoursPerDay, weeklyOffs = [] } = db.settings;
  const holidays = holidayDates(db);
  const shortHours = db.settings.halfDayShortHours == null ? 2 : Number(db.settings.halfDayShortHours);
  const halfDayBelow = shortHours > 0 ? (Number(hoursPerDay) - shortHours) * 60 : null;
  const fullShort = db.settings.fullDayShortHours == null ? 5 : Number(db.settings.fullDayShortHours);
  const fullDayBelow = fullShort > 0 ? (Number(hoursPerDay) - fullShort) * 60 : null;
  const sickFrom = db.settings.sickLeaveFrom || null;

  const employees = db.employees
    .filter((e) => {
      if (db.sessions.some((s) => s.employeeId === e.id && inMonth(s.date) && counts(s))) return true;
      // skip people who were inactive, or had not joined yet, in this month
      return e.active && !(e.joinedOn && e.joinedOn > dates[dates.length - 1]);
    })
    .map((e) => {
      const sessions = db.sessions.filter((s) => s.employeeId === e.id && inMonth(s.date) && counts(s));
      const leaves = db.leaves.filter((l) => l.employeeId === e.id && inMonth(l.date));
      // days with any attendance (approved or still waiting) or a leave request waiting for approval
      const attended = new Set(db.sessions.filter((s) => s.employeeId === e.id && inMonth(s.date) && notRejected(s)).map((s) => s.date));
      const requested = new Set();
      (db.leaveRequests || []).filter((r) => r.employeeId === e.id && r.status === 'pending')
        .forEach((r) => (r.dates || []).forEach((d) => requested.add(d)));
      const sickStart = sickFrom && e.joinedOn && e.joinedOn > sickFrom ? e.joinedOn : sickFrom;
      const days = {};
      let totalMinutes = 0;
      let daysPresent = 0;
      let openSessions = 0;
      let sickDays = 0;

      for (const date of dates) {
        const daySessions = sessions
          .filter((s) => s.date === date)
          .sort((a, b) => a.in.localeCompare(b.in));
        const leave = leaves.find((l) => l.date === date) || null;
        if (!daySessions.length && !leave) {
          const sick = !!(e.active && sickStart && today && date >= sickStart && date < today
            && !attended.has(date) && !requested.has(date)
            && !weeklyOffs.includes(weekday(date)) && !holidays.has(date));
          if (sick) {
            days[date] = { minutes: 0, sessions: [], leave: null, firstIn: null, lastOut: null, late: false,
              open: 0, running: 0, autoFullDay: false, autoHalfDay: false, sick: true };
            sickDays++;
          }
          continue;
        }

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
          && (!today || date < today) && !weeklyOffs.includes(weekday(date)) && !holidays.has(date);
        days[date].autoFullDay = !!(eligible && fullDayBelow != null && minutes < fullDayBelow);
        days[date].autoHalfDay = !!(eligible && !days[date].autoFullDay && halfDayBelow != null && minutes <= halfDayBelow);
        days[date].sick = false;
        totalMinutes += minutes;
        openSessions += open;
        if (daySessions.length) daysPresent++;
      }

      const recordedLeaveDays = leaves.reduce((sum, l) => sum + (Number(l.portion) || 0), 0);
      const autoHalfDays = Object.values(days).filter((d) => d.autoHalfDay).length;
      const autoFullDays = Object.values(days).filter((d) => d.autoFullDay).length;
      const leaveDays = recordedLeaveDays + autoHalfDays * 0.5 + autoFullDays + sickDays;
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
        sickDays,
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
 *  Shared by target achievement: each person's score is
 *      workedHours * (workedHours / requiredHours)
 *  so working beyond the requirement raises the share faster than hours
 *  alone, and falling short lowers it. Share = own score / everyone's score.
 *  Staff with the incentive switched off get none and are left out.
 */
function computeSalary({ rows, workingDays, totalSales, settings }) {
  const hoursPerDay = Number(settings.hoursPerDay) || 0;
  const pool = (Number(totalSales) || 0) * (Number(settings.incentivePercent) || 0) / 100;

  const base = rows.map((r) => {
    const workedHours = r.totalMinutes / 60;
    const leaveDays = Math.min(r.leaveDays, workingDays);
    const requiredHours = Math.max(0, workingDays - leaveDays) * hoursPerDay;
    const extraHours = Math.max(0, workedHours - requiredHours);
    const shortHours = Math.max(0, requiredHours - workedHours);
    const perDay = workingDays > 0 ? r.basicSalary / workingDays : 0;
    const leaveDeduction = Math.min(r.basicSalary, perDay * leaveDays);
    // share of the target reached (1 = exactly the required hours)
    const achievement = requiredHours > 0 ? workedHours / requiredHours : (workedHours > 0 ? 1 : 0);
    const eligible = r.incentive !== false;
    const score = eligible ? workedHours * achievement : 0;
    return { ...r, workedHours, leaveDays, requiredHours, extraHours, shortHours, perDay, leaveDeduction, achievement, eligible, score };
  });

  const totalScore = base.reduce((s, r) => s + r.score, 0);

  const out = base.map((r) => {
    const incentive = totalScore > 0 ? pool * r.score / totalScore : 0;
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
      incentive: r.eligible,
      achievement: round2(100 * r.achievement),
      score: round2(r.score),
      incentiveShare: totalScore > 0 ? round2(100 * r.score / totalScore) : 0,
      perDay: round2(r.perDay),
      leaveDeduction: round2(r.leaveDeduction),
      salaryAfterLeave: round2(salaryAfterLeave),
      totalIncentive: round2(incentive),
      netPay: round2(salaryAfterLeave + incentive),
      openSessions: r.openSessions,
      // leave breakdown for the payslip
      recordedLeaveDays: r.recordedLeaveDays || 0,
      autoHalfDays: r.autoHalfDays || 0,
      autoFullDays: r.autoFullDays || 0,
      sickDays: r.sickDays || 0,
    };
  });

  const sum = (k) => round2(out.reduce((s, r) => s + r[k], 0));
  return {
    workingDays,
    totalSales: Number(totalSales) || 0,
    pool: round2(pool),
    undistributed: round2(totalScore > 0 ? 0 : pool),
    rows: out,
    totals: {
      basicSalary: sum('basicSalary'),
      leaveDeduction: sum('leaveDeduction'),
      salaryAfterLeave: sum('salaryAfterLeave'),
      workedHours: sum('workedHours'),
      extraHours: sum('extraHours'),
      totalIncentive: sum('totalIncentive'),
      netPay: sum('netPay'),
    },
  };
}

module.exports = { counts, notRejected, holidayDates, sessionMinutes, defaultWorkingDays, attendanceSummary, computeSalary, round2 };
