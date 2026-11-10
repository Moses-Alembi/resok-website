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
  style.textContent = `
    .welfare-live{--wl-green:#00932e;--wl-red:#bc0b22;box-sizing:border-box;position:relative;isolation:isolate;overflow:hidden;margin:0 0 22px;padding:26px;border:1px solid var(--line,#d8dee7);border-radius:16px;background:radial-gradient(ellipse at 96% 0,rgba(188,11,34,.07),transparent 34%),linear-gradient(115deg,rgba(0,147,46,.065),transparent 44%),var(--surface,#fff);color:var(--ink,#172b3a);box-shadow:0 14px 36px rgba(16,24,40,.09);font:14px/1.6 system-ui,sans-serif}
    .welfare-live:before{content:'';position:absolute;z-index:-1;inset:0 auto 0 0;width:5px;background:linear-gradient(180deg,var(--wl-green) 0 58%,var(--wl-red) 58%)}
    .welfare-live article{min-width:0}.welfare-live h2{font-size:clamp(21px,2.2vw,28px);letter-spacing:-.035em;line-height:1.2;margin:5px 0 7px;color:var(--ink,#172b3a)}
    .welfare-live .wl-head{display:flex;justify-content:space-between;align-items:flex-start;gap:20px;margin-bottom:22px}.welfare-live .wl-eyebrow{display:flex;align-items:center;gap:8px;color:var(--brand-ink,#05603a);font-size:10px;font-weight:850;letter-spacing:.14em;text-transform:uppercase}.welfare-live .wl-caption{margin:0;color:var(--muted,#667085);font-size:13px;line-height:1.7}
    .welfare-live .wl-state{display:inline-flex;align-items:center;gap:8px;flex:none;padding:7px 11px;border:1px solid rgba(0,147,46,.25);border-radius:999px;background:rgba(0,147,46,.09);color:var(--brand-ink,#05603a);font-size:10px;font-weight:850;letter-spacing:.09em;text-transform:uppercase}.welfare-live .wl-state:before{content:'';width:7px;height:7px;border-radius:50%;background:var(--wl-green);box-shadow:0 0 0 4px rgba(0,147,46,.12)}.welfare-live .wl-state.final{border-color:rgba(188,11,34,.25);background:rgba(188,11,34,.08);color:var(--wl-red)}.welfare-live .wl-state.final:before{background:var(--wl-red);box-shadow:0 0 0 4px rgba(188,11,34,.1)}
    .welfare-live .wl-facts{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin:0 0 20px}.welfare-live .wl-stat{min-width:0;padding:17px 18px;border:1px solid var(--line,#d8dee7);border-radius:12px;background:var(--bg,rgba(255,255,255,.7))}.welfare-live .wl-stat strong{display:block;overflow-wrap:anywhere;color:var(--ink,#172b3a);font-size:clamp(25px,3vw,36px);font-weight:800;letter-spacing:-.045em;line-height:1.12;font-variant-numeric:tabular-nums}.welfare-live .wl-stat:first-child strong{color:var(--brand-primary,var(--wl-green))}.welfare-live .wl-stat span{display:block;margin-top:6px;color:var(--muted,#667085);font-size:10px;font-weight:750;letter-spacing:.1em;text-transform:uppercase}
    .welfare-live .wl-details{margin-top:8px;border:1px solid var(--line,#d8dee7);border-radius:12px;background:var(--surface,#fff);overflow:hidden}.welfare-live .wl-details summary{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:15px 17px;cursor:pointer;list-style:none;color:var(--ink,#172b3a);font-size:12.5px;font-weight:750}.welfare-live .wl-details summary::-webkit-details-marker{display:none}.welfare-live .wl-details summary:after{content:'+';display:grid;place-items:center;width:25px;height:25px;border-radius:8px;background:var(--brand-soft,rgba(0,147,46,.1));color:var(--brand-ink,#05603a);font-size:18px;line-height:1}.welfare-live .wl-details[open] summary:after{content:'?'}.welfare-live .wl-detail-body{padding:0 17px 17px;border-top:1px solid var(--line,#d8dee7)}
    .welfare-live h3{font-size:13px;margin:18px 0 8px;color:var(--ink,#172b3a)}.welfare-live a{color:var(--brand-ink,#05603a);font-weight:750;text-decoration:none}.welfare-live a:hover{text-decoration:underline}.welfare-live .wl-stale{color:var(--bad,#a3390b)}
    .welfare-live .wl-row{display:grid;grid-template-columns:minmax(110px,1fr) minmax(80px,2fr) auto;gap:13px;align-items:center;margin:11px 0;color:var(--ink,#172b3a);font-size:12.5px}.welfare-live progress{width:100%;height:9px;border:0;border-radius:999px;accent-color:var(--wl-green)}.welfare-live progress::-webkit-progress-bar{background:var(--hover,#eef1f5);border-radius:999px}.welfare-live progress::-webkit-progress-value{background:linear-gradient(90deg,var(--wl-green),#53bd73);border-radius:999px}.welfare-live progress::-moz-progress-bar{background:var(--wl-green);border-radius:999px}.welfare-live .wl-row strong{font-variant-numeric:tabular-nums;font-size:14px}.welfare-live .wl-meta{color:var(--muted,#667085);font-size:11px}.welfare-live .wl-actions{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-top:17px}.welfare-live .wl-actions p{margin:0;color:var(--muted,#667085);font-size:11.5px}.welfare-live .wl-open{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:9px;background:var(--brand,#087539);color:#fff!important;font-size:12px}.welfare-live .wl-open:after{content:'?';font-size:16px}.welfare-live .wl-foot{display:flex;align-items:center;gap:7px;margin:17px 0 0;padding-top:13px;border-top:1px solid var(--line,#d8dee7);color:var(--muted,#667085);font-size:10.5px}.welfare-live .wl-foot:before{content:'?';color:var(--brand-ink,#05603a);font-size:8px}.welfare-live [hidden]{display:none!important}
    @media(max-width:620px){.welfare-live{padding:19px}.welfare-live .wl-head{gap:12px}.welfare-live .wl-facts{gap:8px}.welfare-live .wl-stat{padding:13px 11px}.welfare-live .wl-stat span{font-size:9px}.welfare-live .wl-row{grid-template-columns:minmax(0,1fr) auto;gap:9px}.welfare-live progress{grid-column:1/-1;grid-row:2}.welfare-live .wl-actions{align-items:flex-start}}
    @media(max-width:390px){.welfare-live .wl-head{display:block}.welfare-live .wl-state{margin-top:13px}.welfare-live .wl-facts{grid-template-columns:1fr}.welfare-live .wl-stat{display:flex;align-items:center;justify-content:space-between;gap:12px}.welfare-live .wl-stat span{margin:0;text-align:right}}
  `;
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
          const header = node('div', null, 'wl-head');
          const heading = node('div');
          heading.append(node('div', 'STAFF WELFARE ELECTION', 'wl-eyebrow'));
          heading.append(node('h2', entry.election.title));
          heading.append(node('p', entry.final ? 'The returning officer has published the final result.' : 'Provisional totals refresh automatically while the election is in progress.', 'wl-caption'));
          header.append(heading, node('span', entry.final ? 'Final result' : 'Live updates', 'wl-state' + (entry.final ? ' final' : '')));
          article.append(header);
          const facts = node('div', null, 'wl-facts');
          const participation = node('div', null, 'wl-stat');
          participation.append(node('strong', String(entry.roll.voted).padStart(2, '0') + ' / ' + entry.roll.onRoll), node('span', 'Staff ballots cast'));
          const turnout = node('div', null, 'wl-stat');
          turnout.append(node('strong', entry.roll.turnout + '%'), node('span', 'Voter turnout'));
          const phase = node('div', null, 'wl-stat');
          phase.append(node('strong', entry.election.status.replace(/_/g, ' ')), node('span', 'Election phase'));
          facts.append(participation, turnout, phase);
          article.append(facts);
          if (entry.attention) article.append(node('p', entry.attention, 'wl-stale'));
          const details = node('details', null, 'wl-details'); details.dataset.election = String(entry.election.id); details.open = full || expanded.has(String(entry.election.id));
          const summary = node('summary');
          summary.append(node('span', 'Candidate standings and election updates'), node('span', (entry.positions || []).length + ' positions', 'wl-meta'));
          details.append(summary);
          const detailBody = node('div', null, 'wl-detail-body');
          (entry.positions || []).forEach((p) => {
            detailBody.append(node('h3', p.title));
            (p.candidates || []).filter(c => c.status === 'approved').forEach((c) => {
              const row = node('div', null, 'wl-row');
              const bar = node('progress'); bar.max = Math.max(1, p.ballotsCast); bar.value = c.votes;
              bar.setAttribute('aria-label', c.name + ': ' + c.votes + ' votes');
              row.append(node('span', c.name), bar, node('strong', String(c.votes)));
              detailBody.append(row);
            });
            detailBody.append(node('p', p.ballotsCast + ' ballots ? ' + p.abstentions + ' abstentions', 'wl-meta'));
          });
          const feed = node('ul');
          (entry.events || []).forEach(e => feed.append(node('li', e.message)));
          if (entry.events && entry.events.length) detailBody.append(node('h3', 'Election updates'), feed);
          details.append(detailBody); article.append(details);
          const vote = node('a', 'Open welfare elections'); vote.className = 'wl-open'; vote.href = 'elections?for=staff#' + encodeURIComponent(entry.election.slug);
          // Observers see totals; eligibility is always decided by the server.
          const info = node('div', null, 'wl-actions');
          if (entry.canParticipate) info.append(vote);
          info.append(node('p', entry.canParticipate ? 'Your access to voting is checked securely by the portal.' : 'Live totals are visible here. Ballots are available only to eligible staff.'));
          article.append(info);
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
