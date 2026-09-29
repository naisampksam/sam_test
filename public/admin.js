'use strict';

const S = {
  tab: 'today',
  month: null,
  settings: null,
  employees: [],
};
const view = document.getElementById('view');
const DAYS = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

function money(n) {
  const cur = (S.settings && S.settings.currency) || '';
  return cur + Number(n || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function monthPicker() {
  return `<input type="month" value="${S.month}" data-month-picker aria-label="Month">`;
}
function bindMonthPicker(render) {
  const el = view.querySelector('[data-month-picker]');
  if (el) el.addEventListener('change', () => { if (el.value) { S.month = el.value; render(); } });
}

async function guard(fn) {
  try { return await fn(); } catch (e) {
    if (e.status === 401 && /Admin login/.test(e.message)) { showAuth(); return; }
    toast(e.message, true);
  }
}

// ---------------- auth ----------------

async function boot() {
  const st = await api('GET', '/api/admin/state');
  S.auth = st;
  document.querySelectorAll('[data-company]').forEach((el) => { el.textContent = st.companyName; });
  if (st.loggedIn) return startApp();
  showAuth(st.setupRequired);
}

function showAuth(setup) {
  document.getElementById('app').classList.add('hidden');
  document.getElementById('auth').classList.remove('hidden');
  const form = document.getElementById('login-form');
  form.dataset.setup = setup ? '1' : '';
  const needCode = !!(setup && S.auth && S.auth.setupCodeRequired);
  form.querySelector('[data-confirm]').classList.toggle('hidden', !setup);
  form.querySelector('[data-setup-code]').classList.toggle('hidden', !needCode);
  form.querySelector('[name=setupCode]').required = needCode;
  form.querySelector('[data-setup-msg]').textContent = setup
    ? 'First-time setup: choose an admin password (at least 6 characters). Only people with this password can add employees, edit attendance and see salaries.'
      + (needCode && S.auth.setupCodeHint ? ' ' + S.auth.setupCodeHint : '')
    : '';
  form.querySelector('[data-submit]').textContent = setup ? 'Set password' : 'Log in';
  form.querySelector('[data-auth-title]').textContent = setup ? 'Create admin password' : 'Sign in';
  form.querySelector('[name=password]').autocomplete = setup ? 'new-password' : 'current-password';
  form.querySelector('[name=password]').focus();
}

document.getElementById('login-form').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const form = ev.target;
  const { password, confirm, setupCode } = formData(form);
  try {
    if (form.dataset.setup) {
      if (password !== confirm) return toast('Passwords do not match', true);
      await api('POST', '/api/admin/setup', { password, setupCode });
    } else {
      await api('POST', '/api/admin/login', { password });
    }
    form.reset();
    startApp();
  } catch (e) { toast(e.message, true); }
});

document.getElementById('logout').addEventListener('click', async () => {
  await api('POST', '/api/admin/logout').catch(() => {});
  showAuth(false);
});

async function startApp() {
  document.getElementById('auth').classList.add('hidden');
  document.getElementById('app').classList.remove('hidden');
  await guard(async () => {
    S.settings = await api('GET', '/api/admin/settings');
    S.month = S.month || S.settings.today.slice(0, 7);
    document.querySelectorAll('[data-company]').forEach((el) => { el.textContent = S.settings.companyName; });
    await loadEmployees();
    await refreshRequests();
    renderTab();
  });
}

async function loadEmployees() {
  S.employees = await api('GET', '/api/admin/employees');
}

const NAV = {
  today: ['home', 'Today'], attendance: ['clock', 'Attendance'], leaves: ['leave', 'Leaves'],
  salary: ['wallet', 'Salary & incentive'], employees: ['users', 'Employees'], settings: ['settings', 'Settings'],
};
document.querySelectorAll('#nav [data-tab]').forEach((b) => {
  const [ic, label] = NAV[b.dataset.tab];
  b.innerHTML = `${icon(ic)}<span>${label}</span>`;
});
document.getElementById('kiosk-link').innerHTML = `${icon('monitor')}<span class="label-text">Clock-in page</span>`;
document.getElementById('logout').innerHTML = `${icon('logout')}<span class="label-text">Log out</span>`;

function goTab(tab) {
  S.tab = tab;
  document.querySelectorAll('#nav [data-tab]').forEach((x) => x.classList.toggle('active', x.dataset.tab === tab));
  renderTab();
  window.scrollTo(0, 0);
}
document.getElementById('nav').addEventListener('click', (ev) => {
  const b = ev.target.closest('[data-tab]');
  if (b) goTab(b.dataset.tab);
});

// Pending leave requests: badge in the sidebar
async function refreshRequests() {
  try { S.requests = await api('GET', '/api/admin/leave-requests'); } catch (e) { S.requests = []; }
  const n = S.requests.filter((r) => r.status === 'pending').length;
  const b = document.querySelector('#nav [data-tab=leaves]');
  const old = b.querySelector('.badge');
  if (old) old.remove();
  if (n) b.insertAdjacentHTML('beforeend', `<span class="badge">${n}</span>`);
  return n;
}

function person(name, sub) {
  return `<div class="person"><div class="avatar">${esc(initials(name))}</div><div><strong>${esc(name)}</strong>${sub ? `<div class="muted small">${sub}</div>` : ''}</div></div>`;
}
function warnNote(html) {
  return `<div class="note warn">${icon('alert')}<span>${html}</span></div>`;
}

function renderTab() {
  view.innerHTML = '<p class="muted" style="padding:8px 0">Loading…</p>';
  ({ today: renderToday, attendance: renderAttendance, leaves: renderLeaves, salary: renderSalary,
    employees: renderEmployees, settings: renderSettings })[S.tab]();
}

// ---------------- today ----------------

