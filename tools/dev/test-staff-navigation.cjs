/* Navigation and Leave rendering checks using an in-memory DOM; not visual browser QA. */
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
const publicDir = path.join(__dirname, '../../resok-portal/public');
const page = fs.readFileSync(path.join(publicDir, 'staff.html'), 'utf8');
const viewGuard = page.slice(page.indexOf('    function canOpenView(view)'), page.indexOf('    // ---------- announcements'));
let checks = 0;
function allowed(home, view) {
  return vm.runInNewContext(viewGuard + '\ncanOpenView(view)', { home, view, can: cap => !!home && (home.super === true || home.caps.includes(cap)), Map });
}
const employee = { super: false, onStaffList: true, caps: [] };
for (const view of ['home', 'announcements', 'helpdesk', 'equipment']) {
  assert(allowed(employee, view), view); checks++;
}
for (const view of ['administration', 'permissions', 'activity', 'people', 'departments', 'manage-announcements', 'payroll', 'directory', 'leave', 'field', 'field-reports', 'timesheets', 'procurement', 'appraisals', 'payslips', 'location', 'profile']) {
  assert(!allowed(employee, view), view); checks++;
}
for (const view of ['administration', 'permissions', 'activity', 'people', 'departments', 'manage-announcements', 'payroll', 'leave', 'timesheets']) {
  assert(allowed({ super: true, onStaffList: false, caps: [] }, view), view); checks++;
}
for (const view of ['profile', 'field', 'payslips']) {
  assert(!allowed({ super: true, onStaffList: false, caps: [] }, view), view); checks++;
}
assert(!allowed(null, 'home')); checks++;
for (const view of ['home','announcements','helpdesk','equipment']) { assert(allowed({super:false,onStaffList:true,caps:['staff.manage','payroll.manage']},view)); checks++; }
for (const view of ['people','payroll','leave','appraisals']) { assert(!allowed({super:false,onStaffList:true,caps:['staff.manage','payroll.manage']},view)); checks++; }
const linkLocation = {href:'https://www.resok.org/resok-portal/public/staff',origin:'https://www.resok.org'};
function allowedLink(home, href) { return vm.runInNewContext(viewGuard+'\nstaffLinkAvailable(href)',{home,href,location:linkLocation,URL,Map,can:cap=>!!home&&(home.super===true||home.caps.includes(cap))}); }
for (const href of ['#home','#announcements','#equipment','#helpdesk','#announcement-7','#main','staff#home','staff#announcements','elections?for=staff','elections?for=staff#welfare-2026','elections.html?for=staff','election-live','election-live.html','https://example.org/news']) { assert(allowedLink(employee,href),href); checks++; }
for (const href of ['#directory','#leave','#appraisals','#timesheets','#procurement','#payslips','#profile','#location','elections','elections?for=members','election-admin?for=staff','admin-home','ict','staff#leave','staff/#leave','staff#administration','#timesheet-approvals']) { assert(!allowedLink(employee,href),href); checks++; }
for (const href of ['#leave','#appraisals','elections?for=staff','election-live','#administration']) { assert(allowedLink({super:true},href)); checks++; }

const hr = {super:false,admin:true,onStaffList:true,caps:['staff.admin','staff.manage','payroll.manage','announcements.publish']};
for (const view of ['administration','activity','staff-accounts','people','departments','payroll','leave','appraisals','timesheet-approvals']) { assert(allowed(hr,view),view); checks++; }
assert(!allowed(hr,'permissions')); checks++;
for (const href of ['#administration','#staff-accounts','#leave','staff#people','elections?for=staff','election-live']) { assert(allowedLink(hr,href),href); checks++; }
for (const href of ['#permissions','ict','admin-home','election-admin?for=staff']) { assert(!allowedLink(hr,href),href); checks++; }
const caption = page.match(/const accountCaption = (.*);/)[1];
for (const [home, me, accountRole, expected] of [
  [{ super: false }, { jobTitle: 'Finance Officer' }, 'Staff member', 'Finance Officer'],
  [{ super: false }, { jobTitle: null }, 'Staff member', 'Staff member'],
  [{ super: false }, { jobTitle: '   ' }, 'Staff manager', 'Staff manager'],
  [{ super: false, admin: true }, { jobTitle: 'Finance & Admin Manager' }, 'Staff administrator / HR', 'Staff administrator / HR'],
  [{ super: true }, { jobTitle: 'Communications Officer' }, 'Super administrator', 'Super administrator']
]) {
  assert.equal(vm.runInNewContext(caption, { home, me, accountRole, String }), expected); checks++;
  const headerNodes = {};
  const headerTitles = page.slice(page.indexOf("      el('staffAccessRole').textContent ="), page.indexOf("      el('staffRolePill').textContent ="));
  vm.runInNewContext(headerTitles, {home,me,accountCaption:expected,el:id=>headerNodes[id] ||= {},String});
  assert.equal(headerNodes.staffAccessRole.textContent,expected);
  assert.equal(headerNodes.staffAccountJobTitle.hidden,(home.super !== true && home.admin !== true) || !String(me.jobTitle || '').trim()); checks++;
}

