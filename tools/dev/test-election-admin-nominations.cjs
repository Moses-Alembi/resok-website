'use strict';

// Exercise the production nomination renderer without fetching a live election.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const source = fs.readFileSync(path.join(__dirname, '../../resok-portal/public/election-admin.html'), 'utf8');
const nominationsStart = source.indexOf('    async function renderNominations() {');
const nominationsEnd = source.indexOf('    /* ---------------- the count ---------------- */', nominationsStart);
const countStart = source.indexOf('    function renderCount() {', nominationsEnd);
const countEnd = source.indexOf("    el('loadTally').addEventListener", countStart);
assert(nominationsStart >= 0 && nominationsEnd > nominationsStart && countEnd > countStart);

class Node {
  constructor(tag = 'div') {
    this.tagName = tag;
    this.children = [];
    this.hidden = false;
    this.disabled = false;
    this.style = {};
    this.attributes = {};
    this.listeners = {};
    this.ownText = '';
  }
  set textContent(value) { this.children = []; this.ownText = String(value); }
  get textContent() { return this.ownText + this.children.map(child => child.textContent).join(''); }
  appendChild(child) { this.children.push(child); return child; }
  setAttribute(name, value) { this.attributes[name] = String(value); }
  addEventListener(name, listener) { this.listeners[name] = listener; }
}

function setup() {
  const nodes = new Map();
  const el = id => {
    if (!nodes.has(id)) nodes.set(id, new Node());
    return nodes.get(id);
  };
  const make = (tag, cls, text) => {
    const node = new Node(tag);
    if (cls) node.className = cls;
    if (text != null) node.textContent = text;
    return node;
  };
  const context = vm.createContext({
    current: { election: { id: 1, electorate: 'staff', status: 'nominations', nominationsFinal: true } },
    nominationRequest: 0,
    api: async () => ({ nominations: [] }), el, make,
    notice: (id, text) => { el(id).textContent = text; },
    recordAcceptance() {}, approveCandidate() {},
  });
  vm.runInContext(source.slice(nominationsStart, nominationsEnd) + source.slice(countStart, countEnd), context);
  return { context, el, run: () => vm.runInContext('renderNominations()', context) };
}

function nomination(id, count) {
  return { id, positionId: 1, position: 'Chairperson', name: 'Candidate ' + id,
    nominatedBy: 'Original staff member', status: 'approved', nominationCount: count };
}

let checks = 0;
function check(condition, message) {
  assert(condition, message);
  ++checks;
  process.stdout.write('ok: ' + message + '\n');
}

