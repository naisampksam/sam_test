'use strict';

let state = null;
let loadedAt = 0;

async function load() {
  try {
    state = await api('GET', '/api/public/status');
    loadedAt = Date.now();
    render();
    tick();
  } catch (e) {
    toast(e.message, true);
  }
}

function liveMinutes(emp) {
  // add minutes elapsed since the last refresh for someone currently in
  if (emp.status !== 'in') return emp.todayMinutes;
  return emp.todayMinutes + Math.floor((Date.now() - loadedAt) / 60000);
}

function render() {
  document.getElementById('company').textContent = state.companyName;
  document.title = `${state.companyName} · Attendance`;
  document.getElementById('hours-note').textContent =
    `Office hours ${fmtTime12(state.workStart)} – ${fmtTime12(state.workEnd)}`;
  document.getElementById('today').textContent = fmtDate(state.today, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

  const grid = document.getElementById('grid');
  document.getElementById('empty').classList.toggle('hidden', state.employees.length > 0);

  const stale = state.employees.filter((e) => e.staleOpen).map((e) => e.name);
  const staleEl = document.getElementById('stale');
  staleEl.classList.toggle('hidden', !stale.length);
  staleEl.textContent = stale.length
    ? `Forgot to clock out on an earlier day: ${stale.join(', ')}. Please ask the admin to correct it.`
    : '';

  grid.innerHTML = state.employees.map((e) => {
    const isIn = e.status === 'in';
    const chips = e.sessions.map((s) =>
      `<span class="chip ${s.source === 'manual' ? 'manual' : ''}" title="${s.source === 'manual' ? 'Entered manually' : ''}">${fmtTime12(s.in)} – ${s.out ? fmtTime12(s.out) : 'now'}</span>`
    ).join('');
    return `
      <article class="card emp ${isIn ? 'is-in' : ''}" data-id="${esc(e.id)}">
        <div class="emp-head">
          <div class="avatar">${esc(initials(e.name))}</div>
          <div style="min-width:0">
            <div class="emp-name">${esc(e.name)}</div>
            <div class="muted small">${esc(e.position || '—')}</div>
          </div>
        </div>
        <div class="emp-hours">
          <div class="row" style="gap:6px">
            <span class="pill ${isIn ? 'in' : 'out'}">${isIn ? 'In · ' + fmtTime12(e.since) : 'Out'}</span>
            ${e.onLeave ? '<span class="pill warn">On leave</span>' : ''}
          </div>
          <div style="text-align:right">
            <div class="big" data-live>${fmtMin(liveMinutes(e))}</div>
            <div class="muted small">worked today</div>
          </div>
        </div>
        <div class="timeline">${chips || '<span class="muted small">No entries yet today</span>'}</div>
        <button class="big ${isIn ? 'go-out' : 'go-in'}" data-act="${isIn ? 'out' : 'in'}">${isIn ? 'Clock Out' : 'Clock In'}</button>
        <div class="row small">
          <button class="link" data-manual>Enter time manually</button>
          <div class="spacer"></div>
          <button class="link" data-month>My monthly hours</button>
        </div>
      </article>`;
  }).join('');
}

function empById(id) { return state.employees.find((e) => e.id === id); }

function askPin(emp, title) {
  return new Promise((resolve) => {
    if (!emp.hasPin) return resolve('');
    const dlg = openDialog(`
      <h2>${esc(title)}</h2>
      <p class="muted">${esc(emp.name)}, enter your PIN</p>
      <form>
        <input name="pin" type="password" inputmode="numeric" autocomplete="off" maxlength="6" required autofocus
          style="width:100%;font-size:22px;text-align:center;letter-spacing:.4em">
        <div class="form-actions">
          <button type="button" data-close>Cancel</button>
          <button class="primary">Continue</button>
        </div>
      </form>`);
    let pin = null;
    dlg.querySelector('form').addEventListener('submit', (ev) => {
      ev.preventDefault();
      pin = dlg.querySelector('input').value.trim();
      dlg.close();
    });
    dlg.addEventListener('close', () => resolve(pin));
  });
}

async function punch(emp, action, btn) {
  const pin = await askPin(emp, action === 'in' ? 'Clock In' : 'Clock Out');
  if (pin === null) return;
  btn.disabled = true;
  try {
    const r = await api('POST', '/api/punch', { employeeId: emp.id, action, pin });
    toast(action === 'in'
      ? `${emp.name} clocked in at ${fmtTime12(r.since)}`
      : `${emp.name} clocked out · ${fmtMin(r.todayMinutes)} today`);
  } catch (e) {
    toast(e.message, true);
  }
  load();
}

function manualEntry(emp) {
  const dlg = openDialog(`
    <h2>Enter time manually</h2>
    <p class="muted small">${esc(emp.name)} — add one in/out period. Manual entries are marked for the admin to review.</p>
    <form class="form-grid">
      <label>Date<input type="date" name="date" value="${state.today}" max="${state.today}" required></label>
      <label>In time<input type="time" name="in" value="${esc(state.workStart)}" required></label>
      <label>Out time<input type="time" name="out" value="${esc(state.workEnd)}" required></label>
      <label style="grid-column:1/-1">Note (optional)<input name="note" maxlength="200" placeholder="e.g. forgot to clock in"></label>
      ${emp.hasPin ? '<label>PIN<input name="pin" type="password" inputmode="numeric" maxlength="6" required></label>' : ''}
      <div class="form-actions" style="grid-column:1/-1">
        <button type="button" data-close>Cancel</button>
        <button class="primary">Save entry</button>
      </div>
    </form>`);
  dlg.querySelector('form').addEventListener('submit', async (ev) => {
    ev.preventDefault();
    const data = formData(ev.target);
    try {
      await api('POST', '/api/manual', { employeeId: emp.id, ...data });
      dlg.close();
      toast('Entry saved');
      load();
    } catch (e) { toast(e.message, true); }
  });
}

async function myMonth(emp) {
  const pin = await askPin(emp, 'My monthly hours');
  if (pin === null) return;
  const month = state.today.slice(0, 7);
  let data;
  try { data = await api('POST', '/api/my', { employeeId: emp.id, pin, month }); } catch (e) { return toast(e.message, true); }

  const dlg = openDialog('<div data-body></div>', { wide: true });
  const body = dlg.querySelector('[data-body]');
  const draw = (d) => {
    const diff = d.totalMinutes - d.requiredMinutes;
    body.innerHTML = `
      <div class="section-head">
        <h2>${esc(d.name)} · ${esc(fmtMonth(d.month))}</h2>
        <div class="spacer"></div>
        <input type="month" value="${d.month}" max="${state.today.slice(0, 7)}" data-m>
      </div>
      <div class="stats">
        <div class="stat"><div class="label">Worked</div><div class="value">${fmtMin(d.totalMinutes)}</div></div>
        <div class="stat"><div class="label">Required</div><div class="value">${fmtMin(d.requiredMinutes)}</div></div>
        <div class="stat"><div class="label">${diff >= 0 ? 'Extra' : 'Short by'}</div><div class="value ${diff >= 0 ? 'pos' : 'neg'}">${fmtMin(Math.abs(diff))}</div></div>
        <div class="stat"><div class="label">Days present</div><div class="value">${d.daysPresent}</div></div>
        <div class="stat"><div class="label">Leave days</div><div class="value">${d.leaveDays}</div></div>
      </div>
      <p class="muted small">Required = (${d.workingDays} working days − ${d.leaveDays} leave) × ${d.hoursPerDay} h</p>
      <div class="table-wrap"><table>
        <thead><tr><th>Date</th><th>In / Out</th><th class="r">Hours</th></tr></thead>
        <tbody>${d.days.length ? d.days.map((x) => `
          <tr><td>${esc(fmtDate(x.date))}</td>
          <td class="small">${x.leave ? '<span class="pill warn">Leave</span> ' : ''}${x.sessions.map((s) => `${fmtTime12(s.in)}–${s.out ? fmtTime12(s.out) : '…'}`).join(', ')}</td>
          <td class="r">${fmtMin(x.minutes)}</td></tr>`).join('') : '<tr><td colspan="3" class="muted">No attendance this month</td></tr>'}
        </tbody></table></div>
      <div class="form-actions"><button data-close>Close</button></div>`;
    body.querySelector('[data-close]').addEventListener('click', () => dlg.close());
    body.querySelector('[data-m]').addEventListener('change', async (ev) => {
      try { draw(await api('POST', '/api/my', { employeeId: emp.id, pin, month: ev.target.value })); } catch (e) { toast(e.message, true); }
    });
  };
  draw(data);
}

document.getElementById('grid').addEventListener('click', (ev) => {
  const card = ev.target.closest('[data-id]');
  if (!card) return;
  const emp = empById(card.dataset.id);
  const btn = ev.target.closest('button');
  if (!btn || !emp) return;
  if (btn.dataset.act) punch(emp, btn.dataset.act, btn);
  else if ('manual' in btn.dataset) manualEntry(emp);
  else if ('month' in btn.dataset) myMonth(emp);
});

function tick() {
  const d = new Date();
  let t;
  try {
    t = d.toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit', second: '2-digit', timeZone: state ? state.timezone : undefined });
  } catch (e) {
    t = d.toLocaleTimeString('en-IN', { hour: 'numeric', minute: '2-digit', second: '2-digit' });
  }
  document.getElementById('clock').textContent = t;
  if (state) {
    document.querySelectorAll('[data-id]').forEach((card) => {
      const emp = empById(card.dataset.id);
      if (emp) card.querySelector('[data-live]').textContent = fmtMin(liveMinutes(emp));
    });
  }
}

load();
tick();
setInterval(tick, 1000);
setInterval(() => { if (!document.querySelector('dialog[open]')) load(); }, 30000);
