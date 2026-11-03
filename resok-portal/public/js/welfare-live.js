(function () {
  'use strict';
  if (window.ResokWelfareLive || !window.ResokPortal) return;
  window.ResokWelfareLive = true;
  const full = !!document.getElementById('welfareLiveRoot');
  const host = document.getElementById('welfareLiveRoot') || document.querySelector('main');
  if (!host) return;
  const box = document.createElement('section');
  box.className = 'welfare-live'; box.hidden = true;
  const style = document.createElement('style');
  style.textContent = '.welfare-live{box-sizing:border-box;margin:0 0 20px;padding:20px;border:1px solid #cbd5e1;border-left:5px solid #00932e;border-radius:10px;background:#fff;color:#172b3a;font:14px/1.6 system-ui,sans-serif}.welfare-live h2{font-size:20px;margin:0 0 8px}.welfare-live h3{font-size:16px;margin:14px 0 6px}.welfare-live a{color:#08782c;text-decoration:underline}.welfare-live .wl-stale{color:#a3390b}.welfare-live .wl-row{display:grid;grid-template-columns:minmax(100px,1fr) minmax(60px,2fr) auto;gap:12px;align-items:center;margin:8px 0}.welfare-live progress{width:100%;height:13px;accent-color:#00932e}.welfare-live .wl-facts{display:flex;gap:18px;flex-wrap:wrap;font-weight:700}.welfare-live details{margin-top:10px}.welfare-live .wl-meta{color:#526171;font-size:12px}.welfare-live button{font:inherit;padding:7px 12px;cursor:pointer}@media(max-width:480px){.welfare-live{padding:14px}.welfare-live .wl-row{grid-template-columns:1fr auto}.welfare-live progress{grid-column:1/-1}}';
  document.head.appendChild(style);host.prepend(box);
  const node = (tag, text, cls) => { const n = document.createElement(tag); if (text != null) n.textContent = text; if (cls) n.className = cls; return n; };
  let timer, running = false, previous = '', lastSuccess = 0;
  const stale = node('p', '', 'wl-stale'); stale.setAttribute('role', 'status');
  const content = node('div'); box.append(content, stale);
  async function refresh() {
    if (running || document.hidden) return;
    running = true;
    try {
      const data = await window.ResokPortal.api('welfare/live');
      if (!data || !Array.isArray(data.elections)) {
        const error = new Error('The live election feed is unavailable. Please reload the portal or try again shortly.');
        error.code = 'INVALID_API_RESPONSE';
        throw error;
      }
      const signature = JSON.stringify(data.elections);
      const voteNav = document.getElementById('welfareVoteNav');
      if (voteNav) voteNav.hidden = !(data.elections || []).some(e => e.canParticipate);
      lastSuccess = Date.now(); stale.textContent = '';
      box.hidden = !(data.elections || []).length;
      if (full && box.hidden) { box.hidden = false; content.textContent = 'No welfare election has been scheduled yet.'; }
      if (signature !== previous) {
        const expanded = new Set(Array.from(content.querySelectorAll('details[open]')).map(d => d.dataset.election));
        previous = signature; content.textContent = '';
        if (full && !(data.elections || []).length) content.textContent = 'No welfare election has been scheduled yet.';
        (data.elections || []).forEach((entry) => {
          const article = node('article');
          article.append(node('h2', entry.election.title));
          article.append(node('p', entry.final ? 'Final results published' : 'Live provisional totals — updated every 5 seconds'));
          const facts = node('div', null, 'wl-facts');
          facts.append(node('span', entry.roll.voted + ' of ' + entry.roll.onRoll + ' voters'), node('span', entry.roll.turnout + '% turnout'), node('span', entry.election.status.replace(/_/g, ' ')));
          article.append(facts);
          if (entry.attention) article.append(node('p', entry.attention, 'wl-stale'));
          const details = node('details'); details.dataset.election = String(entry.election.id); details.open = full || expanded.has(String(entry.election.id));
          details.append(node('summary', 'Candidate totals and election updates'));
          (entry.positions || []).forEach((p) => {
            details.append(node('h3', p.title));
            (p.candidates || []).filter(c => c.status === 'approved').forEach((c) => {
              const row = node('div', null, 'wl-row');
              const bar = node('progress'); bar.max = Math.max(1, p.ballotsCast); bar.value = c.votes;
              bar.setAttribute('aria-label', c.name + ': ' + c.votes + ' votes');
              row.append(node('span', c.name), bar, node('strong', String(c.votes)));
              details.append(row);
            });
            details.append(node('p', p.ballotsCast + ' ballots · ' + p.abstentions + ' abstentions', 'wl-meta'));
          });
          const feed = node('ul');
          (entry.events || []).forEach(e => feed.append(node('li', e.message)));
          details.append(feed); article.append(details);
          const vote = node('a', 'Open welfare elections'); vote.href = 'elections?for=staff#' + encodeURIComponent(entry.election.slug);
          // Observers see totals; eligibility is always decided by the server.
          const info = node('p'); if (entry.canParticipate) info.append(vote); else info.textContent = 'Viewing live totals. Voting is restricted to the listed electorate.'; article.append(info);
          content.append(article);
        });
      }
      const stamp = node('p', 'Last checked ' + new Date(lastSuccess).toLocaleTimeString('en-KE') + ' · Totals do not disclose individual votes.', 'wl-meta');
      const old = box.querySelector('[data-welfare-stamp]'); if (old) old.remove();
      stamp.dataset.welfareStamp = '1'; box.append(stamp);
    } catch (e) {
      if (e.status === 401) { clearInterval(timer); if (full) location.href = 'login?next=election-live'; }
      if (lastSuccess) stale.textContent = 'Live connection interrupted. Totals shown are from the last successful update; reconnecting.';
      else if (full) {
        box.hidden = false;
        stale.textContent = e instanceof TypeError ? 'Could not connect to the election feed. Please check your connection and try again.' : (e.message || 'Could not connect to the election feed.');
        if (e.code === 'INVALID_API_RESPONSE' && ['localhost', '127.0.0.1'].includes(location.hostname)) {
          stale.textContent = 'Open the local portal to sign in and view live election results.';
          const link = node('a', 'Open local portal');
          link.href = 'http://localhost:8081/resok-portal/public/election-live';
          stale.append(' ', link);
        }
      }
    } finally { running = false; }
  }
  timer = setInterval(refresh, 5000);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) refresh(); });
  window.addEventListener('pagehide', () => clearInterval(timer), { once: true });
  refresh();
})();
