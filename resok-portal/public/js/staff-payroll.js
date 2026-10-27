/*
 * Staff portal: my payslips (every member of staff) and payroll (payroll.manage and
 * payroll.approve only). api lib/staff-payroll.php. Nodes and textContent only.
 */
(() => {
  const S = window.StaffPortal;
  if (!S) return;
  const { api, post, h, empty, fmtDate, fmtWhen, ksh } = S;
  const fld = (label, input) => h('div', null, h('label', { class: 'f', text: label }), input);
  const bad = (box, e) => box.replaceChildren(h('div', { class: 'note bad', text: e.message || String(e) }));
  const RUN_TONE = { draft: 'p-muted', submitted: 'p-info', approved: 'p-ok', void: 'p-bad' };
  const RUN_WORD = { draft: 'Draft', submitted: 'Waiting for approval', approved: 'Approved', void: 'Void' };
  const runPill = (r) => h('span', { class: 'pill ' + RUN_TONE[r.status], text: RUN_WORD[r.status] });
  const num = (n) => Number(n || 0).toLocaleString('en-KE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

  // ---------- payslip (shared by My payslips and the run view) ----------
  function payslip(p, label, kinds) {
    const row = (l, v, strong) => h('tr', null, h('td', { text: l, style: strong ? 'font-weight:700' : '' }), h('td', { text: num(v), style: 'text-align:right' + (strong ? ';font-weight:700' : '') }));
    const earn = h('table', null, h('tr', null, h('th', { text: 'Earnings' }), h('th', { text: 'KSh', style: 'text-align:right' })), row('Basic salary', p.basic));
    p.items.filter((i) => i.kind.startsWith('earning')).forEach((i) => earn.appendChild(row(i.label + (i.kind === 'earning_nontaxable' ? ' (not taxed)' : ''), i.amount)));
    earn.appendChild(row('Gross pay', p.gross, true));
    const ded = h('table', null, h('tr', null, h('th', { text: 'Deductions' }), h('th', { text: 'KSh', style: 'text-align:right' })),
      row('PAYE', p.paye), row('NSSF', p.nssf), row('SHIF', p.shif), row('Housing Levy', p.housingLevy));
    p.items.filter((i) => i.kind.startsWith('deduction')).forEach((i) => ded.appendChild(row(i.label, i.amount)));
    ded.appendChild(row('Total deductions', p.gross - p.net, true));
    return h('div', null,
      S.reportHeader('Payslip', label),
      h('div', { class: 'grid2', style: 'margin-bottom:10px' },
        h('div', null, h('h2', { text: p.name }), h('div', { class: 'hint', text: [p.jobTitle, p.department].filter(Boolean).join(' · ') })),
        h('dl', { class: 'dl' }, h('dt', { text: 'Staff no.' }), h('dd', { text: p.staffNumber || '–' }), h('dt', { text: 'KRA PIN' }), h('dd', { text: p.kraPin || '–' }),
          h('dt', { text: 'Paid to' }), h('dd', { text: p.paymentMethod === 'mpesa' ? 'M-Pesa ' + (p.account || '') : [p.bankName, p.account].filter(Boolean).join(' ') }))),
      h('div', { class: 'grid2' }, earn, ded),
      h('div', { class: 'note ok', style: 'font-size:17px;font-weight:800;margin-top:14px', text: 'Net pay: ' + ksh(p.net) }),
      h('p', { class: 'hint', style: 'margin-top:8px', text: 'Taxable pay ' + ksh(p.taxablePay) + '. ReSoK also paid ' + ksh(p.employerNssf) + ' NSSF and ' + ksh(p.employerHousingLevy) + ' Housing Levy on your behalf.' }));
  }

  // ---------- My payslips ----------
  async function loadPayslips(openRun) {
    const root = S.panel('payslips');
    let d;
    try { d = await api('staff/payroll/mine'); } catch (e) { root.replaceChildren(empty(e.message)); return; }
    const view = h('div', { class: 'panel' });
    const list = h('div', { class: 'panel no-print' }, h('h2', { text: 'Payslips' }));
    if (!d.payslips.length) list.appendChild(empty('No payslips yet. They appear here once a month\'s payroll is approved.'));
    const open = async (p) => {
      try {
        const r = await api('staff/payroll/mine/' + p.runId);
        view.textContent = '';
        S.printButtons(view);
        view.appendChild(payslip(r.payslip, r.payslip.label));
        view.scrollIntoView({ behavior: 'smooth' });
      } catch (e) { view.replaceChildren(empty(e.message)); }
    };
    d.payslips.forEach((p) => list.appendChild(h('div', { class: 'list-row' }, h('a', { class: 'grow', href: '#payslips', text: p.label, onclick: (e) => { e.preventDefault(); open(p); } }),
      h('span', { class: 'hint', text: 'Net ' + ksh(p.net) }))));
    root.replaceChildren(h('div', { class: 'no-print' }, h('h1', { text: 'My payslips' }), h('p', { class: 'lede', text: 'Only you can see your payslips here. Payroll staff see them to prepare them.' })), list);
    if (d.payslips.length) { root.appendChild(view); open(d.payslips.find((p) => p.runId === openRun) || d.payslips[0]); }
  }
  S.loaders.payslips = () => loadPayslips();

  // ---------- Payroll ----------
  let st = { tab: 'runs', runId: null, data: null };
  const root = () => S.panel('payroll');

  async function load() {
    try { st.data = await api('staff/payroll'); } catch (e) { root().replaceChildren(empty(e.message || 'Payroll could not load.')); return; }
    render();
  }

  function render() {
    const d = st.data;
    const tabs = [['runs', 'Monthly runs']];
    if (d.manage) tabs.push(['records', 'Pay records']);
    tabs.push(['rates', 'Statutory rates']);
    root().replaceChildren(...[
      h('div', { class: 'no-print' }, h('h1', { text: 'Payroll' }), h('p', { class: 'lede', text: 'Prepare the month\'s payroll, have it approved by someone else, then release payslips and download the bank and statutory files. Only payroll staff can see this.' })),
      d.settings.confirmed ? null : h('div', { class: 'note warn', text: 'The statutory rates have not been confirmed yet. They are the system\'s starting values; check them against KRA, NSSF and SHA and save them under Statutory rates before the first run can be submitted.' }),
      h('div', { class: 'row no-print' }, tabs.map(([k, label]) => h('button', { type: 'button', class: 'btn sm' + (st.tab === k && !st.runId ? ' primary' : ''), text: label, onclick: () => { st.tab = k; st.runId = null; render(); } })))].filter(Boolean));
    const body = h('div', { class: 'stack' });
    root().appendChild(body);
    if (st.runId) return renderRun(body, st.runId);
    ({ runs: renderRuns, records: renderRecords, rates: renderRates })[st.tab](body);
  }

  function renderRuns(body) {
    const d = st.data;
    const msg = h('div');
    if (d.manage) {
      const now = new Date();
      const month = h('input', { type: 'month', value: now.getFullYear() + '-' + String(now.getMonth() + 1).padStart(2, '0'), style: 'width:auto' });
      body.appendChild(h('div', { class: 'panel no-print' }, h('h2', { text: 'Prepare a month' }),
        d.withoutPayRecord ? h('p', { class: 'hint', text: d.withoutPayRecord + ' active staff have no pay record yet and will not be on the run.' }) : null,
        h('div', { class: 'row', style: 'margin-top:8px' }, month, h('button', { type: 'button', class: 'btn primary', text: 'Prepare payroll', onclick: async () => {
          try { const r = await post('staff/payroll/runs', { period: month.value }); st.runId = r.run.id; render(); } catch (e) { bad(msg, e); }
        } })), msg));
    }
    const list = h('div', { class: 'panel' }, h('h2', { text: 'Runs' }));
    if (!d.runs.length) list.appendChild(empty('No payroll runs yet.'));
    else {
      const t = h('table', null, h('tr', null, ['Month', 'Status', 'Staff', 'Gross', 'Net', 'Prepared by', 'Approved by'].map((x) => h('th', { text: x }))));
      d.runs.forEach((r) => t.appendChild(h('tr', null, h('td', null, h('a', { href: '#payroll', text: r.label, onclick: (e) => { e.preventDefault(); st.runId = r.id; render(); } })),
        h('td', null, runPill(r)), h('td', { text: String(r.totals.people) }), h('td', { text: ksh(r.totals.gross) }), h('td', { text: ksh(r.totals.net) }),
        h('td', { text: r.preparedBy || '' }), h('td', { text: r.approvedBy || '' }))));
      list.appendChild(h('div', { class: 'table-wrap' }, t));
    }
    body.appendChild(list);
  }

  async function renderRun(body, id) {
    let d;
    try { d = await api('staff/payroll/runs/' + id); } catch (e) { body.appendChild(empty(e.message)); return; }
    const r = d.run;
    const me = st.data;
    const msg = h('div');
    const act = async (action, payload, after) => {
      try { await post('staff/payroll/runs/' + id + '/' + action, payload || {}); if (after) after(); else render(); S.refreshHome(); }
      catch (e) { bad(msg, e); msg.scrollIntoView({ block: 'center' }); }
    };
    const T = r.totals;
    const tiles = h('div', { class: 'rep-tiles' }, [[T.people, 'Staff paid'], [ksh(T.gross), 'Gross pay'], [ksh(T.net), 'Net pay'], [ksh(T.paye), 'PAYE']]
      .map(([n, l]) => h('div', null, h('b', { text: String(n), style: 'font-size:17px' }), h('span', { text: l }))));
    const buttons = h('div', { class: 'row no-print' });
    if (r.status === 'draft' && me.manage) {
      buttons.append(h('button', { type: 'button', class: 'btn', text: 'Recalculate from pay records', onclick: () => act('recalculate') }),
        h('button', { type: 'button', class: 'btn primary', text: 'Submit for approval', onclick: () => act('submit') }),
        h('button', { type: 'button', class: 'btn danger', text: 'Delete draft', onclick: () => { if (confirm('Delete this draft run?')) act('delete', {}, () => { st.runId = null; load(); }); } }));
    }
    if (d.mayApprove) {
      buttons.append(h('button', { type: 'button', class: 'btn primary', text: 'Approve and release payslips', onclick: () => { if (confirm('Approve ' + r.label + ' payroll? Payslips are released to staff at once and the run is locked.')) act('approve'); } }),
        h('button', { type: 'button', class: 'btn', text: 'Return to preparer', onclick: () => { const why = prompt('What needs correcting?'); if (why) act('return', { reason: why }); } }));
    } else if (r.status === 'submitted' && r.preparedById === me.me) {
      buttons.append(h('span', { class: 'hint', text: 'Waiting for another payroll approver: you prepared it, so you cannot approve it.' }));
    }
    if (r.status === 'approved' && S.home().super) buttons.append(h('button', { type: 'button', class: 'btn danger', text: 'Void run', onclick: () => { const why = prompt('Why is this approved run being voided? Payslips will be withdrawn.'); if (why) act('void', { reason: why }); } }));
    const files = h('div', { class: 'row no-print' }, 'Download:',
      h('a', { class: 'btn sm', href: 'api/index.php?route=staff/payroll/runs/' + id + '/export&type=register', text: 'Payroll register' }),
      h('a', { class: 'btn sm', href: 'api/index.php?route=staff/payroll/runs/' + id + '/export&type=statutory', text: 'Statutory summary' }),
      r.status === 'approved' ? h('a', { class: 'btn sm', href: 'api/index.php?route=staff/payroll/runs/' + id + '/export&type=bank', text: 'Bank / M-Pesa payments' }) : h('span', { class: 'hint', text: 'Payment file after approval.' }));
    body.appendChild(h('div', { class: 'panel' },
      h('div', { class: 'head' }, h('div', null, h('h2', { text: 'Payroll for ' + r.label }), h('div', { class: 'hint', text: 'Prepared by ' + (r.preparedBy || '–') + (r.approvedBy ? ' · approved by ' + r.approvedBy + ' ' + fmtWhen(r.approvedAt) : '') })), runPill(r)),
      r.returnReason && r.status === 'draft' ? h('div', { class: 'note warn', text: 'Returned: ' + r.returnReason }) : null,
      r.voidReason ? h('div', { class: 'note bad', text: 'Voided: ' + r.voidReason }) : null,
      !d.ratesConfirmed ? h('div', { class: 'note warn', text: 'Statutory rates for this month are not confirmed yet; it cannot be submitted until they are.' }) : null,
      T.negative ? h('div', { class: 'note bad', text: T.negative + ' net pay below zero. Correct the deductions.' }) : null,
      tiles,
      h('p', { class: 'hint', text: 'To remit: PAYE ' + ksh(T.paye) + ' · NSSF ' + ksh(T.nssf + T.employerNssf) + ' (with employer) · SHIF ' + ksh(T.shif) + ' · Housing Levy ' + ksh(T.housingLevy + T.employerHousingLevy) + ' (with employer)' }),
      buttons, files, msg,
      d.notOnRun.length ? h('p', { class: 'hint', style: 'margin-top:10px', text: 'Not on this run (no pay record, or marked off payroll): ' + d.notOnRun.join(', ') }) : null));

    const detail = h('div', { class: 'panel', hidden: true });
    const t = h('table', null, h('tr', null, ['Name', 'Basic', 'Gross', 'NSSF', 'SHIF', 'Housing', 'PAYE', 'Other', 'Net', ''].map((x) => h('th', { text: x }))));
    d.lines.forEach((l) => t.appendChild(h('tr', { style: l.net < 0 ? 'color:var(--bad)' : '' }, h('td', { text: l.name }), [l.basic, l.gross, l.nssf, l.shif, l.housingLevy, l.paye, l.pension + l.otherDeductions, l.net].map((v) => h('td', { text: num(v), style: 'text-align:right;white-space:nowrap' })),
      h('td', null, h('button', { type: 'button', class: 'btn sm', text: 'Payslip', onclick: () => { detail.hidden = false; detail.replaceChildren(h('button', { type: 'button', class: 'btn sm no-print', text: 'Close', onclick: () => { detail.hidden = true; } }), payslip(l, r.label)); detail.scrollIntoView({ behavior: 'smooth' }); } })))));
    body.appendChild(h('div', { class: 'panel' }, h('h2', { text: 'Staff on this run' }), h('div', { class: 'table-wrap' }, t)));
    body.appendChild(detail);

    if (r.status === 'draft' && me.manage) {
      const who = h('select', null, h('option', { value: '', text: 'Staff member…' }), d.lines.map((l) => h('option', { value: l.userId, text: l.name })));
      const kind = h('select', null, Object.entries(me.itemKinds).map(([k, v]) => h('option', { value: k, text: v })));
      const label = h('input', { type: 'text', maxLength: 80, placeholder: 'e.g. Overtime October, Salary advance' });
      const amount = h('input', { type: 'text', inputMode: 'decimal', placeholder: 'KSh' });
      const items = h('div');
      d.oneOff.forEach((i) => items.appendChild(h('div', { class: 'list-row' }, h('span', { class: 'grow', text: (i.name || 'User ' + i.userId) + ': ' + i.label + ' (' + me.itemKinds[i.kind] + ')' }), h('span', { text: ksh(i.amount) }),
        h('button', { type: 'button', class: 'btn sm', text: 'Remove', onclick: () => act('items/' + i.id + '/delete') }))));
      body.appendChild(h('div', { class: 'panel no-print' }, h('h2', { text: 'One-off items this month' }),
        h('p', { class: 'hint', text: 'Overtime, arrears, a bonus, an advance to recover. Allowances that repeat every month belong on the pay record instead.' }), items,
        h('div', { class: 'grid2' }, fld('Staff member', who), fld('Type', kind), fld('Description', label), fld('Amount', amount)),
        h('button', { type: 'button', class: 'btn', style: 'margin-top:10px', text: 'Add item', onclick: () => act('items', { userId: who.value, kind: kind.value, label: label.value, amount: amount.value }) })));
    }
    body.appendChild(h('div', { class: 'row no-print' }, h('button', { type: 'button', class: 'btn sm', text: '‹ All runs', onclick: () => { st.runId = null; load(); } })));
  }

  // ---------- pay records ----------
  async function renderRecords(body) {
    let d;
    try { d = await api('staff/payroll/records'); } catch (e) { body.appendChild(empty(e.message)); return; }
    const form = h('div', { class: 'panel', hidden: true });
    if (!d.encrypted) body.appendChild(h('div', { class: 'note warn', text: 'Encryption is not configured on this server, so bank details and ID numbers are stored in plain text. Set the encryption key before entering real pay data.' }));
    const t = h('table', null, h('tr', null, ['Name', 'Basic salary', 'Paid to', 'KRA PIN', 'Recurring items', ''].map((x) => h('th', { text: x }))));
    d.people.forEach((p) => t.appendChild(h('tr', { style: p.hasRecord && !p.onPayroll ? 'color:var(--muted)' : '' },
      h('td', null, p.name, p.jobTitle ? h('div', { class: 'hint', text: p.jobTitle }) : null),
      h('td', { text: p.hasRecord ? ksh(p.basic) + (p.onPayroll ? '' : ' (off payroll)') : 'No pay record' }),
      h('td', { text: p.hasRecord ? (p.paymentMethod === 'mpesa' ? 'M-Pesa ' : (p.bankName || '') + ' ') + (p.account || '') : '' }),
      h('td', { text: p.hasKraPin ? 'On file' : (p.hasRecord ? 'Missing' : '') }), h('td', { text: p.recurringItems ? String(p.recurringItems) : '' }),
      h('td', null, p.me ? h('span', { class: 'hint', text: 'Your own: another payroll officer edits it' }) : h('button', { type: 'button', class: 'btn sm', text: p.hasRecord ? 'Edit' : 'Set up', onclick: () => openRecord(p.userId, form) })))));
    body.append(form, h('div', { class: 'panel' }, h('h2', { text: 'Pay records' }), h('div', { class: 'table-wrap' }, t)));
  }

  async function openRecord(uid, form) {
    let r;
    try { r = (await api('staff/payroll/records/' + uid)).record; } catch (e) { form.hidden = false; form.replaceChildren(empty(e.message)); return; }
    const kinds = st.data.itemKinds;
    const f = {
      basic: h('input', { type: 'text', inputMode: 'decimal', value: r.basic != null ? String(r.basic) : '' }),
      paymentMethod: h('select', null, h('option', { value: 'bank', text: 'Bank transfer' }), h('option', { value: 'mpesa', text: 'M-Pesa' })),
      bankName: h('input', { type: 'text', maxLength: 80, value: r.bankName || '' }), bankBranch: h('input', { type: 'text', maxLength: 80, value: r.bankBranch || '' }),
      bankAccount: h('input', { type: 'text', maxLength: 40, value: r.bankAccount || '', autocomplete: 'off' }), mpesaPhone: h('input', { type: 'tel', maxLength: 20, value: r.mpesaPhone || '' }),
      kraPin: h('input', { type: 'text', maxLength: 20, value: r.kraPin || '', placeholder: 'A123456789B' }), nssfNumber: h('input', { type: 'text', maxLength: 30, value: r.nssfNumber || '' }),
      shifNumber: h('input', { type: 'text', maxLength: 30, value: r.shifNumber || '' }), offPayroll: h('input', { type: 'checkbox', checked: !r.onPayroll }),
    };
    f.paymentMethod.value = r.paymentMethod;
    const bankBox = h('div', { class: 'grid2' }, fld('Bank', f.bankName), fld('Branch', f.bankBranch), fld('Account number', f.bankAccount));
    const mpesaBox = h('div', null, fld('M-Pesa number', f.mpesaPhone));
    const sync = () => { bankBox.hidden = f.paymentMethod.value !== 'bank'; mpesaBox.hidden = f.paymentMethod.value !== 'mpesa'; };
    f.paymentMethod.addEventListener('change', sync); sync();
    const items = h('div');
    const addItem = (i) => items.appendChild(h('div', { class: 'row', 'data-item': '1', style: 'margin-top:6px' },
      (() => { const s = h('select', { style: 'width:auto' }, Object.entries(kinds).map(([k, v]) => h('option', { value: k, text: v }))); s.value = i.kind || 'earning_taxable'; return s; })(),
      h('input', { type: 'text', maxLength: 80, value: i.label || '', placeholder: 'e.g. House allowance', style: 'width:auto;flex:1' }),
      h('input', { type: 'text', inputMode: 'decimal', value: i.amount != null ? String(i.amount) : '', placeholder: 'KSh', style: 'width:120px' }),
      h('button', { type: 'button', class: 'btn sm', text: '×', 'aria-label': 'Remove', onclick: (e) => e.target.closest('[data-item]').remove() })));
    r.items.forEach(addItem);
    const msg = h('div');
    form.hidden = false;
    form.replaceChildren(h('h2', { text: 'Pay record: ' + r.name }),
      h('div', { class: 'grid2' }, fld('Basic monthly salary (KSh)', f.basic), fld('Paid by', f.paymentMethod)), bankBox, mpesaBox,
      h('div', { class: 'grid2' }, fld('KRA PIN', f.kraPin), fld('NSSF number', f.nssfNumber), fld('SHIF / SHA number', f.shifNumber)),
      h('label', { class: 'f', text: 'Every month: allowances and deductions' }), items,
      h('button', { type: 'button', class: 'btn sm', style: 'margin-top:6px', text: '+ Add allowance or deduction', onclick: () => addItem({}) }),
      h('label', { class: 'check' }, f.offPayroll, 'Off payroll (e.g. unpaid leave, left): not included in runs'),
      h('div', { class: 'row', style: 'margin-top:12px' }, h('button', { type: 'button', class: 'btn primary', text: 'Save pay record', onclick: async () => {
        const b = {}; Object.keys(f).forEach((k) => { b[k] = k === 'offPayroll' ? f[k].checked : f[k].value; });
        b.items = Array.from(items.querySelectorAll('[data-item]')).map((x) => { const [s, l, a] = x.querySelectorAll('select,input'); return { kind: s.value, label: l.value, amount: a.value }; });
        try { await post('staff/payroll/records/' + uid, b); S.say('flash', 'Pay record saved for ' + r.name + '.', 'ok'); render(); } catch (e) { bad(msg, e); }
      } }), h('button', { type: 'button', class: 'btn', text: 'Close', onclick: () => { form.hidden = true; } })), msg);
    form.scrollIntoView({ behavior: 'smooth' });
  }

  // ---------- statutory rates ----------
  function renderRates(body) {
    const d = st.data;
    const s = d.settings;
    const R = s.rates;
    const canEdit = d.manage;
    const inp = (v) => h('input', { type: 'text', inputMode: 'decimal', value: String(v), disabled: !canEdit, style: 'width:140px' });
    const f = { personalRelief: inp(R.personalRelief), shifRate: inp(R.shifRate), shifMinimum: inp(R.shifMinimum), housingLevyRate: inp(R.housingLevyRate), housingLevyEmployerRate: inp(R.housingLevyEmployerRate),
      nssfRate: inp(R.nssfRate), nssfLowerLimit: inp(R.nssfLowerLimit), nssfUpperLimit: inp(R.nssfUpperLimit), pensionCap: inp(R.pensionCap),
      shifAndLevyDeductible: h('input', { type: 'checkbox', checked: !!R.shifAndLevyDeductible, disabled: !canEdit }) };
    const bands = h('table', null, h('tr', null, h('th', { text: 'Band' }), h('th', { text: 'Width of band (KSh a month)' }), h('th', { text: 'Rate %' })));
    R.payeBands.forEach((b, i) => bands.appendChild(h('tr', { 'data-band': '1' }, h('td', { text: i === 0 ? 'First' : (i === R.payeBands.length - 1 ? 'Above that' : 'Next') }),
      h('td', null, i === R.payeBands.length - 1 ? h('span', { class: 'hint', text: 'everything above' }) : inp(b[0])), h('td', null, inp(b[1])))));
    const from = h('input', { type: 'month', value: new Date().toISOString().slice(0, 7), style: 'width:auto', disabled: !canEdit });
    const note = h('input', { type: 'text', maxLength: 300, placeholder: 'Where you checked them, e.g. "KRA, NSSF and SHA websites, 27 Oct 2026"', disabled: !canEdit });
    const msg = h('div');
    body.appendChild(h('div', { class: 'panel' },
      h('h2', { text: 'Statutory rates' }),
      s.confirmed ? h('p', { class: 'hint', text: 'In force from ' + fmtDate(s.effectiveFrom) + ', confirmed by ' + (s.confirmedBy || 'payroll') + ' on ' + fmtDate(s.confirmedAt) + (s.note ? ' · ' + s.note : '') })
        : h('div', { class: 'note warn', text: 'Starting values, not yet confirmed. Check every figure before saving. Each run keeps the rates it was calculated with.' }),
      h('label', { class: 'f', text: 'PAYE bands (monthly)' }), h('div', { class: 'table-wrap' }, bands),
      h('div', { class: 'grid2' }, fld('Personal relief (KSh a month)', f.personalRelief), fld('Pension + NSSF tax relief cap (KSh a month)', f.pensionCap),
        fld('SHIF rate %', f.shifRate), fld('SHIF minimum (KSh)', f.shifMinimum), fld('Housing Levy: employee %', f.housingLevyRate), fld('Housing Levy: employer %', f.housingLevyEmployerRate),
        fld('NSSF rate % (each side)', f.nssfRate), fld('NSSF lower earnings limit', f.nssfLowerLimit), fld('NSSF upper earnings limit', f.nssfUpperLimit)),
      h('label', { class: 'check' }, f.shifAndLevyDeductible, 'SHIF and Housing Levy are deducted before PAYE is worked out'),
      canEdit ? h('div', { style: 'margin-top:12px' }, h('div', { class: 'grid2' }, fld('Apply from month', from), fld('Checked against', note)),
        h('button', { type: 'button', class: 'btn primary', style: 'margin-top:12px', text: 'Confirm and save these rates', onclick: async () => {
          const rates = {}; Object.keys(f).forEach((k) => { rates[k] = k === 'shifAndLevyDeductible' ? f[k].checked : f[k].value; });
          rates.payeBands = Array.from(bands.querySelectorAll('tr[data-band]')).map((tr) => { const ins = tr.querySelectorAll('input'); return ins.length === 2 ? [ins[0].value, ins[1].value] : [null, ins[0].value]; });
          try { await post('staff/payroll/settings', { rates, effectiveFrom: from.value, note: note.value }); await load(); S.say('flash', 'Rates saved.', 'ok'); } catch (e) { bad(msg, e); }
        } }), msg) : h('p', { class: 'hint', text: 'Only payroll officers change these.' })));
  }

  S.loaders.payroll = () => { st.tab = 'runs'; st.runId = null; load(); };
})();
