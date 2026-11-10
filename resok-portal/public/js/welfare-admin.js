(function () {
  'use strict';
  const api = (route, body) => window.ResokPortal.api(route, body === undefined ? {} : { method: 'POST', body: JSON.stringify(body) });
  const node = (tag, text, cls) => { const n = document.createElement(tag); if (text != null) n.textContent = text; if (cls) n.className = cls; return n; };
  async function render(election) {
    let panel = document.getElementById('welfareAdmin');
    if (panel) panel.remove();
    if (election.electorate !== 'staff') return;
    panel = node('section', null, 'panel'); panel.id = 'welfareAdmin';
    const parent = document.getElementById('viewOne'); parent.insertBefore(panel, parent.children[1] || null);
    panel.append(node('h2', 'Automatic welfare election'));
    panel.append(node('p', 'Every active staff account is eligible and stays on the voter roll, including accounts awaiting email verification. Once verified, staff can sign in and vote. Nominations are final and approved automatically. Live aggregate totals are visible to signed-in portal users.'));
    const message = node('p'); message.setAttribute('role', 'status');
    async function act(action, body) {
      message.textContent = 'Saving…';
      try { const result = await api('admin/welfare/' + election.id + '/' + action, body || {}); await render(election); const status = document.querySelector('#welfareAdmin [role=status]'); if (status) status.textContent = result.message || 'Saved.'; }
      catch(e) { message.textContent = e.message || 'Could not save changes.'; }
    }
    try {
      const data = await api('admin/welfare/' + election.id);
      const r = data.readiness;
      const awaitingVerification = Number(r.unverifiedEmployees || 0);
      panel.append(node('p', r.employeesReady + '/' + r.targetEmployees + ' staff accounts verified; ' + r.expectedVoters + ' voters on the roll', 'lede'));
      if (awaitingVerification) panel.append(node('p', awaitingVerification + ' active staff account(s) are awaiting email verification. They stay on the roll and can vote after activation.', 'note warn'));
      if (r.lastError) panel.append(node('p', r.lastError, 'note warn'));
      if (!data.settings) {
        const configure = node('button', 'Set up automatic election', 'btn btn-primary'); configure.type = 'button'; configure.onclick = () => act('configure'); panel.append(configure, message); return;
      }
      panel.append(node('p', r.armed ? 'Schedule armed. Dates, electorate and candidates freeze when voting opens.' : 'Schedule not armed. Reconcile accounts, confirm all dates, then arm it.'));
      const controls = node('div'); controls.style.cssText = 'display:flex;gap:12px;flex-wrap:wrap;margin:14px 0';
      const arm = node('button', r.armed ? 'Disarm schedule' : 'Arm automatic schedule', 'btn btn-primary'); arm.type = 'button';
      arm.disabled = election.locked || (!r.armed && !r.ready);
      arm.onclick = () => {
        const warning = awaitingVerification ? ' ' + awaitingVerification + ' account(s) still need email verification; they will stay on the roll and can vote after activation.' : ' All active staff accounts are verified.';
        if (confirm(r.armed ? 'Disarm the schedule before voting opens?' : 'Freeze the voter roll and arm the schedule?' + warning + ' Nominations, voting, counting and publication will progress automatically.')) act(r.armed ? 'disarm' : 'arm');
      };
      const live = node('a', 'Open live election screen', 'btn btn-ghost'); live.href = 'election-live'; controls.append(arm, live); panel.append(controls);
      if (!r.armed && !election.locked) {
        const form = node('form');
        const rowsLabel = node('label', 'Staff roster: full name and email, or employee number, full name and email (one employee per row, separated by tabs or commas)'); rowsLabel.htmlFor = 'welfareImportRows';
        const rows = node('textarea'); rows.id='welfareImportRows'; rows.className='in'; rows.rows=6; rows.required=true;
        const save = node('button','Create or reconcile these accounts','btn btn-primary');save.type='submit';
        form.append(rowsLabel,rows,node('p','Existing passwords and roles are preserved. New accounts need activation; this action does not send email.'),save);
        form.onsubmit=async(event)=>{
          event.preventDefault();
          try {
            const people=rows.value.split(/\r?\n/).filter(l=>l.trim()).map(line=>{
              const parts=line.split(line.includes('\t')?'\t':',').map(p=>p.trim());
              if(parts.length===3)return {staffNumber:parts[0],name:parts[1],email:parts[2]};
              if(parts.length===2)return {staffNumber:'',name:parts[0],email:parts[1]};
              throw new Error('Each staff row needs full name and email, with an optional employee number.');
            });
            save.disabled=true;await act('import',{kind:'employee',people});
          }catch(e){message.textContent=e.message;}finally{save.disabled=false;}
        };
        panel.append(form);
      }
      panel.append(node('p','Voting is recorded against the voter roll. The live feed exposes aggregate totals only. Keep the scheduled cron job running every minute; portal activity also advances due stages.','lede'),message);
    } catch(e) { panel.append(node('p',e.message||'Could not load welfare setup.','note warn')); }
  }
  document.addEventListener('resok:election-admin-open', e => render(e.detail));
})();
