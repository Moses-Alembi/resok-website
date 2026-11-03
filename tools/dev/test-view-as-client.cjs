/* Session preview client checks with an in-memory DOM; not visual browser QA. */
'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../resok-portal/public/js/portal-state.js'), 'utf8');
const functions = source.slice(source.indexOf('  const VIEW_AS_FLAG ='), source.indexOf('  if (document.readyState === "loading")', source.indexOf('  async function showViewAsBanner()')));
let checks = 0;
function check(message, run) { run(); checks++; }
class Element {
  constructor(tag) {
    this.tag = tag; this.children = []; this.attributes = {}; this.events = {}; this.ownText = ''; this.disabled = false;
    this.style = { setProperty: (key, value) => { this.style[key] = value; } };
    const classes = new Set(); this.classList = { add: value => classes.add(value), contains: value => classes.has(value) };
  }
  append(...items) { this.children.push(...items.map(item => item instanceof Element ? item : Object.assign(new Element('#text'), { ownText: String(item) }))); }
  appendChild(item) { this.append(item); return item; }
  prepend(item) { this.children.unshift(item); }
  setAttribute(key, value) { this.attributes[key] = value; }
  addEventListener(key, listener) { this.events[key] = listener; }
  click() { return this.disabled ? undefined : this.events.click?.(); }
  getBoundingClientRect() { return { height: 72 }; }
  get textContent() { return this.ownText + this.children.map(child => child.textContent).join(''); }
  set textContent(value) { this.ownText = String(value); this.children = []; }
  all(tag) { return [this, ...this.children.flatMap(child => child.all(tag))].filter(child => !tag || child.tag === tag); }
}
function setup(options = {}) {
  const storage = new Map(options.flag ? [['resok_viewas', '1']] : []), calls = [], cache = [], timers = [];
  const body = new Element('body'), head = new Element('head'), root = new Element('html'), windowEvents = {}, documentEvents = {};
  let reloads = 0;
  let responder = options.respond || (async () => ({ active: false }));
  const context = vm.createContext({
    localStorage: {
      getItem: key => { if (options.storageBlocked) throw Error('Storage blocked'); return storage.get(key); },
      setItem: (key, value) => { if (options.storageBlocked) throw Error('Storage blocked'); storage.set(key, value); },
      removeItem: key => { if (options.storageBlocked) throw Error('Storage blocked'); storage.delete(key); }
    },
    defaultState: () => ({ loggedIn: false, member: { email: '', surname: '' } }),
    saveState: value => { if (options.storageBlocked) throw Error('Storage blocked'); cache.push(value); },
    api: async (route, init) => { calls.push({ route, init }); return responder(route, init); },
    pageName: () => options.page || 'staff',
    window: { location: { href: '', reload: () => { reloads++; } }, addEventListener: (key, fn) => { windowEvents[key] = fn; }, setTimeout: (callback, delay) => { timers.push({ callback, delay }); return timers.length; } },
    document: { head, body, documentElement: root, readyState: 'complete', hidden: false, addEventListener: (key, fn) => { documentEvents[key] = fn; }, createElement: tag => new Element(tag),
      getElementById: id => [...body.all(), ...head.all()].find(element => element.id === id) || null }
  });
  vm.runInContext(functions + '\nglobalThis.previewClient = {viewAsStart,viewAsStaffStart,viewAsEnd,showViewAsBanner,viewAsFlag};', context);
  return { context, client: context.previewClient, calls, cache, storage, body, head, root, timers, windowEvents, documentEvents, reloads: () => reloads,
    setResponder: fn => { responder = fn; } };
}
const activeStaff = { active: true, scope: 'staff', name: '<img src=x onerror=bad()>', email: 'staff@example.test', endsAt: Math.floor(Date.now()/1000)+1800, returnTo: 'staff#people' };
(async () => {
  const staff = setup({ respond: async () => ({ ...activeStaff, goTo: 'staff' }) });
  await staff.client.viewAsStaffStart('24', 'staff#people');
  check('staff preview sends an explicit target and scope', () => assert.deepEqual(JSON.parse(staff.calls[0].init.body), { userId: 24, scope: 'staff', returnTo: 'staff#people' }));
  check('starting resets the profile cache to the viewed employee', () => { assert.equal(staff.cache.length, 1); assert.equal(staff.cache[0].member.email, 'staff@example.test'); assert.equal(staff.cache[0].member.surname, ''); });
  check('staff preview opens the staff portal', () => assert.equal(staff.context.window.location.href, 'staff'));
  check('the shared flag is set', () => assert.equal(staff.storage.get('resok_viewas'), '1'));
  for (const invalid of [0, -1, 'no-account', 1.25]) {
    const bad = setup(); await assert.rejects(bad.client.viewAsStaffStart(invalid), /Choose a staff/);
    check('invalid target does not call the server', () => assert.equal(bad.calls.length, 0));
  }
  const unsafe = setup({ respond: async () => ({ ...activeStaff, returnTo: 'https://outside.test', goTo: 'https://outside.test' }) });
  await unsafe.client.viewAsStaffStart(24, 'https://outside.test');
  check('staff return location is allowlisted', () => assert.equal(JSON.parse(unsafe.calls[0].init.body).returnTo, 'ict#staff'));
  check('a response cannot send the browser outside the staff portal', () => assert.equal(unsafe.context.window.location.href, 'staff'));
  const refused = setup({ respond: async () => { throw Error('Forbidden'); } });
  await assert.rejects(refused.client.viewAsStaffStart(24), /Forbidden/);
  check('a refused preview preserves existing session state', () => { assert.equal(refused.cache.length, 0); assert.equal(refused.storage.size, 0); assert.equal(refused.context.window.location.href, ''); });
  const member = setup({ respond: async () => ({ email: 'member@example.test', goTo: 'academy' }) });
  await member.client.viewAsStart(9);
  check('member preview keeps its profile-ID contract and destination', () => { assert.deepEqual(JSON.parse(member.calls[0].init.body), { profileId: 9 }); assert.equal(member.context.window.location.href, 'academy'); });
  const quiet = setup(); await quiet.client.showViewAsBanner();
  check('ordinary page loads do not erase the member cache', () => assert.equal(quiet.cache.length, 0));
  check('protected pages check server status without trusting a local flag', () => assert.equal(quiet.calls[0].route, '/api/view-as/status'));
  const publicPage = setup({ page: 'login' }); await publicPage.client.showViewAsBanner();
  check('signed-out entry pages do not query preview status unnecessarily', () => assert.equal(publicPage.calls.length, 0));
  const banner = setup({ respond: async () => activeStaff }); await banner.client.showViewAsBanner();
  check('staff banner names the viewed account and explains read-only mode', () => { assert(banner.body.textContent.includes(activeStaff.name)); assert(banner.body.textContent.includes('Read-only preview')); assert(banner.body.textContent.includes('Return to my admin view')); });
  check('preview names render as text', () => assert.equal(banner.body.all('img').length, 0));
  check('the fixed staff shell is offset below the measured banner', () => { assert(banner.body.classList.contains('view-as-active')); assert.equal(banner.root.style['--resok-view-as-height'], '72px'); assert(banner.head.textContent.includes('.admin-sidebar{top:calc(64px')); });
  check('staff previews schedule their exit at the server expiry', () => { assert.equal(banner.timers.length, 1); assert(banner.timers[0].delay > 0 && banner.timers[0].delay <= 1800000); });
  await banner.client.showViewAsBanner();
  check('repeat status checks do not duplicate the banner', () => assert.equal(banner.body.children.length, 1));
  const exit = banner.body.all('button')[0];
  banner.setResponder(async () => { throw Error('Connection lost'); }); await exit.events.click();
  check('failed exit keeps preview active and offers retry', () => { assert.equal(exit.disabled, false); assert(banner.body.textContent.includes('Connection lost')); assert.equal(banner.storage.get('resok_viewas'), '1'); assert.equal(banner.context.window.location.href, ''); });
  banner.setResponder(async () => ({ active: false, returnTo: 'staff#people' })); await exit.events.click();
  check('successful exit returns to the originating staff list', () => assert.equal(banner.context.window.location.href, 'staff#people'));
  check('successful exit clears preview flag and cached target identity', () => { assert.equal(banner.storage.has('resok_viewas'), false); assert.equal(banner.cache.at(-1).member.email, ''); });
  const privateMode = setup({ storageBlocked: true, respond: async () => activeStaff }); await privateMode.client.showViewAsBanner();
  check('server-confirmed banner works with browser storage blocked', () => assert(privateMode.body.textContent.includes('Read-only preview')));
  await privateMode.client.viewAsStaffStart(24, 'staff#people');
  check('blocked storage does not strand a started preview', () => assert.equal(privateMode.context.window.location.href, 'staff'));
  const oldMember = setup({ flag: true, respond: async () => ({ ...activeStaff, scope: 'member', returnTo: 'admin-review' }) }); await oldMember.client.showViewAsBanner();
  check('member banner preserves its existing profile-edit explanation', () => { assert(oldMember.body.textContent.includes('Only their profile can be changed')); assert(oldMember.body.textContent.includes('Exit view as')); });
  check('existing member previews do not gain a staff expiry timer', () => assert.equal(oldMember.timers.length, 0));
  const expiredStaff = setup({ respond: async () => activeStaff }); await expiredStaff.client.showViewAsBanner();
  expiredStaff.setResponder(async () => ({ active: false, targetUserId: null, returnTo: 'admin-review' }));
  await expiredStaff.timers[0].callback();
  check('an expired preview returns to the last verified staff source', () => assert.equal(expiredStaff.context.window.location.href, 'staff#people'));
  check('starting or ending a preview broadcasts a cross-tab revision', () => assert(staff.storage.has('resok_viewas_sync')));
  const otherTab = setup();
  otherTab.windowEvents.storage({ key: 'resok-admin-theme' });
  check('unrelated browser storage changes do not reload the portal', () => assert.equal(otherTab.reloads(), 0));
  otherTab.windowEvents.storage({ key: 'resok_viewas' }); otherTab.windowEvents.storage({ key: 'resok_viewas_sync' });
  check('cross-tab preview changes trigger one page reload', () => assert.equal(otherTab.reloads(), 1));
  const switched = setup({ respond: async () => activeStaff }); await switched.client.showViewAsBanner();
  switched.setResponder(async () => ({ active: false })); await switched.client.showViewAsBanner();
  check('a status change reconciles an already-open preview', () => assert.equal(switched.reloads(), 1));
  const changedTarget = setup({ respond: async () => ({ ...activeStaff, targetUserId: 24 }) }); await changedTarget.client.showViewAsBanner();
  changedTarget.setResponder(async () => ({ ...activeStaff, targetUserId: 25 })); await changedTarget.client.showViewAsBanner();
  check('changing the target in another tab cannot leave the old identity displayed', () => assert.equal(changedTarget.reloads(), 1));
  check('tab focus and visibility both revalidate server-side preview state', () => { assert.equal(typeof changedTarget.windowEvents.focus, 'function'); assert.equal(typeof changedTarget.documentEvents.visibilitychange, 'function'); });
  console.log(checks + ' preview session and banner checks passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
