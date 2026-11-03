/* Staff preview entry-point checks in an in-memory DOM; not visual browser QA. */
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const publicDir = path.join(__dirname, '../../resok-portal/public');
const staffPage = fs.readFileSync(path.join(publicDir, 'staff.html'), 'utf8');
const ictPage = fs.readFileSync(path.join(publicDir, 'ict.html'), 'utf8');
let checks = 0;
function check(message, action) { action(); checks++; }
class Element {
  constructor(tag, className, text) { this.tag = tag; this.className = className || ''; this.children = []; this.events = {}; this.dataset = {}; this.style = {}; this.ownText = text || ''; this.value = ''; this.disabled = false; }
  append(...nodes) { this.children.push(...nodes); }
  appendChild(node) { this.append(node); return node; }
  addEventListener(type, fn) { this.events[type] = fn; }
  get lastChild() { return this.children[this.children.length - 1]; }
  set textContent(value) { this.ownText = value; this.children = []; }
  get textContent() { return this.ownText + this.children.map(child => child.textContent).join(' '); }
  all(tag) { return [this, ...this.children.flatMap(child => child.all(tag))].filter(node => node.tag === tag); }
}
const make = (tag, cls, text) => new Element(tag, cls, text);
const employee = { userId: 15, name: '<img src=x onerror=bad()>', email: 'employee@example.test', jobTitle: 'Programme Officer', active: true, accountReady: true, state: 'active', role: 'member' };
function previewContext(kind, roleInfo, people = [employee]) {
  const nodes = {}, calls = [], notices = [], edits = [];
  const el = id => nodes[id] ||= make('div');
  el('staffInviteBtn').append(make('span', null, 'Send invites'));
  let start = async () => {};
  const context = vm.createContext({ home: roleInfo, me: roleInfo, people, counties: [], depts: [],
    el, make, STAFF_STATE: { active: ['Active', 's-ok'], invited: ['Invited', 's-warning'], not_invited: ['Not invited yet', 's-unknown'] },
    empty: text => make('div', 'empty', text), editPerson: person => edits.push(person.userId), fmtDate: String,
    confirm: () => false, post: async () => {}, api: async () => {}, loadPeople() {},
    say: (...args) => notices.push(args), staffAcctNotice: (...args) => notices.push(args),
    window: { ResokPortal: { viewAsStaffStart: async (...args) => { calls.push(args); return start(...args); } } }
  });
  const page = kind === 'staff' ? staffPage : ictPage;
  const helper = page.slice(page.indexOf('    let staffPreviewStarting') >= 0 ? page.indexOf('    let staffPreviewStarting') : page.indexOf('  let staffPreviewStarting'), page.indexOf(kind === 'staff' ? '    async function loadPeople()' : '  function renderStaffAccounts('));
  const renderer = kind === 'staff'
    ? page.slice(page.indexOf('    function renderPeople()'), page.indexOf('    function editPerson('))
    : page.slice(page.indexOf('  function renderStaffAccounts('), page.indexOf('  async function loadStaffAccounts('));
  vm.runInContext(helper + renderer, context);
  context.list = people;
  vm.runInContext(kind === 'staff' ? 'renderPeople();' : 'renderStaffAccounts(list);', context);
  return { root: el(kind === 'staff' ? 'peopleList' : 'staffAcctList'), calls, notices, edits, setStart: fn => { start = fn; } };
}
function previews(root) { return root.all('button').filter(button => button.dataset.staffPreview); }
const tick = () => new Promise(setImmediate);
(async () => {
  for (const kind of ['staff', 'ict']) {
    const admin = kind === 'staff' ? { super: true, me: { userId: 1 } } : { isSuperAdmin: true };
    const ordinary = previewContext(kind, kind === 'staff' ? { super: false } : { isSuperAdmin: false });
    check(kind + ' ordinary users cannot see preview actions', () => assert.equal(previews(ordinary.root).length, 0));
    const falsyRole = previewContext(kind, kind === 'staff' ? { super: 'true' } : { isSuperAdmin: 'true' });
    check(kind + ' requires a strict server boolean', () => assert.equal(previews(falsyRole.root).length, 0));
    const ui = previewContext(kind, admin);
    const buttons = previews(ui.root);
    check(kind + ' gives super admins a clickable name and explicit action', () => { assert.equal(buttons.length, 2); assert(buttons.some(button => button.textContent === 'View staff portal')); });
    check(kind + ' renders names and titles as text', () => { assert.equal(ui.root.all('img').length, 0); assert(ui.root.textContent.includes(employee.name)); assert(ui.root.textContent.includes(employee.jobTitle)); });
    let complete;
    ui.setStart(() => new Promise(resolve => { complete = resolve; }));
    const first = buttons[0].events.click();
    check(kind + ' disables the clicked name while starting', () => assert(buttons[0].disabled));
    await buttons[1].events.click();
    check(kind + ' avoids overlapping preview starts', () => assert.equal(ui.calls.length, 1));
    complete(); await first;
    check(kind + ' starts preview by target account ID with the correct return page', () => assert.deepEqual(ui.calls[0], [15, kind === 'staff' ? 'staff#people' : 'ict#staff']));
    ui.setStart(async () => { throw Error('Preview refused'); });
    await buttons[1].events.click(); await tick();
    check(kind + ' reports backend refusal and re-enables the action', () => { assert.equal(buttons[1].disabled, false); assert(ui.notices.some(args => args.includes('Preview refused'))); });
    const staffAdmin = previewContext(kind, admin, [{ ...employee, role: 'admin' }]);
    check(kind + ' lets the server decide staff accounts with admin roles', () => assert.equal(previews(staffAdmin.root).length, 2));
    if (kind === 'staff') {
      const self = previewContext(kind, { super: true, me: { userId: 15 } });
      check('Staff records do not offer a redundant self-preview', () => assert.equal(previews(self.root).length, 0));
      const inactive = previewContext(kind, admin, [{ ...employee, active: false }]);
      check('Deactivated staff do not offer preview entry points', () => assert.equal(previews(inactive.root).length, 0));
      ui.root.all('button').find(button => button.textContent === 'Edit').events.click();
      check('Editing a staff record keeps its own action', () => { assert.deepEqual(ui.edits, [15]); assert.equal(ui.calls.length, 2); });
    } else {
      const invited = previewContext(kind, admin, [{ ...employee, state: 'invited' }]);
      check('Invitation and removal actions remain distinct from preview', () => {
        const labels = invited.root.all('button').map(button => button.textContent);
        assert(labels.includes('Resend')); assert(labels.includes('Remove')); assert(labels.includes('View staff portal'));
      });
    }
  }
  console.log(checks + ' staff preview entry-point checks passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