async function renderToday() {
  const d = await guard(() => api('GET', '/api/admin/today'));
  if (!d) return;
  const pendingReq = await refreshRequests();
  const inNow = d.employees.filter((e) => e.status === 'in').length;
  const onLeave = d.employees.filter((e) => e.onLeave).length;
  const notYet = d.employees.filter((e) => !e.sessions.length && !e.onLeave).length;
  const total = d.employees.reduce((s, e) => s + e.todayMinutes, 0);
  view.innerHTML = `
    <div class="section-head">
      <h2>Today</h2>
      <div class="spacer"></div>
      <button data-refresh>${icon('refresh', 'sm')}Refresh</button>
      <div class="sub">${esc(fmtDate(d.today, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }))}</div>
    </div>
    ${pendingReq ? `<div class="note info">${icon('leave')}<span><b>${pendingReq} leave request${pendingReq === 1 ? '' : 's'}</b> waiting for your approval. <button class="link" data-go-leaves>Review now</button></span></div>` : ''}
    <div class="stats">
      <div class="stat accent"><div class="label">In office now</div><div class="value">${inNow} / ${d.employees.length}</div></div>
      <div class="stat"><div class="label">Not arrived</div><div class="value">${notYet}</div></div>
      <div class="stat"><div class="label">On leave</div><div class="value">${onLeave}</div></div>
      <div class="stat"><div class="label">Hours so far (all)</div><div class="value">${fmtMin(total)}</div></div>
    </div>
    ${d.employees.length ? `
    <div class="table-wrap"><table>
      <thead><tr><th>Employee</th><th>Status</th><th>First in</th><th>In / out periods</th><th class="r">Worked today</th></tr></thead>
      <tbody>${d.employees.map((e) => {
        const first = e.sessions[0] && e.sessions[0].in;
        const late = first && first > S.settings.workStart;
        return `<tr>
          <td>${person(e.name, esc(e.position))}</td>
          <td>${e.onLeave ? '<span class="pill warn">On leave</span> ' : ''}<span class="pill ${e.status}">${e.status === 'in' ? 'In' : 'Out'}</span>
              ${e.staleOpen ? ' <span class="pill warn" title="An earlier day has no clock-out">Missing clock-out</span>' : ''}</td>
          <td>${first ? fmtTime12(first) + (late ? ' <span class="pill warn">Late</span>' : '') : '<span class="muted">—</span>'}</td>
          <td class="small">${e.sessions.map((s) => `${fmtTime12(s.in)} – ${s.out ? fmtTime12(s.out) : '<em>now</em>'}${s.source === 'manual' ? ' ✎' : ''}`).join('<br>') || '<span class="muted">—</span>'}</td>
          <td class="r">${fmtMin(e.todayMinutes)}</td>
        </tr>`;
      }).join('')}</tbody>
    </table></div>
    <p class="muted small">✎ = entered manually by the employee. Late = first clock-in after ${fmtTime12(S.settings.workStart)}.</p>`
    : emptyEmployees()}`;
  view.querySelector('[data-refresh]').addEventListener('click', renderToday);
  const gl = view.querySelector('[data-go-leaves]');
  if (gl) gl.addEventListener('click', () => goTab('leaves'));
  bindEmptyEmployees();
}

function emptyEmployees() {
  return `<div class="card empty"><h2>No employees yet</h2><p>Add your team to start recording attendance.</p>
    <button class="primary" data-go-emp>${icon('plus', 'sm')}Add employees</button></div>`;
}
function bindEmptyEmployees() {
  const b = view.querySelector('[data-go-emp]');
  if (b) b.addEventListener('click', () => goTab('employees'));
}

// ---------------- attendance ----------------

async function renderAttendance() {
  const d = await guard(() => api('GET', `/api/admin/attendance?month=${S.month}`));
  if (!d) return;
  const openCount = d.employees.reduce((s, e) => s + e.openSessions, 0);
  const day = (date) => Number(date.slice(8));
  const offs = S.settings.weeklyOffs || [];
  const wd = (date) => new Date(date + 'T00:00:00Z').getUTCDay();

  view.innerHTML = `
    <div class="section-head">
      <h2>Attendance</h2>
      <div class="spacer"></div>
      ${monthPicker()}
      <button data-add>${icon('plus', 'sm')}Add entry</button>
      <button data-csv>${icon('download', 'sm')}Export CSV</button>
      <div class="sub">${esc(fmtMonth(S.month))} · click an employee to see or correct their entries</div>
    </div>
    <div class="stats">
      <div class="stat"><div class="label">Working days</div><div class="value">${d.workingDays}</div></div>
      <div class="stat"><div class="label">Hours per day</div><div class="value">${d.hoursPerDay}</div></div>
      <div class="stat"><div class="label">Full-month hours</div><div class="value">${d.workingDays * d.hoursPerDay}</div></div>
    </div>
    ${openCount ? warnNote(`${openCount} entr${openCount === 1 ? 'y has' : 'ies have'} no clock-out yet. Open entries count as 0 hours until an out time is added. Click an employee to fix.`) : ''}
    ${d.employees.length ? `
    <div class="table-wrap"><table>
      <thead><tr><th>Employee</th><th class="r">Days present</th><th class="r">Leave days</th><th class="r">Worked</th><th class="r">Required</th><th class="r">Extra / short</th><th></th></tr></thead>
      <tbody>${d.employees.map((e) => {
        const diff = e.totalMinutes - e.requiredMinutes;
        return `<tr class="clickable" data-emp="${esc(e.id)}">
          <td>${person(e.name, esc(e.position) + (e.active ? '' : ' · inactive'))}</td>
          <td class="r">${e.daysPresent}</td>
          <td class="r">${e.leaveDays}${e.autoHalfDays || e.autoFullDays ? `<div class="muted small">incl. auto ${[e.autoFullDays && `${e.autoFullDays} full`, e.autoHalfDays && `${e.autoHalfDays} × ½`].filter(Boolean).join(', ')}</div>` : ''}</td>
          <td class="r">${fmtMin(e.totalMinutes)}</td>
          <td class="r">${fmtMin(e.requiredMinutes)}</td>
          <td class="r ${diff >= 0 ? 'pos' : 'neg'}">${diff >= 0 ? '+' : '−'}${fmtMin(Math.abs(diff))}</td>
          <td>${e.openSessions ? '<span class="pill warn">Missing out</span>' : ''} <button class="sm">Details</button></td>
        </tr>`;
      }).join('')}</tbody>
    </table></div>

    <h3 style="margin:28px 0 10px">Daily hours</h3>
    <div class="table-wrap"><table class="small dense">
      <thead><tr><th>Employee</th>${d.dates.map((x) => `<th class="r" title="${esc(fmtDate(x))}" style="${offs.includes(wd(x)) ? 'opacity:.5' : ''}">${day(x)}<br>${DAYS[wd(x)].slice(0, 2)}</th>`).join('')}<th class="r">Total</th></tr></thead>
      <tbody>${d.employees.map((e) => `<tr>
        <td>${esc(e.name)}</td>
        ${d.dates.map((x) => {
          const c = e.days[x];
          if (!c) return `<td class="r muted" style="${offs.includes(wd(x)) ? 'opacity:.5' : ''}">·</td>`;
          if (c.leave && !c.sessions.length) return `<td class="r" title="Leave"><span class="pill warn">${c.leave.portion === 0.5 ? '½L' : 'L'}</span></td>`;
          return `<td class="r ${c.open ? 'neg' : ''}" title="${esc(c.sessions.map((s) => s.in + '–' + (s.out || '?')).join(', '))}">${(c.minutes / 60).toFixed(1)}${c.open ? '!' : ''}${c.running ? '…' : ''}${c.late ? '<sup>L</sup>' : ''}${c.autoHalfDay ? ' <span class="pill warn" title="Half-day leave">½</span>' : ''}${c.autoFullDay ? ' <span class="pill warn" title="Full-day leave">L</span>' : ''}</td>`;
        }).join('')}
        <td class="r"><strong>${(e.totalMinutes / 60).toFixed(1)}</strong></td>
      </tr>`).join('')}</tbody>
    </table></div>
    <p class="muted small">Hours in decimals. <sup>L</sup> late arrival · ! missing clock-out · … still clocked in · L leave · ½L half-day leave${halfDayRule() ? ` · ½ automatic half-day leave (${esc(halfDayRule())})` : ''}${fullDayRule() ? ` · L next to hours = automatic full-day leave (${esc(fullDayRule())})` : ''}.</p>
    ` : emptyEmployees()}`;

  bindMonthPicker(renderAttendance);
  bindEmptyEmployees();
  view.querySelectorAll('[data-emp]').forEach((tr) => tr.addEventListener('click', () => {
    employeeDetail(d.employees.find((e) => e.id === tr.dataset.emp), d);
  }));
  view.querySelector('[data-add]').addEventListener('click', () => sessionForm(null, null, renderAttendance));
  view.querySelector('[data-csv]').addEventListener('click', () => {
    const rows = [['Employee', 'Position', 'Date', 'In', 'Out', 'Hours', 'Source', 'Leave']];
    d.employees.forEach((e) => Object.entries(e.days).forEach(([date, c]) => {
      if (!c.sessions.length) rows.push([e.name, e.position, date, '', '', '0', '', c.leave ? c.leave.portion : '']);
      c.sessions.forEach((s) => rows.push([e.name, e.position, date, s.in, s.out || '',
        s.out ? ((toMin(s.out) - toMin(s.in)) / 60).toFixed(2) : '', s.source, c.leave ? c.leave.portion : (c.autoFullDay ? '1 (auto)' : (c.autoHalfDay ? '0.5 (auto)' : ''))]));
    }));
    downloadCsv(`attendance-${S.month}.csv`, rows);
  });
}