class Element {
  constructor(tag) {
    this.tag = tag; this.children = []; this.events = {}; this.ownText = ''; this.value = ''; this.checked = false;
    this.dataset = {}; this.attributes = {}; this.parent = null; this.style = {};
    const classes = new Set();
    this.classList = { add: (...values) => values.forEach(value => classes.add(value)), remove: (...values) => values.forEach(value => classes.delete(value)), contains: value => classes.has(value), toggle: (value, enabled) => { if (enabled) classes.add(value); else classes.delete(value); } };
  }
  append(...kids) { for (const kid of kids.flat()) if (kid !== null && kid !== undefined && kid !== false) { this.children.push(kid); if (kid instanceof Element) kid.parent = this; } }
  appendChild(kid) { this.append(kid); return kid; }
  replaceChildren(...kids) { this.children = []; this.append(...kids); }
  addEventListener(key, fn) { this.events[key] = fn; }
  scrollIntoView() {}
  focus() {}
  dataKey(key) { return key.slice(5).replace(/-([a-z])/g, (_, letter) => letter.toUpperCase()); }
  getAttribute(key) { return key.startsWith('data-') ? this.dataset[this.dataKey(key)] ?? null : key === 'title' ? this.title ?? null : this.attributes[key] ?? null; }
  setAttribute(key, value) { if (key.startsWith('data-')) this.dataset[this.dataKey(key)] = String(value); else if (key === 'title') this.title = String(value); else this.attributes[key] = String(value); }
  removeAttribute(key) { if (key.startsWith('data-')) delete this.dataset[this.dataKey(key)]; else if (key === 'title') delete this.title; else delete this.attributes[key]; }
  hasAttribute(key) { return this.getAttribute(key) !== null; }
  closest(selector) { if (selector === '.side' && this.classList.contains('side')) return this; if (selector === 'a[data-staff-launch-href]' && this.tag === 'a' && this.hasAttribute('data-staff-launch-href')) return this; return this.parent?.closest(selector) || null; }
  querySelector(selector) {
    if (selector === '[data-launch-badge]') return this.all('span').find(node => node.dataset.launchBadge) || null;
    if (selector === '[data-election-paused-badge]') return this.all('span').find(node => node.dataset.electionPausedBadge) || null;
    return null;
  }
  remove() { if (this.parent) this.parent.children = this.parent.children.filter(node => node !== this); this.parent = null; }
  set textContent(value) { this.ownText = value; this.children = []; }
  get textContent() { return this.ownText + this.children.map(kid => typeof kid === 'string' ? kid : kid.textContent).join(' '); }
  all(tag) { return [this, ...this.children.filter(kid => kid instanceof Element).flatMap(kid => kid.all(tag))].filter(kid => kid.tag === tag); }
}
function h(tag, attrs, ...kids) {
  const node = new Element(tag);
  for (const [key, value] of Object.entries(attrs || {})) {
    if (key === 'text') node.textContent = value;
    else if (key.startsWith('on')) node.addEventListener(key.slice(2), value);
    else node[key] = value;
  }
  node.append(...kids); return node;
}
// Disabled entries remain visible and can be restored when a super-admin session loads.
const sidebar = new Element('nav'); sidebar.classList.add('side');
const paused = h('a', { text: 'Leave' }); paused.setAttribute('href', '#leave'); paused.setAttribute('title', 'Original title'); paused.setAttribute('tabindex', '0');
const active = h('a', { text: 'ICT helpdesk' }); active.setAttribute('href', '#helpdesk');
const shortcut = h('a', { text: 'Welfare elections' }); shortcut.setAttribute('href', 'elections?for=staff');
const liveShortcut = h('a', { text: 'Live election results' }); liveShortcut.setAttribute('href', 'election-live');
sidebar.append(paused, active);
const body = new Element('body');
const launchContext = vm.createContext({ home: employee, location: linkLocation, URL, Map,
  document: { body, querySelectorAll: () => [paused, active, shortcut, liveShortcut] }, make: (tag, cls, text) => h(tag, { class: cls, text }) });
