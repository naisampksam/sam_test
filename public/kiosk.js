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
    if (!state) {
      const el = document.getElementById('stale');
      el.classList.remove('hidden');
      el.innerHTML = `${icon('alert')}<span>Could not load the staff list: <b>${esc(e.message)}</b>. Please tell the admin.</span>`;
    }
  }
}

function liveMinutes(emp) {
  // add minutes elapsed since the last refresh for someone currently in
  if (emp.status !== 'in') return emp.todayMinutes;
  return emp.todayMinutes + Math.floor((Date.now() - loadedAt) / 60000);
}

function greeting() {
  const h = Number((state.now || '12:00').slice(0, 2));
  return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
}

function render() {
  document.getElementById('company').textContent = state.companyName;
  document.title = `${state.companyName} · Attendance`;
  document.getElementById('hours-note').textContent =
    `Office hours ${fmtTime12(state.workStart)} – ${fmtTime12(state.workEnd)}`;
  document.getElementById('today').textContent = fmtDate(state.today, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });
  document.getElementById('greeting').textContent = greeting();

  const emps = state.employees;
  const inNow = emps.filter((e) => e.status === 'in').length;
  const onLeave = emps.filter((e) => e.onLeave).length;
  document.getElementById('summary').innerHTML = emps.length ? `
    <span class="chip-stat"><b>${inNow}</b> of ${emps.length} in office</span>
    ${onLeave ? `<span class="chip-stat"><b>${onLeave}</b> on leave</span>` : ''}` : '';

  document.getElementById('empty').classList.toggle('hidden', emps.length > 0);

  const stale = emps.filter((e) => e.staleOpen).map((e) => e.name);
  const staleEl = document.getElementById('stale');
  staleEl.classList.toggle('hidden', !stale.length);
  staleEl.innerHTML = stale.length
    ? `${icon('alert')}<span>Forgot to clock out on an earlier day: <b>${esc(stale.join(', '))}</b>. Please ask the admin to correct it.</span>`
    : '';

  document.getElementById('grid').innerHTML = emps.map((e) => {
    const isIn = e.status === 'in';
    const chips = e.sessions.map((s) =>
      `<span class="chip ${s.source === 'manual' ? 'manual' : ''}" title="${s.source === 'manual' ? 'Entered manually' : ''}">${fmtTime12(s.in)} – ${s.out ? fmtTime12(s.out) : 'now'}</span>`
    ).join('');
    return `
      <article class="card emp ${isIn ? 'is-in' : ''}" data-id="${esc(e.id)}">
        <div class="emp-head">
          <div class="avatar">${esc(initials(e.name))}</div>
          <div style="min-width:0;flex:1">
            <div class="emp-name">${esc(e.name)}</div>
            <div class="emp-role">${esc(e.position || 'Team member')}</div>
          </div>
        </div>
        <div>
          <div class="emp-hours">
            <span class="big" data-live>${fmtMin(liveMinutes(e))}</span><span class="muted small">of ${state.hoursPerDay} h today</span>
            <span class="spacer"></span>
            ${e.onLeave ? '<span class="pill warn">On leave</span>' : `<span class="pill ${isIn ? 'in' : 'out'}">${isIn ? 'In since ' + fmtTime12(e.since) : 'Out'}</span>`}
          </div>
          <div class="progress" style="margin-top:10px"><span data-bar style="width:${pct(e)}%"></span></div>
        </div>
        <div class="timeline">${chips || '<span class="muted small">No entries yet today</span>'}</div>
        <button class="big ${isIn ? 'go-out' : 'go-in'}" data-act="${isIn ? 'out' : 'in'}">${icon(isIn ? 'logout' : 'clock')}${isIn ? 'Clock out' : 'Clock in'}</button>
        <div class="emp-actions">
          <button data-manual>${icon('edit')}Manual entry</button>
          <button data-month>${icon('chart')}My hours</button>
          <button data-leave>${icon('leave')}Request leave</button>
        </div>
      </article>`;
  }).join('');
}

function pct(emp) {
  return Math.min(100, Math.round(100 * liveMinutes(emp) / ((state.hoursPerDay || 9) * 60)));
}

function empById(id) { return state.employees.find((e) => e.id === id); }