function halfDayRule() {
  const h = Number(S.settings.halfDayShortHours);
  return h > 0 ? `worked ${S.settings.hoursPerDay - h} h or less (${h}+ h short)` : null;
}
function fullDayRule() {
  const h = Number(S.settings.fullDayShortHours);
  return h > 0 ? `worked less than ${S.settings.hoursPerDay - h} h (more than ${h} h short)` : null;
}
function autoRules() {
  return [halfDayRule() && `½ day if ${halfDayRule()}`, fullDayRule() && `full day if ${fullDayRule()}`].filter(Boolean).join('; ');
}

function toMin(t) { const [h, m] = t.split(':').map(Number); return h * 60 + m; }

function employeeDetail(emp, d) {
  const dates = Object.keys(emp.days).sort();
  const dlg = openDialog(`
    <div class="section-head">
      <div><h2>${esc(emp.name)}</h2><div class="muted small">${esc(fmtMonth(S.month))}</div></div>
      <div class="spacer"></div>
      <button class="primary sm" data-add>${icon('plus', 'sm')}Add entry</button>
    </div>
    <p class="muted small">Worked ${fmtMin(emp.totalMinutes)} of ${fmtMin(emp.requiredMinutes)} required · ${emp.daysPresent} days present · ${emp.leaveDays} leave days</p>
    <div class="table-wrap"><table>
      <thead><tr><th>Date</th><th>In</th><th>Out</th><th class="r">Hours</th><th></th></tr></thead>
      <tbody>${dates.length ? dates.map((date) => {
        const c = emp.days[date];
        const leaveRow = c.leave ? `<tr><td>${esc(fmtDate(date))}</td><td colspan="4"><span class="pill warn">${c.leave.portion === 0.5 ? 'Half-day leave' : 'Leave'}</span> <span class="muted small">${esc(c.leave.note || '')}</span></td></tr>` : '';
        const halfRow = c.autoHalfDay || c.autoFullDay
          ? `<tr><td>${esc(fmtDate(date))}</td><td colspan="4"><span class="pill warn">${c.autoFullDay ? 'Full-day leave' : 'Half-day leave'}</span> <span class="muted small">automatic: ${esc(c.autoFullDay ? fullDayRule() : halfDayRule())}. Hours worked still count.</span></td></tr>` : '';
        return leaveRow + halfRow + c.sessions.map((s) => `<tr>
          <td>${esc(fmtDate(date))}${s.source !== 'button' ? ` <span class="pill plain" title="${esc(s.note || '')}">${esc(s.source)}${s.edited ? ', edited' : ''}</span>` : (s.edited ? ' <span class="pill plain">edited</span>' : '')}</td>
          <td>${fmtTime12(s.in)}</td>
          <td>${s.out ? fmtTime12(s.out) : '<span class="pill warn">Missing</span>'}</td>
          <td class="r">${s.out ? fmtMin(toMin(s.out) - toMin(s.in)) : '—'}</td>
          <td class="r"><button class="sm" data-edit="${esc(s.id)}">Edit</button> <button class="sm danger" data-del="${esc(s.id)}">Delete</button></td>
        </tr>`).join('');
      }).join('') : '<tr><td colspan="5" class="muted">No entries this month</td></tr>'}</tbody>
    </table></div>
    <div class="form-actions"><button data-close>Close</button></div>`, { wide: true });

  const refresh = async () => {
    dlg.close();
    await renderAttendance();
  };
  const all = dates.flatMap((x) => emp.days[x].sessions);
  dlg.querySelector('[data-add]').addEventListener('click', () => { dlg.close(); sessionForm(null, emp.id, renderAttendance); });
  dlg.querySelectorAll('[data-edit]').forEach((b) => b.addEventListener('click', () => {
    dlg.close();
    sessionForm(all.find((s) => s.id === b.dataset.edit), emp.id, renderAttendance);
  }));
  dlg.querySelectorAll('[data-del]').forEach((b) => b.addEventListener('click', async () => {
    if (!await confirmDialog('Delete this in/out entry?', 'Delete')) return;
    await guard(async () => { await api('DELETE', `/api/admin/sessions/${b.dataset.del}`); toast('Entry deleted'); });
    refresh();
  }));
}

