/*
 * Staff portal: timesheets (api lib/staff-timesheet.php).
 * My week, sheets to approve, the monthly hours report, and projects & codes.
 * Built with nodes and textContent only: codes, notes and names are typed by people.
 */
(() => {
  const S = window.StaffPortal;
  if (!S) return;
  const { api, post, h, empty, fmtDate, hours } = S;
  const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
  const STATUS = { draft: ['Draft', 'p-muted'], submitted: ['Waiting for approval', 'p-info'], approved: ['Approved', 'p-ok'], returned: ['Returned', 'p-warn'] };
  const pill = (status) => h('span', { class: 'pill ' + (STATUS[status] || ['', 'p-muted'])[1], text: (STATUS[status] || [status])[0] });
  const iso = (d) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  const monday = (v) => { const d = new Date(v + 'T00:00:00'); d.setDate(d.getDate() - ((d.getDay() + 6) % 7)); return iso(d); };
  const addDays = (v, n) => { const d = new Date(v + 'T00:00:00'); d.setDate(d.getDate() + n); return iso(d); };

  let state = { tab: 'week', week: monday(iso(new Date())), data: null, month: iso(new Date()).slice(0, 7) };
  const root = () => S.panel('timesheets');

  function tabs() {
    const d = state.data || {};
    const list = [['week', 'My week']];
    // Approvals only for people who have someone to approve: a team, HR, or a sheet waiting.
    if (d.toDecide || (d.people || []).length > 1 || d.isHr) list.push(['approve', 'To approve' + (d.toDecide ? ' (' + d.toDecide + ')' : '')]);
    list.push(['report', 'Monthly report']);
    if (d.canManageCodes) list.push(['codes', 'Projects & codes']);
    return h('div', { class: 'row no-print' }, list.map(([k, label]) =>
      h('button', { type: 'button', class: 'btn sm' + (state.tab === k ? ' primary' : ''), text: label, onclick: () => { state.tab = k; render(); } })));
  }

  async function load() {
    try { state.data = await api('staff/timesheets&week=' + state.week); }
    catch (e) { root().replaceChildren(empty(e.message || 'Timesheets could not load.')); return; }
    render();
  }

  function render() {
    const box = root();
    box.replaceChildren(
      h('div', { class: 'no-print' }, h('h1', { text: 'Timesheets' }),
        h('p', { class: 'lede', text: 'Your hours each week against the projects and grants you work on. Your manager approves them; the monthly report is what donors see as level of effort.' })),
      tabs());
    const body = h('div', { class: 'stack' });
    box.appendChild(body);
    ({ week: renderWeek, approve: renderApprovals, report: renderReport, codes: renderCodes })[state.tab](body);
  }

  // ---------- my week ----------
  function sheetTable(sheet, codes, editable) {
    const lines = (sheet && sheet.lines && sheet.lines.length) ? sheet.lines.map((l) => ({ codeId: l.codeId, note: l.note || '', hours: l.minutes.map((m) => m ? m / 60 : '') }))
      : (editable ? [{ codeId: '', note: '', hours: ['', '', '', '', '', '', ''] }] : []);
    const table = h('table');
    const totalsRow = h('tr');
    const recompute = () => {
      const per = [0, 0, 0, 0, 0, 0, 0];
      table.querySelectorAll('tr[data-line]').forEach((tr) => {
        let row = 0;
        tr.querySelectorAll('input[data-day]').forEach((inp) => { const v = parseFloat(inp.value) || 0; per[+inp.dataset.day] += v; row += v; });
        tr.querySelector('[data-rowtotal]').textContent = row ? row.toLocaleString('en-KE') : '';
      });
      totalsRow.replaceChildren(...[h('th', { text: 'Total' }), per.map((v) => h('th', { text: v ? v.toLocaleString('en-KE') : '–', style: v > 24 ? 'color:var(--bad)' : '' })),
        h('th', { text: per.reduce((a, b) => a + b, 0).toLocaleString('en-KE') }), editable ? h('th') : null].flat().filter(Boolean));
    };
    const head = h('tr', null, h('th', { text: 'Project / code' }), DAYS.map((d, i) => h('th', { text: d + ' ' + addDays(state.week, i).slice(8) })), h('th', { text: 'Hours' }), editable ? h('th') : null);
    table.appendChild(head);
    const addLine = (l) => {
      const tr = h('tr', { 'data-line': '1' });
      if (editable) {
        const sel = h('select', { 'aria-label': 'Project or code', style: 'min-width:170px' }, h('option', { value: '', text: 'Choose…' }),
          codes.map((c) => h('option', { value: c.id, text: c.code + ' · ' + c.name })));
        sel.value = l.codeId || '';
        const note = h('input', { type: 'text', maxLength: 200, placeholder: 'Note (optional)', value: l.note, style: 'margin-top:4px;font-size:12.5px' });
        tr.appendChild(h('td', null, sel, note));
        l.hours.forEach((v, i) => tr.appendChild(h('td', null, h('input', { type: 'text', inputMode: 'decimal', 'data-day': i, value: v === '' ? '' : String(v),
          'aria-label': DAYS[i] + ' hours', style: 'width:58px;padding:7px 6px;text-align:right', oninput: recompute }))));
      } else {
        const c = codes.find((x) => x.id === l.codeId) || {};
        tr.appendChild(h('td', null, h('b', { text: c.code || '' }), ' ', c.name || '', l.note ? h('div', { class: 'hint', text: l.note }) : null));
        l.hours.forEach((v, i) => tr.appendChild(h('td', { 'data-day-view': i, text: v === '' ? '–' : String(v) })));
      }
      tr.appendChild(h('td', { 'data-rowtotal': '1', style: 'font-weight:700' }));
      if (editable) tr.appendChild(h('td', null, h('button', { type: 'button', class: 'btn sm', 'aria-label': 'Remove line', text: '×', onclick: () => { tr.remove(); recompute(); } })));
      table.insertBefore(tr, totalsRow.parentNode === table ? totalsRow : null);
    };
    table.appendChild(totalsRow);
    lines.forEach(addLine);
    if (!editable) {
      // read-only totals
      table.querySelectorAll('tr[data-line]').forEach((tr) => {
        let row = 0; tr.querySelectorAll('[data-day-view]').forEach((td) => { row += parseFloat(td.textContent) || 0; });
        tr.querySelector('[data-rowtotal]').textContent = row ? row.toLocaleString('en-KE') : '';
      });
      const per = [0, 0, 0, 0, 0, 0, 0];
      table.querySelectorAll('[data-day-view]').forEach((td) => { per[+td.dataset.dayView] += parseFloat(td.textContent) || 0; });
      totalsRow.replaceChildren(...[h('th', { text: 'Total' }), per.map((v) => h('th', { text: v ? v.toLocaleString('en-KE') : '–' })), h('th', { text: per.reduce((a, b) => a + b, 0).toLocaleString('en-KE') })].flat());
    } else recompute();
    const collect = () => Array.from(table.querySelectorAll('tr[data-line]')).map((tr) => ({
      codeId: tr.querySelector('select').value, note: tr.querySelector('input[type=text]:not([data-day])').value,
      hours: Array.from(tr.querySelectorAll('input[data-day]')).map((i) => i.value.trim()),
    }));
    return { node: h('div', { class: 'table-wrap' }, table), addLine: () => { addLine({ codeId: '', note: '', hours: ['', '', '', '', '', '', ''] }); recompute(); }, collect };
  }

  function renderWeek(body) {
    const d = state.data;
    const sheet = d.sheet;
    const editable = d.onStaffList && (!sheet || sheet.status === 'draft' || sheet.status === 'returned');
    const weekInput = h('input', { type: 'date', value: state.week, style: 'width:auto', 'aria-label': 'Week', onchange: (e) => { if (e.target.value) { state.week = monday(e.target.value); load(); } } });
    const nav = h('div', { class: 'row' },
      h('button', { type: 'button', class: 'btn sm', text: '‹ Previous', onclick: () => { state.week = addDays(state.week, -7); load(); } }),
      weekInput,
      h('button', { type: 'button', class: 'btn sm', text: 'Next ›', onclick: () => { state.week = addDays(state.week, 7); load(); } }),
      h('b', { text: 'Week of ' + fmtDate(state.week) + ' to ' + fmtDate(addDays(state.week, 6)) }),
      sheet ? pill(sheet.status) : h('span', { class: 'pill p-muted', text: 'Not started' }));
    const panel = h('div', { class: 'panel' }, nav);
    if (sheet && sheet.status === 'returned' && sheet.returnReason) panel.appendChild(h('div', { class: 'note warn', text: 'Returned' + (sheet.decidedBy ? ' by ' + sheet.decidedBy : '') + ': ' + sheet.returnReason }));
    if (sheet && sheet.status === 'approved') panel.appendChild(h('div', { class: 'note ok', text: 'Approved' + (sheet.decidedBy ? ' by ' + sheet.decidedBy : '') + ' on ' + fmtDate(sheet.decidedAt) + '. It is locked; HR can reopen it if something must change.' }));
    if (d.fieldMinutes) panel.appendChild(h('p', { class: 'hint', style: 'margin-top:10px', text: 'Field work you checked in to this week: ' + hours(d.fieldMinutes) + ' hours.' }));
    if (!d.codes.length) panel.appendChild(h('div', { class: 'note warn', text: 'No projects or codes are set up yet. HR or finance adds them under Projects & codes.' }));
    const grid = sheetTable(sheet, d.codes, editable);
    panel.appendChild(grid.node);
    const note = h('textarea', { maxLength: 500, placeholder: 'Anything your manager should know about this week (optional)', value: sheet && sheet.note ? sheet.note : '', disabled: !editable, style: 'min-height:60px;margin-top:10px' });
    panel.appendChild(note);
    const msg = h('div');
    if (editable) {
      const save = async (submit) => {
        try {
          await post('staff/timesheets/save', { week: state.week, lines: grid.collect(), note: note.value, submit });
          await load();
          S.say('flash', submit ? 'Submitted to your manager.' : 'Saved.', 'ok');
        } catch (e) { msg.replaceChildren(h('div', { class: 'note bad', text: e.message })); }
      };
      panel.appendChild(h('div', { class: 'row', style: 'margin-top:12px' },
        h('button', { type: 'button', class: 'btn', text: '+ Add a line', onclick: grid.addLine }),
        h('span', { style: 'flex:1' }),
        h('button', { type: 'button', class: 'btn', text: 'Save draft', onclick: () => save(false) }),
        h('button', { type: 'button', class: 'btn primary', text: 'Submit for approval', onclick: () => save(true) })));
      panel.appendChild(h('p', { class: 'hint', style: 'margin-top:8px', text: 'Hours in quarter hours: 7.5 means seven and a half hours.' }));
    } else if (sheet && sheet.status === 'submitted') {
      panel.appendChild(h('div', { class: 'row', style: 'margin-top:12px' }, h('button', { type: 'button', class: 'btn', text: 'Withdraw to change it', onclick: async () => {
        try { await post('staff/timesheets/' + sheet.id + '/withdraw'); await load(); } catch (e) { msg.replaceChildren(h('div', { class: 'note bad', text: e.message })); }
      } })));
    }
    panel.appendChild(msg);
    body.appendChild(panel);

    const recent = h('div', { class: 'panel' }, h('h2', { text: 'Recent weeks' }));
    if (!d.recent.length) recent.appendChild(empty('No timesheets yet.'));
    d.recent.forEach((s) => recent.appendChild(h('div', { class: 'list-row' },
      h('a', { href: '#timesheets', class: 'grow', text: 'Week of ' + fmtDate(s.weekStart), onclick: (e) => { e.preventDefault(); state.week = s.weekStart; load(); } }),
      h('span', { class: 'hint', text: hours(s.totalMinutes) + ' h' }), pill(s.status))));
    body.appendChild(recent);
  }

  // ---------- approvals ----------
  async function renderApprovals(body) {
    body.appendChild(h('div', { class: 'panel', text: 'Loading…' }));
    let data;
    try { data = await api('staff/timesheets/approvals'); } catch (e) { body.replaceChildren(empty(e.message)); return; }
    body.textContent = '';
    if (!data.sheets.length) { body.appendChild(h('div', { class: 'panel' }, empty('No timesheets are waiting for you.'))); return; }
    const codes = state.data.codes;
    data.sheets.forEach((s) => {
      const keepWeek = state.week; state.week = s.weekStart;
      const grid = sheetTable(s, codes.concat(s.lines.filter((l) => !codes.find((c) => c.id === l.codeId)).map((l) => ({ id: l.codeId, code: l.code, name: l.codeName }))), false);
      state.week = keepWeek;
      const msg = h('div');
      const decide = async (action) => {
        let reason = '';
        if (action === 'return') { reason = prompt('What should ' + s.name + ' correct?'); if (!reason) return; }
        try { await post('staff/timesheets/' + s.id + '/' + action, { reason }); await load(); state.tab = 'approve'; render(); S.refreshHome(); }
        catch (e) { msg.replaceChildren(h('div', { class: 'note bad', text: e.message })); }
      };
      body.appendChild(h('div', { class: 'panel' },
        h('div', { class: 'head' }, h('div', null, h('h2', { text: s.name }), h('div', { class: 'hint', text: 'Week of ' + fmtDate(s.weekStart) + ' · ' + hours(s.totalMinutes) + ' hours · submitted ' + S.fmtWhen(s.submittedAt) })),
          h('div', { class: 'row' }, h('button', { type: 'button', class: 'btn', text: 'Return', onclick: () => decide('return') }), h('button', { type: 'button', class: 'btn primary', text: 'Approve', onclick: () => decide('approve') }))),
        grid.node, s.note ? h('p', { class: 'hint', text: 'Note: ' + s.note }) : null, msg));
    });
  }

  // ---------- monthly report ----------
  async function renderReport(body) {
    const month = h('input', { type: 'month', value: state.month, style: 'width:auto', 'aria-label': 'Month' });
    const out = h('div', { class: 'panel' });
    const run = async () => {
      state.month = month.value || state.month;
      let r;
      try { r = await api('staff/timesheets/report&month=' + state.month); } catch (e) { out.replaceChildren(empty(e.message)); return; }
      out.textContent = '';
      S.printButtons(out, 'api/index.php?route=staff/timesheets/report&month=' + state.month + '&csv=1');
      out.appendChild(S.reportHeader('Timesheet hours by project', r.label));
      const t = h('table', null, h('tr', null, ['Staff', 'Project / code', 'Approved hours', 'Not yet approved'].map((x) => h('th', { text: x }))));
      r.people.forEach((p) => {
        if (!p.codes.length) { t.appendChild(h('tr', { style: 'color:var(--muted)' }, h('td', { text: p.name }), h('td', { text: 'No hours recorded' }), h('td'), h('td'))); return; }
        p.codes.forEach((c, i) => t.appendChild(h('tr', null, h('td', { text: i ? '' : p.name, style: 'font-weight:700' }), h('td', { text: c.code + ' · ' + c.name }),
          h('td', { text: hours(c.approved) }), h('td', { text: c.pending ? hours(c.pending) : '–' }))));
        if (p.codes.length > 1) t.appendChild(h('tr', null, h('td'), h('td', { text: 'Total', class: 'hint' }), h('td', { text: hours(p.approved), style: 'font-weight:700' }), h('td', { text: p.pending ? hours(p.pending) : '–' })));
      });
      out.appendChild(h('div', { class: 'table-wrap' }, t));
      out.appendChild(h('p', { class: 'hint', style: 'margin-top:10px', text: 'Weeks that cross into the next month are split by day. Only approved hours should go to a donor.' }));
    };
    body.append(h('div', { class: 'panel no-print' }, h('div', { class: 'row' }, month, h('button', { type: 'button', class: 'btn primary', text: 'View report', onclick: run }))), out);
    run();
  }

  // ---------- projects and codes ----------
  async function renderCodes(body) {
    let data;
    try { data = await api('staff/timesheets/codes'); } catch (e) { body.appendChild(empty(e.message)); return; }
    const f = { id: h('input', { type: 'hidden' }), code: h('input', { type: 'text', maxLength: 30, placeholder: 'e.g. GF-ASTHMA-26' }), name: h('input', { type: 'text', maxLength: 160, placeholder: 'e.g. Asthma care in Kitui (Global Fund)' }),
      donor: h('input', { type: 'text', maxLength: 120, placeholder: 'Optional' }), kind: h('select', null, h('option', { value: 'project', text: 'Project or grant' }), h('option', { value: 'internal', text: 'Internal (admin, leave…)' })),
      inactive: h('input', { type: 'checkbox' }) };
    const msg = h('div');
    const fld = (label, input) => h('div', null, h('label', { class: 'f', text: label }), input);
    const reset = () => { f.id.value = ''; f.code.value = ''; f.name.value = ''; f.donor.value = ''; f.kind.value = 'project'; f.inactive.checked = false; };
    const form = h('div', { class: 'panel' }, h('h2', { text: 'Add or change a code' }), f.id,
      h('div', { class: 'grid2' }, fld('Code', f.code), fld('Name', f.name), fld('Donor', f.donor), fld('Type', f.kind)),
      h('label', { class: 'check' }, f.inactive, 'No longer in use (hidden from new timesheets and requests)'),
      h('div', { class: 'row', style: 'margin-top:12px' }, h('button', { type: 'button', class: 'btn primary', text: 'Save', onclick: async () => {
        try { data.codes = (await post('staff/timesheets/codes', { id: f.id.value, code: f.code.value, name: f.name.value, donor: f.donor.value, kind: f.kind.value, inactive: f.inactive.checked })).codes; reset(); list(); msg.replaceChildren(h('div', { class: 'note ok', text: 'Saved.' })); }
        catch (e) { msg.replaceChildren(h('div', { class: 'note bad', text: e.message })); }
      } }), h('button', { type: 'button', class: 'btn', text: 'Clear', onclick: reset })), msg);
    const table = h('div', { class: 'table-wrap' });
    const list = () => {
      const t = h('table', null, h('tr', null, ['Code', 'Name', 'Donor', 'Type', ''].map((x) => h('th', { text: x }))));
      data.codes.forEach((c) => t.appendChild(h('tr', { style: c.active ? '' : 'color:var(--muted)' }, h('td', null, h('code', { class: 'tag', text: c.code })), h('td', { text: c.name + (c.active ? '' : ' (not in use)') }),
        h('td', { text: c.donor || '' }), h('td', { text: c.kind === 'internal' ? 'Internal' : 'Project' }),
        h('td', null, h('button', { type: 'button', class: 'btn sm', text: 'Edit', onclick: () => { f.id.value = c.id; f.code.value = c.code; f.name.value = c.name; f.donor.value = c.donor || ''; f.kind.value = c.kind; f.inactive.checked = !c.active; form.scrollIntoView({ behavior: 'smooth' }); } })))));
      table.replaceChildren(t);
    };
    list();
    body.append(form, h('div', { class: 'panel' }, h('h2', { text: 'Projects and codes' }), h('p', { class: 'hint', text: 'Used on timesheets and purchase requests.' }), table));
  }

  let pendingTab = null;
  S.loaders.timesheets = () => { state.tab = pendingTab || 'week'; pendingTab = null; load(); };
  S.routes.push((hash) => {
    if (hash !== 'timesheet-approvals') return false;
    pendingTab = 'approve'; S.show('timesheets'); return true;
  });
})();
