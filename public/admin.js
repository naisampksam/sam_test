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
  document.querySelectorAll('[data-company]').forEach((el) => { el.textContent = st.companyName; });
  if (st.loggedIn) return startApp();
  showAuth(st.setupRequired);
}

function showAuth(setup) {
  document.getElementById('app').classList.add('hidden');
  document.getElementById('auth').classList.remove('hidden');
  const form = document.getElementById('login-form');
  form.dataset.setup = setup ? '1' : '';
  form.querySelector('[data-confirm]').classList.toggle('hidden', !setup);
  form.querySelector('[data-setup-msg]').textContent = setup
    ? 'First-time setup: choose an admin password (at least 6 characters). Only people with this password can add employees, edit attendance and see salaries.'
    : '';
  form.querySelector('[data-submit]').textContent = setup ? 'Set password' : 'Log in';
  form.querySelector('[name=password]').autocomplete = setup ? 'new-password' : 'current-password';
  form.querySelector('[name=password]').focus();
}

document.getElementById('login-form').addEventListener('submit', async (ev) => {
  ev.preventDefault();
  const form = ev.target;
  const { password, confirm } = formData(form);
  try {
    if (form.dataset.setup) {
      if (password !== confirm) return toast('Passwords do not match', true);
      await api('POST', '/api/admin/setup', { password });
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
    renderTab();
  });
}

async function loadEmployees() {
  S.employees = await api('GET', '/api/admin/employees');
}

document.querySelector('.tabs').addEventListener('click', (ev) => {
  const b = ev.target.closest('[data-tab]');
  if (!b) return;
  S.tab = b.dataset.tab;
  document.querySelectorAll('.tabs button').forEach((x) => x.classList.toggle('active', x === b));
  renderTab();
});

function renderTab() {
  view.innerHTML = '<p class="muted">Loading…</p>';
  ({ today: renderToday, attendance: renderAttendance, leaves: renderLeaves, salary: renderSalary,
    employees: renderEmployees, settings: renderSettings })[S.tab]();
}

// ---------------- today ----------------

async function renderToday() {
  const d = await guard(() => api('GET', '/api/admin/today'));
  if (!d) return;
  const inNow = d.employees.filter((e) => e.status === 'in').length;
  const onLeave = d.employees.filter((e) => e.onLeave).length;
  const notYet = d.employees.filter((e) => !e.sessions.length && !e.onLeave).length;
  const total = d.employees.reduce((s, e) => s + e.todayMinutes, 0);
  view.innerHTML = `
    <div class="section-head">
      <h2>${esc(fmtDate(d.today, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }))}</h2>
      <div class="spacer"></div>
      <button data-refresh>Refresh</button>
    </div>
    <div class="stats">
      <div class="stat"><div class="label">In office now</div><div class="value">${inNow} / ${d.employees.length}</div></div>
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
          <td><strong>${esc(e.name)}</strong><div class="muted small">${esc(e.position)}</div></td>
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
  bindEmptyEmployees();
}

function emptyEmployees() {
  return `<div class="card empty"><h2>No employees yet</h2><p>Add your team to start recording attendance.</p>
    <button class="primary" data-go-emp>Add employees</button></div>`;
}
function bindEmptyEmployees() {
  const b = view.querySelector('[data-go-emp]');
  if (b) b.addEventListener('click', () => document.querySelector('[data-tab=employees]').click());
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
      <h2>Attendance · ${esc(fmtMonth(S.month))}</h2>
      <div class="spacer"></div>
      ${monthPicker()}
      <button data-add>Add entry</button>
      <button data-csv>Export CSV</button>
    </div>
    <div class="stats">
      <div class="stat"><div class="label">Working days</div><div class="value">${d.workingDays}</div></div>
      <div class="stat"><div class="label">Hours per day</div><div class="value">${d.hoursPerDay}</div></div>
      <div class="stat"><div class="label">Full-month hours</div><div class="value">${d.workingDays * d.hoursPerDay}</div></div>
    </div>
    ${openCount ? `<div class="note warn" style="margin-bottom:14px">${openCount} entr${openCount === 1 ? 'y has' : 'ies have'} no clock-out yet. Open entries count as 0 hours until an out time is added. Click an employee to fix.</div>` : ''}
    ${d.employees.length ? `
    <div class="table-wrap"><table>
      <thead><tr><th>Employee</th><th class="r">Days present</th><th class="r">Leave days</th><th class="r">Worked</th><th class="r">Required</th><th class="r">Extra / short</th><th></th></tr></thead>
      <tbody>${d.employees.map((e) => {
        const diff = e.totalMinutes - e.requiredMinutes;
        return `<tr class="clickable" data-emp="${esc(e.id)}">
          <td><strong>${esc(e.name)}</strong><div class="muted small">${esc(e.position)}${e.active ? '' : ' · inactive'}</div></td>
          <td class="r">${e.daysPresent}</td>
          <td class="r">${e.leaveDays}${e.autoHalfDays ? `<div class="muted small">incl. ${e.autoHalfDays} × ½ auto</div>` : ''}</td>
          <td class="r">${fmtMin(e.totalMinutes)}</td>
          <td class="r">${fmtMin(e.requiredMinutes)}</td>
          <td class="r ${diff >= 0 ? 'pos' : 'neg'}">${diff >= 0 ? '+' : '−'}${fmtMin(Math.abs(diff))}</td>
          <td>${e.openSessions ? '<span class="pill warn">Missing out</span>' : ''} <button class="sm">Details</button></td>
        </tr>`;
      }).join('')}</tbody>
    </table></div>

    <h3 style="margin:24px 0 10px">Daily hours</h3>
    <div class="table-wrap"><table class="small">
      <thead><tr><th>Employee</th>${d.dates.map((x) => `<th class="r" title="${esc(fmtDate(x))}" style="${offs.includes(wd(x)) ? 'opacity:.5' : ''}">${day(x)}<br>${DAYS[wd(x)].slice(0, 2)}</th>`).join('')}<th class="r">Total</th></tr></thead>
      <tbody>${d.employees.map((e) => `<tr>
        <td>${esc(e.name)}</td>
        ${d.dates.map((x) => {
          const c = e.days[x];
          if (!c) return `<td class="r muted" style="${offs.includes(wd(x)) ? 'opacity:.5' : ''}">·</td>`;
          if (c.leave && !c.sessions.length) return `<td class="r" title="Leave"><span class="pill warn">${c.leave.portion === 0.5 ? '½L' : 'L'}</span></td>`;
          return `<td class="r ${c.open ? 'neg' : ''}" title="${esc(c.sessions.map((s) => s.in + '–' + (s.out || '?')).join(', '))}">${(c.minutes / 60).toFixed(1)}${c.open ? '!' : ''}${c.running ? '…' : ''}${c.late ? '<sup>L</sup>' : ''}${c.autoHalfDay ? ' <span class="pill warn" title="Half-day leave">½</span>' : ''}</td>`;
        }).join('')}
        <td class="r"><strong>${(e.totalMinutes / 60).toFixed(1)}</strong></td>
      </tr>`).join('')}</tbody>
    </table></div>
    <p class="muted small">Hours in decimals. <sup>L</sup> late arrival · ! missing clock-out · … still clocked in · L leave · ½L half-day leave${halfDayRule() ? ` · ½ automatic half-day leave (${esc(halfDayRule())})` : ''}.</p>
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
        s.out ? ((toMin(s.out) - toMin(s.in)) / 60).toFixed(2) : '', s.source, c.leave ? c.leave.portion : (c.autoHalfDay ? '0.5 (auto)' : '')]));
    }));
    downloadCsv(`attendance-${S.month}.csv`, rows);
  });
}

