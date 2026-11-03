/* Staff leave: employee requests and super-admin decisions and type settings. */
(() => {
  'use strict';
  const S = window.StaffPortal;
  if (!S) return;
  const { api, post, h, empty, fmtDate, fmtWhen } = S;
  const root = () => S.panel('leave');
  const tones = { pending: 'p-info', approved: 'p-ok', declined: 'p-bad', cancelled: 'p-muted' };
  const words = { pending: 'Awaiting approval', approved: 'Approved', declined: 'Declined', cancelled: 'Cancelled' };
  const field = (label, input) => h('div', null, h('label', { class: 'f', text: label }), input);
  let data, tab = 'mine', nextTab = null, savedMessage = '';
  async function load() {
    try {
      data = await api('staff/leave');
      if (!data.canManage && tab !== 'mine') tab = 'mine';
      render();
    } catch (error) { root().replaceChildren(empty(error.message || 'Leave requests could not load.')); }
  }
  async function action(button, route, body, message) {
    button.disabled = true;
    try { await post(route, body); savedMessage = message; await load(); S.refreshHome(); }
    catch (error) { S.say('flash', error.message || 'The request could not be saved.', 'bad'); button.disabled = false; }
  }
  function render() {
    const tabs = [['mine', 'My leave']];
    const pending = data.pending || data.requests.filter(r => r.status === 'pending');
    if (data.canManage) tabs.push(['queue', 'To approve (' + (data.pendingCount ?? pending.length) + ')'], ['all', 'Recent requests'], ['types', 'Leave types']);
    root().replaceChildren(
      h('div', null, h('h1', { text: 'Leave' }), h('p', { class: 'lede', text: 'Request time away and track your approvals. Leave requests are decided by the super administrator.' })),
      h('div', { class: 'row' }, tabs.map(([key, text]) => h('button', { type: 'button', class: 'btn sm' + (tab === key ? ' primary' : ''), text, onclick: () => { tab = key; savedMessage = ''; render(); } }))));
    if (savedMessage) root().appendChild(h('p', { class: 'note ok', role: 'status', text: savedMessage }));
    if (tab === 'types') { renderTypes(); return; }
    if (tab === 'mine' && data.onStaffList) root().appendChild(requestForm());
    if (tab === 'mine' && !data.onStaffList) root().appendChild(h('p', { class: 'note info', text: 'You can manage staff leave without an employee record. Open To approve, Recent requests, or Leave types.' }));
    const rows = tab === 'mine' ? data.mine : tab === 'queue' ? pending : data.requests;
    const box = h('div', { class: 'panel' }, h('h2', { text: tab === 'mine' ? 'My requests' : tab === 'queue' ? 'Waiting for your decision' : 'Recent leave requests' }));
    if (tab === 'queue' && data.pendingCount > rows.length) box.appendChild(h('p', { class: 'hint', text: 'Showing the oldest ' + rows.length + ' waiting requests. More appear as these are decided.' }));
    if (tab !== 'queue' && rows.length === 300) box.appendChild(h('p', { class: 'hint', text: 'Showing the 300 most recent requests.' }));
    box.appendChild(requestTable(rows)); root().appendChild(box);
  }
  function requestForm() {
    const types = data.types.filter(type => Number(type.active));
    if (!types.length) return h('div', { class: 'panel' }, h('h2', { text: 'Request leave' }), empty(data.canManage ? 'Add a leave type under Leave types before employees can request leave.' : 'Leave types have not been configured yet. Please contact the super administrator.'));
    const type = h('select', { required: true, 'aria-label': 'Leave type' }, h('option', { value: '', text: 'Choose a leave type' }), types.map(t => h('option', { value: t.id, text: t.name })));
    const description = h('p', { class: 'hint' });
    type.addEventListener('change', () => { description.textContent = (types.find(t => String(t.id) === type.value) || {}).description || ''; });
    const startsOn = h('input', { type: 'date', required: true, min: data.today, value: data.today, 'aria-label': 'First day of leave' });
    const endsOn = h('input', { type: 'date', required: true, min: data.today, value: data.today, 'aria-label': 'Last day of leave' });
    startsOn.addEventListener('change', () => { endsOn.min = startsOn.value || data.today; if (endsOn.value < startsOn.value) endsOn.value = startsOn.value; });
    const reason = h('textarea', { maxLength: 1000, rows: 3, 'aria-label': 'Leave note', placeholder: 'Optional information for the approver. Avoid unnecessary personal details.' });
    const submit = h('button', { class: 'btn primary', type: 'submit', text: 'Submit leave request' });
    const form = h('form', { class: 'panel', onsubmit: event => {
      event.preventDefault(); action(submit, 'staff/leave', { typeId: type.value, startsOn: startsOn.value, endsOn: endsOn.value, reason: reason.value }, 'Your leave request has been submitted.');
    } }, h('h2', { text: 'Request leave' }), field('Leave type', type), description,
    h('div', { class: 'grid2' }, field('First day', startsOn), field('Last day', endsOn)), field('Note (optional)', reason),
    h('div', { class: 'row', style: 'margin-top:14px' }, submit));
    return form;
  }
  function requestTable(rows) {
    if (!rows.length) return empty(tab === 'queue' ? 'No requests are waiting for approval.' : 'No leave requests yet.');
    const table = h('table', null, h('tr', null, ['Employee / leave', 'Dates', 'Status', 'Notes', 'Actions'].map(text => h('th', { text }))));
    rows.forEach(request => {
      const buttons = h('div', { class: 'row' });
      const send = (verb, label, noteRequired) => {
        const button = h('button', { type: 'button', class: 'btn sm' + (verb === 'approve' ? ' primary' : ''), text: label, onclick: () => {
          let note = '';
          if (noteRequired) {
            note = prompt(verb === 'decline' ? 'Why is this leave request being declined?' : 'Why are you cancelling this employee\'s leave?');
            if (note === null) return;
            if (!note.trim()) { S.say('flash', 'Please give a reason.', 'bad'); return; }
          } else if (!confirm(label + ' for ' + request.type + ' (' + fmtDate(request.startsOn) + ' to ' + fmtDate(request.endsOn) + ')?')) return;
          action(button, 'staff/leave/' + request.id + '/' + verb, { note }, 'Leave request ' + (verb === 'approve' ? 'approved.' : verb === 'decline' ? 'declined.' : 'cancelled.'));
        } });
        buttons.appendChild(button);
      };
      if (data.canManage && request.status === 'pending') { send('approve', 'Approve', false); send('decline', 'Decline', true); }
      if (['pending', 'approved'].includes(request.status) && request.endsOn >= data.today) send('cancel', 'Cancel', tab !== 'mine');
      const notes = h('div', null, request.reason ? h('p', { text: request.reason, style: 'white-space:pre-wrap' }) : h('span', { class: 'hint', text: 'No note' }),
        request.decisionNote ? h('p', { text: 'Decision: ' + request.decisionNote, style: 'white-space:pre-wrap' }) : null,
        request.cancellationNote ? h('p', { text: 'Cancellation: ' + request.cancellationNote, style: 'white-space:pre-wrap' }) : null,
        request.decidedBy ? h('small', { class: 'hint', text: 'Decision by ' + request.decidedBy + ' · ' + fmtWhen(request.decidedAt) }) : null);
      table.appendChild(h('tr', null, h('td', null, h('strong', { text: request.person }), h('div', { class: 'hint', text: request.type })),
        h('td', { text: fmtDate(request.startsOn) + ' to ' + fmtDate(request.endsOn) }),
        h('td', null, h('span', { class: 'pill ' + tones[request.status], text: words[request.status] })), h('td', null, notes), h('td', null, buttons)));
    });
    return h('div', { class: 'table-wrap' }, table);
  }
  function renderTypes() {
    if (!data.canManage) return;
    const name = h('input', { type: 'text', required: true, maxLength: 120, 'aria-label': 'Leave type name' });
    const description = h('textarea', { maxLength: 1000, rows: 3, 'aria-label': 'Leave type guidance' });
    const inactive = h('input', { type: 'checkbox' }); let editingId = null;
    const heading = h('h2', { text: 'Add leave type' });
    const save = h('button', { type: 'submit', class: 'btn primary', text: 'Save leave type' });
    const form = h('form', { class: 'panel', onsubmit: event => {
      event.preventDefault(); action(save, 'staff/leave/types', { id: editingId, name: name.value, description: description.value, inactive: inactive.checked }, 'Leave type saved.');
    } }, heading, h('p', { class: 'hint', text: 'Use the leave types and guidance approved for your organisation. No default allowances or balances are assumed.' }),
    field('Name', name), field('Guidance (optional)', description), h('label', { class: 'check' }, inactive, 'Inactive (unavailable for new requests)'),
    h('div', { class: 'row', style: 'margin-top:14px' }, save, h('button', { type: 'button', class: 'btn', text: 'Clear', onclick: () => { editingId = null; name.value = ''; description.value = ''; inactive.checked = false; heading.textContent = 'Add leave type'; } })));
    const list = h('div', { class: 'panel' }, h('h2', { text: 'Configured leave types' }));
    if (!data.types.length) list.appendChild(empty('No leave types configured yet.'));
    data.types.forEach(type => list.appendChild(h('div', { class: 'list-row' }, h('div', { class: 'grow' }, h('strong', { text: type.name }),
      h('p', { class: 'hint', text: type.description || '' })), h('span', { class: 'pill ' + (Number(type.active) ? 'p-ok' : 'p-muted'), text: Number(type.active) ? 'Active' : 'Inactive' }),
      h('button', { type: 'button', class: 'btn sm', text: 'Edit', onclick: () => { editingId = type.id; name.value = type.name; description.value = type.description || ''; inactive.checked = !Number(type.active); heading.textContent = 'Edit leave type'; form.scrollIntoView({ behavior: 'smooth' }); name.focus(); } }))));
    root().append(form, list);
  }
  S.loaders.leave = () => { tab = nextTab || 'mine'; nextTab = null; load(); };
  S.routes.push(hash => {
    if (hash !== 'leave-manage') return false;
    nextTab = 'queue'; S.show('leave'); return true;
  });
})();
