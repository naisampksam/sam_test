'use strict';

const http = require('http');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const store = require('./lib/store');
const T = require('./lib/time');
const { sessionMinutes, defaultWorkingDays, attendanceSummary, computeSalary } = require('./lib/calc');

const PORT = Number(process.env.PORT) || 3000;
const HOST = process.env.HOST || '0.0.0.0';
const PUBLIC_DIR = path.join(__dirname, 'public');
const SESSION_TTL_MS = 12 * 60 * 60 * 1000;

const db = store.load();

// ---------- helpers ----------

class HttpError extends Error {
  constructor(status, message) { super(message); this.status = status; }
}
const bad = (msg) => new HttpError(400, msg);

function hashSecret(secret) {
  const salt = crypto.randomBytes(16).toString('hex');
  const hash = crypto.scryptSync(String(secret), salt, 64).toString('hex');
  return `${salt}:${hash}`;
}
function verifySecret(secret, stored) {
  if (!stored) return false;
  const [salt, hash] = stored.split(':');
  const test = crypto.scryptSync(String(secret), salt, 64);
  const ref = Buffer.from(hash, 'hex');
  return ref.length === test.length && crypto.timingSafeEqual(ref, test);
}

const adminSessions = new Map(); // token -> expiresAt
function newAdminSession() {
  const token = crypto.randomBytes(32).toString('hex');
  adminSessions.set(token, Date.now() + SESSION_TTL_MS);
  return token;
}
function isAdmin(req) {
  const token = parseCookies(req).looma_admin;
  const exp = token && adminSessions.get(token);
  if (!exp) return false;
  if (exp < Date.now()) { adminSessions.delete(token); return false; }
  return true;
}
function parseCookies(req) {
  const out = {};
  (req.headers.cookie || '').split(';').forEach((c) => {
    const i = c.indexOf('=');
    if (i > 0) out[c.slice(0, i).trim()] = decodeURIComponent(c.slice(i + 1).trim());
  });
  return out;
}
function adminCookie(token, maxAgeSec) {
  return `looma_admin=${token}; HttpOnly; SameSite=Strict; Path=/; Max-Age=${maxAgeSec}`;
}

// Basic brute-force protection for password / PIN checks.
const failures = new Map(); // key -> { count, until }
function checkLock(key) {
  const f = failures.get(key);
  if (f && f.until > Date.now()) throw new HttpError(429, 'Too many wrong attempts. Try again in a few minutes.');
}
function recordFailure(key) {
  const f = failures.get(key) || { count: 0, until: 0 };
  f.count++;
  if (f.count >= 5) { f.until = Date.now() + 5 * 60 * 1000; f.count = 0; }
  failures.set(key, f);
}

function readBody(req) {
  return new Promise((resolve, reject) => {
    let size = 0;
    const chunks = [];
    req.on('data', (c) => {
      size += c.length;
      if (size > 100 * 1024) { reject(new HttpError(413, 'Request too large')); req.destroy(); return; }
      chunks.push(c);
    });
    req.on('end', () => {
      if (!chunks.length) return resolve({});
      try { resolve(JSON.parse(Buffer.concat(chunks).toString('utf8'))); } catch (e) { reject(bad('Invalid JSON')); }
    });
    req.on('error', reject);
  });
}

