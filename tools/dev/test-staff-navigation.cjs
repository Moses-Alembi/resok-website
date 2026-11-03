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
for (const view of ['home', 'announcements', 'directory', 'helpdesk', 'equipment', 'field', 'field-reports', 'timesheets', 'procurement', 'appraisals', 'payslips', 'location', 'profile', 'leave']) {
  assert(allowed(employee, view), view); checks++;
}
for (const view of ['administration', 'permissions', 'activity', 'people', 'departments', 'manage-announcements', 'payroll']) {
  assert(!allowed(employee, view), view); checks++;
}
for (const view of ['administration', 'permissions', 'activity', 'people', 'departments', 'manage-announcements', 'payroll', 'leave', 'timesheets']) {
  assert(allowed({ super: true, onStaffList: false, caps: [] }, view), view); checks++;
}
for (const view of ['profile', 'field', 'payslips']) {
  assert(!allowed({ super: true, onStaffList: false, caps: [] }, view), view); checks++;
}
assert(!allowed(null, 'home')); checks++;

class Element {
  constructor(tag) { this.tag = tag; this.children = []; this.events = {}; this.ownText = ''; this.value = ''; this.checked = false; }
  append(...kids) { for (const kid of kids.flat()) if (kid !== null && kid !== undefined && kid !== false) this.children.push(kid); }
  appendChild(kid) { this.append(kid); return kid; }
  replaceChildren(...kids) { this.children = []; this.append(...kids); }
  addEventListener(key, fn) { this.events[key] = fn; }
  scrollIntoView() {}
  focus() {}
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
