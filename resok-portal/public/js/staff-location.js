/*
 * Staff portal: location and working-hours access (api lib/staff-geo.php).
 * Everyone: where the system sees them, their rules, and asking for an exception before
 * travelling or working late. Super admins: the requests to decide, what was refused or
 * noted, and the rules themselves.
 * Built with nodes and textContent only: reasons and names are typed by people.
 */
(() => {
  const S = window.StaffPortal;
  if (!S) return;
  const { api, post, h, empty, fmtDate, fmtWhen } = S;
  const root = () => S.panel('location');
  const COUNTRIES = [['', 'Any country'], ['UG', 'Uganda'], ['TZ', 'Tanzania'], ['RW', 'Rwanda'], ['ET', 'Ethiopia'], ['SO', 'Somalia'],
    ['SS', 'South Sudan'], ['ZA', 'South Africa'], ['NG', 'Nigeria'], ['GB', 'United Kingdom'], ['US', 'United States'], ['IN', 'India'], ['AE', 'United Arab Emirates']];
  const STATUS = { pending: ['Waiting for ICT', 'p-info'], approved: ['Approved', 'p-ok'], declined: ['Declined', 'p-warn'], cancelled: ['Cancelled', 'p-muted'] };
  const pill = (st) => h('span', { class: 'pill ' + (STATUS[st] || ['', 'p-muted'])[1], text: (STATUS[st] || [st])[0] });
  const today = () => new Date().toISOString().slice(0, 10);
  const note = (box, text, kind) => box.replaceChildren(text ? h('div', { class: 'note ' + kind, text }) : '');
  const fld = (label, input) => h('div', null, h('label', { class: 'f', text: label }), input);
  const MODES = [['off', 'Off'], ['monitor', 'Monitor only (log, do not refuse)'], ['enforce', 'Enforce (refuse)']];
  const select = (opts, value) => { const s = h('select'); opts.forEach(([v, t]) => s.appendChild(h('option', { value: v, text: t, selected: v === value }))); return s; };

  async function load() {
    let d;
    try { d = await api('staff/location'); } catch (e) { root().replaceChildren(empty(e.message || 'Location access could not load.')); return; }
    const box = root();
    box.replaceChildren(
      h('div', null, h('h1', { text: 'Location access' }),
        h('p', { class: 'lede', text: 'Staff accounts can be used in Kenya' + (d.myCounties.length ? ', in your counties of operation,' : '') +
          ' during working hours: ' + d.hoursLabel + '. Going abroad, working in another county or working late? Ask here first. ICT approves it.' })),
      rules(d), requestForm(d), mine(d));
    if (d.canDecide) box.append(queue(d), recent(d), events(d));
    if (d.isSuper) box.append(settings(d));
  }

  function rules(d) {
    const seen = d.seenAs.label + (d.seenAs.trusted ? '' : ' (not confirmed: the connection did not come through Cloudflare)');
    return h('div', { class: 'panel' }, h('h2', { text: 'Your rules' }),
      h('table', null,
        h('tr', null, h('th', { text: 'Where you are now' }), h('td', { text: seen })),
        h('tr', null, h('th', { text: 'Counties of operation' }), h('td', { text: d.myCounties.length ? d.myCounties.join(', ') : 'Anywhere in Kenya' })),
        h('tr', null, h('th', { text: 'Working hours' }), h('td', { text: d.exemptFromHours ? d.hoursLabel + ' (super admins are exempt)' : d.hoursLabel }))));
  }

  function requestForm(d) {
    const kind = select([['abroad', 'Outside Kenya'], ['county', 'Another county in Kenya'], ['hours', 'Outside working hours']], 'abroad');
    const country = select(COUNTRIES, '');
    const county = select([['', 'Any county']].concat(d.counties.map((c) => [c, c])), '');
    const from = h('input', { type: 'date', value: today(), min: today() });
    const to = h('input', { type: 'date', value: today(), min: today() });
    const reason = h('input', { type: 'text', maxLength: 500, placeholder: 'e.g. ERS congress in Vienna' });
    const msg = h('div');
    const countryWrap = fld('Country', country), countyWrap = fld('County', county);
    const sync = () => { countryWrap.hidden = kind.value !== 'abroad'; countyWrap.hidden = kind.value !== 'county'; };
    kind.addEventListener('change', sync); sync();
    const btn = h('button', { type: 'button', class: 'btn primary', text: 'Send to ICT', onclick: async () => {
      btn.disabled = true;
      try {
        const r = await post('staff/location/request', { kind: kind.value, country: country.value, county: county.value, fromDate: from.value, toDate: to.value, reason: reason.value });
        note(msg, r.message, 'ok'); reason.value = ''; setTimeout(load, 900);
      } catch (e) { note(msg, e.message, 'bad'); } finally { btn.disabled = false; }
    } });
    return h('div', { class: 'panel' }, h('h2', { text: 'Ask for access' }),
      h('div', { class: 'grid2' }, fld('What you need', kind), countryWrap, countyWrap, fld('First day', from), fld('Last day', to), fld('Why', reason)),
      h('div', { class: 'row', style: 'margin-top:12px' }, btn), msg);
  }

  function requestTable(rows, actions) {
    if (!rows.length) return empty('None.');
    return h('div', { class: 'table-wrap' }, h('table', null,
      h('tr', null, ['Who', 'For', 'Dates', 'Why', 'Status', ''].map((x) => h('th', { text: x }))),
      rows.map((r) => h('tr', null,
        h('td', { text: r.name || '' }), h('td', { text: r.where }),
        h('td', { text: fmtDate(r.fromDate) + (r.toDate !== r.fromDate ? ' to ' + fmtDate(r.toDate) : '') }),
        h('td', null, r.reason, r.askedFrom ? h('div', { class: 'hint', text: r.askedFrom }) : null, r.note ? h('div', { class: 'hint', text: 'Note: ' + r.note }) : null),
        h('td', null, pill(r.status), r.decidedBy ? h('div', { class: 'hint', text: 'by ' + r.decidedBy }) : null),
        h('td', null, actions(r))))));
  }

  function mine(d) {
    return h('div', { class: 'panel' }, h('h2', { text: 'Your requests' }), requestTable(d.mine, (r) =>
      ['pending', 'approved'].includes(r.status) && r.toDate >= today()
        ? h('button', { type: 'button', class: 'btn sm', text: 'Cancel', onclick: async () => { try { await post('staff/location/' + r.id + '/cancel'); load(); } catch (e) { alert(e.message); } } })
        : null));
  }

  function queue(d) {
    const msg = h('div');
    return h('div', { class: 'panel' }, h('h2', { text: 'Waiting for your decision' + (d.queue.length ? ' (' + d.queue.length + ')' : '') }), msg,
      requestTable(d.queue, (r) => h('div', { class: 'row' },
        h('button', { type: 'button', class: 'btn sm primary', text: 'Approve', onclick: async () => {
          try { await post('staff/location/' + r.id + '/approve'); load(); } catch (e) { note(msg, e.message, 'bad'); } } }),
        h('button', { type: 'button', class: 'btn sm', text: 'Decline', onclick: async () => {
          const why = prompt('Why is this declined? They will see this.');
          if (!why) return;
          try { await post('staff/location/' + r.id + '/decline', { note: why }); load(); } catch (e) { note(msg, e.message, 'bad'); } } }))));
  }

  function recent(d) {
    return h('details', { class: 'panel' }, h('summary', { text: 'Decided requests' }), requestTable(d.recent || [], () => null));
  }

  function events(d) {
    const label = { abroad: 'Outside Kenya', county: 'Outside their counties', 'county-unknown': 'County unknown', hours: 'Outside working hours', untrusted: 'Not through Cloudflare' };
    const rows = d.events || [];
    return h('details', { class: 'panel', open: rows.some((e) => e.outcome === 'refused') },
      h('summary', { text: 'Sign-ins outside the rules, last 30 days (' + rows.length + ')' }),
      h('p', { class: 'hint', text: '"Noted" means the rule is on Monitor: it was allowed and recorded. Use this to check counties are read correctly before enforcing them.' }),
      rows.length ? h('div', { class: 'table-wrap' }, h('table', null,
        h('tr', null, ['When', 'Who', 'What', 'Where', 'Outcome'].map((x) => h('th', { text: x }))),
        rows.map((e) => h('tr', null, h('td', { text: fmtWhen(e.at) }), h('td', { text: e.name }), h('td', { text: label[e.kind] || e.kind }),
          h('td', { text: [e.region, e.country].filter(Boolean).join(', ') }), h('td', { text: e.outcome })))))
        : empty('Nothing yet.'));
  }

  function settings(d) {
    const s = d.settings;
    const country = select(MODES, s.country), county = select(MODES, s.county), hours = select(MODES, s.hours);
    const from = h('input', { type: 'time', value: s.hoursFrom }), to = h('input', { type: 'time', value: s.hoursTo });
    const dayBoxes = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((n, i) =>
      h('label', { class: 'check', style: 'margin-right:10px' }, h('input', { type: 'checkbox', value: String(i + 1), checked: s.days.includes(String(i + 1)) }), n));
    const msg = h('div');
    const btn = h('button', { type: 'button', class: 'btn primary', text: 'Save rules', onclick: async () => {
      btn.disabled = true;
      try {
        await post('staff/location/settings', { country: country.value, county: county.value, hours: hours.value, hoursFrom: from.value, hoursTo: to.value,
          days: dayBoxes.map((l) => l.firstChild).filter((c) => c.checked).map((c) => c.value).join('') });
        note(msg, 'Saved.', 'ok'); setTimeout(load, 700);
      } catch (e) { note(msg, e.message, 'bad'); } finally { btn.disabled = false; }
    } });
    return h('div', { class: 'panel' }, h('h2', { text: 'Rules (super admins)' }),
      h('p', { class: 'hint', text: d.edgeSecret
        ? 'Cloudflare check is set up: connections that do not come through Cloudflare are refused.'
        : 'The Cloudflare secret header is not set up yet, so a connection that goes round Cloudflare is only logged. Set edge_secret in config.local.php and the matching Transform Rule in Cloudflare.' }),
      h('div', { class: 'grid2' }, fld('Outside Kenya', country), fld('Outside counties of operation', county), fld('Outside working hours', hours),
        fld('Working hours from', from), fld('Working hours to', to)),
      h('div', { style: 'margin-top:10px' }, h('label', { class: 'f', text: 'Working days' }), dayBoxes),
      h('div', { class: 'row', style: 'margin-top:12px' }, btn), msg);
  }

  S.loaders.location = load;
})();
