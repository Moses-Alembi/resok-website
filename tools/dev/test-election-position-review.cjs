'use strict';

// Test the real admin renderer with synthetic API responses; no live election requests.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../../resok-portal/public/election-admin.html'), 'utf8');
const start = source.indexOf('    function setPositionReviewDisabled(disabled) {');
const end = source.indexOf('    /* ---------------- nominations ---------------- */', start);
assert(start >= 0 && end > start);

class Element {
  constructor(tag = 'div') {
    this.tagName = tag;
    this.children = [];
    this.style = {};
    this.listeners = {};
    this.attributes = {};
    this.hidden = false;
    this.disabled = false;
    this.ownText = '';
    this.value = '';
  }
  set textContent(value) { this.children = []; this.ownText = String(value); }
  get textContent() { return this.ownText + this.children.map(node => node.textContent).join(''); }
  appendChild(node) { this.children.push(node); return node; }
  addEventListener(type, listener) { this.listeners[type] = listener; }
  setAttribute(key, value) { this.attributes[key] = String(value); }
}

function descendants(node, tag) {
  return node.children.flatMap(child => [
    ...(child.tagName === tag ? [child] : []), ...descendants(child, tag),
  ]);
}

function fixture(overrides = {}) {
  return { available: true, electionId: 1, phase: 'review', canRecord: true,
    reviewPeriodValid: true, ready: false,
    conflicts: [{ userId: 22, name: 'Synthetic nominee', resolved: false, choice: null,
      positions: [{ positionId: 7, title: 'Chairperson', candidateId: 70, nominationCount: 3 },
        { positionId: 8, title: 'Treasurer', candidateId: 80, nominationCount: 1 }] }],
    blockedPosts: [], blockingReasons: [], excludedCandidateIds: [], ...overrides };
}

function setup() {
  const nodes = new Map();
  const el = id => {
    if (!nodes.has(id)) nodes.set(id, new Element());
    return nodes.get(id);
  };
  const make = (tag, cls, text) => {
    const node = new Element(tag);
    node.className = cls || '';
    if (text != null) node.textContent = text;
    return node;
  };
  const opened = [];
  let context;
  context = vm.createContext({
    current: { election: { id: 1, electorate: 'staff', status: 'nominations_closed', locked: false } },
    positionReviewRequest: 0, positionReviewSaving: null, positionReviewControls: [],
    api: async () => ({ review: fixture() }), el, make, fmt: value => String(value),
    notice: (id, text) => { el(id).textContent = text; },
    openElection: async id => { opened.push(id); await vm.runInContext('renderPositionReview()', context); },
  });
  vm.runInContext(source.slice(start, end), context);
  return { context, el, opened, run: () => vm.runInContext('renderPositionReview()', context) };
}

let count = 0;
function check(condition, description) {
  assert(condition, description);
  ++count;
  console.log('ok: ' + description);
}
const submit = form => form.listeners.submit({ preventDefault() {} });

