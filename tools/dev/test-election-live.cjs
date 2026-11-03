/* Full live-results UI checks in an in-memory DOM; this is not visual browser QA. */
'use strict';
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const publicDir = path.join(__dirname, '../../resok-portal/public');

class Element {
  constructor(tag, document) {
    this.tagName = tag.toUpperCase(); this.ownerDocument = document; this.children = [];
    this.parentElement = null; this.attributes = {}; this.dataset = {}; this.style = {};
    this.events = new Map(); this.ownText = ''; this.value = '';
    this.disabled = false;
    const classes = new Set();
    this.classList = {
      add: (...values) => values.forEach(value => classes.add(value)),
      remove: (...values) => values.forEach(value => classes.delete(value)),
      contains: value => classes.has(value),
      toggle: (value, enabled) => { const wanted = enabled === undefined ? !classes.has(value) : enabled; wanted ? classes.add(value) : classes.delete(value); return wanted; },
      toString: () => [...classes].join(' ')
    };
  }
  get className() { return this.classList.toString(); }
  set className(value) { this.classList.toString().split(/\s+/).forEach(token => this.classList.remove(token)); String(value).split(/\s+/).filter(Boolean).forEach(token => this.classList.add(token)); }
  get id() { return this.attributes.id || ''; }
  set id(value) { this.attributes.id = String(value); }
  get href() { return this.attributes.href || ''; }
  set href(value) { this.attributes.href = String(value); }
  get title() { return this.attributes.title || ''; }
  set title(value) { this.attributes.title = String(value); }
  get hidden() { return Object.hasOwn(this.attributes, 'hidden'); }
  set hidden(value) { if (value) this.attributes.hidden = ''; else delete this.attributes.hidden; }
  get open() { return Object.hasOwn(this.attributes, 'open'); }
  set open(value) { if (value) this.attributes.open = ''; else delete this.attributes.open; }
  get textContent() { return this.ownText + this.children.map(child => child.textContent).join(''); }
  set textContent(value) { this.ownText = String(value ?? ''); this.children.forEach(child => { child.parentElement = null; }); this.children = []; }
  dataKey(key) { return key.slice(5).replace(/-([a-z])/g, (_, letter) => letter.toUpperCase()); }
  setAttribute(key, value) {
    if (key === 'class') this.className = value;
    else if (key.startsWith('data-')) this.dataset[this.dataKey(key)] = String(value);
    else this.attributes[key] = String(value);
  }
  getAttribute(key) { return key === 'class' ? this.className : key.startsWith('data-') ? this.dataset[this.dataKey(key)] ?? null : this.attributes[key] ?? null; }
  hasAttribute(key) { return this.getAttribute(key) !== null; }
  removeAttribute(key) { if (key === 'class') this.className = ''; else if (key.startsWith('data-')) delete this.dataset[this.dataKey(key)]; else delete this.attributes[key]; }
  append(...items) {
    for (const item of items.flat()) {
      if (item === null || item === undefined) continue;
      const child = item instanceof Element ? item : this.ownerDocument.createTextNode(String(item));
      if (child.tagName === '#FRAGMENT') { this.append(...child.children); child.children = []; continue; }
      if (child.parentElement) child.remove();
      this.children.push(child); child.parentElement = this;
    }
  }
  appendChild(child) { this.append(child); return child; }
  prepend(...items) { const previous = [...this.children]; this.children = []; this.append(...items, ...previous); }
  replaceChildren(...items) { this.textContent = ''; this.append(...items); }
  remove() { if (this.parentElement) this.parentElement.children = this.parentElement.children.filter(child => child !== this); this.parentElement = null; }
  contains(node) { return node === this || this.children.some(child => child.contains(node)); }
  focus() { this.ownerDocument.activeElement = this; }
  addEventListener(type, listener) { if (!this.events.has(type)) this.events.set(type, []); this.events.get(type).push(listener); }
  dispatch(type, event = {}) { for (const listener of this.events.get(type) || []) listener({ target: this, preventDefault() {}, ...event }); }
  matches(selector) {
    const simple = selector.trim();
    const tag = simple.match(/^[a-z][a-z0-9-]*/i)?.[0];
    if (tag && this.tagName !== tag.toUpperCase()) return false;
    const id = simple.match(/#([\w-]+)/)?.[1]; if (id && this.id !== id) return false;
    for (const match of simple.matchAll(/\.([\w-]+)/g)) if (!this.classList.contains(match[1])) return false;
    for (const match of simple.matchAll(/\[([\w-]+)(?:=["']?([^\]"']+)["']?)?\]/g)) {
      if (!this.hasAttribute(match[1])) return false;
      if (match[2] !== undefined && this.getAttribute(match[1]) !== match[2]) return false;
    }
    return true;
  }
  descendants() { return this.children.flatMap(child => [child, ...child.descendants()]); }
  querySelectorAll(selector) { return this.descendants().filter(node => selector.split(',').some(part => node.matches(part))); }
  querySelector(selector) { return this.querySelectorAll(selector)[0] || null; }
  closest(selector) { return this.matches(selector) ? this : this.parentElement?.closest(selector) || null; }
}

function documentFromHtml(source) {
  const events = new Map();
  const document = {
    hidden: false, activeElement: null,
    createElement: tag => new Element(tag, document),
    createTextNode: value => { const node = new Element('#text', document); node.textContent = value; return node; },
    createDocumentFragment: () => new Element('#fragment', document),
    addEventListener: (type, listener) => { if (!events.has(type)) events.set(type, []); events.get(type).push(listener); },
    dispatch: (type, event = {}) => { for (const listener of events.get(type) || []) listener(event); }
  };
  const root = document.createElement('html'); document.documentElement = root;
  const stack = [root];
  const markup = source.replace(/<script\b[^>]*>[\s\S]*?<\/script>/gi, '').replace(/<style\b[^>]*>[\s\S]*?<\/style>/gi, '');
  for (const match of markup.matchAll(/<!--[\s\S]*?-->|<![^>]*>|<\/?[a-z][^>]*>|[^<]+/gi)) {
    const token = match[0];
    if (token.startsWith('<!')) continue;
    if (token.startsWith('</')) { if (stack.length > 1) stack.pop(); continue; }
    if (token.startsWith('<')) {
      const tag = token.match(/^<([\w-]+)/)[1].toLowerCase();
      if (tag === 'html') continue;
      const node = document.createElement(tag);
      const attrs = token.slice(tag.length + 1).replace(/\/?\s*>$/, '');
      for (const attribute of attrs.matchAll(/([\w:-]+)(?:\s*=\s*(?:"([^"]*)"|'([^']*)'|([^\s"'=<>`]+)))?/g)) node.setAttribute(attribute[1], attribute[2] ?? attribute[3] ?? attribute[4] ?? '');
      stack[stack.length - 1].append(node);
      if (tag === 'body') document.body = node;
      if (tag === 'head') document.head = node;
      if (!['area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr'].includes(tag) && !token.endsWith('/>')) stack.push(node);
    } else if (token.trim()) stack[stack.length - 1].append(document.createTextNode(token));
  }
  document.getElementById = id => root.descendants().find(node => node.id === id) || null;
  document.querySelectorAll = selector => root.querySelectorAll(selector);
  document.querySelector = selector => root.querySelector(selector);
  document.activeElement = document.body;
  return document;
}

const tick = () => new Promise(setImmediate);
async function flush() { for (let index = 0; index < 4; index++) await tick(); }

function electionEntry(overrides = {}) {
  return {
    election: { id: 1, slug: 'welfare-2026', title: 'Welfare election', status: 'open' },
    canParticipate: false, final: false, attention: null,
    roll: { voted: 3, onRoll: 34, turnout: 8.8 },
    positions: [{ id: 11, title: 'Chairperson', ballotsCast: 3, abstentions: 0,
      candidates: [{ id: 7, name: 'Example Employee', jobTitle: 'Programme Officer', status: 'approved', votes: 2 }] }],
    events: [{ message: 'Voting opened' }], ...overrides
  };
}

function rejected(status, message = 'Request failed') { return Object.assign(new Error(message), { status }); }
function deferred() { let resolve, reject; const promise = new Promise((yes, no) => { resolve = yes; reject = no; }); return { promise, resolve, reject }; }

function startLive({ staffHome = rejected(403), ownAccount = { me: { name: 'Observer', email: 'observer@live.test' } }, feed = [{ elections: [] }] } = {}) {
  const html = fs.readFileSync(path.join(publicDir, 'election-live.html'), 'utf8');
  const source = fs.readFileSync(path.join(publicDir, 'js/election-live.js'), 'utf8');
  const document = documentFromHtml(html), calls = [], timers = new Map(), windowEvents = new Map();
  let nextTimer = 0, feedIndex = 0, logouts = 0;
  const location = { href: 'https://www.resok.org/resok-portal/public/election-live', hostname: 'www.resok.org' };
  const deliver = value => value instanceof Error ? Promise.reject(value) : typeof value === 'function' ? value() : Promise.resolve(structuredClone(value));
  const api = route => {
    const name = route.replace(/^\/?api\//, '').replace(/^\/+/, ''); calls.push(name);
    if (name === 'welfare/live') return deliver(feed[Math.min(feedIndex++, feed.length - 1)]);
    if (name === 'staff/home') return deliver(staffHome);
    if (name === 'welfare/me') return deliver(ownAccount);
    if (name === 'members/me') return deliver({ firstName: 'Observer', surname: 'Member' });
    throw Error('Unexpected API route: ' + route);
  };
  const setInterval = (fn, delay) => { const id = ++nextTimer; timers.set(id, { fn, delay }); return id; };
  const clearInterval = id => timers.delete(id);
  const window = { location, ResokWelfareLive: true, ResokPortal: { api, logout: () => { logouts++; } },
    addEventListener(type, listener) { if (!windowEvents.has(type)) windowEvents.set(type, []); windowEvents.get(type).push(listener); },
    setInterval, clearInterval };
  vm.runInNewContext(source, { window, document, location, setInterval, clearInterval, console, URLSearchParams });
  return { document, calls, timers, window, get logouts() { return logouts; },
    node: id => { const node = document.getElementById(id); assert(node, 'Live page must provide #' + id); return node; },
    poll: () => { for (const { fn } of [...timers.values()]) fn(); },
    windowEvent: (type, event = {}) => { for (const listener of windowEvents.get(type) || []) listener(event); }
  };
}

let checks = 0;
function check(message, assertion) {
  try { assertion(); checks++; }
  catch (error) { error.message = message + ': ' + error.message; throw error; }
}
function liveCalls(screen) { return screen.calls.filter(route => route === 'welfare/live').length; }

(async () => {
  const html = fs.readFileSync(path.join(publicDir, 'election-live.html'), 'utf8');
  const ids = [...html.matchAll(/\bid="([^"]+)"/g)].map(match => match[1]);
  check('page identifiers are unique', () => assert.equal(new Set(ids).size, ids.length));
  for (const id of ['menuBtn','side','staffSidebarBackdrop','staffThemeToggle','staffSearch','staffSearchResults','staffSearchWrap','staffFullscreen']) {
    check('shared shell has #' + id, () => assert(ids.includes(id)));
  }
  const headScript = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)][0][1];
  for (const [saved, expected] of [['light','light'], ['dark','dark'], [null,'dark']]) {
    const root = new Element('html'), window = {};
    vm.runInNewContext(headScript, { window, document: { documentElement: root }, localStorage: { getItem: () => saved } });
    check('theme preference initializes to ' + expected, () => assert.equal(root.dataset.theme, expected));
    check('compact renderer is suppressed on the full screen', () => assert.equal(window.ResokWelfareLive, true));
  }
  const noStorageRoot = new Element('html');
  vm.runInNewContext(headScript, { window: {}, document: { documentElement: noStorageRoot }, localStorage: { getItem: () => { throw new Error('Storage unavailable'); } } });
  check('unavailable browser storage still initializes the dark theme', () => assert.equal(noStorageRoot.dataset.theme, 'dark'));

  const staff = startLive({ staffHome: { onStaffList: true, super: false, me: { name: 'Example Employee', jobTitle: 'Programme Officer' } } });
  await flush();
  check('staff identity selects staff navigation', () => { assert(!staff.node('liveStaffNav').hidden); assert(staff.node('liveObserverNav').hidden); });
  check('staff account title appears below the name', () => { assert.equal(staff.node('liveAccountName').textContent, 'Example Employee'); assert.equal(staff.node('liveAccountCaption').textContent, 'Programme Officer'); });
  check('ordinary staff do not get a duplicate title line', () => assert(staff.node('liveAccountJobTitle').hidden));
  check('staff home links point to the staff workspace', () => { for (const id of ['liveBrandHome','liveHomeBreadcrumb','liveFooterHome']) assert.equal(staff.node(id).getAttribute('href'), 'staff'); });
  check('ordinary staff cannot use administrative controls', () => { assert(staff.node('liveManageNav').hidden); assert(staff.node('liveManageAction').hidden); });
  check('ordinary staff paused tools stay visible and disabled', () => {
    const paused = staff.node('liveStaffNav').querySelectorAll('[data-staff-paused-href]'); assert(paused.length > 0);
    for (const link of paused) { assert.equal(link.getAttribute('href'), null); assert.equal(link.getAttribute('aria-disabled'), 'true'); assert.equal(link.getAttribute('tabindex'), '-1'); assert(!link.querySelector('.soon').hidden); }
  });
  check('all six staff launch sections remain available', () => {
    const destinations = staff.node('side').querySelectorAll('a[href]').filter(link => !link.closest('[hidden]')).map(link => link.getAttribute('href'));
    for (const href of ['staff','staff#announcements','staff#helpdesk','staff#equipment','elections?for=staff','election-live']) assert(destinations.includes(href), href);
    assert(!staff.node('welfareVoteNav').hidden);
  });
  check('empty feed is a useful scheduled-election state', () => { assert(staff.node('welfareLiveRoot').textContent.includes('No welfare election has been scheduled yet.')); assert.equal(staff.node('liveConnection').dataset.state, 'connected'); });

  const superAdmin = startLive({ staffHome: { onStaffList: false, super: true, me: { name: 'Example Administrator', jobTitle: 'Unchanged job title' } } });
  await flush();
  check('verified super admins without staff records retain management', () => { assert(!superAdmin.node('liveStaffNav').hidden); assert(!superAdmin.node('liveManageNav').hidden); assert(!superAdmin.node('liveManageAction').hidden); });
  check('super admin keeps their role caption', () => assert.equal(superAdmin.node('liveAccountCaption').textContent, 'Super administrator'));
  check('a super admin job title also appears beneath their name', () => { assert(!superAdmin.node('liveAccountJobTitle').hidden); assert.equal(superAdmin.node('liveAccountJobTitle').textContent,'Unchanged job title'); });
  check('super admin can use the existing paused staff tools', () => {
    for (const link of superAdmin.node('liveStaffNav').querySelectorAll('[data-staff-paused-href]')) { assert.equal(link.getAttribute('href'), link.dataset.staffPausedHref); assert.equal(link.getAttribute('aria-disabled'), null); assert.equal(link.getAttribute('tabindex'), null); assert(link.querySelector('.soon').hidden); }
  });

  for (const metadata of [rejected(403), rejected(503), { role: 'admin', onStaffList: false, super: false }, { super: 'true', onStaffList: 'true' }, null]) {
    const observer = startLive({ staffHome: metadata, feed: [{ elections: [electionEntry()] }] }); await flush();
    check('unverified metadata keeps observer navigation', () => { assert(observer.node('liveStaffNav').hidden); assert(!observer.node('liveObserverNav').hidden); assert(observer.node('liveManageNav').hidden); assert.equal(observer.node('liveHomeBreadcrumb').getAttribute('href'), 'dashboard'); });
    check('metadata errors do not block live totals', () => { assert(observer.node('welfareLiveRoot').textContent.includes('Example Employee')); assert.equal(observer.node('liveConnection').dataset.state, 'connected'); assert.equal(liveCalls(observer), 1); });
  }
  const unavailableMetadata = startLive({ staffHome: rejected(503), ownAccount: rejected(503), feed: [{ elections: [electionEntry()] }] }); await flush();
  check('failure of both metadata sources preserves the live feed', () => { assert.equal(unavailableMetadata.node('liveAccountCaption').textContent, 'Portal account'); assert(unavailableMetadata.node('welfareLiveRoot').textContent.includes('Chairperson')); });
  const pendingMetadata = deferred();
  const independent = startLive({ staffHome: () => pendingMetadata.promise, feed: [{ elections: [electionEntry()] }] }); await flush();
  check('live polling starts without waiting for account metadata', () => { assert.equal(liveCalls(independent), 1); assert.equal(independent.node('liveConnection').dataset.state, 'connected'); });
  pendingMetadata.resolve({ super: false, onStaffList: false }); await flush();

  const observer = startLive({ feed: [{ elections: [electionEntry()] }] }); await flush();
  check('observers see aggregate totals without voting actions', () => { assert(observer.node('welfareVoteNav').hidden); assert.equal(observer.node('welfareLiveRoot').querySelectorAll('a').length, 0); assert(observer.node('welfareLiveRoot').textContent.includes('3 / 34')); assert(observer.node('welfareLiveRoot').textContent.includes('8.8%')); });
  check('candidate titles and accessible vote bars render', () => { const bar = observer.node('welfareLiveRoot').querySelector('progress'); assert.equal(bar.value, 2); assert.equal(bar.max, 3); assert.equal(bar.getAttribute('aria-label'), 'Example Employee: 2 votes'); assert(observer.node('welfareLiveRoot').textContent.includes('Programme Officer')); });
  const eligible = startLive({ feed: [{ elections: [electionEntry({ canParticipate: true })] }] }); await flush();
  check('electoral eligibility adds voting links without granting staff privileges', () => { assert(!eligible.node('welfareVoteNav').hidden); assert(eligible.node('liveStaffNav').hidden); assert(eligible.node('liveManageNav').hidden); assert.equal(eligible.node('welfareLiveRoot').querySelector('a').getAttribute('href'), 'elections?for=staff#welfare-2026'); });
  const stringEligibility = startLive({ feed: [{ elections: [electionEntry({ canParticipate: 'true' })] }] }); await flush();
  check('voting links require a strict eligibility boolean', () => { assert(stringEligibility.node('welfareVoteNav').hidden); assert.equal(stringEligibility.node('welfareLiveRoot').querySelectorAll('a').length, 0); });

  const injected = '<img src=x onerror=bad()>', unsafe = electionEntry();
  unsafe.election.title = injected; unsafe.positions[0].candidates[0].name = injected;
  unsafe.positions[0].candidates[0].jobTitle = injected; unsafe.events[0].message = '<script>bad()</script>';
  const safe = startLive({ feed: [{ elections: [unsafe] }] }); await flush();
  check('untrusted names, titles and milestones render as text', () => { const root = safe.node('welfareLiveRoot'); assert(root.textContent.includes(injected)); assert(root.textContent.includes('<script>bad()</script>')); assert.equal(root.querySelectorAll('img,script').length, 0); });
  const final = startLive({ feed: [{ elections: [electionEntry({ final: true })] }] }); await flush();
  check('declared results do not add current job titles to historical records', () => { assert(final.node('welfareLiveRoot').textContent.includes('Final results have been declared.')); assert(!final.node('welfareLiveRoot').textContent.includes('Programme Officer')); });
  const withdrawn = electionEntry(); withdrawn.positions[0].candidates.push({ name: 'Withdrawn Candidate', status: 'withdrawn', votes: 0 });
  const confirmed = startLive({ feed: [{ elections: [withdrawn] }] }); await flush();
  check('withdrawn candidates are excluded from the live candidate bars', () => { assert(!confirmed.node('welfareLiveRoot').textContent.includes('Withdrawn Candidate')); assert.equal(confirmed.node('welfareLiveRoot').querySelectorAll('progress').length, 1); });

  const changed = electionEntry(); changed.roll.voted = 4; changed.positions[0].candidates[0].votes = 3;
  const updated = startLive({ feed: [{ elections: [electionEntry({ canParticipate: true })] }, { elections: [{ ...changed, canParticipate: true }] }] }); await flush();
  const details = updated.node('welfareLiveRoot').querySelector('details'); details.open = true;
  const originalSummary = details.querySelector('summary'); originalSummary.focus();
  updated.poll(); await flush();
  check('updated counts preserve expanded election updates', () => assert(updated.node('welfareLiveRoot').querySelector('details').open));
  check('updated counts restore keyboard focus to the equivalent control', () => { assert.notEqual(updated.document.activeElement, originalSummary); assert.equal(updated.document.activeElement.dataset.liveFocusKey, originalSummary.dataset.liveFocusKey); });
  const same = startLive({ feed: [{ elections: [electionEntry()] }] }); await flush();
  const samePanel = same.node('welfareLiveRoot').querySelector('article'); same.poll(); await flush();
  check('unchanged totals do not rebuild the results tree', () => assert.equal(same.node('welfareLiveRoot').querySelector('article'), samePanel));

  const stale = startLive({ feed: [{ elections: [electionEntry()] }, rejected(503), { elections: [] }] }); await flush();
  const originalText = stale.node('welfareLiveRoot').textContent; stale.poll(); await flush();
  check('a network failure keeps the last successful totals visible', () => { assert.equal(stale.node('welfareLiveRoot').textContent, originalText); assert.equal(stale.node('liveConnection').dataset.state, 'stale'); assert(!stale.node('liveFeedNotice').hidden); });
  stale.poll(); await flush();
  check('successful reconnection removes the stale banner', () => { assert.equal(stale.node('liveConnection').dataset.state, 'connected'); assert(stale.node('liveFeedNotice').hidden); assert(stale.node('welfareLiveRoot').textContent.includes('No welfare election')); });
  const offline = startLive({ feed: [rejected(503)] }); await flush();
  check('an initial feed error has a useful error state', () => { assert.equal(offline.node('liveConnection').dataset.state, 'offline'); assert(offline.node('welfareLiveRoot').textContent.includes('Could not load live results')); assert(!offline.node('liveRefresh').disabled); });
  const invalid = startLive({ feed: [null] }); await flush();
  check('an invalid response is handled without a null-property exception', () => { assert.equal(invalid.node('liveConnection').dataset.state, 'offline'); assert(invalid.node('welfareLiveRoot').textContent.includes('Could not load live results')); });

  const request = deferred(), overlap = startLive({ feed: [() => request.promise] }); await flush();
  overlap.poll(); overlap.node('liveRefresh').dispatch('click'); overlap.poll(); await flush();
  check('in-flight requests cannot overlap through timer or manual refresh', () => { assert.equal(liveCalls(overlap), 1); assert(overlap.node('liveRefresh').disabled); });
  request.resolve({ elections: [] }); await flush();
  check('completed requests restore refresh and clear busy status', () => { assert(!overlap.node('liveRefresh').disabled); assert.equal(overlap.node('welfareLiveRoot').getAttribute('aria-busy'), 'false'); });
  check('automatic polling uses five-second intervals', () => assert([...overlap.timers.values()].every(timer => timer.delay === 5000)));
  overlap.document.hidden = true; overlap.poll(); await flush();
  check('hidden pages pause automatic requests', () => assert.equal(liveCalls(overlap), 1));
  overlap.document.hidden = false; overlap.document.dispatch('visibilitychange'); await flush();
  check('returning to the page refreshes its totals', () => assert.equal(liveCalls(overlap), 2));
  overlap.windowEvent('pagehide');
  check('page exit clears its polling timer', () => assert.equal(overlap.timers.size, 0));
  overlap.windowEvent('pageshow', { persisted: true }); await flush();
  check('history restoration restarts a single timer and refreshes', () => { assert.equal(overlap.timers.size, 1); assert.equal(liveCalls(overlap), 3); });
  overlap.node('liveLogout').dispatch('click');
  check('sign out uses the shared portal logout', () => assert.equal(overlap.logouts, 1));
  const expired = startLive({ feed: [rejected(401)] }); await flush();
  check('expired sessions return to login and stop polling', () => { assert.equal(expired.window.location.href, 'login?next=election-live'); assert.equal(expired.timers.size, 0); });

  console.log(checks + ' live election UI checks passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