function halfDayRule() {
  const h = Number(S.settings.halfDayShortHours);
  return h > 0 ? `worked ${S.settings.hoursPerDay - h} h or less (${h}+ h short)` : null;
}

function toMin(t) { const [h, m] = t.split(':').map(Number); return h * 60 + m; }

function employeeDetail(emp, d) {
  const dates = Object.keys(emp.days).sort();
  const dlg = openDialog(`
    <div class="section-head">
      <h2>${esc(emp.name)} · ${esc(fmtMonth(S.month))}</h2>
      <div class="spacer"></div>
      <button class="primary sm" data-add>Add entry</button>
    </div>
    <p class="muted small">Worked ${fmtMin(emp.totalMinutes)} of ${fmtMin(emp.requiredMinutes)} required · ${emp.daysPresent} days present · ${emp.leaveDays} leave days</p>
    <div class="table-wrap"><table>
      <thead><tr><th>Date</th><th>In</th><th>Out</th><th class="r">Hours</th><th></th></tr></thead>
      <tbody>${dates.length ? dates.map((date) => {
        const c = emp.days[date];
        const leaveRow = c.leave ? `<tr><td>${esc(fmtDate(date))}</td><td colspan="4"><span class="pill warn">${c.leave.portion === 0.5 ? 'Half-day leave' : 'Leave'}</span> <span class="muted small">${esc(c.leave.note || '')}</span></td></tr>` : '';
        const halfRow = c.autoHalfDay ? `<tr><td>${esc(fmtDate(date))}</td><td colspan="4"><span class="pill warn">Half-day leave</span> <span class="muted small">automatic: ${esc(halfDayRule())}</span></td></tr>` : '';
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
  const name = (id) => (S.employees.find((e) => e.id === id) || {}).name || '(removed)';
  const perEmp = {};
  leaves.forEach((l) => { perEmp[l.employeeId] = (perEmp[l.employeeId] || 0) + l.portion; });

  view.innerHTML = `
    <div class="section-head">
      <h2>Leaves · ${esc(fmtMonth(S.month))}</h2>
      <div class="spacer"></div>
      ${monthPicker()}
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
        <div class="form-actions" style="grid-column:1/-1;margin-top:0"><button class="primary">Add leave</button></div>
      </form>
      <p class="muted small" style="margin:10px 0 0">A leave day is unpaid: salary is reduced by basic ÷ working days, and that day's ${S.settings.hoursPerDay} hours are removed from the required hours. Weekly off days in a range are skipped.${halfDayRule() ? ` <br>Half-day leave is also counted <b>automatically</b> on any working day someone ${esc(halfDayRule())}; those days appear in the Attendance tab and are not listed here. Change this rule in Settings.` : ''}</p>
    </div>
    ${Object.keys(perEmp).length ? `<div class="stats">${Object.entries(perEmp).map(([id, n]) =>
      `<div class="stat"><div class="label">${esc(name(id))}</div><div class="value">${n} day${n === 1 ? '' : 's'}</div></div>`).join('')}</div>` : ''}
    <div class="table-wrap"><table>
      <thead><tr><th>Date</th><th>Employee</th><th>Type</th><th>Note</th><th></th></tr></thead>
      <tbody>${leaves.length ? leaves.map((l) => `<tr>
        <td>${esc(fmtDate(l.date))}</td><td>${esc(name(l.employeeId))}</td>
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
}

// ---------------- salary ----------------

async function renderSalary() {
  const d = await guard(() => api('GET', `/api/admin/salary?month=${S.month}`));
  if (!d) return;
  const extraPct = 100 - d.hoursPoolPercent;
  const openCount = d.rows.reduce((s, r) => s + r.openSessions, 0);

  view.innerHTML = `
    <div class="section-head">
      <h2>Salary &amp; incentive · ${esc(fmtMonth(S.month))}</h2>
      <div class="spacer"></div>
      ${monthPicker()}
      <button data-csv>Export CSV</button>
      <button data-print>Print</button>
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

    ${openCount ? `<div class="note warn" style="margin-bottom:14px">${openCount} attendance entr${openCount === 1 ? 'y is' : 'ies are'} missing a clock-out and counted as 0 hours. Fix them in the Attendance tab before finalising salaries.</div>` : ''}

    <div class="stats">
      <div class="stat"><div class="label">Incentive pool (${d.incentivePercent}% of sales)</div><div class="value">${money(d.pool)}</div></div>
      <div class="stat"><div class="label">${d.hoursPoolPercent}% · by hours worked</div><div class="value">${money(d.hoursPool)}</div></div>
      <div class="stat"><div class="label">${extraPct}% · by extra hours</div><div class="value">${money(d.extraPool)}</div></div>
      <div class="stat"><div class="label">Total payroll</div><div class="value">${money(d.totals.netPay)}</div></div>
    </div>
    ${d.undistributed > 0 && d.pool > 0 ? `<div class="note warn" style="margin-bottom:14px">${money(d.undistributed)} of the incentive is not distributed because ${d.totals.workedHours > 0 ? 'nobody worked more than their required hours' : 'no hours were recorded'} this month.</div>` : ''}

    ${d.rows.length ? `
    <div class="table-wrap"><table>
      <thead><tr>
        <th>Employee</th><th class="r">Basic</th><th class="r">Leave days</th><th class="r">Leave cut</th><th class="r">Salary</th>
        <th class="r">Worked h</th><th class="r">Required h</th><th class="r">Extra h</th>
        <th class="r">Hours incentive</th><th class="r">Extra-hours incentive</th><th class="r">Total incentive</th><th class="r">Net pay</th>
      </tr></thead>
      <tbody>${d.rows.map((r) => `<tr>
        <td><strong>${esc(r.name)}</strong><div class="muted small">${esc(r.position)}</div></td>
        <td class="r">${money(r.basicSalary)}</td>
        <td class="r">${r.leaveDays}</td>
        <td class="r ${r.leaveDeduction ? 'neg' : ''}">${r.leaveDeduction ? '−' + money(r.leaveDeduction) : '—'}</td>
        <td class="r">${money(r.salaryAfterLeave)}</td>
        <td class="r">${fmtHours(r.workedHours)}</td>
        <td class="r">${fmtHours(r.requiredHours)}</td>
        <td class="r ${r.extraHours ? 'pos' : ''}">${r.extraHours ? '+' + fmtHours(r.extraHours) : (r.shortHours ? `<span class="neg">−${fmtHours(r.shortHours)}</span>` : '0.00')}</td>
        <td class="r">${money(r.hoursIncentive)}<div class="muted small">${r.hoursShare}%</div></td>
        <td class="r">${money(r.extraIncentive)}<div class="muted small">${r.extraShare}%</div></td>
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

    <div class="note" style="margin-top:18px">
      <strong>How it is calculated</strong><br>
      • <b>Salary</b> = basic − (basic ÷ ${d.workingDays} working days × leave days).<br>
      ${halfDayRule() ? `• <b>Leave days</b> include recorded leaves plus an automatic ½ day for each working day someone ${esc(halfDayRule())}.<br>` : ''}
      • <b>Required hours</b> = (${d.workingDays} working days − leave days) × ${d.hoursPerDay} h. Full month = ${d.workingDays * d.hoursPerDay} h.<br>
      • <b>Incentive pool</b> = ${d.incentivePercent}% × total sales ${money(d.totalSales)} = ${money(d.pool)}.<br>
      • <b>${d.hoursPoolPercent}%</b> (${money(d.hoursPool)}) is shared by everyone in proportion to hours worked (own hours ÷ everyone's hours).<br>
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
      <span class="muted">${S.employees.filter((e) => e.active).length} active</span>
      <div class="spacer"></div>
      <button class="primary" data-add>Add employee</button>
    </div>
    ${S.employees.length ? `
    <div class="table-wrap"><table>
      <thead><tr><th>Name</th><th>Position</th><th class="r">Basic salary</th><th>Joined</th><th>PIN</th><th>Status</th><th></th></tr></thead>
      <tbody>${S.employees.map((e) => `<tr>
        <td><strong>${esc(e.name)}</strong></td><td>${esc(e.position || '—')}</td>
        <td class="r">${money(e.basicSalary)}</td>
        <td>${e.joinedOn ? esc(fmtDate(e.joinedOn, { day: 'numeric', month: 'short', year: 'numeric' })) : '—'}</td>
        <td>${e.hasPin ? 'Set' : '<span class="muted">None</span>'}</td>
        <td><span class="pill ${e.active ? 'in' : 'out'}">${e.active ? 'Active' : 'Inactive'}</span></td>
        <td class="r"><button class="sm" data-edit="${esc(e.id)}">Edit</button></td>
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
      ${e ? `
      <div class="checks" style="grid-column:1/-1">
        <label><input type="checkbox" name="active" ${e.active ? 'checked' : ''}> Active (shown on clock-in page)</label>
        ${e.hasPin ? '<label><input type="checkbox" name="removePin"> Remove PIN</label>' : ''}
      </div>` : ''}
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

function renderSettings() {
  const s = S.settings;
  view.innerHTML = `
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
    <div class="card">
      <h2 style="margin-bottom:14px">Change admin password</h2>
      <form class="form-grid" id="pw-form">
        <label>Current password<input type="password" name="current" autocomplete="current-password" required></label>
        <label>New password<input type="password" name="password" autocomplete="new-password" minlength="6" required></label>
        <label>Confirm new password<input type="password" name="confirm" autocomplete="new-password" minlength="6" required></label>
        <div class="form-actions" style="grid-column:1/-1"><button class="primary">Change password</button></div>
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