(async () => {
  const { context, el, opened, run } = setup();
  await run();
  check(!el('positionReviewPanel').hidden && el('positionReviewStatus').textContent.includes('1 nominee still needs'),
    'pending multi-position nominee is highlighted');
  check(el('positionReviewBody').textContent.includes('Chairperson — 3 nominations')
    && el('positionReviewBody').textContent.includes('Treasurer — 1 nomination'), 'each position shows its own nomination count');
  let form = descendants(el('positionReviewBody'), 'form')[0];
  check(form && descendants(form, 'button')[0].textContent === 'Record chosen position', 'eligible review offers a choice form');
  check(el('openVotingBtn').disabled, 'unresolved choices block the open-voting control');
  check(descendants(form, 'textarea')[0].required && descendants(form, 'select')[0].required,
    'position and confirmation evidence are required');

  let postCalls = 0;
  let review = fixture();
  context.api = async (request, options) => {
    assert.equal(request, '/api/admin/elections/1/position-review');
    if (options && options.method === 'POST') ++postCalls;
    return { review };
  };
  await submit(form);
  check(postCalls === 0 && el('positionReviewNotice').textContent.includes('Choose the position'), 'missing choice sends no write');
  descendants(form, 'select')[0].value = '7';
  descendants(form, 'textarea')[0].value = '   ';
  await submit(form);
  check(postCalls === 0 && el('positionReviewNotice').textContent.includes('Record how'), 'blank confirmation note sends no write');

  let resolvePost;
  let posted;
  context.api = async (request, options) => {
    if (options && options.method === 'POST') {
      ++postCalls;
      posted = JSON.parse(options.body);
      return new Promise(resolve => { resolvePost = resolve; });
    }
    return { review };
  };
  descendants(form, 'textarea')[0].value = '  Confirmed by phone with the nominee.  ';
  const saving = submit(form);
  check(context.positionReviewControls.every(control => control.disabled), 'all review controls disable while a choice is being saved');
  await submit(form);
  check(postCalls === 1, 'a second submit while saving cannot record another choice');
  check(posted.userId === 22 && posted.positionId === 7
    && posted.confirmationNote === 'Confirmed by phone with the nominee.', 'POST identifies nominee and chosen position with trimmed evidence');
  review = fixture({ ready: true, conflicts: [{ ...fixture().conflicts[0], resolved: true,
    choice: { positionId: 7, positionTitle: 'Chairperson', confirmedAt: '2026-11-20 12:00:00',
      confirmedBy: 1, confirmationNote: 'Confirmed by phone with the nominee.' } }] });
  resolvePost({ review });
  await saving;
  check(opened.length === 1 && opened[0] === 1, 'saving reloads the election so positions and tally refresh together');
  check(el('positionReviewBody').textContent.includes('Confirmed choice: Chairperson')
    && el('positionReviewBody').textContent.includes('Confirmation: Confirmed by phone'), 'saved position and confirmation evidence remain visible');
  check(el('positionReviewStatus').textContent.includes('Ballot positions confirmed') && !el('openVotingBtn').disabled,
    'resolved review with no blockers can enable voting');
  check(context.positionReviewControls.every(control => !control.disabled), 'controls recover after a successful save');

  context.current.election.locked = true;
  context.current.election.status = 'open';
  await run();
  check(descendants(el('positionReviewBody'), 'form').length === 0
    && el('positionReviewBody').textContent.includes('Confirmed choice: Chairperson'), 'locked election shows saved choice without edit controls');
  check(el('positionReviewStatus').textContent.includes('locked') && el('openVotingBtn').disabled, 'locked election cannot enable voting or choice changes');

  context.current.election.locked = false;
  context.current.election.status = 'nominations';
  review = fixture({ canRecord: true });
  await run();
  check(descendants(el('positionReviewBody'), 'form').length === 0,
    'client does not offer position changes while nominations are still open');
  context.current.election.status = 'nominations_closed';
  review = fixture({ reviewPeriodValid: false, blockedPosts: [{ positionId: 8, title: 'Treasurer', seats: 1, standing: 0 }],
    blockingReasons: ['Resolve all choices before opening voting.'] });
  await run();
  check(el('positionReviewBody').textContent.includes('Correct the details')
    && el('positionReviewBody').textContent.includes('Treasurer: 0 standing for 1 seat.'), 'invalid gap and candidate shortages have practical explanations');
  check(el('openVotingBtn').disabled, 'invalid review gap and candidate shortages keep voting blocked');

  review = fixture({ ready: true, conflicts: [] });
  await run();
  check(el('positionReviewStatus').textContent.includes('Ballot positions confirmed')
    && descendants(el('positionReviewBody'), 'form').length === 0, 'single-position nominees require no manual choice');

  context.api = async () => ({});
  await run();
  check(el('positionReviewStatus').textContent.includes('unavailable') && el('openVotingBtn').disabled,
    'missing review data never claims readiness');
  context.api = async () => ({ review: fixture({ available: false, ready: true }) });
  await run();
  check(el('positionReviewStatus').textContent.includes('unavailable')
    && descendants(el('positionReviewBody'), 'form').length === 0, 'missing migration disables review controls even if an inconsistent ready flag appears');

  let resolveOld;
  context.api = () => new Promise(resolve => { resolveOld = resolve; });
  const oldRequest = run();
  context.current.election = { id: 2, electorate: 'staff', status: 'nominations_closed', locked: false };
  context.api = async () => ({ review: fixture({ electionId: 2, conflicts: [{ ...fixture().conflicts[0], name: 'Current election nominee' }] }) });
  await run();
  resolveOld({ review: fixture({ conflicts: [{ ...fixture().conflicts[0], name: 'Stale election nominee' }] }) });
  await oldRequest;
  check(el('positionReviewBody').textContent.includes('Current election nominee')
    && !el('positionReviewBody').textContent.includes('Stale election nominee'), 'stale review responses cannot overwrite another election');

  context.api = async () => ({ review: fixture({ electionId: 2, conflicts: [{ ...fixture().conflicts[0],
    name: '<img onerror=alert(1)>', positions: [{ positionId: 7, title: '<script>bad</script>', nominationCount: null }] }] }) });
  await run();
  check(el('positionReviewBody').textContent.includes('<img onerror=alert(1)>')
    && el('positionReviewBody').textContent.includes('<script>bad</script>')
    && descendants(el('positionReviewBody'), 'script').length === 0, 'nominee names and position titles use safe literal text');
  check(el('positionReviewBody').textContent.includes('Nomination total unavailable')
    && !el('positionReviewBody').textContent.includes('0 nominations'), 'unknown per-position counts never appear as zero');

  context.current.election.electorate = 'members';
  let boardRead = false;
  context.api = async () => { boardRead = true; throw new Error('Board review must not load'); };
  await run();
  check(el('positionReviewPanel').hidden && !boardRead, 'board elections do not load or display staff position review');
  check(source.includes("c.ballotStatus === 'not_contesting'") && source.includes("n.ballotStatus === 'not_contesting'")
    && source.includes('Not contesting this position'), 'posts and nomination history distinguish a declined position from disqualification');
  console.log('\n' + count + ' election position review UI checks passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
