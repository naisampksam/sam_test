'use strict';

// All attendance is stored as company-local wall-clock values:
// dates as "YYYY-MM-DD" and times as "HH:MM", in the configured timezone.

const TIME_RE = /^([01]\d|2[0-3]):[0-5]\d$/;
const DATE_RE = /^\d{4}-(0[1-9]|1[0-2])-(0[1-9]|[12]\d|3[01])$/;
const MONTH_RE = /^\d{4}-(0[1-9]|1[0-2])$/;

function isTime(v) { return typeof v === 'string' && TIME_RE.test(v); }
function isDate(v) {
  if (typeof v !== 'string' || !DATE_RE.test(v)) return false;
  const [y, m, d] = v.split('-').map(Number);
  return d <= daysInMonth(y, m);
}
function isMonth(v) { return typeof v === 'string' && MONTH_RE.test(v); }

function toMinutes(hhmm) {
  const [h, m] = hhmm.split(':').map(Number);
  return h * 60 + m;
}

function daysInMonth(year, month) {
  return new Date(Date.UTC(year, month, 0)).getUTCDate();
}

function monthDates(month) {
  const [y, m] = month.split('-').map(Number);
  const out = [];
  for (let d = 1; d <= daysInMonth(y, m); d++) {
    out.push(`${month}-${String(d).padStart(2, '0')}`);
  }
  return out;
}

function weekday(date) {
  const [y, m, d] = date.split('-').map(Number);
  return new Date(Date.UTC(y, m - 1, d)).getUTCDay(); // 0 = Sunday
}

// Current date/time in the company timezone.
function nowParts(timeZone, when = new Date()) {
  let parts;
  try {
    parts = new Intl.DateTimeFormat('en-CA', {
      timeZone, year: 'numeric', month: '2-digit', day: '2-digit',
      hour: '2-digit', minute: '2-digit', hourCycle: 'h23',
    }).formatToParts(when);
  } catch (e) {
    return nowParts('UTC', when);
  }
  const get = (t) => parts.find((p) => p.type === t).value;
  return {
    date: `${get('year')}-${get('month')}-${get('day')}`,
    time: `${get('hour')}:${get('minute')}`,
  };
}

function isValidTimeZone(tz) {
  try {
    new Intl.DateTimeFormat('en-US', { timeZone: tz });
    return true;
  } catch (e) {
    return false;
  }
}

module.exports = {
  isTime, isDate, isMonth, toMinutes, daysInMonth, monthDates, weekday,
  nowParts, isValidTimeZone,
};