function employeeOptions(selected, includeInactive) {
  return S.employees.filter((e) => e.active || includeInactive || e.id === selected)
    .map((e) => `<option value="${esc(e.id)}" ${e.id === selected ? 'selected' : ''}>${esc(e.name)}</option>`).join('');
}

function sessionForm(s, empId, after) {
  const today = S.settings.today;
  const dlg = openDialog(`
    <h2>${s ? 'Edit entry' : 'Add in/out entry'}</h2>
    <form class="form-grid">
      <label style="grid-column:1/-1">Employee<select name="employeeId" ${s ? 'disabled' : ''} required>${employeeOptions(empId, true)}</select></label>
      <label>Date<input type="date" name="date" value="${s ? s.date : today}" max="${today}" required></label>
      <label>In time<input type="time" name="in" value="${s ? s.in : S.settings.workStart}" required></label>
      <label>Out time<input type="time" name="out" value="${s ? (s.out || '') : S.settings.workEnd}"></label>
      <label style="grid-column:1/-1">Note<input name="note" maxlength="200" value="${esc(s ? s.note || '' : '')}"></label>
      <div class="form-actions" style="grid-column:1/-1">
        <button type="button" data-close>Cancel</button>
        <button class="primary">Save</button>
      </div>
    </form>`);
  dlg.querySelector('form').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const data = formData(ev.target);
    await guard(async () => {
      if (s) await api('PUT', `/api/admin/sessions/${s.id}`, { date: data.date, in: data.in, out: data.out });
      else await api('POST', '/api/admin/sessions', data);
      dlg.close();
      toast('Saved');
      after();
    });
  });
}

// ---------------- leaves ----------------

async function renderLeaves() {
  const leaves = await guard(() => api('GET', `/api/admin/leaves?month=${S.month}`));
  if (!leaves) return;
  await refreshRequests();
  const pending = S.requests.filter((r) => r.status === 'pending');
  const decided = S.requests.filter((r) => r.status !== 'pending').slice(0, 8);
  const reqRow = (r) => `
    <div class="req" data-req="${esc(r.id)}">
      ${person(name(r.employeeId), '')}
      <div class="req-main">
        <div class="dates">${esc(fmtDates(r.dates))}</div>
        <div class="meta">${r.dates.length} day${r.dates.length === 1 ? '' : 's'} · ${r.portion === 0.5 ? 'Half day' : 'Full day'} · requested ${esc(fmtDate(r.createdAt.slice(0, 10)))}</div>
        <div class="reason">“${esc(r.reason)}”</div>
        ${r.adminNote ? `<div class="meta">Your note: ${esc(r.adminNote)}</div>` : ''}
      </div>
      <div class="acts">${r.status === 'pending'
        ? `<button class="sm" data-reject="${esc(r.id)}">${icon('x', 'sm')}Reject</button><button class="sm success" data-approve="${esc(r.id)}">${icon('check', 'sm')}Approve</button>`
        : statusPill(r.status)}</div>
    </div>`;
  const name = (id) => (S.employees.find((e) => e.id === id) || {}).name || '(removed)';
  const perEmp = {};
  leaves.forEach((l) => { perEmp[l.employeeId] = (perEmp[l.employeeId] || 0) + l.portion; });

  view.innerHTML = `
    <div class="section-head">
      <h2>Leaves</h2>
      <div class="spacer"></div>
      ${monthPicker()}
      <div class="sub">Leave requests from staff, and leave recorded for ${esc(fmtMonth(S.month))}</div>
    </div>
    <div class="card" style="margin-bottom:18px">
      <div class="card-head"><h3>${icon('leave')}Leave requests</h3>${pending.length ? `<span class="pill warn">${pending.length} waiting</span>` : ''}</div>
      <div class="req-list">${pending.length ? pending.map(reqRow).join('') : '<p class="muted small" style="margin:0">No requests waiting. Staff can request planned leave from the clock-in page.</p>'}</div>
      ${decided.length ? `<details style="margin-top:14px"><summary class="small muted" style="cursor:pointer">Recent decisions (${decided.length})</summary><div class="req-list" style="margin-top:10px">${decided.map(reqRow).join('')}</div></details>` : ''}
    </div>
    ${S.employees.length ? `
    <div class="card" style="margin-bottom:18px">
      <h3 style="margin-bottom:12px">Record leave</h3>
      <form class="form-grid" id="leave-form">
        <label>Employee<select name="employeeId" required>${employeeOptions()}</select></label>
        <label>From<input type="date" name="date" value="${S.settings.today}" required></label>
        <label>To (optional)<input type="date" name="toDate"></label>
        <label>Type<select name="portion"><option value="1">Full day</option><option value="0.5">Half day</option></select></label>
        <label style="grid-column:1/-1">Note<input name="note" maxlength="200" placeholder="Reason (optional)"></label>
        <div class="form-actions" style="grid-column:1/-1;margin-top:0"><button class="primary">${icon('plus', 'sm')}Add leave</button></div>
      </form>
      <p class="muted small" style="margin:10px 0 0">A leave day is unpaid: salary is reduced by basic ÷ working days, and that day's ${S.settings.hoursPerDay} hours are removed from the required hours. Weekly off days in a range are skipped.${autoRules() ? ` <br>Leave is also counted <b>automatically</b> on working days when someone is present but short: ${esc(autoRules())}. Those days appear in the Attendance tab and are not listed here. Change this rule in Settings.` : ''}</p>
    </div>
    ${Object.keys(perEmp).length ? `<div class="stats">${Object.entries(perEmp).map(([id, n]) =>
      `<div class="stat"><div class="label">${esc(name(id))}</div><div class="value">${n} day${n === 1 ? '' : 's'}</div><div class="hint">recorded in ${esc(fmtMonth(S.month))}</div></div>`).join('')}</div>` : ''}
    <div class="table-wrap"><table>
      <thead><tr><th>Date</th><th>Employee</th><th>Type</th><th>Note</th><th></th></tr></thead>
      <tbody>${leaves.length ? leaves.map((l) => `<tr>
        <td>${esc(fmtDate(l.date))}</td><td>${person(name(l.employeeId), '')}</td>
        <td>${l.portion === 0.5 ? 'Half day' : 'Full day'}</td><td class="muted">${esc(l.note || '')}</td>
        <td class="r"><button class="sm danger" data-del="${esc(l.id)}">Remove</button></td></tr>`).join('')
        : '<tr><td colspan="5" class="muted">No leaves recorded this month</td></tr>'}</tbody>
    </table></div>` : emptyEmployees()}`;

  bindMonthPicker(renderLeaves);
  bindEmptyEmployees();
  const form = view.querySelector('#leave-form');
  if (form) form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const data = formData(form);
    await guard(async () => {
      const added = await api('POST', '/api/admin/leaves', data);
      toast(`${added.length} leave day${added.length === 1 ? '' : 's'} added`);
      S.month = data.date.slice(0, 7);
      renderLeaves();
    });
  });
  view.querySelectorAll('[data-del]').forEach((b) => b.addEventListener('click', async () => {
    await guard(async () => { await api('DELETE', `/api/admin/leaves/${b.dataset.del}`); toast('Leave removed'); renderLeaves(); });
  }));
  view.querySelectorAll('[data-approve]').forEach((b) => b.addEventListener('click', async () => {
    await guard(async () => {
      const r = await api('POST', `/api/admin/leave-requests/${b.dataset.approve}/approve`, {});
      toast(`Approved: ${name(r.employeeId)}, ${fmtDates(r.dates)}`);
      renderLeaves();
    });
  }));
  view.querySelectorAll('[data-reject]').forEach((b) => b.addEventListener('click', () => {
    const dlg = openDialog(`
      <h2>Reject leave request</h2>
      <p class="modal-sub">Optionally tell the employee why. They will see this note.</p>
      <form><label>Note<input name="note" maxlength="200" placeholder="e.g. Stock-taking that week"></label>
      <div class="form-actions"><button type="button" data-close>Cancel</button><button class="danger-solid">Reject request</button></div></form>`);
    dlg.querySelector('form').addEventListener('submit', async (ev) => {
      ev.preventDefault();
      await guard(async () => {
        await api('POST', `/api/admin/leave-requests/${b.dataset.reject}/reject`, formData(ev.target));
        dlg.close();
        toast('Request rejected');
        renderLeaves();
      });
    });
  }));
}

