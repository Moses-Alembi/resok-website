(function () {
  'use strict';
  let current;
  const api = (route, body) => window.ResokPortal.api(route, body === undefined ? {} : { method: 'POST', body: JSON.stringify(body) });
  const node = (tag, text, cls) => { const n = document.createElement(tag); if (text != null) n.textContent = text; if (cls) n.className = cls; return n; };
  async function render(election) {
    current = election;
    let panel = document.getElementById('welfareAdmin');
    if (panel) panel.remove();
    if (election.electorate !== 'staff') return;
    panel = node('section', null, 'panel'); panel.id = 'welfareAdmin';
    const parent = document.getElementById('viewOne'); parent.insertBefore(panel, parent.children[1] || null);
    panel.append(node('h2', 'Automatic welfare election'));
    panel.append(node('p', '32 employee accounts plus the named additional voters. Nominations are final and approved automatically. Live aggregate totals are visible to signed-in portal users.'));
    const message = node('p'); message.setAttribute('role', 'status');
    async function act(action, body) {
      message.textContent = 'Saving…';
      try { const result = await api('admin/welfare/' + election.id + '/' + action, body || {}); await render(election); const status = document.querySelector('#welfareAdmin [role=status]'); if (status) status.textContent = result.message || 'Saved.'; }
      catch(e) { message.textContent = e.message || 'Could not save changes.'; }
    }
    try {
      const data = await api('admin/welfare/' + election.id);
      const r = data.readiness;
      panel.append(node('p', r.employeesReady + '/' + r.targetEmployees + ' employee accounts ready · ' + r.additionalReady + '/' + r.additionalVoters + ' additional accounts ready · ' + r.expectedVoters + ' expected voters', 'lede'));
      if (r.lastError) panel.append(node('p', r.lastError, 'note warn'));
      if (!data.settings) {
        const configure = node('button', 'Set up automatic election', 'btn btn-primary'); configure.type = 'button'; configure.onclick = () => act('configure'); panel.append(configure, message); return;
      }
      panel.append(node('p', r.armed ? 'Schedule armed. Dates, electorate and candidates freeze when voting opens.' : 'Schedule not armed. Reconcile accounts, confirm all dates, then arm it.'));
      const controls = node('div'); controls.style.cssText = 'display:flex;gap:12px;flex-wrap:wrap;margin:14px 0';
      const arm = node('button', r.armed ? 'Disarm schedule' : 'Arm automatic schedule', 'btn btn-primary'); arm.type = 'button';
      arm.disabled = election.locked || (!r.armed && !r.ready);
      arm.onclick = () => { if (confirm(r.armed ? 'Disarm the schedule before voting opens?' : 'Freeze the listed voter roll and arm the schedule? Nominations, voting, counting and publication will progress automatically.')) act(r.armed ? 'disarm' : 'arm'); };
      const live = node('a', 'Open live election screen', 'btn btn-ghost'); live.href = 'election-live'; controls.append(arm, live); panel.append(controls);
      if (!r.armed && !election.locked) {
        const form = node('form');
        const label = node('label', 'Account list'); label.htmlFor = 'welfareImportKind';
        const select = node('select'); select.id = 'welfareImportKind'; select.className = 'in';
        [['employee', 'Employees: employee number, full name, email'], ['guest', 'Additional voters: full name, email']].forEach(([v,t])=>{const o=node('option',t);o.value=v;select.append(o);});
        const rowsLabel = node('label', 'Paste rows copied from a spreadsheet (tab-separated), or comma-separated rows'); rowsLabel.htmlFor = 'welfareImportRows';
        const rows = node('textarea'); rows.id='welfareImportRows'; rows.className='in'; rows.rows=6; rows.required=true;
        const save = node('button','Create or reconcile these accounts','btn btn-primary');save.type='submit';
        form.append(label,select,rowsLabel,rows,node('p','Existing passwords and roles are preserved. New accounts need activation; this action does not send email.'),save);
        form.onsubmit=async(event)=>{
          event.preventDefault();
          try {
            const people=rows.value.split(/\r?\n/).filter(l=>l.trim()).map(line=>{
              const parts=line.split(line.includes('\t')?'\t':',').map(p=>p.trim());
              if(select.value==='employee'&&parts.length===3)return {staffNumber:parts[0],name:parts[1],email:parts[2]};
              if(select.value==='guest'&&parts.length===2)return {name:parts[0],email:parts[1]};
              throw new Error('Each employee row needs 3 columns; an additional-voter row needs 2.');
            });
            save.disabled=true;await act('import',{kind:select.value,people});
          }catch(e){message.textContent=e.message;}finally{save.disabled=false;}
        };
        panel.append(form);
      }
      if(data.guests.length){
        panel.append(node('h3','Additional voters'));
        data.guests.forEach(g=>{
          const row=node('p',g.name+' — '+g.email+' — '+(Number(g.ready)?'Ready':'Needs activation'));
          if(!r.armed&&!election.locked){const remove=node('button','Remove','btn btn-ghost btn-sm');remove.type='button';remove.onclick=()=>act('remove-guest',{userId:g.userId});row.append(' ',remove);}
          panel.append(row);
        });
      }
      panel.append(node('p','Voting is recorded against the voter roll. The live feed exposes aggregate totals only. Keep the scheduled cron job running every minute; portal activity also advances due stages.','lede'),message);
    } catch(e) { panel.append(node('p',e.message||'Could not load welfare setup.','note warn')); }
  }
  document.addEventListener('resok:election-admin-open', e => render(e.detail));
})();