function askPin(emp, title) {
  return new Promise((resolve) => {
    if (!emp.hasPin) return resolve('');
    const dlg = openDialog(`
      <h2>${esc(title)}</h2>
      <p class="modal-sub">${esc(emp.name)}, enter your PIN to continue.</p>
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
    <h2>Manual time entry</h2>
    <p class="modal-sub">${esc(emp.name)} · add one in/out period. Manual entries are marked for the admin to review.</p>
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
        <div><h2>${esc(d.name)}</h2><div class="muted small">Monthly hours · ${esc(fmtMonth(d.month))}</div></div>
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
          <td class="small">${x.halfDay ? '<span class="pill warn">Half-day leave</span> ' : (x.leave ? '<span class="pill warn">Leave</span> ' : '')}${x.sessions.map((s) => `${fmtTime12(s.in)}–${s.out ? fmtTime12(s.out) : '…'}`).join(', ')}</td>
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

// ---------- planned leave request ----------

function addMonths(month, n) {
  const [y, m] = month.split('-').map(Number);
  const d = new Date(Date.UTC(y, m - 1 + n, 1));
  return d.toISOString().slice(0, 7);
}

async function requestLeave(emp) {
  const pin = await askPin(emp, 'Request leave');
  if (pin === null) return;
  const firstMonth = state.today.slice(0, 7);
  const lastMonth = addMonths(firstMonth, 6);
  let month = firstMonth;
  let data;
  const selected = new Set();
  let portion = 1;
  const load = async () => {
    data = await api('POST', '/api/my/leave', { employeeId: emp.id, pin, month });
  };
  try { await load(); } catch (e) { return toast(e.message, true); }

  const dlg = openDialog('<div data-body></div>', { wide: true });
  const body = dlg.querySelector('[data-body]');

  const calendar = () => {
    const [y, m] = month.split('-').map(Number);
    const first = new Date(Date.UTC(y, m - 1, 1)).getUTCDay(); // 0 = Sun
    const lead = (first + 6) % 7; // Monday-first grid
    const n = new Date(Date.UTC(y, m, 0)).getUTCDate();
    const cells = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((d) => `<div class="dow">${d}</div>`);
    for (let i = 0; i < lead; i++) cells.push('<div class="blank"></div>');
    for (let d = 1; d <= n; d++) {
      const date = `${month}-${String(d).padStart(2, '0')}`;
      const wd = new Date(Date.UTC(y, m - 1, d)).getUTCDay();
      const off = data.weeklyOffs.includes(wd);
      const past = date < data.today;
      const mark = data.days[date];
      const disabled = off || past || !!mark;
      const cls = [date === data.today ? 'today' : '', selected.has(date) ? 'sel' : '', off ? 'off' : ''].join(' ');
      const title = off ? 'Weekly off' : mark === 'pending' ? 'Request pending' : mark ? 'Leave booked' : past ? 'Past' : '';
      cells.push(`<button type="button" class="${cls}" data-date="${date}" ${disabled ? 'disabled' : ''} ${mark ? `data-mark="${mark}"` : ''} title="${title}">${d}</button>`);
    }
    return cells.join('');
  };

  const draw = () => {
    const sel = [...selected].sort();
    const pending = data.requests.filter((r) => r.status === 'pending').length;
    body.innerHTML = `
      <div class="card-head" style="margin-bottom:6px">
        <div><h2>Request leave</h2><div class="modal-sub" style="margin:2px 0 0">${esc(emp.name)} · pick the days you plan to be away. The admin approves requests.</div></div>
      </div>
      <div style="display:grid;gap:22px;grid-template-columns:repeat(auto-fit,minmax(290px,1fr));margin-top:12px">
        <div>
          <div class="cal-head">
            <button type="button" class="sm ghost" data-prev ${month <= firstMonth ? 'disabled' : ''} aria-label="Previous month">${icon('left')}</button>
            <div class="title">${esc(fmtMonth(month))}</div>
            <button type="button" class="sm ghost" data-next ${month >= lastMonth ? 'disabled' : ''} aria-label="Next month">${icon('right')}</button>
          </div>
          <div class="cal">${calendar()}</div>
          <div class="legend"><span><i style="background:var(--brand)"></i>Selected</span><span><i style="background:var(--warn)"></i>Pending</span><span><i style="background:var(--ok)"></i>Leave booked</span></div>
        </div>
        <form data-form style="display:flex;flex-direction:column;gap:14px">
          <div>
            <div class="small" style="font-weight:550;color:var(--text-2);margin-bottom:6px">Leave type</div>
            <div class="segmented">
              <button type="button" data-portion="1" class="${portion === 1 ? 'on' : ''}">Full day</button>
              <button type="button" data-portion="0.5" class="${portion === 0.5 ? 'on' : ''}">Half day</button>
            </div>
          </div>
          <div class="note" style="margin:0">${icon('calendar')}<span>${sel.length
            ? `<b>${sel.length} day${sel.length === 1 ? '' : 's'}</b> selected: ${esc(fmtDates(sel))}`
            : 'Tap days on the calendar to select them.'}</span></div>
          <label>Reason<textarea name="reason" maxlength="300" required placeholder="e.g. Family function, medical appointment">${esc(body.dataset.reason || '')}</textarea></label>
          <div class="form-actions" style="margin-top:0">
            <button type="button" data-close>Close</button>
            <button class="primary" ${sel.length ? '' : 'disabled'}>${icon('check')}Send request</button>
          </div>
        </form>
      </div>
      <h3 style="margin:24px 0 10px">My requests ${pending ? `<span class="pill warn" style="margin-left:6px">${pending} pending</span>` : ''}</h3>
      <div class="req-list">${data.requests.length ? data.requests.map((r) => `
        <div class="req">
          <div class="req-main">
            <div class="dates">${esc(fmtDates(r.dates))}</div>
            <div class="meta">${r.dates.length} day${r.dates.length === 1 ? '' : 's'} · ${r.portion === 0.5 ? 'Half day' : 'Full day'} · requested ${esc(fmtDate(r.createdAt.slice(0, 10)))}</div>
            <div class="reason">${esc(r.reason)}</div>
            ${r.adminNote ? `<div class="meta">Admin: ${esc(r.adminNote)}</div>` : ''}
          </div>
          <div class="acts">${statusPill(r.status)}${r.status === 'pending' ? `<button type="button" class="sm danger" data-cancel="${esc(r.id)}">Cancel</button>` : ''}</div>
        </div>`).join('') : '<p class="muted small">No requests yet.</p>'}</div>`;

    body.querySelector('[data-close]').addEventListener('click', () => dlg.close());
    body.querySelector('[name=reason]').addEventListener('input', (ev) => { body.dataset.reason = ev.target.value; });
    body.querySelectorAll('[data-date]').forEach((b) => b.addEventListener('click', () => {
      const d = b.dataset.date;
      if (selected.has(d)) selected.delete(d); else selected.add(d);
      draw();
    }));
    body.querySelectorAll('[data-portion]').forEach((b) => b.addEventListener('click', () => { portion = Number(b.dataset.portion); draw(); }));
    const go = async (n) => {
      month = addMonths(month, n);
      selected.clear();
      try { await load(); } catch (e) { toast(e.message, true); }
      draw();
    };
    const prev = body.querySelector('[data-prev]');
    const next = body.querySelector('[data-next]');
    prev.addEventListener('click', () => go(-1));
    next.addEventListener('click', () => go(1));
    body.querySelector('[data-form]').addEventListener('submit', async (ev) => {
      ev.preventDefault();
      try {
        await api('POST', '/api/leave-requests', { employeeId: emp.id, pin, dates: [...selected], portion, reason: body.dataset.reason || '' });
        toast('Leave request sent to the admin');
        selected.clear();
        body.dataset.reason = '';
        await load();
        draw();
      } catch (e) { toast(e.message, true); }
    });
    body.querySelectorAll('[data-cancel]').forEach((b) => b.addEventListener('click', async () => {
      try {
        await api('POST', `/api/leave-requests/${b.dataset.cancel}/cancel`, { employeeId: emp.id, pin });
        toast('Request cancelled');
        await load();
        draw();
      } catch (e) { toast(e.message, true); }
    }));
  };
  draw();
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
  else if ('leave' in btn.dataset) requestLeave(emp);
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
      if (!emp) return;
      card.querySelector('[data-live]').textContent = fmtMin(liveMinutes(emp));
      card.querySelector('[data-bar]').style.width = pct(emp) + '%';
    });
  }
}

load();
tick();
setInterval(tick, 1000);
setInterval(() => { if (!document.querySelector('dialog[open]')) load(); }, 30000);