// ---------------- salary ----------------

async function renderSalary() {
  const d = await guard(() => api('GET', `/api/admin/salary?month=${S.month}`));
  if (!d) return;
  const extraPct = 100 - d.hoursPoolPercent;
  const openCount = d.rows.reduce((s, r) => s + r.openSessions, 0);

  view.innerHTML = `
    <div class="section-head">
      <h2>Salary &amp; incentive</h2>
      <div class="spacer"></div>
      ${monthPicker()}
      <button data-csv>${icon('download', 'sm')}Export CSV</button>
      <button data-print>${icon('printer', 'sm')}Print</button>
      <div class="sub">${esc(d.companyName)} · payroll for ${esc(fmtMonth(S.month))}</div>
    </div>

    <div class="card no-print" style="margin-bottom:16px">
      <form class="form-grid" id="month-form">
        <label>Working days in month
          <input type="number" name="workingDays" min="0" max="31" step="0.5" value="${d.workingDays}" required>
        </label>
        <label>Total sales for the month (${esc(d.currency)})
          <input type="number" name="totalSales" min="0" step="0.01" value="${d.totalSales || ''}" placeholder="0.00">
        </label>
        <div class="form-actions" style="align-self:end;margin-top:0">
          ${d.customWorkingDays ? '<button type="button" data-reset-days>Use default days</button>' : ''}
          <button class="primary">Calculate</button>
        </div>
      </form>
      <p class="muted small" style="margin:10px 0 0">Default working days for this month: ${d.defaultWorkingDays} (all days except weekly offs). Change it if there are holidays.</p>
    </div>

    ${openCount ? warnNote(`${openCount} attendance entr${openCount === 1 ? 'y is' : 'ies are'} missing a clock-out and counted as 0 hours. Fix them in the Attendance tab before finalising salaries.`) : ''}

    <div class="stats">
      <div class="stat"><div class="label">Incentive pool (${d.incentivePercent}% of sales)</div><div class="value">${money(d.pool)}</div></div>
      <div class="stat"><div class="label">${d.hoursPoolPercent}% · by hours worked</div><div class="value">${money(d.hoursPool)}</div></div>
      <div class="stat"><div class="label">${extraPct}% · by extra hours</div><div class="value">${money(d.extraPool)}</div></div>
      <div class="stat accent"><div class="label">Total payroll</div><div class="value">${money(d.totals.netPay)}</div></div>
    </div>
    ${d.undistributed > 0 && d.pool > 0 ? warnNote(`${money(d.undistributed)} of the incentive is not distributed because ${d.totals.workedHours > 0 ? 'nobody worked more than their required hours' : 'no hours were recorded'} this month.`) : ''}

    ${d.rows.length ? `
    <div class="table-wrap"><table>
      <thead><tr>
        <th>Employee</th><th class="r">Basic</th><th class="r">Leave days</th><th class="r">Leave cut</th><th class="r">Salary</th>
        <th class="r">Worked h</th><th class="r">Required h</th><th class="r">Extra h</th>
        <th class="r">Hours incentive</th><th class="r">Extra-hours incentive</th><th class="r">Total incentive</th><th class="r">Net pay</th>
      </tr></thead>
      <tbody>${d.rows.map((r) => `<tr>
        <td>${person(r.name, esc(r.position))}</td>
        <td class="r">${money(r.basicSalary)}</td>
        <td class="r">${r.leaveDays}</td>
        <td class="r ${r.leaveDeduction ? 'neg' : ''}">${r.leaveDeduction ? '−' + money(r.leaveDeduction) : '—'}</td>
        <td class="r">${money(r.salaryAfterLeave)}</td>
        <td class="r">${fmtHours(r.workedHours)}</td>
        <td class="r">${fmtHours(r.requiredHours)}</td>
        <td class="r ${r.extraHours ? 'pos' : ''}">${r.extraHours ? '+' + fmtHours(r.extraHours) : (r.shortHours ? `<span class="neg">−${fmtHours(r.shortHours)}</span>` : '0.00')}</td>
        ${r.incentive ? `<td class="r">${money(r.hoursIncentive)}<div class="muted small">${r.hoursShare}%</div></td>
        <td class="r">${money(r.extraIncentive)}<div class="muted small">${r.extraShare}%</div></td>`
        : '<td class="r muted" colspan="2">No incentive</td>'}
        <td class="r">${money(r.totalIncentive)}</td>
        <td class="r"><strong>${money(r.netPay)}</strong></td>
      </tr>`).join('')}</tbody>
      <tfoot><tr>
        <td>Total</td><td class="r">${money(d.totals.basicSalary)}</td><td></td><td class="r">−${money(d.totals.leaveDeduction)}</td>
        <td class="r">${money(d.totals.salaryAfterLeave)}</td><td class="r">${fmtHours(d.totals.workedHours)}</td><td></td>
        <td class="r">${fmtHours(d.totals.extraHours)}</td><td class="r">${money(d.totals.hoursIncentive)}</td>
        <td class="r">${money(d.totals.extraIncentive)}</td><td class="r">${money(d.totals.totalIncentive)}</td><td class="r">${money(d.totals.netPay)}</td>
      </tr></tfoot>
    </table></div>` : emptyEmployees()}

    <div class="note" style="margin-top:18px;display:block;line-height:1.7">
      <strong>How it is calculated</strong><br>
      • <b>Salary</b> = basic − (basic ÷ ${d.workingDays} working days × leave days).<br>
      ${autoRules() ? `• <b>Leave days</b> include recorded leaves plus automatic leave on short days (${esc(autoRules())}). Hours worked on those days still count towards worked and extra hours.<br>` : ''}
      • <b>Required hours</b> = (${d.workingDays} working days − leave days) × ${d.hoursPerDay} h. Full month = ${d.workingDays * d.hoursPerDay} h.<br>
      • <b>Incentive pool</b> = ${d.incentivePercent}% × total sales ${money(d.totalSales)} = ${money(d.pool)}.<br>
      • <b>${d.hoursPoolPercent}%</b> (${money(d.hoursPool)}) is shared by everyone who gets the incentive, in proportion to hours worked (own hours ÷ their combined hours). Staff marked "No incentive" in Employees are left out.<br>
      • <b>${extraPct}%</b> (${money(d.extraPool)}) is shared only by people who worked more than their required hours, in proportion to their extra hours.<br>
      • <b>Net pay</b> = salary + hours incentive + extra-hours incentive.
    </div>`;

  bindMonthPicker(renderSalary);
  bindEmptyEmployees();
  const form = view.querySelector('#month-form');
  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const data = formData(form);
    await guard(async () => {
      await api('PUT', `/api/admin/months/${S.month}`, { workingDays: data.workingDays, totalSales: data.totalSales || 0 });
      toast('Calculated');
      renderSalary();
    });
  });
  const reset = view.querySelector('[data-reset-days]');
  if (reset) reset.addEventListener('click', () => guard(async () => {
    await api('PUT', `/api/admin/months/${S.month}`, { workingDays: null });
    renderSalary();
  }));
  view.querySelector('[data-print]').addEventListener('click', () => window.print());
  view.querySelector('[data-csv]').addEventListener('click', () => {
    const rows = [['Employee', 'Position', 'Basic salary', 'Working days', 'Days present', 'Leave days', 'Leave deduction',
      'Salary after leave', 'Worked hours', 'Required hours', 'Extra hours', 'Hours incentive', 'Extra-hours incentive',
      'Total incentive', 'Net pay']];
    d.rows.forEach((r) => rows.push([r.name, r.position, r.basicSalary, d.workingDays, r.daysPresent, r.leaveDays, r.leaveDeduction,
      r.salaryAfterLeave, r.workedHours, r.requiredHours, r.extraHours, r.hoursIncentive, r.extraIncentive, r.totalIncentive, r.netPay]));
    rows.push([]);
    rows.push(['Total sales', d.totalSales]);
    rows.push(['Incentive pool', d.pool]);
    downloadCsv(`salary-${S.month}.csv`, rows);
  });
}