(async () => {
  const { context, el, run } = setup();
  let data = [nomination(1, 0), nomination(2, 1), nomination(3, 2)];
  context.api = async request => {
    assert.equal(request, '/api/admin/elections/' + context.current.election.id + '/nominations');
    return { nominations: data };
  };
  await run();
  check(el('nominationsTitle').textContent === 'Nomination tally', 'staff panel has a separate nomination title');
  check(el('nominationsBody').textContent.includes('0 nominations')
    && el('nominationsBody').textContent.includes('1 nomination')
    && el('nominationsBody').textContent.includes('2 nominations'), 'zero, singular and plural nomination counts render');
  check(el('nominationSummary').textContent === '3 candidates · 3 nominations recorded', 'summary totals recorded nominations');
  check(el('nominationsBody').textContent.includes('original nominator: Original staff member'), 'original nominator is explicitly identified');
  check(el('refreshNominations').hidden === false && !el('refreshNominations').disabled,
    'staff have an enabled manual nomination refresh control');
  check(el('nominationsBody').attributes['aria-busy'] === 'false', 'completed tally clears the loading state');

  data = [nomination(1, null), nomination(2, undefined)];
  await run();
  check(el('nominationsBody').textContent.includes('Total unavailable')
    && !el('nominationsBody').textContent.includes('0 nominations'), 'null and missing totals never display a false zero');
  check(!el('nominationTotalsNotice').hidden && el('nominationSummary').textContent.includes('Totals unavailable'),
    'unavailable totals include a visible explanation');

  data = [nomination(1, 2)];
  await run();
  data = [nomination(1, 4)];
  await el('refreshNominations').listeners.click();
  check(el('nominationsBody').textContent.includes('4 nominations')
    && !el('nominationsBody').textContent.includes('2 nominations'), 'manual refresh displays newly recorded nominations');

  for (const status of ['draft', 'open', 'closed', 'published']) {
    context.current.election.status = status;
    await run();
    check(!el('nominationsPanel').hidden, 'staff nomination tally remains visible during ' + status);
  }

  context.current.election = { id: 2, electorate: 'members', status: 'nominations', nominationsFinal: false };
  data = [{ ...nomination(7, undefined), accepted: false, status: 'nominated', blocker: 'Waiting for acceptance' }];
  await run();
  check(el('nominationsTitle').textContent === 'Nominations' && el('refreshNominations').hidden
    && el('nominationSummary').hidden, 'board panel keeps its nomination label and hides staff tally controls');
  check(el('nominationsBody').textContent.includes('nominated by Original staff member')
    && !el('nominationsBody').textContent.includes('Total unavailable'), 'board nominations keep their previous attribution without staff counts');
  const boardApprove = el('nominationsBody').children[0].children.find(node => node.tagName === 'button');
  check(boardApprove && boardApprove.textContent === 'Approve' && boardApprove.disabled,
    'board approval still waits for nominee acceptance');
  context.current.election.status = 'open';
  await run();
  check(el('nominationsPanel').hidden, 'board nomination panel keeps its existing stage restriction');

  context.current.election = { id: 3, electorate: 'staff', status: 'nominations', nominationsFinal: true };
  let resolveOld;
  context.api = () => new Promise(resolve => { resolveOld = resolve; });
  const oldRequest = run();
  context.current.election = { id: 4, electorate: 'staff', status: 'nominations', nominationsFinal: true };
  context.api = async () => ({ nominations: [{ ...nomination(4, 5), name: 'Current election candidate' }] });
  await run();
  resolveOld({ nominations: [{ ...nomination(3, 9), name: 'Stale election candidate' }] });
  await oldRequest;
  check(el('nominationsBody').textContent.includes('Current election candidate')
    && !el('nominationsBody').textContent.includes('Stale election candidate'), 'a stale response cannot replace another election tally');

  let rejectOld;
  context.api = () => new Promise((resolve, reject) => { rejectOld = reject; });
  const failingOldRequest = run();
  context.api = async () => ({ nominations: [nomination(4, 6)] });
  await run();
  rejectOld(new Error('Old request failed'));
  await failingOldRequest;
  check(el('nominationsBody').textContent.includes('6 nominations')
    && !el('nominationsBody').textContent.includes('Old request failed'), 'a stale error cannot overwrite a refreshed tally');

  context.api = async () => ({ nominations: [{ ...nomination(5, 1), name: '<img src=x onerror=alert(1)>' }] });
  await run();
  check(el('nominationsBody').textContent.includes('<img src=x onerror=alert(1)>')
    && el('nominationsBody').children[0].children[0].children[0].tagName === 'strong',
    'candidate names are rendered as literal text');

  vm.runInContext('renderCount()', context);
  check(el('voteTallyLede').textContent.includes('submitted voting ballots')
    && el('voteTallyLede').textContent.includes('Nomination totals appear separately'), 'vote tally explanation distinguishes votes from nominations');
  check(source.includes('<h2>Vote tally</h2>') && source.includes('Read vote tally</button>'),
    'vote controls are clearly labelled');
  context.current.election.electorate = 'members';
  vm.runInContext('renderCount()', context);
  check(!el('voteTallyLede').textContent.includes('Nomination totals') && el('voteTallyLede').textContent.includes('after voting closes'),
    'board vote explanation does not promise nomination totals');

  context.current.election = { id: 5, electorate: 'staff', status: 'closed', nominationsFinal: true, locked: true };
  context.api = async () => ({ nominations: [{ ...nomination(5, 3), ballotStatus: 'not_contesting' }] });
  await run();
  check(el('nominationsBody').textContent.includes('Not contesting this position')
    && el('nominationsBody').textContent.includes('3 nominations')
    && !el('nominationsBody').textContent.includes('disqualified'), 'historical nominations preserve counts and clearly mark the unchosen position');
  const positionsStart = source.indexOf('    function renderPositions() {');
  const positionsEnd = source.indexOf("    el('eeSave').addEventListener", positionsStart);
  assert(positionsStart >= 0 && positionsEnd > positionsStart);
  vm.runInContext(source.slice(positionsStart, positionsEnd), context);
  context.current.positions = [{ id: 1, title: 'Chairperson', seats: 1, maxChoices: 1,
    candidates: [{ id: 5, name: 'Candidate 5', status: 'approved', standing: false, ballotStatus: 'not_contesting' }] }];
  vm.runInContext('renderPositions()', context);
  check(el('positionsBody').textContent.includes('Not contesting this position')
    && el('positionsBody').textContent.includes('0 standing')
    && context.current.positions[0].candidates[0].status === 'approved', 'posts use ballot eligibility without relabelling the candidate as disqualified');
  console.log('\n' + checks + ' election-admin nomination UI checks passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