vm.runInContext(viewGuard + '\nsyncStaffLaunchLinks();', launchContext);
assert.equal(paused.getAttribute('href'), null); assert.equal(paused.getAttribute('aria-disabled'), 'true'); assert.equal(paused.getAttribute('tabindex'), '-1'); checks++;
assert(paused.textContent.includes('Leave') && paused.textContent.includes('Soon')); assert(body.classList.contains('staff-limited')); checks++;
assert.equal(active.getAttribute('href'), '#helpdesk'); assert.equal(shortcut.getAttribute('href'), 'elections?for=staff'); assert.equal(liveShortcut.getAttribute('href'), 'election-live'); checks++;
vm.runInContext('syncStaffLaunchLinks();', launchContext); assert.equal(paused.all('span').length, 1); checks++;
const intercept = page.match(/document.addEventListener\('click', \(event\) => \{([\s\S]*?)\}, true\);/)[1];
let prevented = false, stopped = false;
vm.runInNewContext('((event) => {' + intercept + '})(event)', { event: { target: paused, preventDefault() { prevented = true; }, stopImmediatePropagation() { stopped = true; } } });
assert(prevented && stopped); checks++;
launchContext.home = { super: true };
vm.runInContext('syncStaffLaunchLinks();', launchContext);
assert.equal(paused.getAttribute('href'), '#leave'); assert.equal(shortcut.getAttribute('href'), 'elections?for=staff'); checks++;
assert.equal(paused.getAttribute('title'), 'Original title'); assert.equal(paused.getAttribute('tabindex'), '0'); assert.equal(paused.getAttribute('aria-disabled'), null); checks++;
assert(!paused.querySelector('[data-launch-badge]') && !paused.classList.contains('staff-unavailable') && !body.classList.contains('staff-limited')); checks++;
function leaveContext(manage, pendingCount = 1) {
  const root = new Element('section'), posts = [];
  const pending = manage ? [{ id: 1, userId: 3, person: 'Employee', type: 'Configured leave', startsOn: '2026-11-10', endsOn: '2026-11-12', status: 'pending', reason: 'Employee note' }] : [];
  const data = { canManage: manage, onStaffList: !manage, today: '2026-11-03', types: [{ id: 1, name: 'Configured leave', active: 1 }], mine: [], requests: [], pending, pendingCount: manage ? pendingCount : 0 };
  const S = { routes: [], loaders: {}, api: async () => structuredClone(data), post: async (route, body) => { posts.push({ route, body }); return { id: 1 }; },
    h, panel: () => root, empty: text => h('p', { text }), fmtDate: String, fmtWhen: String, refreshHome() {}, say() {}, show: view => S.loaders[view]() };
  vm.runInNewContext(fs.readFileSync(path.join(publicDir, 'js/staff-leave.js'), 'utf8'), { window: { StaffPortal: S }, confirm: () => true, prompt: () => 'A recorded reason' });
  return { S, root, posts };
}
const tick = () => new Promise(setImmediate);
(async () => {
  const elections = fs.readFileSync(path.join(publicDir, 'elections.html'), 'utf8');
  const accessFunctions = elections.slice(elections.indexOf('    function setStaffElectionAccount'), elections.indexOf('    const make =', elections.indexOf('    function applyElectionManagementAccess')));
  const themeInit = [...elections.matchAll(/<script>([\s\S]*?)<\/script>/g)][0][1];
  for (const [staffMode, saved, expected] of [[true, 'light', 'light'], [true, 'dark', 'dark'], [true, null, 'dark'], [false, 'light', null]]) {
    const root = new Element('html'), sheets = {};
    vm.runInNewContext(themeInit, {
      URLSearchParams, location: { search: staffMode ? '?for=staff' : '' },
      localStorage: { getItem: () => saved },
      document: { documentElement: root, getElementById: id => sheets[id] ||= { media: 'not all' } }
    });
    assert.equal(root.dataset.theme ?? null, expected); assert.equal(root.classList.contains('staff-elections'), staffMode); checks++;
    if (staffMode) { assert(Object.values(sheets).every(sheet => sheet.media === 'all')); checks++; }
    else { assert.equal(Object.keys(sheets).length, 0); checks++; }
  }
  for (const id of ['menuBtn','side','staffSidebarBackdrop','staffThemeToggle','staffSearch','staffSearchResults','staffSearchWrap','staffFullscreen']) {
    assert(elections.includes('id="' + id + '"'), id + ' required by shared staff shell'); checks++;
  }
  const captionNodes = {};
  const captionContext = vm.createContext({ el: id => captionNodes[id] ||= {} });
  vm.runInContext(accessFunctions, captionContext);
  for (const [jobTitle, superAdmin, expected] of [['Finance Officer', false, 'Finance Officer'], [null, false, 'Staff member'], ['  ', false, 'Staff member'], ['Communications Officer', true, 'Super administrator']]) {
    captionContext.me = { name: 'Example Employee', jobTitle }; captionContext.superAdmin = superAdmin;
    vm.runInContext('setStaffElectionAccount(me, superAdmin);', captionContext);
    assert.equal(captionNodes.staffElectionCaption.textContent, expected); assert.equal(captionNodes.staffElectionName.textContent, 'Example Employee'); assert.equal(captionNodes.staffAvatar.textContent, 'EE'); checks++;
    assert.equal(captionNodes.staffElectionJobTitle.hidden,superAdmin !== true || !String(jobTitle || '').trim());
    if (!captionNodes.staffElectionJobTitle.hidden) assert.equal(captionNodes.staffElectionJobTitle.textContent,jobTitle); checks++;
  }
  const candidateRender = elections.slice(elections.indexOf('    function renderCandidates()'), elections.indexOf('    function renderResults('));
  const nominationRender = elections.slice(elections.indexOf('    function renderNominationList()'), elections.indexOf('    async function respond('));
  const ballotRender = elections.slice(elections.indexOf('    function renderBallot()'), elections.indexOf('    /** What the voter has marked'));
  for (const staffMode of [true, false]) {
    const nodes = {}, title = '<img src=x onerror=bad()>', candidate = { id: 1, name: 'Example Candidate', jobTitle: title, status: 'approved' };
    const position = { id: 1, title: 'Chairperson', seats: 1, maxChoices: 1, candidates: [candidate] };
    const renderContext = vm.createContext({ STAFF: staffMode, el: id => nodes[id] ||= new Element('div'),
      make: (tag, cls, text) => h(tag, { class: cls, text }), document: { createElement: tag => new Element(tag) },
      current: { positions: [position], election: { nominationsFinal: true } },
      nominations: { nominations: [{ ...candidate, position: 'Chairperson' }] },
      ballot: { election: { title: 'Test election' }, voter: { name: 'Test voter' }, positions: [position] }
    });
    vm.runInContext(candidateRender + nominationRender + ballotRender + '\nrenderCandidates(); renderNominationList(); renderBallot();', renderContext);
    for (const id of ['eCandidates', 'eNominations', 'ballotForm']) {
      assert.equal(nodes[id].textContent.includes(title), staffMode, id + ' job-title visibility');
      assert.equal(nodes[id].all('img').length, 0, 'job titles render as text'); checks++;
    }
    assert.equal(nodes.ballotForm.all('input').length, 1); assert.equal(nodes.eNominations.all('button').length, 0); checks++;
  }
  // Opening welfare elections must retain the same staff launch controls and super-admin access.
  const electionLinks = ['staff','staff#announcements','staff#helpdesk','staff#equipment','elections?for=staff','election-live','staff#leave','staff#appraisals','staff#directory','election-admin?for=staff','admin-home'].map(href => {
    const link = h('a', { text: href }); link.setAttribute('href', href); return link;
  });
  const logout = h('a', { id: 'staffLogout', text: 'Logout' }); logout.setAttribute('href', '#'); electionLinks.push(logout);
  electionLinks[6].setAttribute('title', 'Request leave'); electionLinks[6].setAttribute('tabindex', '0');
  const navNodes = { staffNav: { querySelectorAll: () => electionLinks }, whoami: { textContent: 'Staff' } };
  const navContext = vm.createContext({ el: id => navNodes[id] ||= { hidden: true }, make: (tag, cls, text) => h(tag, { class: cls, text }) });
  vm.runInContext(accessFunctions + '\napplyElectionManagementAccess(null);', navContext);
  for (const link of electionLinks.slice(0, 6).concat(logout)) { assert(link.getAttribute('href')); assert.equal(link.getAttribute('aria-disabled'), null); checks++; }
  for (const link of electionLinks.slice(6, -1)) { assert.equal(link.getAttribute('href'), null); assert.equal(link.getAttribute('aria-disabled'), 'true'); checks++; }
  assert(navNodes.electionManagerNav.hidden && navNodes.electionAdminNav.hidden && navNodes.electionManagerActions.hidden); checks++;
  vm.runInContext('applyElectionManagementAccess({isSuperAdmin:false});', navContext);
  assert.equal(electionLinks[6].all('span').length, 1); checks++;
  vm.runInContext('applyElectionManagementAccess({isSuperAdmin:true});', navContext);
  assert.equal(electionLinks[6].getAttribute('href'), 'staff#leave'); assert.equal(electionLinks[6].getAttribute('title'), 'Request leave'); assert.equal(electionLinks[6].getAttribute('tabindex'), '0'); checks++;
  assert.equal(electionLinks[6].getAttribute('aria-disabled'), null); assert(!electionLinks[6].querySelector('[data-election-paused-badge]')); checks++;
  assert(!navNodes.electionManagerNav.hidden && !navNodes.electionAdminNav.hidden && !navNodes.electionManagerActions.hidden); checks++;
  const bootStart = elections.lastIndexOf('    (async () => {');
  const boot = elections.slice(bootStart, elections.indexOf('    })();', bootStart) + '    })();'.length);
  for (const kind of ['super', 'staff-admin', 'employee', 'member']) {
    const nodes = {}; let redirected = '', loaded = false;
    const el = id => nodes[id] ||= { textContent: '', hidden: true, querySelectorAll: () => [] };
    const context = vm.createContext({ STAFF: kind !== 'member', el, location: { hash: '' },
      window: { location: { replace: path => { redirected = path; } } },
      api: async route => {
        if (route.includes('admin/whoami')) {
          if (kind === 'employee') throw Object.assign(new Error('Forbidden'), { status: 403 });
          return { isSuperAdmin: kind === 'super' };
        }
        return route.includes('welfare/me') ? { me: { name: 'Staff' } } : { firstName: 'Member' };
      }, loadList: async () => { loaded = true; }, openElection() {} });
    vm.runInContext(accessFunctions, context); await vm.runInContext(boot, context);
    assert(loaded); assert.equal(redirected, '');
    if (kind !== 'member') assert.equal(nodes.electionManagerActions.hidden, kind !== 'super');
    checks++;
  }
  const ordinary = leaveContext(false); ordinary.S.loaders.leave(); await tick();
  assert(ordinary.root.textContent.includes('Submit leave request')); assert(!ordinary.root.textContent.includes('To approve')); checks++;
  const form = ordinary.root.all('form')[0]; form.all('select')[0].value = '1'; form.all('textarea')[0].value = '<img src=x onerror=bad()>';
  form.events.submit({ preventDefault() {} }); await tick(); await tick();
  assert.equal(ordinary.posts[0].route, 'staff/leave'); assert.equal(ordinary.posts[0].body.reason, '<img src=x onerror=bad()>'); checks++;
  ordinary.S.routes[0]('leave-manage'); await tick(); assert(!ordinary.root.textContent.includes('To approve')); checks++;
  const admin = leaveContext(true); assert(admin.S.routes[0]('leave-manage')); await tick();
  assert(admin.root.textContent.includes('To approve (1)')); assert(admin.root.all('button').some(button => button.textContent === 'Approve')); checks++;
  admin.root.all('button').find(button => button.textContent === 'Leave types').events.click(); assert(admin.root.textContent.includes('Add leave type')); checks++;
  const queue = leaveContext(true, 301); queue.S.routes[0]('leave-manage'); await tick();
  assert(queue.root.textContent.includes('To approve (301)')); assert(queue.root.textContent.includes('oldest 1 waiting requests')); checks++;
  console.log(checks + ' employee navigation and Leave UI checks passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