function downloadCsv(filename, rows) {
  const csv = rows.map((r) => r.map((v) => {
    const s = String(v == null ? '' : v);
    return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
  }).join(',')).join('\n');
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob(['﻿' + csv], { type: 'text/csv' }));
  a.download = filename;
  a.click();
  setTimeout(() => URL.revokeObjectURL(a.href), 1000);
}

// ---------------- employees ----------------

async function renderEmployees() {
  await guard(loadEmployees);
  view.innerHTML = `
    <div class="section-head">
      <h2>Employees</h2>
      <span class="pill plain">${S.employees.filter((e) => e.active).length} active</span>
      <div class="spacer"></div>
      <button class="primary" data-add>${icon('plus', 'sm')}Add employee</button>
      <div class="sub">Team members, salaries, PINs and incentive eligibility</div>
    </div>
    ${S.employees.length ? `
    <div class="table-wrap"><table>
      <thead><tr><th>Name</th><th>Position</th><th class="r">Basic salary</th><th>Joined</th><th>Incentive</th><th>PIN</th><th>Status</th><th></th></tr></thead>
      <tbody>${S.employees.map((e) => `<tr>
        <td>${person(e.name, '')}</td><td>${esc(e.position || '—')}</td>
        <td class="r">${money(e.basicSalary)}</td>
        <td>${e.joinedOn ? esc(fmtDate(e.joinedOn, { day: 'numeric', month: 'short', year: 'numeric' })) : '—'}</td>
        <td>${e.incentive ? '<span class="pill ok">Yes</span>' : '<span class="pill plain">No</span>'}</td>
        <td>${e.hasPin ? `${icon('lock', 'sm')}` : '<span class="muted">—</span>'}</td>
        <td><span class="pill ${e.active ? 'in' : 'out'}">${e.active ? 'Active' : 'Inactive'}</span></td>
        <td class="r"><button class="sm" data-edit="${esc(e.id)}">${icon('edit', 'sm')}Edit</button></td>
      </tr>`).join('')}</tbody>
    </table></div>` : '<div class="card empty"><h2>No employees yet</h2><p>Add each person with their position and basic monthly salary.</p></div>'}
    <p class="muted small">Setting a PIN is optional. When set, the employee must enter it to clock in/out, so nobody can clock in for someone else.</p>`;

  view.querySelector('[data-add]').addEventListener('click', () => employeeForm(null));
  view.querySelectorAll('[data-edit]').forEach((b) => b.addEventListener('click', () =>
    employeeForm(S.employees.find((e) => e.id === b.dataset.edit))));
}

