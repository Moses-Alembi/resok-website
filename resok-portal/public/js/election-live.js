/* Full live screen. The compact welfare widget remains unchanged on other portal pages. */
(function () {
  'use strict';
  const byId = (id) => document.getElementById(id);
  const api = (route) => window.ResokPortal.api(route);
  const feed = byId('welfareLiveRoot');
  const refreshButton = byId('liveRefresh');
  const notice = byId('liveFeedNotice');
  let isStaff = false, canParticipate = false, running = false, successful = false;
  let previous = '', timer = null;
  const node = (tag, text, className) => {
    const result = document.createElement(tag);
    if (text !== undefined && text !== null) result.textContent = String(text);
    if (className) result.className = className;
    return result;
  };
  const amount = (value) => Number.isFinite(Number(value)) ? Math.max(0, Number(value)) : 0;

  function updateVoteLink() { byId('welfareVoteNav').hidden = !isStaff && !canParticipate; }
  function applyAccount(home, person) {
    const superAdmin = home?.super === true;
    isStaff = superAdmin || home?.onStaffList === true;
    const account = isStaff ? (home.me || person || {}) : (person || {});
    const name = String(account.name || account.email || 'Portal account');
    byId('liveAccountName').textContent = name;
    byId('liveAccountJobTitle').textContent = String(account.jobTitle || '').trim();
    byId('liveAccountJobTitle').hidden = !superAdmin || !byId('liveAccountJobTitle').textContent;
    byId('liveAccountCaption').textContent = superAdmin ? 'Super administrator'
      : (isStaff ? (String(account.jobTitle || '').trim() || 'Staff member') : 'Portal account');
    byId('staffAvatar').textContent = name.split(/\s+/).filter(Boolean).slice(0, 2).map((part) => part.charAt(0)).join('').toUpperCase();
    byId('liveStaffNav').hidden = !isStaff;
    byId('liveObserverNav').hidden = isStaff;
    byId('liveManageNav').hidden = !superAdmin;
    byId('liveManageAction').hidden = !superAdmin;
    const homePath = isStaff ? 'staff' : 'dashboard';
    ['liveBrandHome', 'liveHomeBreadcrumb', 'liveFooterHome'].forEach((id) => { byId(id).setAttribute('href', homePath); });
    byId('liveHomeBreadcrumb').textContent = isStaff ? 'Staff portal' : 'Portal home';
    byId('liveFooterLabel').textContent = isStaff ? 'ReSoK Staff Portal' : 'ReSoK Portal';
    byId('liveWorkspaceLabel').textContent = isStaff ? 'Staff workspace' : 'Portal workspace';
    byId('liveSidebarCaption').textContent = isStaff ? 'Staff workspace' : 'Portal workspace';
    byId('side').setAttribute('aria-label', isStaff ? 'Staff navigation' : 'Portal navigation');
    byId('staffSearch').placeholder = isStaff ? 'Find a staff section...' : 'Find a portal section...';
    byId('liveStaffNav').querySelectorAll('[data-staff-paused-href]').forEach((link) => {
      if (superAdmin) {
        link.setAttribute('href', link.dataset.staffPausedHref);
        link.removeAttribute('aria-disabled'); link.removeAttribute('tabindex'); link.removeAttribute('title');
        link.classList.remove('staff-unavailable', 'locked', 'greyed');
        const badge = link.querySelector('.soon'); if (badge) badge.hidden = true;
      } else {
        link.removeAttribute('href'); link.setAttribute('aria-disabled', 'true'); link.setAttribute('tabindex', '-1');
        link.title = 'Coming soon'; link.classList.add('staff-unavailable', 'locked', 'greyed');
        const badge = link.querySelector('.soon'); if (badge) badge.hidden = false;
      }
    });
    updateVoteLink();
  }
  async function loadAccount() {
    let home = null, person = null;
    try { home = await api('staff/home'); } catch (error) { /* Observers do not have staff access. */ }
    if (!home || !(home.super === true || home.onStaffList === true)) {
      try { person = (await api('welfare/me'))?.me || null; } catch (error) { /* Results work independently of account metadata. */ }
    }
    applyAccount(home, person);
  }

  function connection(state, message) {
    const label = byId('liveConnection');
    label.dataset.state = state;
    if (label.textContent !== message) label.textContent = message;
  }
  function emptyPanel(title, message, iconClass) {
    const panel = node('section', null, 'panel live-empty');
    const icon = node('i', null, 'fas ' + iconClass); icon.setAttribute('aria-hidden', 'true');
    const mark = node('span', null, 'live-empty-icon'); mark.appendChild(icon);
    panel.append(mark, node('h2', title), node('p', message));
    return panel;
  }
  function render(elections) {
    const expanded = new Map(Array.from(feed.querySelectorAll('details[data-live-election]')).map((details) => [details.dataset.liveElection, details.open]));
    const focusKey = feed.contains(document.activeElement) ? document.activeElement?.dataset.liveFocusKey : null;
    const panels = [];
    if (!elections.length) panels.push(emptyPanel('No welfare election has been scheduled yet.', 'Scheduled welfare elections and live candidate totals will appear here.', 'fa-check-to-slot'));
    elections.forEach((entry) => {
      const electionKey = String(entry.election.id);
      const panel = node('article', null, 'panel live-election');
      const header = node('div', null, 'live-election-header');
      const heading = node('div');
      heading.append(node('h2', entry.election.title), node('p', entry.final ? 'Final results have been declared.' : 'Provisional candidate totals. Results can change until the count is declared.'));
      const phaseClass = entry.final ? 'p-info' : (['open', 'nominations'].includes(entry.election.status) ? 'p-ok' : 'p-muted');
      header.append(heading, node('span', entry.final ? 'Results declared' : String(entry.election.status).replace(/_/g, ' '), 'pill ' + phaseClass));
      panel.appendChild(header);
      const stats = node('div', null, 'live-stats');
      [[amount(entry.roll.voted) + ' / ' + amount(entry.roll.onRoll), 'Ballots recorded'], [amount(entry.roll.turnout) + '%', 'Voter turnout'], [amount(entry.roll.onRoll), 'Registered voters']].forEach(([value, label]) => {
        const stat = node('div', null, 'live-stat'); stat.append(node('strong', value), node('span', label)); stats.appendChild(stat);
      });
      panel.appendChild(stats);
      if (entry.attention) panel.appendChild(node('p', entry.attention, 'note warn'));
      const positions = node('div', null, 'live-positions');
      (entry.positions || []).forEach((position) => {
        const box = node('section', null, 'live-position');
        const head = node('div', null, 'live-position-head');
        head.append(node('h3', position.title), node('small', amount(position.ballotsCast) + ' ballots · ' + amount(position.abstentions) + ' abstentions'));
        box.appendChild(head);
        const candidates = node('div', null, 'live-candidates');
        const approved = (position.candidates || []).filter((candidate) => candidate.status === 'approved');
        approved.forEach((candidate) => {
          const row = node('div', null, 'live-candidate');
          const name = node('div', null, 'live-candidate-name'); name.appendChild(node('strong', candidate.name));
          if (!entry.final && candidate.jobTitle) name.appendChild(node('small', candidate.jobTitle));
          const bar = node('progress'); bar.max = Math.max(1, amount(position.ballotsCast)); bar.value = amount(candidate.votes);
          bar.setAttribute('aria-label', candidate.name + ': ' + amount(candidate.votes) + ' votes');
          const total = node('div', null, 'live-vote-count'); total.append(node('strong', amount(candidate.votes)), node('small', amount(candidate.votes) === 1 ? 'vote' : 'votes'));
          row.append(name, bar, total); candidates.appendChild(row);
        });
        if (!approved.length) candidates.appendChild(node('p', 'No confirmed candidates for this position yet.', 'live-no-candidates'));
        box.appendChild(candidates); positions.appendChild(box);
      });
      if (!(entry.positions || []).length) positions.appendChild(node('p', 'Candidate totals will appear once the election positions are ready.', 'live-no-candidates'));
      panel.appendChild(positions);
      if ((entry.events || []).length) {
        const details = node('details', null, 'live-milestones'); details.dataset.liveElection = electionKey;
        details.open = expanded.get(electionKey) === true;
        const summary = node('summary', 'Election updates'); summary.dataset.liveFocusKey = electionKey + '-updates';
        const events = node('ul'); entry.events.forEach((event) => events.appendChild(node('li', event.message)));
        details.append(summary, events); panel.appendChild(details);
      }
      const actions = node('div', null, 'live-election-actions');
      if (entry.canParticipate === true) {
        const vote = node('a', 'Open welfare elections', 'btn primary');
        vote.setAttribute('href', 'elections?for=staff#' + encodeURIComponent(entry.election.slug)); vote.dataset.liveFocusKey = electionKey + '-vote'; actions.appendChild(vote);
      } else { actions.appendChild(node('p', 'Viewing live totals. Voting is available to people on this election’s roll.')); }
      panel.appendChild(actions); panels.push(panel);
    });
    feed.replaceChildren(...panels);
    if (focusKey) {
      const replacement = Array.from(feed.querySelectorAll('[data-live-focus-key]')).find((element) => element.dataset.liveFocusKey === focusKey);
      if (replacement) replacement.focus({ preventScroll: true });
    }
  }

  async function refresh(manual) {
    if (running || (!manual && document.hidden)) return;
    running = true; refreshButton.disabled = true; feed.setAttribute('aria-busy', 'true');
    try {
      const data = await api('welfare/live');
      if (!data || !Array.isArray(data.elections) || data.elections.some((entry) => !entry?.election || !entry.roll || !Array.isArray(entry.positions) || !Array.isArray(entry.events))) {
        throw new Error('The live election feed is unavailable. Use Refresh to try again.');
      }
      const signature = JSON.stringify(data.elections);
      if (signature !== previous) { render(data.elections); previous = signature; }
      successful = true; canParticipate = data.elections.some((entry) => entry.canParticipate === true); updateVoteLink();
      notice.hidden = true; notice.textContent = '';
      connection('connected', 'Automatic updates on');
      byId('liveCheckedAt').textContent = 'Last checked ' + new Date().toLocaleTimeString('en-KE') + ' · Updates every 5 seconds';
    } catch (error) {
      if (error.status === 401) {
        clearInterval(timer); connection('offline', 'Sign in to view live results');
        window.location.href = 'login?next=election-live'; return;
      }
      if (successful) {
        connection('stale', 'Reconnecting to live results');
        notice.textContent = 'Connection interrupted. Totals shown are from the last successful update. We’ll keep trying automatically.'; notice.hidden = false;
      } else {
        connection('offline', 'Live results are unavailable');
        feed.replaceChildren(emptyPanel('Could not load live results', 'Check your connection or use Refresh to try again. ' + (error.message || ''), 'fa-wifi'));
      }
    } finally {
      running = false; refreshButton.disabled = false; feed.setAttribute('aria-busy', 'false');
    }
  }
  function startTimer() { clearInterval(timer); timer = setInterval(() => refresh(false), 5000); }
  refreshButton.addEventListener('click', () => refresh(true));
  byId('liveLogout').addEventListener('click', () => window.ResokPortal.logout());
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(false); });
  window.addEventListener('pagehide', () => clearInterval(timer));
  window.addEventListener('pageshow', (event) => { if (event.persisted) { startTimer(); refresh(false); } });
  startTimer(); refresh(false);
  loadAccount();
})();
