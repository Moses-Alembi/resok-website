/*
 * Staff portal: appraisals (api lib/staff-appraisal.php).
 * My appraisals, the ones I appraise, one appraisal at its current stage, and HR's cycles.
 * Nodes and textContent only.
 */
(() => {
  const S = window.StaffPortal;
  if (!S) return;
  const { api, post, h, empty, fmtDate } = S;
  const fld = (label, input) => h('div', null, h('label', { class: 'f', text: label }), input);
  const bad = (box, e) => box.replaceChildren(h('div', { class: 'note bad', text: e.message || String(e) }));
  const STAGE_TONE = { objectives: 'p-warn', objectives_submitted: 'p-info', self_assessment: 'p-warn', manager_review: 'p-info', acknowledge: 'p-warn', completed: 'p-ok' };
  const stagePill = (a) => h('span', { class: 'pill ' + (STAGE_TONE[a.stage] || 'p-muted'), text: a.stageLabel });

  let state = { view: 'list', id: null, cycleId: null, data: null };
  let pending = null;
  const root = () => S.panel('appraisals');

  async function load() {
    try { state.data = await api('staff/appraisals'); } catch (e) { root().replaceChildren(empty(e.message || 'Appraisals could not load.')); return; }
    render();
  }

  function render() {
    const d = state.data;
    const tabs = [['list', 'Appraisals']];
    if (d.isHr) tabs.push(['cycles', 'Cycles (HR)']);
    root().replaceChildren(
      h('div', { class: 'no-print' }, h('h1', { text: 'Appraisals' }),
        h('p', { class: 'lede', text: 'Agree your objectives with your appraiser, assess yourself, read their review and acknowledge it. Each side sees the other\'s ratings only once they are submitted.' })),
      tabs.length < 2 ? h('span') : h('div', { class: 'row no-print' }, tabs.map(([k, label]) => h('button', { type: 'button', class: 'btn sm' + (state.view === k ? ' primary' : ''), text: label, onclick: () => { state.view = k; state.id = null; state.cycleId = null; render(); } }))));
    const body = h('div', { class: 'stack' });
    root().appendChild(body);
    if (state.id) return renderOne(body, state.id);
    if (state.view === 'cycles') return state.cycleId ? renderCycle(body, state.cycleId) : renderCycles(body);
    renderList(body);
  }

  function rows(list, showPerson) {
    if (!list.length) return null;
    const t = h('table', null, h('tr', null, [showPerson ? 'Staff' : 'Cycle', showPerson ? 'Cycle' : 'Appraiser', 'Stage', 'Result'].map((x) => h('th', { text: x }))));
    list.forEach((a) => t.appendChild(h('tr', null,
      h('td', null, h('a', { href: '#appraisal-' + a.id, text: showPerson ? a.person : a.cycle }), showPerson && a.jobTitle ? h('div', { class: 'hint', text: a.jobTitle }) : null),
      h('td', { text: showPerson ? a.cycle : a.appraiser }), h('td', null, stagePill(a)), h('td', { text: a.rating ? a.rating + ' (' + a.score.toFixed(2) + ')' : '–' }))));
    return h('div', { class: 'table-wrap' }, t);
  }

  function renderList(body) {
    const d = state.data;
    body.appendChild(h('div', { class: 'panel' }, h('h2', { text: 'My appraisals' }), rows(d.mine, false) || empty('You have no appraisals yet. HR opens a cycle when it is time.')));
    if (d.toAppraise.length) body.appendChild(h('div', { class: 'panel' }, h('h2', { text: 'People I appraise' }), rows(d.toAppraise, true)));
  }

  // ---------- one appraisal ----------
  const ratingSelect = (value, label) => {
    const s = h('select', { 'aria-label': label, style: 'width:auto;min-width:210px' }, h('option', { value: '', text: 'Rate 1–5…' }),
      [5, 4, 3, 2, 1].map((n) => h('option', { value: n, text: n + ' · ' + state.ratings[n] })));
    s.value = value == null ? '' : String(value);
    return s;
  };
  const ratingText = (n) => n == null ? '–' : n + ' · ' + state.ratings[n];

  async function renderOne(body, id) {
    let d;
    try { d = await api('staff/appraisals/' + id); } catch (e) { body.appendChild(empty(e.message)); return; }
    state.ratings = d.ratings;
    const a = d.appraisal;
    const msg = h('div');
    const send = async (action, payload, done) => {
      try { await post('staff/appraisals/' + a.id + '/' + action, payload); S.refreshHome(); if (done) S.say('flash', done, 'ok'); render(); }
      catch (e) { bad(msg, e); msg.scrollIntoView({ block: 'center' }); }
    };
    const me = a.role;
    const head = h('div', { class: 'panel' },
      h('div', { class: 'head' }, h('div', null, h('div', { class: 'hint', text: a.cycle + ' · ' + fmtDate(a.periodStart) + ' to ' + fmtDate(a.periodEnd) }),
        h('h2', { text: a.person }), h('div', { class: 'hint', text: [a.jobTitle, a.department, 'Appraiser: ' + (a.appraiser || 'HR')].filter(Boolean).join(' · ') })), stagePill(a)),
      a.rating ? h('div', { class: 'note ok', text: 'Overall: ' + a.rating + ' (' + a.score.toFixed(2) + ' out of 5)' }) : null,
      h('div', { class: 'row no-print', style: 'margin-top:10px' }, h('button', { type: 'button', class: 'btn sm', text: '‹ Back', onclick: () => { location.hash = 'appraisals'; } }),
        a.stage === 'completed' ? h('button', { type: 'button', class: 'btn sm', text: 'Print / Save as PDF', onclick: () => window.print() }) : null));
    body.appendChild(head);

    // Stage 1: the person drafts objectives.
    if (me === 'self' && a.stage === 'objectives') {
      const list = h('div');
      const addObj = (o) => list.appendChild(h('div', { 'data-obj': '1', style: 'border:1px solid var(--line);border-radius:10px;padding:12px;margin-top:10px' },
        fld('Objective', h('textarea', { maxLength: 500, value: o.objective || '', 'data-o': '1', style: 'min-height:60px', placeholder: 'e.g. Train 200 health workers on asthma care in four counties' })),
        h('div', { class: 'grid2' }, fld('How it will be measured', h('input', { type: 'text', maxLength: 500, value: o.measure || '', 'data-m': '1', placeholder: 'e.g. Attendance registers; 200 trained by 30 June' })),
          fld('Weight (%)', h('input', { type: 'text', inputMode: 'numeric', value: o.weight || '', 'data-w': '1', style: 'width:90px' }))),
        h('button', { type: 'button', class: 'btn sm', style: 'margin-top:8px', text: 'Remove', onclick: (e) => e.target.closest('[data-obj]').remove() })));
      (a.objectives.length ? a.objectives : [{}, {}, {}]).forEach(addObj);
      const collect = () => Array.from(list.querySelectorAll('[data-obj]')).map((x) => ({ objective: x.querySelector('[data-o]').value, measure: x.querySelector('[data-m]').value, weight: x.querySelector('[data-w]').value }));
      body.appendChild(h('div', { class: 'panel' }, h('h2', { text: 'Your objectives' }),
        a.objectivesReturnNote ? h('div', { class: 'note warn', text: 'Your appraiser asked: ' + a.objectivesReturnNote }) : null,
        h('p', { class: 'hint', text: 'Between 1 and 8 objectives. Weights show how much each counts and must add up to 100.' + (a.objectivesDue ? ' Due ' + fmtDate(a.objectivesDue) + '.' : '') }),
        list,
        h('div', { class: 'row', style: 'margin-top:12px' }, h('button', { type: 'button', class: 'btn', text: '+ Add an objective', onclick: () => addObj({}) }), h('span', { style: 'flex:1' }),
          h('button', { type: 'button', class: 'btn', text: 'Save draft', onclick: () => send('objectives', { objectives: collect() }, 'Saved.') }),
          h('button', { type: 'button', class: 'btn primary', text: 'Send to appraiser', onclick: () => send('objectives', { objectives: collect(), submit: true }, 'Sent to your appraiser.') })),
        msg));
      return;
    }

    // Objectives, read-only, with whatever ratings this person may see.
    const objTable = (editSide) => {
      const t = h('table', null, h('tr', null, ['Objective', 'Weight', 'Self-assessment', 'Appraiser'].map((x) => h('th', { text: x }))));
      a.objectives.forEach((o, i) => {
        const self = editSide === 'self' ? [ratingSelect(o.selfRating, 'Your rating'), h('input', { type: 'text', maxLength: 1000, value: o.selfComment || '', placeholder: 'What you achieved', 'data-sc': i, style: 'margin-top:4px' })]
          : [h('div', { text: ratingText(o.selfRating) }), o.selfComment ? h('div', { class: 'hint', text: o.selfComment }) : null];
        const mgr = editSide === 'appraiser' ? [ratingSelect(o.managerRating, 'Appraiser rating'), h('input', { type: 'text', maxLength: 1000, value: o.managerComment || '', placeholder: 'Comment', 'data-mc': i, style: 'margin-top:4px' })]
          : [h('div', { text: ratingText(o.managerRating) }), o.managerComment ? h('div', { class: 'hint', text: o.managerComment }) : null];
        t.appendChild(h('tr', { 'data-row': i }, h('td', null, h('b', { text: o.objective }), o.measure ? h('div', { class: 'hint', text: 'Measure: ' + o.measure }) : null),
          h('td', { text: o.weight + '%' }), h('td', null, self), h('td', null, mgr)));
      });
      return t;
    };
    const compTable = (editSide) => {
      const t = h('table', null, h('tr', null, ['Competency', 'Self-assessment', 'Appraiser'].map((x) => h('th', { text: x }))));
      a.competencies.forEach((c) => t.appendChild(h('tr', { 'data-comp': c.key }, h('td', { text: c.label }),
        h('td', null, editSide === 'self' ? ratingSelect(c.selfRating, c.label) : ratingText(c.selfRating)),
        h('td', null, editSide === 'appraiser' ? [ratingSelect(c.managerRating, c.label), h('input', { type: 'text', maxLength: 1000, value: c.managerComment || '', placeholder: 'Comment (optional)', style: 'margin-top:4px' })]
          : [h('div', { text: ratingText(c.managerRating) }), c.managerComment ? h('div', { class: 'hint', text: c.managerComment }) : null]))));
      return t;
    };
    const editSide = (me === 'self' && a.stage === 'self_assessment') ? 'self' : (me === 'appraiser' && a.stage === 'manager_review') ? 'appraiser' : null;
    const objT = objTable(editSide);
    const compT = compTable(editSide);
    body.appendChild(h('div', { class: 'panel' }, h('h2', { text: 'Objectives' }), a.objectivesAgreedAt ? h('p', { class: 'hint', text: 'Agreed ' + fmtDate(a.objectivesAgreedAt) }) : null, h('div', { class: 'table-wrap' }, objT)));
    if (a.stage !== 'objectives_submitted') body.appendChild(h('div', { class: 'panel' }, h('h2', { text: 'Competencies' }), h('div', { class: 'table-wrap' }, compT)));

    // Stage 2: the appraiser agrees the objectives.
    if (me === 'appraiser' && a.stage === 'objectives_submitted') {
      body.appendChild(h('div', { class: 'panel' }, h('h2', { text: 'Agree these objectives?' }), h('p', { class: 'hint', text: 'Agreeing moves the appraisal to self-assessment. Returning sends them back with your note.' }),
        h('div', { class: 'row', style: 'margin-top:10px' }, h('button', { type: 'button', class: 'btn primary', text: 'Agree objectives', onclick: () => send('agree', {}, 'Objectives agreed.') }),
          h('button', { type: 'button', class: 'btn', text: 'Ask for changes', onclick: () => { const n = prompt('What should change?'); if (n) send('return-objectives', { note: n }); } })), msg));
      return;
    }

    const collectRatings = (side) => ({
      objectives: Array.from(objT.querySelectorAll('tr[data-row]')).map((tr) => ({ rating: tr.querySelector('select').value, comment: tr.querySelector(side === 'self' ? '[data-sc]' : '[data-mc]').value })),
      competencies: Object.fromEntries(Array.from(compT.querySelectorAll('tr[data-comp]')).map((tr) => [tr.dataset.comp, tr.querySelector('select').value])),
      competencyComments: Object.fromEntries(Array.from(compT.querySelectorAll('tr[data-comp]')).map((tr) => [tr.dataset.comp, (tr.querySelector('input') || {}).value || ''])),
    });

    // Stage 3: self-assessment.
    if (editSide === 'self') {
      const comment = h('textarea', { maxLength: 4000, value: a.selfComment || '', placeholder: 'Your overall view of the period: achievements, difficulties, what support would help' });
      body.appendChild(h('div', { class: 'panel' }, h('h2', { text: 'Your overall comment' }), comment,
        h('div', { class: 'row', style: 'margin-top:12px' }, h('span', { style: 'flex:1' }),
          h('button', { type: 'button', class: 'btn', text: 'Save draft', onclick: () => send('self', Object.assign(collectRatings('self'), { comment: comment.value }), 'Saved.') }),
          h('button', { type: 'button', class: 'btn primary', text: 'Submit self-assessment', onclick: () => send('self', Object.assign(collectRatings('self'), { comment: comment.value, submit: true }), 'Submitted to your appraiser.') })), msg));
      return;
    }
    if (a.selfComment) body.appendChild(h('div', { class: 'panel' }, h('h2', { text: 'Self-assessment comment' }), h('p', { style: 'white-space:pre-wrap', text: a.selfComment })));

    // Stage 4: the appraiser's review.
    if (editSide === 'appraiser') {
      const t = (v, ph) => h('textarea', { maxLength: 4000, value: v || '', placeholder: ph, style: 'min-height:70px' });
      const comment = t(a.managerComment, 'Overall comment on the period');
      const strengths = t(a.strengths, 'What they did well');
      const dev = t(a.developmentNeeds, 'Where they should develop');
      const training = t(a.trainingNeeds, 'Training or support recommended (HR collects these)');
      const payload = (submit) => Object.assign(collectRatings('appraiser'), { comment: comment.value, strengths: strengths.value, developmentNeeds: dev.value, trainingNeeds: training.value, submit });
      body.appendChild(h('div', { class: 'panel' }, h('h2', { text: 'Your review' }), fld('Overall comment', comment), fld('Strengths', strengths), fld('Development needs', dev), fld('Training needs', training),
        h('p', { class: 'hint', style: 'margin-top:8px', text: 'The score is 70% objectives (by weight) and 30% competencies. Once submitted, ' + a.person + ' reads it and acknowledges it.' }),
        h('div', { class: 'row', style: 'margin-top:12px' }, h('span', { style: 'flex:1' }),
          h('button', { type: 'button', class: 'btn', text: 'Save draft', onclick: () => send('review', payload(false), 'Saved.') }),
          h('button', { type: 'button', class: 'btn primary', text: 'Submit review', onclick: () => { if (confirm('Submit the review? ' + a.person + ' will see it.')) send('review', payload(true), 'Review submitted.'); } })), msg));
      return;
    }
    if (a.managerComment || a.strengths || a.developmentNeeds || a.trainingNeeds) {
      const p = h('div', { class: 'panel' }, h('h2', { text: 'Appraiser\'s review' }));
      [['Overall comment', a.managerComment], ['Strengths', a.strengths], ['Development needs', a.developmentNeeds], ['Training needs', a.trainingNeeds]].forEach(([l, v]) => {
        if (v) p.append(h('label', { class: 'f', text: l }), h('p', { style: 'white-space:pre-wrap', text: v }));
      });
      body.appendChild(p);
    }

    // Stage 5: acknowledgement.
    if (me === 'self' && a.stage === 'acknowledge') {
      const agree = h('select', { style: 'width:auto' }, h('option', { value: '', text: 'Choose…' }), h('option', { value: '1', text: 'I agree with this appraisal' }), h('option', { value: '0', text: 'I do not agree' }));
      const comment = h('textarea', { maxLength: 4000, placeholder: 'Your comment (required if you disagree; HR will see it)' });
      body.appendChild(h('div', { class: 'panel' }, h('h2', { text: 'Acknowledge' }), h('p', { class: 'hint', text: 'Acknowledging says you have read it. You can still disagree.' }), agree, comment,
        h('button', { type: 'button', class: 'btn primary', style: 'margin-top:12px', text: 'Acknowledge', onclick: () => {
          if (agree.value === '') { bad(msg, { message: 'Say whether you agree.' }); return; }
          send('acknowledge', { agrees: agree.value === '1', comment: comment.value }, 'Acknowledged. Your appraisal is complete.');
        } }), msg));
      return;
    }
    if (a.acknowledgedAt) body.appendChild(h('div', { class: 'panel' }, h('h2', { text: 'Acknowledgement' }),
      h('p', { text: (a.staffAgrees ? 'Agreed' : 'Did not agree') + ' · ' + fmtDate(a.acknowledgedAt) }), a.staffComment ? h('p', { style: 'white-space:pre-wrap', text: a.staffComment }) : null));
    if (msg.childNodes.length) body.appendChild(msg);
    if (a.stage !== 'completed') body.appendChild(h('div', { class: 'panel' }, h('p', { class: 'hint', text: {
      objectives: 'Waiting for ' + a.person + ' to set objectives.', objectives_submitted: 'Waiting for the appraiser to agree the objectives.',
      self_assessment: 'Waiting for ' + a.person + '\'s self-assessment.', manager_review: 'Waiting for the appraiser\'s review.', acknowledge: 'Waiting for ' + a.person + ' to acknowledge.',
    }[a.stage] || '' })));
  }

  // ---------- cycles (HR) ----------
  async function renderCycles(body) {
    let d;
    try { d = await api('staff/appraisals/cycles'); } catch (e) { body.appendChild(empty(e.message)); return; }
    const f = { id: h('input', { type: 'hidden' }), name: h('input', { type: 'text', maxLength: 120, placeholder: 'e.g. 2026 annual appraisal' }), periodStart: h('input', { type: 'date' }),
      periodEnd: h('input', { type: 'date' }), objectivesDue: h('input', { type: 'date' }), reviewDue: h('input', { type: 'date' }) };
    const msg = h('div');
    const list = h('div', { class: 'panel' }, h('h2', { text: 'Cycles' }));
    if (!d.cycles.length) list.appendChild(empty('No cycles yet. Create one, then open it to start everyone\'s appraisal.'));
    d.cycles.forEach((c) => list.appendChild(h('div', { class: 'list-row' },
      h('div', { class: 'grow' }, h('a', { href: '#appraisals', text: c.name, onclick: (e) => { e.preventDefault(); state.cycleId = c.id; render(); } }),
        h('div', { class: 'hint', text: fmtDate(c.periodStart) + ' to ' + fmtDate(c.periodEnd) + ' · ' + c.done + ' of ' + c.people + ' completed' })),
      h('span', { class: 'pill ' + (c.status === 'open' ? 'p-ok' : 'p-muted'), text: c.status }),
      c.status !== 'closed' ? h('button', { type: 'button', class: 'btn sm', text: c.status === 'open' ? 'Add new joiners' : 'Open cycle', onclick: async () => {
        if (c.status === 'draft' && !confirm('Open "' + c.name + '"? Every active member of staff gets an appraisal, with their line manager as appraiser, and is notified.')) return;
        try { const r = await post('staff/appraisals/cycles/' + c.id + '/open'); S.say('flash', r.added + ' appraisal' + (r.added === 1 ? '' : 's') + ' started.', 'ok'); render(); } catch (e) { bad(msg, e); }
      } }) : null,
      c.status === 'open' ? h('button', { type: 'button', class: 'btn sm', text: 'Close', onclick: async () => { if (confirm('Close this cycle? Nothing more can be changed in it.')) { try { await post('staff/appraisals/cycles/' + c.id + '/close'); render(); } catch (e) { bad(msg, e); } } } }) : null,
      h('button', { type: 'button', class: 'btn sm', text: 'Edit', onclick: () => { f.id.value = c.id; ['name', 'periodStart', 'periodEnd', 'objectivesDue', 'reviewDue'].forEach((k) => { f[k].value = c[k] || ''; }); } }))));
    body.append(list, h('div', { class: 'panel' }, h('h2', { text: 'Create or change a cycle' }), f.id, fld('Name', f.name),
      h('div', { class: 'grid2' }, fld('Period from', f.periodStart), fld('Period to', f.periodEnd), fld('Objectives due', f.objectivesDue), fld('Reviews due', f.reviewDue)),
      h('button', { type: 'button', class: 'btn primary', style: 'margin-top:12px', text: 'Save cycle', onclick: async () => {
        const b = {}; Object.keys(f).forEach((k) => { b[k] = f[k].value; });
        try { await post('staff/appraisals/cycles', b); render(); } catch (e) { bad(msg, e); }
      } }), msg));
  }

  async function renderCycle(body, id) {
    let d;
    try { d = await api('staff/appraisals/cycles/' + id); } catch (e) { body.appendChild(empty(e.message)); return; }
    const box = h('div', { class: 'panel' });
    S.printButtons(box, 'api/index.php?route=staff/appraisals/cycles/' + id + '&csv=1');
    const counts = {};
    d.appraisals.forEach((a) => { counts[a.stageLabel] = (counts[a.stageLabel] || 0) + 1; });
    box.appendChild(h('p', { class: 'hint', text: Object.entries(counts).map(([k, n]) => k + ': ' + n).join(' · ') || 'Nobody yet.' }));
    const msg = h('div');
    const t = h('table', null, h('tr', null, ['Staff', 'Appraiser', 'Stage', 'Result', 'Agrees'].map((x) => h('th', { text: x }))));
    d.appraisals.forEach((a) => {
      const sel = h('select', { class: 'no-print', style: 'width:auto;max-width:200px', 'aria-label': 'Appraiser for ' + a.person, onchange: async (e) => {
        try { await post('staff/appraisals/' + a.id + '/appraiser', { appraiserId: e.target.value }); msg.replaceChildren(h('div', { class: 'note ok', text: 'Appraiser changed for ' + a.person + '.' })); } catch (er) { bad(msg, er); }
      } }, h('option', { value: '', text: 'HR' }), d.staff.filter((s) => s.userId !== a.userId).map((s) => h('option', { value: s.userId, text: s.name })));
      sel.value = a.appraiserId == null ? '' : String(a.appraiserId);
      t.appendChild(h('tr', null, h('td', null, h('a', { href: '#appraisal-' + a.id, text: a.person }), a.jobTitle ? h('div', { class: 'hint', text: a.jobTitle }) : null),
        h('td', null, sel), h('td', { text: a.stageLabel }), h('td', { text: a.rating ? a.rating + ' (' + a.score.toFixed(2) + ')' : '–' }),
        h('td', { text: a.agrees == null ? '' : (a.agrees ? 'Yes' : 'No'), style: a.agrees === false ? 'color:var(--bad);font-weight:700' : '' })));
    });
    box.append(h('div', { class: 'table-wrap' }, t), msg);
    body.append(h('div', { class: 'row no-print' }, h('button', { type: 'button', class: 'btn sm', text: '‹ All cycles', onclick: () => { state.cycleId = null; render(); } })), box);
  }

  S.loaders.appraisals = () => {
    if (pending) { Object.assign(state, pending); pending = null; } else { state.view = 'list'; state.id = null; state.cycleId = null; }
    load();
  };
  S.routes.push((hash) => {
    const m = hash.match(/^appraisal-(\d+)$/);
    if (!m) return false;
    pending = { view: 'list', id: +m[1], cycleId: null };
    S.show('appraisals');
    return true;
  });
})();