function employeeForm(e) {
  const dlg = openDialog(`
    <h2>${e ? 'Edit employee' : 'Add employee'}</h2>
    <form class="form-grid">
      <label style="grid-column:1/-1">Full name<input name="name" required maxlength="80" value="${esc(e ? e.name : '')}"></label>
      <label>Position<input name="position" maxlength="80" value="${esc(e ? e.position : '')}" placeholder="e.g. Sales Associate"></label>
      <label>Basic monthly salary (${esc(S.settings.currency)})<input type="number" name="basicSalary" min="0" step="0.01" required value="${e ? e.basicSalary : ''}"></label>
      <label>Joining date<input type="date" name="joinedOn" value="${e ? e.joinedOn || '' : S.settings.today}"></label>
      <label>${e && e.hasPin ? 'New PIN (leave blank to keep)' : 'PIN (optional, 4–6 digits)'}<input name="pin" inputmode="numeric" pattern="\\d{4,6}" maxlength="6" autocomplete="off"></label>
      <div class="checks" style="grid-column:1/-1">
        <label><input type="checkbox" name="incentive" ${!e || e.incentive ? 'checked' : ''}> Gets sales incentive</label>
      ${e ? `
        <label><input type="checkbox" name="active" ${e.active ? 'checked' : ''}> Active (shown on clock-in page)</label>
        ${e.hasPin ? '<label><input type="checkbox" name="removePin"> Remove PIN</label>' : ''}` : ''}
      </div>
      <div class="form-actions" style="grid-column:1/-1">
        ${e ? '<button type="button" class="danger" data-delete style="margin-right:auto">Delete</button>' : ''}
        <button type="button" data-close>Cancel</button>
        <button class="primary">${e ? 'Save' : 'Add employee'}</button>
      </div>
    </form>`);
  const form = dlg.querySelector('form');
  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const data = formData(form);
    data.incentive = form.incentive.checked;
    if (e) {
      data.active = form.active.checked;
      data.removePin = form.removePin ? form.removePin.checked : false;
    }
    if (!data.pin) delete data.pin;
    await guard(async () => {
      if (e) await api('PUT', `/api/admin/employees/${e.id}`, data);
      else await api('POST', '/api/admin/employees', data);
      dlg.close();
      toast(e ? 'Employee updated' : `${data.name} added`);
      renderEmployees();
    });
  });
  const del = dlg.querySelector('[data-delete]');
  if (del) del.addEventListener('click', async () => {
    dlg.close();
    if (!await confirmDialog(`Permanently delete ${e.name} and ALL their attendance and leave records? To keep history, mark them Inactive instead.`, 'Delete forever')) return;
    await guard(async () => { await api('DELETE', `/api/admin/employees/${e.id}`); toast('Employee deleted'); renderEmployees(); });
  });
}

// ---------------- settings ----------------

async function renderDevices() {
  const box = view.querySelector('[data-devices]');
  if (!box) return;
  const d = await guard(() => api('GET', '/api/admin/devices'));
  if (!d) return;
  const when = (iso) => (iso ? new Date(iso).toLocaleString('en-IN', { day: 'numeric', month: 'short', hour: 'numeric', minute: '2-digit' }) : 'not yet');
  box.innerHTML = `
    <div class="checks" style="margin:6px 0 14px">
      <label><input type="checkbox" data-restrict ${d.restrict ? 'checked' : ''}> <b>Only approved computers can open the staff page</b></label>
    </div>
    ${d.restrict && !d.devices.length ? warnNote('No computer is approved yet, so nobody can clock in. Approve the office computers below.') : ''}
    ${d.devices.length ? `<div class="table-wrap" style="margin-bottom:14px"><table>
      <thead><tr><th>Computer</th><th>Approved</th><th>Last clock-in</th><th></th></tr></thead>
      <tbody>${d.devices.map((x) => `<tr>
        <td><strong>${esc(x.name)}</strong>${x.current ? ' <span class="pill ok">This computer</span>' : ''}</td>
        <td>${esc(when(x.createdAt))}</td><td>${esc(when(x.lastSeen))}</td>
        <td class="r"><button class="sm danger" data-remove-device="${esc(x.id)}">${icon('trash', 'sm')}Remove</button></td>
      </tr>`).join('')}</tbody></table></div>` : ''}
    <form class="form-grid" data-approve-form>
      <label>${d.currentApproved ? 'This computer is approved. Rename it' : 'Name for this computer'}<input name="name" maxlength="60" required placeholder="e.g. Front desk" value="${esc((d.devices.find((x) => x.current) || {}).name || '')}"></label>
      <div class="form-actions" style="align-self:end;margin-top:0"><button class="primary">${icon('check', 'sm')}${d.currentApproved ? 'Save name' : 'Approve this computer'}</button></div>
    </form>`;

  box.querySelector('[data-restrict]').addEventListener('change', async (ev) => {
    const on = ev.target.checked;
    if (on && !d.devices.length && !await confirmDialog('No computer is approved yet. Turning this on blocks clock-in everywhere until you approve the office computers. Continue?', 'Turn on', false)) {
      ev.target.checked = false;
      return;
    }
    await guard(async () => {
      const next = await api('PUT', '/api/admin/settings', { restrictDevices: on });
      S.settings = { ...S.settings, ...next };
      toast(on ? 'Only approved computers can use the staff page now' : 'Any device can use the staff page now');
    });
    renderDevices();
  });
  box.querySelector('[data-approve-form]').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    await guard(async () => {
      await api('POST', '/api/admin/devices', formData(ev.target));
      toast(d.currentApproved ? 'Name saved' : 'This computer is approved for clock-in');
    });
    renderDevices();
  });
  box.querySelectorAll('[data-remove-device]').forEach((b) => b.addEventListener('click', async () => {
    if (!await confirmDialog('Remove this computer? It will no longer be able to open the staff page (if the limit is on).', 'Remove')) return;
    await guard(() => api('DELETE', `/api/admin/devices/${b.dataset.removeDevice}`));
    renderDevices();
  }));
}

