/*
 * Staff portal: purchase requests (api lib/staff-procurement.php).
 * My requests, decisions waiting for me, my team's, all (finance), suppliers, and one request
 * with its quotes, purchase order and timeline. Nodes and textContent only.
 */
(() => {
  const S = window.StaffPortal;
  if (!S) return;
  const { api, post, h, empty, fmtDate, fmtWhen, ksh } = S;
  const TONE = { draft: 'p-muted', submitted: 'p-info', manager_approved: 'p-info', approved: 'p-ok', ordered: 'p-ok', received: 'p-ok', closed: 'p-muted', returned: 'p-warn', rejected: 'p-bad', cancelled: 'p-muted' };
  const pill = (r) => h('span', { class: 'pill ' + (TONE[r.status] || 'p-muted'), text: r.statusLabel });
  const fld = (label, input) => h('div', null, h('label', { class: 'f', text: label }), input);
  const bad = (box, e) => box.replaceChildren(h('div', { class: 'note bad', text: e.message || String(e) }));

  let state = { tab: 'mine', list: null, openId: null, editing: null };
  let pending = null;
  const root = () => S.panel('procurement');

  async function load() {
    const scope = { mine: 'mine', queue: 'queue', team: 'team', all: 'all' }[state.tab] || 'mine';
    try { state.list = await api('staff/procurement&scope=' + scope); }
    catch (e) { root().replaceChildren(empty(e.message || 'Purchase requests could not load.')); return; }
    render();
  }

  function header() {
    const d = state.list || {};
    const tabs = [['mine', 'My requests'], ['queue', 'To decide' + (d.toDecide ? ' (' + d.toDecide + ')' : '')]];
    if (d.hasTeam) tabs.push(['team', 'My team']);
    if (d.finance) tabs.push(['all', 'All requests'], ['suppliers', 'Suppliers']);
    return [
      h('div', { class: 'no-print head' }, h('div', null, h('h1', { text: 'Purchase requests' }),
        h('p', { class: 'lede', text: 'Ask for something to be bought. Your line manager approves, then finance; finance gets quotations and issues the order; you confirm delivery.' })),
        d.onStaffList ? h('button', { type: 'button', class: 'btn primary', text: '+ New request', onclick: () => { state.editing = { items: [{}] }; state.openId = null; render(); } }) : null),
      h('div', { class: 'row no-print' }, tabs.map(([k, label]) => h('button', { type: 'button', class: 'btn sm' + (state.tab === k && !state.openId && !state.editing ? ' primary' : ''), text: label,
        onclick: () => { state.tab = k; state.openId = null; state.editing = null; if (k === 'suppliers') render(); else load(); } }))),
    ];
  }

  function render() {
    const box = root();
    box.replaceChildren(...header());
    const body = h('div', { class: 'stack' });
    box.appendChild(body);
    if (state.editing) return renderForm(body);
    if (state.openId) return renderOne(body, state.openId);
    if (state.tab === 'suppliers') return renderSuppliers(body);
    renderList(body);
  }

  function renderList(body) {
    const rows = state.list.requests;
    const panel = h('div', { class: 'panel' });
    if (state.tab === 'all') {
      const from = h('input', { type: 'date', value: new Date().getFullYear() + '-01-01', style: 'width:auto' });
      const to = h('input', { type: 'date', value: new Date().toISOString().slice(0, 10), style: 'width:auto' });
      const csv = h('a', { class: 'btn sm', text: 'Download register (CSV)' });
      const setHref = () => { csv.href = 'api/index.php?route=staff/procurement/export&from=' + from.value + '&to=' + to.value; };
      from.addEventListener('change', setHref); to.addEventListener('change', setHref); setHref();
      panel.appendChild(h('div', { class: 'row', style: 'margin-bottom:10px' }, 'From', from, 'to', to, csv));
    }
    if (!rows.length) {
      panel.appendChild(empty({ mine: 'You have not raised any purchase requests.', queue: 'Nothing is waiting for you.', team: 'Nobody in your team has raised a request.', all: 'No requests yet.' }[state.tab] || 'Nothing here.'));
    } else {
      const t = h('table', null, h('tr', null, ['Ref', 'Purchase', 'Requested by', 'Estimated', 'Status', 'Updated'].map((x) => h('th', { text: x }))));
      rows.forEach((r) => t.appendChild(h('tr', { style: 'cursor:pointer', onclick: () => { location.hash = 'procurement-' + r.id; } },
        h('td', null, h('code', { class: 'tag', text: r.ref || 'draft' })), h('td', null, h('a', { href: '#procurement-' + r.id, text: r.title }), r.chargeCode ? h('div', { class: 'hint', text: r.chargeCode }) : null),
        h('td', { text: r.requester }), h('td', { text: ksh(r.total) }), h('td', null, pill(r)), h('td', { class: 'hint', text: fmtDate(r.updatedAt) }))));
      panel.appendChild(h('div', { class: 'table-wrap' }, t));
    }
    const th = state.list.thresholds;
    panel.appendChild(h('p', { class: 'hint', style: 'margin-top:10px', text: 'Quotations: one below ' + ksh(th.threeQuotesFrom) + '; three from there; from ' + ksh(th.tenderFrom) + ' also a tender committee reference.' }));
    body.appendChild(panel);
  }

  // ---------- new or edit ----------
  function renderForm(body) {
    const r = state.editing;
    const codes = state.list.codes;
    const f = {
      title: h('input', { type: 'text', maxLength: 200, value: r.title || '', placeholder: 'e.g. Two peak flow meters for the Kitui clinic' }),
      code: h('select', null, h('option', { value: '', text: 'Choose…' }), codes.map((c) => h('option', { value: c.id, text: c.code + ' · ' + c.name }))),
      needed: h('input', { type: 'date', value: r.neededBy || '' }),
      why: h('textarea', { maxLength: 4000, value: r.justification || '', placeholder: 'What it is for and why it is needed now' }),
    };
    f.code.value = r.chargeCodeId || '';
    const items = h('table');
    const total = h('b');
    const recalc = () => {
      let sum = 0;
      items.querySelectorAll('tr[data-item]').forEach((tr) => {
        const [q, c] = [tr.querySelector('[data-q]').value, tr.querySelector('[data-c]').value].map((v) => parseFloat(v) || 0);
        tr.querySelector('[data-amt]').textContent = q && c ? ksh(q * c) : '';
        sum += q * c;
      });
      total.textContent = ksh(sum);
    };
    items.appendChild(h('tr', null, ['Item', 'Qty', 'Unit', 'Est. unit cost (KSh)', 'Amount', ''].map((x) => h('th', { text: x }))));
    const addItem = (it) => {
      items.appendChild(h('tr', { 'data-item': '1' },
        h('td', null, h('input', { type: 'text', maxLength: 300, value: it.description || '', 'data-d': '1', placeholder: 'Description' })),
        h('td', null, h('input', { type: 'text', inputMode: 'decimal', value: it.quantity != null ? String(it.quantity) : '', 'data-q': '1', style: 'width:70px', oninput: recalc })),
        h('td', null, h('input', { type: 'text', maxLength: 20, value: it.unit || '', 'data-u': '1', placeholder: 'pcs', style: 'width:80px' })),
        h('td', null, h('input', { type: 'text', inputMode: 'decimal', value: it.unitCost != null ? String(it.unitCost) : '', 'data-c': '1', style: 'width:120px', oninput: recalc })),
        h('td', { 'data-amt': '1', style: 'white-space:nowrap' }),
        h('td', null, h('button', { type: 'button', class: 'btn sm', text: '×', 'aria-label': 'Remove item', onclick: (e) => { e.target.closest('tr').remove(); recalc(); } }))));
      recalc();
    };
    (r.items && r.items.length ? r.items : [{}]).forEach(addItem);
    const msg = h('div');
    const save = async (submit) => {
      const payload = { title: f.title.value, chargeCodeId: f.code.value, neededBy: f.needed.value, justification: f.why.value, submit,
        items: Array.from(items.querySelectorAll('tr[data-item]')).map((tr) => ({ description: tr.querySelector('[data-d]').value, quantity: tr.querySelector('[data-q]').value, unit: tr.querySelector('[data-u]').value, unitCost: tr.querySelector('[data-c]').value })) };
      try {
        const res = await post('staff/procurement/' + (r.id || 'new') + '/save', payload);
        state.editing = null; S.say('flash', submit ? 'Submitted ' + (res.request.ref || '') + '.' : 'Draft saved.', 'ok');
        location.hash = 'procurement-' + res.request.id;
        if (state.openId === res.request.id) render();
      } catch (e) { bad(msg, e); }
    };
    body.appendChild(h('div', { class: 'panel' },
      h('h2', { text: r.id ? 'Edit ' + (r.ref || 'draft') : 'New purchase request' }),
      r.status === 'returned' ? h('div', { class: 'note warn', text: 'Returned for changes. See the timeline for the reason, change what is needed and submit again.' }) : null,
      fld('What is being bought', f.title),
      h('div', { class: 'grid2' }, fld('Project or budget line', f.code), fld('Needed by', f.needed)),
      fld('Why it is needed', f.why),
      h('label', { class: 'f', text: 'Items' }), h('div', { class: 'table-wrap' }, items),
      h('div', { class: 'row', style: 'margin-top:8px' }, h('button', { type: 'button', class: 'btn sm', text: '+ Add an item', onclick: () => addItem({}) }), h('span', { style: 'flex:1' }), 'Estimated total:', total),
      h('div', { class: 'row', style: 'margin-top:14px' },
        h('button', { type: 'button', class: 'btn', text: 'Cancel', onclick: () => { state.editing = null; render(); } }), h('span', { style: 'flex:1' }),
        h('button', { type: 'button', class: 'btn', text: 'Save draft', onclick: () => save(false) }),
        h('button', { type: 'button', class: 'btn primary', text: 'Submit for approval', onclick: () => save(true) })),
      msg));
  }

  // ---------- one request ----------
  async function renderOne(body, id) {
    let d;
    try { d = await api('staff/procurement/' + id); } catch (e) { body.appendChild(empty(e.message)); return; }
    const r = d.request;
    const msg = h('div');
    const act = async (action, extra) => {
      try { await post('staff/procurement/' + r.id + '/' + action, extra || {}); S.refreshHome(); render(); }
      catch (e) { bad(msg, e); msg.scrollIntoView({ block: 'center' }); }
    };
    const ask = (q, action, key) => { const v = prompt(q); if (v) act(action, { [key || 'note']: v }); };

    const head = h('div', { class: 'panel' },
      h('div', { class: 'head' }, h('div', null, h('div', { class: 'hint', text: (r.ref || 'Draft') + ' · raised by ' + r.requester + ' · ' + fmtDate(r.createdAt) }), h('h2', { text: r.title })), pill(r)),
      h('dl', { class: 'dl' },
        h('dt', { text: 'Project' }), h('dd', { text: r.chargeCode ? r.chargeCode + ' · ' + r.chargeName : '–' }),
        h('dt', { text: 'Department' }), h('dd', { text: r.department || '–' }),
        h('dt', { text: 'Needed by' }), h('dd', { text: r.neededBy ? fmtDate(r.neededBy) : '–' }),
        h('dt', { text: 'Line manager' }), h('dd', { text: r.manager || 'None on record (goes straight to finance)' }),
        r.lpoNumber ? [h('dt', { text: 'Order' }), h('dd', { text: r.lpoNumber + ' · ' + r.supplier + ' · ' + ksh(r.lpoAmount) })] : null,
        r.paymentRef ? [h('dt', { text: 'Paid' }), h('dd', { text: r.paymentRef })] : null),
      r.justification ? h('p', { style: 'white-space:pre-wrap;margin-top:10px', text: r.justification }) : null);

    const it = h('table', null, h('tr', null, ['Item', 'Qty', 'Unit', 'Unit cost', 'Amount'].map((x) => h('th', { text: x }))));
    r.items.forEach((i) => it.appendChild(h('tr', null, h('td', { text: i.description }), h('td', { text: String(i.quantity) }), h('td', { text: i.unit || '' }), h('td', { text: ksh(i.unitCost) }), h('td', { text: ksh(i.amount) }))));
    it.appendChild(h('tr', null, h('th', { text: 'Estimated total' }), h('th'), h('th'), h('th'), h('th', { text: ksh(r.total) })));
    head.appendChild(h('div', { class: 'table-wrap', style: 'margin-top:10px' }, it));

    // Actions for this person at this step.
    const A = d.actions;
    const buttons = h('div', { class: 'row no-print', style: 'margin-top:14px' });
    if (A.includes('edit')) buttons.appendChild(h('button', { type: 'button', class: 'btn primary', text: 'Edit and submit', onclick: () => { state.editing = r; render(); } }));
    if (A.includes('approve')) buttons.appendChild(h('button', { type: 'button', class: 'btn primary', text: r.status === 'submitted' ? 'Approve (line manager)' : 'Approve (finance)', onclick: () => act('approve') }));
    if (A.includes('return')) buttons.appendChild(h('button', { type: 'button', class: 'btn', text: 'Return for changes', onclick: () => ask('What should be changed?', 'return') }));
    if (A.includes('reject')) buttons.appendChild(h('button', { type: 'button', class: 'btn danger', text: 'Reject', onclick: () => ask('Why is it rejected?', 'reject') }));
    if (A.includes('receive')) buttons.appendChild(h('button', { type: 'button', class: 'btn primary', text: 'Confirm delivery', onclick: () => ask('What arrived and in what condition? (e.g. "All 2 delivered in good order, delivery note 4471")', 'receive') }));
    if (A.includes('close')) buttons.appendChild(h('button', { type: 'button', class: 'btn primary', text: 'Mark paid and close', onclick: () => ask('Payment reference (cheque, EFT or M-Pesa code):', 'close', 'paymentRef') }));
    if (A.includes('cancel')) buttons.appendChild(h('button', { type: 'button', class: 'btn danger', text: 'Cancel request', onclick: () => { if (confirm('Cancel this request?')) act('cancel'); } }));
    if (r.lpoNumber) buttons.appendChild(h('button', { type: 'button', class: 'btn', text: 'Print purchase order', onclick: () => printLpo(r) }));
    head.appendChild(buttons);
    head.appendChild(msg);
    body.appendChild(head);

    // Quotations and the order (finance).
    if (r.quotes.length || A.includes('quotes')) {
      const q = h('div', { class: 'panel' }, h('h2', { text: 'Quotations' }),
        h('p', { class: 'hint', text: 'This purchase needs ' + r.quotesNeeded + ' quotation' + (r.quotesNeeded > 1 ? 's' : '') + (r.tenderNeeded ? ' and a tender committee reference' : '') + '. ' + r.quotes.length + ' recorded.' }));
      const choice = [];
      r.quotes.forEach((x, i) => {
        const radio = h('input', { type: 'radio', name: 'quoteChoice', value: x.id, checked: i === 0 });
        choice.push(radio);
        q.appendChild(h('div', { class: 'list-row' }, A.includes('order') ? radio : null, h('b', { class: 'grow', text: x.supplier }), h('span', { text: ksh(x.amount) }),
          x.validUntil ? h('span', { class: 'hint', text: 'valid to ' + fmtDate(x.validUntil) }) : null, x.note ? h('span', { class: 'hint', text: x.note }) : null,
          A.includes('quotes') ? h('button', { type: 'button', class: 'btn sm no-print', text: 'Remove', onclick: () => act('quote-remove', { quoteId: x.id }) }) : null));
      });
      if (A.includes('quotes')) {
        const sup = h('select', null, h('option', { value: '', text: 'Supplier…' }), d.suppliers.map((s) => h('option', { value: s.id, text: s.name })));
        const amt = h('input', { type: 'text', inputMode: 'decimal', placeholder: 'Amount (KSh)' });
        const valid = h('input', { type: 'date' });
        const note = h('input', { type: 'text', maxLength: 300, placeholder: 'Note (optional)' });
        q.appendChild(h('div', { class: 'no-print', style: 'margin-top:12px' }, h('div', { class: 'grid2' }, fld('Supplier', sup), fld('Quoted amount', amt), fld('Valid until', valid), fld('Note', note)),
          h('div', { class: 'row', style: 'margin-top:10px' }, h('button', { type: 'button', class: 'btn', text: 'Record quotation', onclick: () => act('quote', { supplierId: sup.value, amount: amt.value, validUntil: valid.value, note: note.value }) }),
            d.suppliers.length ? null : h('span', { class: 'hint', text: 'Add suppliers under the Suppliers tab first.' }))));
      }
      if (A.includes('order') && r.quotes.length) {
        const tender = h('input', { type: 'text', maxLength: 120, placeholder: 'Tender committee minute reference' });
        const why = h('input', { type: 'text', maxLength: 500, placeholder: 'Needed if this is not the lowest quotation' });
        q.appendChild(h('div', { class: 'no-print', style: 'margin-top:14px;border-top:1px solid var(--line);padding-top:12px' }, h('h2', { text: 'Issue the purchase order' }),
          h('p', { class: 'hint', text: 'Choose the quotation above. The order is numbered automatically.' }),
          r.tenderNeeded ? fld('Tender reference', tender) : null, fld('Reason for the choice', why),
          h('button', { type: 'button', class: 'btn primary', style: 'margin-top:10px', text: 'Issue purchase order', onclick: () => {
            const picked = choice.find((c) => c.checked);
            act('order', { quoteId: picked ? picked.value : '', tenderRef: tender.value, note: why.value });
          } })));
      }
      body.appendChild(q);
    }

    const tl = h('div', { class: 'panel' }, h('h2', { text: 'Timeline' }));
    const WORD = { created: 'Created', submitted: 'Submitted', manager_approved: 'Approved by line manager', finance_approved: 'Approved by finance', returned: 'Returned for changes',
      rejected: 'Rejected', cancelled: 'Cancelled', quote_added: 'Quotation recorded', quote_removed: 'Quotation removed', ordered: 'Purchase order issued', received: 'Delivery confirmed', closed: 'Paid and closed' };
    r.timeline.forEach((t) => tl.appendChild(h('div', { class: 'list-row' }, h('span', { class: 'hint', style: 'min-width:130px', text: fmtWhen(t.at) }),
      h('span', { class: 'grow' }, h('b', { text: WORD[t.action] || t.action }), t.by ? ' · ' + t.by : '', t.note ? h('div', { class: 'hint', text: t.note }) : null))));
    body.appendChild(tl);
  }

  // A purchase order on its own page, printed from a new window so the portal around it is left out.
  function printLpo(r) {
    const w = window.open('', '_blank');
    if (!w) { alert('Allow pop-ups to print the purchase order.'); return; }
    const d = w.document;
    d.title = r.lpoNumber;
    const st = d.createElement('style');
    st.textContent = 'body{font-family:Segoe UI,Arial,sans-serif;color:#16202c;margin:32px}h1{font-size:22px;margin:0}table{width:100%;border-collapse:collapse;margin-top:16px;font-size:14px}th,td{border:1px solid #d0d5dd;padding:8px;text-align:left}.top{display:flex;justify-content:space-between;border-bottom:3px solid #087539;padding-bottom:12px}.muted{color:#667085;font-size:13px}.sign{display:grid;grid-template-columns:1fr 1fr;gap:40px;margin-top:60px}.sign div{border-top:1px solid #16202c;padding-top:6px;font-size:13px}';
    d.head.appendChild(st);
    const mk = (tag, text, parent) => { const n = d.createElement(tag); if (text != null) n.textContent = text; (parent || d.body).appendChild(n); return n; };
    const top = mk('div'); top.className = 'top';
    const left = mk('div', null, top); const img = d.createElement('img'); img.src = new URL('assets/img/logo.png', location.href).href; img.style.height = '48px'; left.appendChild(img);
    mk('div', 'Respiratory Society of Kenya', left).className = 'muted';
    const right = mk('div', null, top); right.style.textAlign = 'right';
    mk('h1', 'LOCAL PURCHASE ORDER', right); mk('div', r.lpoNumber, right).style.fontWeight = '700';
    mk('div', 'Date: ' + fmtDate(r.orderedAt), right).className = 'muted';
    const to = mk('p'); to.style.marginTop = '18px'; to.textContent = 'To: ' + r.supplier;
    mk('p', 'Our reference: ' + r.ref + (r.chargeCode ? ' · Project: ' + r.chargeCode : '')).className = 'muted';
    mk('p', 'Please supply the following:');
    const t = mk('table'); const hr = mk('tr', null, t); ['Item', 'Qty', 'Unit'].forEach((x) => mk('th', x, hr));
    r.items.forEach((i) => { const tr = mk('tr', null, t); mk('td', i.description, tr); mk('td', String(i.quantity), tr); mk('td', i.unit || '', tr); });
    const tot = mk('p'); tot.style.fontWeight = '700'; tot.style.marginTop = '14px'; tot.textContent = 'Order value: ' + ksh(r.lpoAmount) + ' (as quoted)';
    mk('p', 'Deliver to the Respiratory Society of Kenya and quote ' + r.lpoNumber + ' on the delivery note and invoice. Payment is made against this order, the delivery note and the invoice.').className = 'muted';
    const sign = mk('div'); sign.className = 'sign';
    mk('div', 'Issued by: ' + (r.orderedBy || ''), sign); mk('div', 'Authorised signature and stamp', sign);
    setTimeout(() => w.print(), 400);
  }

  // ---------- suppliers (finance) ----------
  async function renderSuppliers(body) {
    let data;
    try { data = await api('staff/procurement/suppliers'); } catch (e) { body.appendChild(empty(e.message)); return; }
    const f = { id: h('input', { type: 'hidden' }), name: h('input', { type: 'text', maxLength: 160 }), kraPin: h('input', { type: 'text', maxLength: 20, placeholder: 'P051234567X' }),
      contactPerson: h('input', { type: 'text', maxLength: 120 }), phone: h('input', { type: 'tel', maxLength: 40 }), email: h('input', { type: 'text', maxLength: 160 }),
      category: h('input', { type: 'text', maxLength: 80, placeholder: 'e.g. Medical equipment, Stationery' }), notes: h('input', { type: 'text', maxLength: 500 }), inactive: h('input', { type: 'checkbox' }) };
    const msg = h('div');
    const fill = (s) => { Object.keys(f).forEach((k) => { if (k === 'inactive') f[k].checked = s ? !s.active : false; else f[k].value = s ? (s[k] || '') : ''; }); };
    const table = h('div', { class: 'table-wrap' });
    const list = () => {
      const t = h('table', null, h('tr', null, ['Supplier', 'KRA PIN', 'Contact', 'Category', ''].map((x) => h('th', { text: x }))));
      data.suppliers.forEach((s) => t.appendChild(h('tr', { style: s.active ? '' : 'color:var(--muted)' }, h('td', { text: s.name + (s.active ? '' : ' (inactive)') }), h('td', { text: s.kraPin || '–' }),
        h('td', { text: [s.contactPerson, s.phone, s.email].filter(Boolean).join(' · ') }), h('td', { text: s.category || '' }),
        h('td', null, h('button', { type: 'button', class: 'btn sm', text: 'Edit', onclick: () => fill(s) })))));
      table.replaceChildren(data.suppliers.length ? t : empty('No suppliers registered yet.'));
    };
    list();
    body.append(h('div', { class: 'panel' }, h('h2', { text: 'Add or change a supplier' }), f.id,
      h('div', { class: 'grid2' }, fld('Name', f.name), fld('KRA PIN', f.kraPin), fld('Contact person', f.contactPerson), fld('Phone', f.phone), fld('Email', f.email), fld('Category', f.category)),
      fld('Notes', f.notes), h('label', { class: 'check' }, f.inactive, 'Inactive (no new quotations)'),
      h('div', { class: 'row', style: 'margin-top:12px' }, h('button', { type: 'button', class: 'btn primary', text: 'Save supplier', onclick: async () => {
        const body2 = {}; Object.keys(f).forEach((k) => { body2[k] = k === 'inactive' ? f[k].checked : f[k].value; });
        try { data = await post('staff/procurement/suppliers', body2); fill(null); list(); msg.replaceChildren(h('div', { class: 'note ok', text: 'Saved.' })); } catch (e) { bad(msg, e); }
      } }), h('button', { type: 'button', class: 'btn', text: 'Clear', onclick: () => fill(null) })), msg),
    h('div', { class: 'panel' }, h('h2', { text: 'Supplier register' }), table));
  }

  S.loaders.procurement = () => {
    if (pending) { Object.assign(state, pending); pending = null; } else { state.tab = 'mine'; state.openId = null; state.editing = null; }
    load();
  };
  S.routes.push((hash) => {
    const m = hash.match(/^procurement-(\d+|queue)$/);
    if (!m) return false;
    pending = m[1] === 'queue' ? { tab: 'queue', openId: null, editing: null } : { openId: +m[1], editing: null };
    S.show('procurement');
    return true;
  });
})();