function send(res, status, data, headers = {}) {
  res.writeHead(status, { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store', ...headers });
  res.end(JSON.stringify(data));
}

const now = () => T.nowParts(db.settings.timezone);
const findEmployee = (id) => {
  const e = db.employees.find((x) => x.id === id);
  if (!e) throw new HttpError(404, 'Employee not found');
  return e;
};
const publicEmployee = (e) => ({
  id: e.id, name: e.name, position: e.position, basicSalary: e.basicSalary, incentive: e.incentive !== false,
  active: e.active, hasPin: !!e.pinHash, joinedOn: e.joinedOn,
});

function checkPin(emp, pin) {
  if (!emp.pinHash) return;
  const key = 'pin:' + emp.id;
  checkLock(key);
  if (!verifySecret(pin || '', emp.pinHash)) { recordFailure(key); throw new HttpError(401, 'Wrong PIN'); }
  failures.delete(key);
}

function cleanText(v, max = 80) {
  return String(v == null ? '' : v).trim().slice(0, max);
}

function validateSession(employeeId, date, tin, tout, ignoreId) {
  if (!T.isDate(date)) throw bad('Invalid date');
  if (!T.isTime(tin)) throw bad('Invalid in time (use HH:MM)');
  if (tout != null && tout !== '' && !T.isTime(tout)) throw bad('Invalid out time (use HH:MM)');
  if (tout && tout <= tin) throw bad('Out time must be after in time');
  const today = now().date;
  if (date > today) throw bad('Date cannot be in the future');
  const a1 = T.toMinutes(tin);
  const a2 = tout ? T.toMinutes(tout) : 24 * 60;
  const clash = db.sessions.find((s) => {
    if (s.employeeId !== employeeId || s.date !== date || s.id === ignoreId) return false;
    const b1 = T.toMinutes(s.in);
    const b2 = s.out ? T.toMinutes(s.out) : 24 * 60;
    return a1 < b2 && b1 < a2;
  });
  if (clash) throw bad(`Overlaps an existing entry (${clash.in} – ${clash.out || 'still in'})`);
}

function monthConfig(month) {
  const cfg = db.months[month] || {};
  const workingDays = cfg.workingDays != null ? cfg.workingDays : defaultWorkingDays(month, db.settings.weeklyOffs);
  return { workingDays, totalSales: cfg.totalSales || 0, custom: cfg.workingDays != null };
}

function employeeToday(e, date, time) {
  const sessions = db.sessions
    .filter((s) => s.employeeId === e.id && s.date === date)
    .sort((a, b) => a.in.localeCompare(b.in));
  const open = sessions.find((s) => !s.out);
  const minutes = sessions.reduce((sum, s) => sum + sessionMinutes(s, time), 0);
  const staleOpen = db.sessions.some((s) => s.employeeId === e.id && !s.out && s.date < date);
  const { basicSalary, incentive, ...pub } = publicEmployee(e);
  return {
    ...pub,
    status: open ? 'in' : 'out',
    since: open ? open.in : null,
    todayMinutes: minutes,
    sessions: sessions.map((s) => ({ in: s.in, out: s.out, source: s.source })),
    onLeave: db.leaves.some((l) => l.employeeId === e.id && l.date === date),
    staleOpen,
  };
}

// ---------- routes ----------

const routes = [];
const route = (method, pattern, handler, opts = {}) => {
  const keys = [];
  const re = new RegExp('^' + pattern.replace(/:(\w+)/g, (_, k) => { keys.push(k); return '([^/]+)'; }) + '$');
  routes.push({ method, re, keys, handler, admin: !!opts.admin });
};

// Public (kiosk) endpoints

route('GET', '/api/public/status', () => {
  const { date, time } = now();
  return {
    companyName: db.settings.companyName,
    timezone: db.settings.timezone,
    workStart: db.settings.workStart,
    workEnd: db.settings.workEnd,
    today: date,
    now: time,
    employees: db.employees.filter((e) => e.active).map((e) => employeeToday(e, date, time)),
  };
});

route('POST', '/api/punch', ({ body }) => {
  const emp = findEmployee(body.employeeId);
  if (!emp.active) throw bad('Employee is inactive');
  checkPin(emp, body.pin);
  const { date, time } = now();
  const open = db.sessions.find((s) => s.employeeId === emp.id && s.date === date && !s.out);

  if (body.action === 'in') {
    if (open) throw bad(`${emp.name} is already clocked in since ${open.in}`);
    const last = db.sessions.filter((s) => s.employeeId === emp.id && s.date === date && s.out).map((s) => s.out).sort().pop();
    const tin = last && last > time ? last : time; // guard against same-minute overlap
    db.sessions.push({ id: crypto.randomUUID(), employeeId: emp.id, date, in: tin, out: null, source: 'button' });
  } else if (body.action === 'out') {
    if (!open) throw bad(`${emp.name} is not clocked in`);
    open.out = time > open.in ? time : open.in;
  } else {
    throw bad('Unknown action');
  }
  store.save();
  return employeeToday(emp, date, time);
});

route('POST', '/api/manual', ({ body }) => {
  const emp = findEmployee(body.employeeId);
  if (!emp.active) throw bad('Employee is inactive');
  checkPin(emp, body.pin);
  if (!body.out) throw bad('Please enter both in and out time');
  validateSession(emp.id, body.date, body.in, body.out);
  db.sessions.push({
    id: crypto.randomUUID(), employeeId: emp.id, date: body.date, in: body.in, out: body.out,
    source: 'manual', note: cleanText(body.note, 200),
  });
  store.save();
  return { ok: true };
});

// An employee's own monthly summary (PIN protected if a PIN is set)
route('POST', '/api/my', ({ body }) => {
  const emp = findEmployee(body.employeeId);
  checkPin(emp, body.pin);
  const month = T.isMonth(body.month) ? body.month : now().date.slice(0, 7);
  const summary = attendanceSummary(db, month, now().date).employees.find((e) => e.id === emp.id);
  const { workingDays } = monthConfig(month);
  const leaveDays = summary ? summary.leaveDays : 0;
  return {
    month,
    name: emp.name,
    workingDays,
    hoursPerDay: db.settings.hoursPerDay,
    requiredMinutes: Math.max(0, workingDays - leaveDays) * db.settings.hoursPerDay * 60,
    totalMinutes: summary ? summary.totalMinutes : 0,
    daysPresent: summary ? summary.daysPresent : 0,
    leaveDays,
    days: summary ? Object.entries(summary.days).map(([date, d]) => ({
      date, minutes: d.minutes, firstIn: d.firstIn, lastOut: d.lastOut, open: d.open, leave: !!d.leave || d.autoFullDay, halfDay: !d.autoFullDay && ( !!d.autoHalfDay || !!(d.leave && d.leave.portion === 0.5)),
      sessions: d.sessions.map((s) => ({ in: s.in, out: s.out, source: s.source })),
    })) : [],
  };
});

// Admin auth

route('GET', '/api/admin/state', ({ req }) => ({
  setupRequired: !db.settings.adminPasswordHash,
  loggedIn: isAdmin(req),
  companyName: db.settings.companyName,
}));

route('POST', '/api/admin/setup', ({ body }) => {
  if (db.settings.adminPasswordHash) throw new HttpError(403, 'Admin password is already set');
  if (!body.password || String(body.password).length < 6) throw bad('Password must be at least 6 characters');
  db.settings.adminPasswordHash = hashSecret(body.password);
  store.save();
  const token = newAdminSession();
  return { ok: true, _cookie: adminCookie(token, SESSION_TTL_MS / 1000) };
});

route('POST', '/api/admin/login', ({ body, req }) => {
  const key = 'admin:' + (req.socket.remoteAddress || '');
  checkLock(key);
  if (!verifySecret(body.password || '', db.settings.adminPasswordHash)) {
    recordFailure(key);
    throw new HttpError(401, 'Wrong password');
  }
  failures.delete(key);
  const token = newAdminSession();
  return { ok: true, _cookie: adminCookie(token, SESSION_TTL_MS / 1000) };
});

route('POST', '/api/admin/logout', ({ req }) => {
  adminSessions.delete(parseCookies(req).looma_admin);
  return { ok: true, _cookie: adminCookie('', 0) };
});

route('POST', '/api/admin/password', ({ body }) => {
  if (!verifySecret(body.current || '', db.settings.adminPasswordHash)) throw new HttpError(401, 'Current password is wrong');
  if (!body.password || String(body.password).length < 6) throw bad('New password must be at least 6 characters');
  db.settings.adminPasswordHash = hashSecret(body.password);
  store.save();
  return { ok: true };
}, { admin: true });

// Settings

route('GET', '/api/admin/settings', () => {
  const { adminPasswordHash, ...rest } = db.settings;
  return { ...rest, today: now().date };
}, { admin: true });

route('PUT', '/api/admin/settings', ({ body }) => {
  const s = db.settings;
  const next = { ...s };
  if (body.companyName != null) next.companyName = cleanText(body.companyName) || s.companyName;
  if (body.currency != null) next.currency = cleanText(body.currency, 5);
  if (body.timezone != null) {
    if (!T.isValidTimeZone(body.timezone)) throw bad('Unknown timezone');
    next.timezone = body.timezone;
  }
  if (body.workStart != null) { if (!T.isTime(body.workStart)) throw bad('Invalid start time'); next.workStart = body.workStart; }
  if (body.workEnd != null) { if (!T.isTime(body.workEnd)) throw bad('Invalid end time'); next.workEnd = body.workEnd; }
  const num = (v, name, min, max) => {
    const n = Number(v);
    if (!Number.isFinite(n) || n < min || n > max) throw bad(`${name} must be between ${min} and ${max}`);
    return n;
  };
  if (body.hoursPerDay != null) next.hoursPerDay = num(body.hoursPerDay, 'Hours per day', 1, 24);
  if (body.incentivePercent != null) next.incentivePercent = num(body.incentivePercent, 'Incentive %', 0, 100);
  if (body.hoursPoolPercent != null) next.hoursPoolPercent = num(body.hoursPoolPercent, 'Hours pool %', 0, 100);
  if (body.fullDayShortHours != null) next.fullDayShortHours = num(body.fullDayShortHours, 'Full-day rule hours', 0, 24);
  if (body.halfDayShortHours != null) next.halfDayShortHours = num(body.halfDayShortHours, 'Half-day rule hours', 0, 24);
  if (Array.isArray(body.weeklyOffs)) next.weeklyOffs = [...new Set(body.weeklyOffs.map(Number).filter((d) => d >= 0 && d <= 6))];
  db.settings = next;
  store.save();
  const { adminPasswordHash, ...rest } = next;
  return rest;
}, { admin: true });

// Employees

route('GET', '/api/admin/employees', () => db.employees.map(publicEmployee), { admin: true });

function applyEmployee(e, body, creating) {
  if (creating || body.name != null) {
    const name = cleanText(body.name);
    if (!name) throw bad('Name is required');
    e.name = name;
  }
  if (creating || body.position != null) e.position = cleanText(body.position);
  if (creating || body.basicSalary != null) {
    const sal = Number(body.basicSalary);
    if (!Number.isFinite(sal) || sal < 0) throw bad('Basic salary must be a positive number');
    e.basicSalary = sal;
  }
  if (body.joinedOn != null && body.joinedOn !== '') {
    if (!T.isDate(body.joinedOn)) throw bad('Invalid joining date');
    e.joinedOn = body.joinedOn;
  }
  if (body.active != null) e.active = !!body.active;
  if (body.incentive != null) e.incentive = !!body.incentive;
  if (body.removePin) e.pinHash = null;
  if (body.pin) {
    if (!/^\d{4,6}$/.test(String(body.pin))) throw bad('PIN must be 4–6 digits');
    e.pinHash = hashSecret(body.pin);
  }
}

route('POST', '/api/admin/employees', ({ body }) => {
  const e = { id: crypto.randomUUID(), active: true, pinHash: null, joinedOn: now().date };
  applyEmployee(e, body, true);
  db.employees.push(e);
  store.save();
  return publicEmployee(e);
}, { admin: true });

route('PUT', '/api/admin/employees/:id', ({ params, body }) => {
  const e = findEmployee(params.id);
  const copy = { ...e };
  applyEmployee(copy, body, false);
  Object.assign(e, copy);
  store.save();
  return publicEmployee(e);
}, { admin: true });

route('DELETE', '/api/admin/employees/:id', ({ params }) => {
  const e = findEmployee(params.id);
  db.employees = db.employees.filter((x) => x.id !== e.id);
  db.sessions = db.sessions.filter((x) => x.employeeId !== e.id);
  db.leaves = db.leaves.filter((x) => x.employeeId !== e.id);
  store.save();
  return { ok: true };
}, { admin: true });

// Attendance

route('GET', '/api/admin/today', () => {
  const { date, time } = now();
  return { today: date, now: time, employees: db.employees.filter((e) => e.active).map((e) => employeeToday(e, date, time)) };
}, { admin: true });

route('GET', '/api/admin/attendance', ({ query }) => {
  const month = T.isMonth(query.month) ? query.month : now().date.slice(0, 7);
  const summary = attendanceSummary(db, month, now().date);
  const cfg = monthConfig(month);
  const hpd = db.settings.hoursPerDay;
  summary.employees.forEach((e) => {
    e.requiredMinutes = Math.max(0, cfg.workingDays - e.leaveDays) * hpd * 60;
  });
  return { ...summary, ...cfg, hoursPerDay: hpd, workStart: db.settings.workStart, today: now().date };
}, { admin: true });

route('POST', '/api/admin/sessions', ({ body }) => {
  const emp = findEmployee(body.employeeId);
  const out = body.out || null;
  validateSession(emp.id, body.date, body.in, out);
  const s = { id: crypto.randomUUID(), employeeId: emp.id, date: body.date, in: body.in, out, source: 'admin', note: cleanText(body.note, 200) };
  db.sessions.push(s);
  store.save();
  return s;
}, { admin: true });

route('PUT', '/api/admin/sessions/:id', ({ params, body }) => {
  const s = db.sessions.find((x) => x.id === params.id);
  if (!s) throw new HttpError(404, 'Entry not found');
  const next = { ...s, in: body.in ?? s.in, out: body.out === '' ? null : (body.out ?? s.out), date: body.date ?? s.date };
  validateSession(s.employeeId, next.date, next.in, next.out, s.id);
  Object.assign(s, next, { edited: true });
  store.save();
  return s;
}, { admin: true });

route('DELETE', '/api/admin/sessions/:id', ({ params }) => {
  const before = db.sessions.length;
  db.sessions = db.sessions.filter((x) => x.id !== params.id);
  if (db.sessions.length === before) throw new HttpError(404, 'Entry not found');
  store.save();
  return { ok: true };
}, { admin: true });

// Leaves

route('GET', '/api/admin/leaves', ({ query }) => {
  const month = T.isMonth(query.month) ? query.month : now().date.slice(0, 7);
  return db.leaves.filter((l) => l.date.startsWith(month + '-')).sort((a, b) => a.date.localeCompare(b.date));
}, { admin: true });

route('POST', '/api/admin/leaves', ({ body }) => {
  const emp = findEmployee(body.employeeId);
  const from = body.date;
  const to = body.toDate || body.date;
  if (!T.isDate(from) || !T.isDate(to) || to < from) throw bad('Invalid date range');
  const portion = Number(body.portion) === 0.5 ? 0.5 : 1;
  const added = [];
  const [y, m, d] = from.split('-').map(Number);
  for (let i = 0; i < 62; i++) {
    const date = new Date(Date.UTC(y, m - 1, d + i)).toISOString().slice(0, 10);
    if (date > to) break;
    if (!body.includeOffDays && db.settings.weeklyOffs.includes(T.weekday(date))) continue;
    if (db.leaves.some((l) => l.employeeId === emp.id && l.date === date)) continue;
    const l = { id: crypto.randomUUID(), employeeId: emp.id, date, portion, note: cleanText(body.note, 200) };
    db.leaves.push(l);
    added.push(l);
  }
  if (!added.length) throw bad('No new leave days added (already recorded or weekly off)');
  store.save();
  return added;
}, { admin: true });

route('DELETE', '/api/admin/leaves/:id', ({ params }) => {
  const before = db.leaves.length;
  db.leaves = db.leaves.filter((x) => x.id !== params.id);
  if (db.leaves.length === before) throw new HttpError(404, 'Leave not found');
  store.save();
  return { ok: true };
}, { admin: true });

// Salary

route('GET', '/api/admin/salary', ({ query }) => {
  const month = T.isMonth(query.month) ? query.month : now().date.slice(0, 7);
  const cfg = monthConfig(month);
  const summary = attendanceSummary(db, month, now().date);
  const result = computeSalary({
    rows: summary.employees.map((e) => ({ ...e, basicSalary: Number(e.basicSalary) || 0 })),
    workingDays: cfg.workingDays,
    totalSales: cfg.totalSales,
    settings: db.settings,
  });
  return {
    month,
    customWorkingDays: cfg.custom,
    defaultWorkingDays: defaultWorkingDays(month, db.settings.weeklyOffs),
    hoursPerDay: db.settings.hoursPerDay,
    incentivePercent: db.settings.incentivePercent,
    hoursPoolPercent: db.settings.hoursPoolPercent,
    currency: db.settings.currency,
    companyName: db.settings.companyName,
    ...result,
  };
}, { admin: true });

route('PUT', '/api/admin/months/:month', ({ params, body }) => {
  if (!T.isMonth(params.month)) throw bad('Invalid month');
  const cur = db.months[params.month] || {};
  if (body.workingDays === null || body.workingDays === '') {
    delete cur.workingDays;
  } else if (body.workingDays != null) {
    const wd = Number(body.workingDays);
    if (!Number.isFinite(wd) || wd < 0 || wd > 31) throw bad('Working days must be 0–31');
    cur.workingDays = wd;
  }
  if (body.totalSales != null) {
    const ts = Number(body.totalSales);
    if (!Number.isFinite(ts) || ts < 0) throw bad('Total sales must be a positive number');
    cur.totalSales = ts;
  }
  db.months[params.month] = cur;
  store.save();
  return monthConfig(params.month);
}, { admin: true });

// ---------- server ----------

const MIME = {
  '.html': 'text/html; charset=utf-8', '.css': 'text/css; charset=utf-8', '.js': 'text/javascript; charset=utf-8',
  '.svg': 'image/svg+xml', '.png': 'image/png', '.ico': 'image/x-icon', '.json': 'application/json',
};

function serveStatic(req, res, pathname) {
  if (pathname === '/') pathname = '/index.html';
  if (pathname === '/admin') pathname = '/admin.html';
  const file = path.normalize(path.join(PUBLIC_DIR, pathname));
  if (!file.startsWith(PUBLIC_DIR + path.sep)) { res.writeHead(403); return res.end(); }
  fs.readFile(file, (err, data) => {
    if (err) { res.writeHead(404, { 'Content-Type': 'text/plain' }); return res.end('Not found'); }
    res.writeHead(200, { 'Content-Type': MIME[path.extname(file)] || 'application/octet-stream', 'Cache-Control': 'no-cache' });
    res.end(data);
  });
}

const server = http.createServer(async (req, res) => {
  const url = new URL(req.url, 'http://localhost');
  const pathname = decodeURIComponent(url.pathname);

  if (!pathname.startsWith('/api/')) {
    if (req.method !== 'GET' && req.method !== 'HEAD') { res.writeHead(405); return res.end(); }
    return serveStatic(req, res, pathname);
  }

  try {
    const r = routes.find((x) => x.method === req.method && x.re.test(pathname));
    if (!r) throw new HttpError(404, 'Not found');
    if (r.admin && !isAdmin(req)) throw new HttpError(401, 'Admin login required');
    const m = pathname.match(r.re);
    const params = Object.fromEntries(r.keys.map((k, i) => [k, m[i + 1]]));
    const body = req.method === 'GET' ? {} : await readBody(req);
    const result = await r.handler({ req, params, body, query: Object.fromEntries(url.searchParams) });
    const headers = {};
    if (result && result._cookie) { headers['Set-Cookie'] = result._cookie; delete result._cookie; }
    send(res, 200, result, headers);
  } catch (e) {
    const status = e.status || 500;
    if (status === 500) console.error(e);
    send(res, status, { error: status === 500 ? 'Server error' : e.message });
  }
});

if (require.main === module) {
  server.listen(PORT, HOST, () => {
    console.log(`${db.settings.companyName} attendance running on http://localhost:${PORT}`);
    console.log(`  Employees clock in/out at  http://localhost:${PORT}/`);
    console.log(`  Admin panel at             http://localhost:${PORT}/admin`);
    console.log(`  Data file: ${store.FILE}`);
  });
}

module.exports = server;