function renderSettings() {
  const s = S.settings;
  view.innerHTML = `
    <div class="section-head"><h2>Settings</h2><div class="sub">Working hours, leave rules, incentive and security</div></div>
    <div class="card" style="margin-bottom:18px">
      <h2 style="margin-bottom:14px">Company &amp; working hours</h2>
      <form class="form-grid" id="settings-form">
        <label>Company name<input name="companyName" value="${esc(s.companyName)}" required></label>
        <label>Currency symbol<input name="currency" value="${esc(s.currency)}" maxlength="5"></label>
        <label>Timezone<input name="timezone" value="${esc(s.timezone)}" placeholder="Asia/Kolkata" required></label>
        <label>Office start<input type="time" name="workStart" value="${esc(s.workStart)}" required></label>
        <label>Office end<input type="time" name="workEnd" value="${esc(s.workEnd)}" required></label>
        <label>Required hours per day<input type="number" name="hoursPerDay" min="1" max="24" step="0.25" value="${s.hoursPerDay}" required></label>
        <label>Half-day leave when short by (hours, 0 = off)<input type="number" name="halfDayShortHours" min="0" max="24" step="0.25" value="${s.halfDayShortHours ?? 2}" required></label>
        <label>Full-day leave when short by more than (hours, 0 = off)<input type="number" name="fullDayShortHours" min="0" max="24" step="0.25" value="${s.fullDayShortHours ?? 4.5}" required></label>
        <div style="grid-column:1/-1"><div class="small muted" style="margin-bottom:6px;font-weight:500">Weekly off days</div>
          <div class="checks">${DAYS.map((dname, i) => `<label><input type="checkbox" name="off" value="${i}" ${s.weeklyOffs.includes(i) ? 'checked' : ''}> ${dname}</label>`).join('')}</div>
        </div>
        <h3 style="grid-column:1/-1;margin-top:8px">Incentive</h3>
        <label>Incentive (% of total sales)<input type="number" name="incentivePercent" min="0" max="100" step="0.01" value="${s.incentivePercent}" required></label>
        <label>Share by hours worked (%)<input type="number" name="hoursPoolPercent" min="0" max="100" step="1" value="${s.hoursPoolPercent}" required></label>
        <label>Share by extra hours (%)<input value="${100 - s.hoursPoolPercent}" disabled data-extra></label>
        <div class="form-actions" style="grid-column:1/-1"><button class="primary">Save settings</button></div>
      </form>
    </div>
    <div class="card" style="margin-bottom:18px" id="devices-card">
      <h2 style="margin-bottom:6px">Clock-in computers</h2>
      <p class="muted small" style="margin-top:0">Limit the staff clock-in page to the office computers. Approve each computer by opening this admin panel <b>on that computer</b>. Other phones and computers will see a “not approved” message. The admin panel itself still works from anywhere with the password.</p>
      <div data-devices><p class="muted small">Loading…</p></div>
    </div>
    <div class="card">
      <h2 style="margin-bottom:14px">Change admin password</h2>
      <form class="form-grid" id="pw-form">
        <label>Current password<input type="password" name="current" autocomplete="current-password" required></label>
        <label>New password<input type="password" name="password" autocomplete="new-password" minlength="6" required></label>
        <label>Confirm new password<input type="password" name="confirm" autocomplete="new-password" minlength="6" required></label>
        <div class="form-actions" style="grid-column:1/-1"><button class="primary">Change password</button></div>
      </form>
    </div>
    <div class="card" style="margin-top:18px">
      <h2 style="margin-bottom:8px;color:var(--bad)">Clear attendance data</h2>
      <p class="muted small" style="margin-top:0">Deletes every in/out entry, leave, leave request and monthly figure (working days, sales). Employees and settings are kept. A backup copy is saved in the <code>data</code> folder first.</p>
      <form class="form-grid" id="clear-form">
        <label>Admin password<input type="password" name="password" autocomplete="current-password" required></label>
        <div class="form-actions" style="align-self:end;margin-top:0"><button class="go-out">Clear attendance data</button></div>
      </form>
    </div>`;

  const f = view.querySelector('#settings-form');
  f.hoursPoolPercent.addEventListener('input', () => {
    f.querySelector('[data-extra]').value = 100 - (Number(f.hoursPoolPercent.value) || 0);
  });
  f.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const data = formData(f);
    delete data.off;
    data.weeklyOffs = [...f.querySelectorAll('[name=off]:checked')].map((x) => Number(x.value));
    await guard(async () => {
      const next = await api('PUT', '/api/admin/settings', data);
      S.settings = { ...S.settings, ...next };
      document.querySelectorAll('[data-company]').forEach((el) => { el.textContent = S.settings.companyName; });
      toast('Settings saved');
    });
  });
  renderDevices();
  const cf = view.querySelector('#clear-form');
  cf.closest('.card').style.borderColor = 'color-mix(in srgb, var(--bad) 30%, var(--border))';
  cf.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const { password } = formData(cf);
    if (!await confirmDialog('Delete ALL attendance, leave and monthly sales data? Employees are kept. This cannot be undone from the app.', 'Clear data')) return;
    try {
      const r = await api('POST', '/api/admin/clear-data', { password });
      cf.reset();
      toast(`Cleared ${r.removed.sessions} entries and ${r.removed.leaves} leaves. Backup: ${r.backup}`);
    } catch (e) { toast(e.message, true); }
  });
  const pw = view.querySelector('#pw-form');
  pw.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const data = formData(pw);
    if (data.password !== data.confirm) return toast('New passwords do not match', true);
    try {
      await api('POST', '/api/admin/password', { current: data.current, password: data.password });
      pw.reset();
      toast('Password changed');
    } catch (e) { toast(e.message, true); }
  });
}

boot().catch((e) => toast(e.message, true));
