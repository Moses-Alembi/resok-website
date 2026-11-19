'use strict';

// Run the real staff nomination flow with synthetic responses; no portal requests/storage.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const source = fs.readFileSync(path.join(__dirname, '../../resok-portal/public/elections.html'), 'utf8');
const start = source.indexOf('    async function loadNominations() {');
const end = source.indexOf('    function renderNominationList() {', start);
assert(start >= 0 && end > start);

class Element {
  constructor(tag = 'div') {
    this.tagName = tag;
    this.children = [];
    this.listeners = {};
    this.style = {};
    this.hidden = false;
    this.disabled = false;
    this.ownText = '';
    this.value = '';
  }
  set textContent(value) { this.children = []; this.ownText = String(value); }
  get textContent() { return this.ownText + this.children.map(node => node.textContent).join(''); }
  appendChild(node) { this.children.push(node); return node; }
  addEventListener(type, listener) { this.listeners[type] = listener; }
}

function fixture(overrides = {}) {
  return { canNominate: true, ownNominations: [], nominations: [],
    positions: [{ id: 1, title: 'Chairperson', seats: 1 }, { id: 2, title: 'Secretary', seats: 1 },
      { id: 3, title: 'Treasurer', seats: 1 }],
    members: [{ userId: 11, memberProfileId: 101, name: 'Synthetic Colleague A', jobTitle: 'Officer' },
      { userId: 12, memberProfileId: 102, name: 'Synthetic Colleague B', jobTitle: 'Accountant' }], ...overrides };
}

function setup(staff = true) {
  const elements = new Map();
  const el = id => {
    if (!elements.has(id)) elements.set(id, new Element());
    return elements.get(id);
  };
  const make = (tag, cls, text) => {
    const node = new Element(tag);
    node.className = cls || '';
    if (text != null) node.textContent = text;
    return node;
  };
  const context = vm.createContext({ STAFF: staff, current: { election: { slug: 'synthetic-election', status: 'nominations' } },
    nominations: fixture(), nominationRequest: 0, electionRequest: 1,
    staffNominationDrafts: new Map(), staffNominationState: null, staffNominationSending: false,
    api: async () => fixture(), el, make, document: { createElement: tag => new Element(tag) },
    notice: (id, text) => { el(id).textContent = text; }, renderNominationList() {},
  });
  vm.runInContext(source.slice(start, end), context);
  return { context, el, render: () => vm.runInContext('renderNominationForm()', context),
    load: () => vm.runInContext('loadNominations()', context),
    review: () => el('staffNominationReviewBtn').listeners.click(),
    submit: () => el('staffNominationSubmitBtn').listeners.click() };
}

let checks = 0;
function check(condition, message) { assert(condition, message); ++checks; console.log('ok: ' + message); }

(async () => {
  const { context, el, render, load, review, submit } = setup();
  render();
  check(el('staffNominationFields').children.length === 3, 'all election positions appear together');
  check(context.staffNominationState.fields.every(field => field.select.value === ''
    && field.select.children[0].textContent === 'Choose a colleague'), 'every editable position starts with an explicit blank placeholder');
  review();
  check(el('nomNotice').textContent.includes('at least one colleague') && el('staffNominationReview').hidden,
    'empty selections do not reach review or submit');

  let state = context.staffNominationState;
  state.fields[0].select.value = '11';
  state.fields[0].note.value = 'First note';
  state.fields[1].select.value = '11';
  state.fields[0].select.listeners.change();
  state.fields[0].note.listeners.input();
  review();
  check(!el('staffNominationReview').hidden && el('staffNominationFields').hidden,
    'review is a separate confirmation step');
  check(el('staffNominationReviewRows').children.length === 2
    && el('staffNominationReviewRows').textContent.includes('Chairperson')
    && el('staffNominationReviewRows').textContent.includes('Secretary')
    && !el('staffNominationReviewRows').textContent.includes('Treasurer'), 'review lists selected positions and permits leaving other positions blank');
  check(state.review.every(choice => choice.userId === 11), 'the same colleague can be nominated for several posts');
  el('staffNominationEditBtn').listeners.click();
  check(!el('staffNominationFields').hidden && state.fields[0].select.value === '11'
    && state.fields[0].note.value === 'First note', 'editing the review preserves selected colleagues and notes');

  context.nominations = fixture({ ownNominations: [{ positionId: 1, userId: 12, name: 'Recorded Colleague', headline: 'Candidate details' }] });
  render();
  state = context.staffNominationState;
  check(state.fields.length === 2 && !state.fields.some(field => field.position.id === 1)
    && el('staffNominationFields').textContent.includes('Recorded Colleague')
    && el('staffNominationFields').textContent.includes('Recorded'), 'previous final nominations render as recorded read-only cards');
  state.fields[0].select.value = '11';
  state.fields[0].note.value = '  Optional candidate note  ';
  review();
  check(el('staffNominationReviewRows').textContent.includes('Already recorded')
    && state.review.length === 1 && state.review[0].positionId === 2, 'review includes recorded choices while the new payload excludes them');

  let postCount = 0;
  let getCount = 0;
  let posted;
  let resolvePost;
  context.api = async (request, options) => {
    if (options && options.method === 'POST') {
      ++postCount;
      assert.equal(request, '/api/elections/synthetic-election/nominate');
      posted = JSON.parse(options.body);
      return new Promise(resolve => { resolvePost = resolve; });
    }
    ++getCount;
    return fixture({ ownNominations: [{ positionId: 1, userId: 12, name: 'Recorded Colleague' },
      { positionId: 2, userId: 11, name: 'Synthetic Colleague A' }] });
  };
  const pending = submit();
  check(state.controls.every(control => control.disabled), 'all submission controls disable while the final request is pending');
  await submit();
  check(postCount === 1 && posted.nominations.length === 1 && posted.nominations[0].positionId === 2
    && posted.nominations[0].userId === 11 && posted.nominations[0].headline === 'Optional candidate note',
    'one final POST submits only new choices with trimmed optional notes');
  resolvePost({ message: 'Nominations recorded.' });
  await pending;
  check(postCount === 1 && getCount === 1 && el('nomNotice').textContent === 'Nominations recorded.',
    'success uses one bulk write and one confirmation read');
  check(context.staffNominationState.fields.length === 1 && context.staffNominationState.fields[0].position.id === 3,
    'confirmed submitted positions are locked and blank positions remain available');

  context.nominations = fixture();
  render();
  state = context.staffNominationState;
  state.fields[0].select.value = '12';
  state.fields[0].note.value = 'Keep this draft';
  review();
  context.api = async () => { throw new Error('Temporary connection error'); };
  await submit();
  check(!el('staffNominationReview').hidden && state.review[0].userId === 12
    && state.fields[0].note.value === 'Keep this draft' && !el('staffNominationSubmitBtn').disabled,
    'a failed or interrupted submission preserves the review and allows a safe retry');
  check(el('nomNotice').textContent === 'Temporary connection error', 'submission errors are visible without claiming success');
  context.api = async (request, options) => {
    if (options) return {};
    throw new Error('Confirmation read failed');
  };
  await submit();
  check(el('staffNominationFields').textContent.includes('Recorded')
    && el('nomNotice').textContent.includes('were recorded'), 'a successful write stays visibly recorded even if the confirmation read fails');

  context.nominations = fixture({ members: [{ userId: 13, name: '<img src=x onerror=alert(1)>', jobTitle: '<script>unsafe</script>' }] });
  render();
  state = context.staffNominationState;
  state.fields[0].select.value = '13';
  review();
  check(el('staffNominationReviewRows').textContent.includes('<img src=x onerror=alert(1)>')
    && el('staffNominationReviewRows').children[0].children[1].tagName === 'span', 'colleague names render as literal text in the review');

  let resolveOld;
  context.api = () => new Promise(resolve => { resolveOld = resolve; });
  const oldLoad = load();
  context.current = { election: { slug: 'second-election', status: 'nominations' } };
  ++context.electionRequest;
  context.api = async () => fixture({ positions: [{ id: 9, title: 'New election post', seats: 1 }] });
  await load();
  resolveOld(fixture({ positions: [{ id: 8, title: 'Stale election post', seats: 1 }] }));
  await oldLoad;
  check(context.staffNominationState.slug === 'second-election'
    && el('staffNominationFields').textContent.includes('New election post')
    && !el('staffNominationFields').textContent.includes('Stale election post'), 'a stale nomination response cannot replace another election form');
  context.current.election.status = 'open';
  await load();
  check(el('eNominateBox').hidden && context.staffNominationState === null,
    'transitioning to voting hides and invalidates the previous nomination form');

  context.current.election.status = 'nominations';
  context.nominations = fixture();
  delete context.nominations.ownNominations;
  render();
  check(context.staffNominationState === null && el('nomNotice').textContent.includes('recorded nominations are unavailable'),
    'missing own-nomination data cannot offer an unsafe editable form');

  const board = setup(false);
  board.context.nominations = fixture();
  board.render();
  check(board.el('nomPosition').children.length === 3 && board.el('nomMember').children[0].value === '101'
    && board.context.staffNominationState === null, 'board elections retain their existing single-post member form');
  check(!source.slice(start, end).includes('localStorage') && !source.slice(start, end).includes('sessionStorage'),
    'nomination drafts are kept in memory without browser storage');
  const css = fs.readFileSync(path.join(__dirname, '../../resok-portal/public/css/staff-elections.css'), 'utf8');
  check(css.includes('.staff-nomination-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr))')
    && css.includes('.staff-nomination-grid{grid-template-columns:1fr}'), 'staff cards have wide and mobile grid layouts');
  console.log('\n' + checks + ' staff nomination form checks passed.');
})().catch(error => { console.error(error); process.exitCode = 1; });
