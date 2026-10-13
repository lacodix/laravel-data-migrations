'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const release = require('./release.cjs');
const sha = 'a'.repeat(40);
const inherited = ['2.0.0', '2.0.1', '2.0.2'];

function harness(options = {}) {
  const calls = [];
  let branchReads = 0;
  const context = {
    eventName: 'push', ref: 'refs/heads/master', sha,
    repo: {owner: 'lacodix', repo: 'laravel-data-migrations'},
    ...options.context,
  };
  const core = {info: message => calls.push(['info', message])};
  const notFound = () => { throw Object.assign(new Error('not found'), {status: 404}); };
  const github = {
    paginate: async (method, params) => {
      assert.equal(method, github.rest.repos.listTags);
      assert.equal(params.per_page, 100);
      return (options.tags || inherited).map(name => ({name}));
    },
    rest: {
      repos: {
        getBranch: async () => ({data: {commit: {sha: (++branchReads >= (options.moveAt || Infinity)) ? 'b'.repeat(40) : sha}}}),
        getLatestRelease: async () => {
          if (options.latestError) throw Object.assign(new Error('API error'), {status: options.latestError});
          return options.latest ? {data: {tag_name: options.latest}} : notFound();
        },
        listTags: () => { throw new Error('Use pagination'); },
        createRelease: async params => {
          if (options.releaseError) throw new Error('release failed');
          calls.push(['release', params]);
        },
      },
      git: {
        getRef: async params => {
          calls.push(['check', params]);
          if (options.tagError) throw Object.assign(new Error('API error'), {status: options.tagError});
          return options.collision ? {data: {}} : notFound();
        },
        createRef: async params => {
          if (options.tagRace) throw Object.assign(new Error('ref already exists'), {status: 422});
          calls.push(['tag', params]);
        },
      },
    },
  };
  return {calls, run: plannedTag => release({github, context, core, plannedTag})};
}

for (const [plan, expected] of [['v0.1.0', 'v3.0.0'], ['v0.0.1', 'v3.0.0'], ['v1.0.0', 'v3.0.0']]) {
  test(`bootstrap ${plan} becomes ${expected} above inherited tags`, async () => {
    const h = harness();
    await h.run(plan);
    const payload = h.calls.find(([kind]) => kind === 'release')[1];
    assert.equal(payload.tag_name, expected);
    assert.equal(payload.target_commitish, sha);
    assert.equal(payload.generate_release_notes, true);
    assert.equal(payload.draft, false);
    assert.equal(payload.prerelease, false);
    assert.deepEqual(h.calls.filter(([kind]) => ['check', 'tag', 'release'].includes(kind)).map(([kind]) => kind),
      ['check', 'tag', 'release']);
    assert.equal(h.calls.find(([kind]) => kind === 'tag')[1].sha, sha);
  });
}

test('explicit v3 bootstrap works with no historical tags or releases', async () => {
  const h = harness({tags: []});
  await h.run('v3.0.0');
  const payload = h.calls.find(([kind]) => kind === 'release')[1];
  assert.equal(payload.tag_name, 'v3.0.0');
  assert.equal(payload.target_commitish, sha);
});

test('first push without tags or releases maps planned v0.1.0 to exact v3.0.0', async () => {
  const h = harness({tags: []});
  await h.run('v0.1.0');
  const writes = h.calls.filter(([kind]) => ['tag', 'release'].includes(kind));
  assert.deepEqual(writes.map(([kind]) => kind), ['tag', 'release']);
  assert.deepEqual(writes[0][1], {
    owner: 'lacodix', repo: 'laravel-data-migrations', ref: 'refs/tags/v3.0.0', sha,
  });
  assert.equal(writes[1][1].tag_name, 'v3.0.0');
  assert.equal(writes[1][1].target_commitish, sha);
});

test('normal releases retain the action plan', async () => {
  const h = harness({latest: 'v3.0.0', tags: [...inherited, 'v3.0.0']});
  await h.run('v3.0.1');
  assert.equal(h.calls.find(([kind]) => kind === 'release')[1].tag_name, 'v3.0.1');
});

test('after the namespace major, a normal minor plan remains minor', async () => {
  const h = harness({latest: 'v3.0.0', tags: [...inherited, 'v3.0.0']});
  await h.run('v3.1.0');
  assert.equal(h.calls.find(([kind]) => kind === 'release')[1].tag_name, 'v3.1.0');
});

test('norelease yields no writes', async () => {
  const h = harness();
  await h.run('');
  assert.deepEqual(h.calls, []);
});

for (const moveAt of [1, 2]) {
  test(`a moved master at check ${moveAt} skips publication`, async () => {
    const h = harness({moveAt});
    await h.run('v0.1.0');
    assert.equal(h.calls.filter(([kind]) => ['tag', 'release'].includes(kind)).length, 0);
  });
}

for (const [name, options, plan, pattern] of [
  ['existing tag', {collision: true}, 'v0.1.0', /already exists/],
  ['tag API failure', {tagError: 403}, 'v0.1.0', /API error/],
  ['release API failure', {latestError: 500}, 'v0.1.0', /API error/],
  ['unknown bootstrap plan', {}, 'v0.2.0', /Unexpected first-release/],
  ['unexpected higher baseline', {tags: ['v2.3.9']}, 'v0.1.0', /Unexpected bootstrap baseline/],
  ['existing namespace baseline without release', {tags: ['v3.0.0']}, 'v0.1.0', /Unexpected bootstrap baseline/],
  ['malformed plan', {}, 'v1.9', /stable vMAJOR/],
  ['historical collision', {latest: 'v3.0.0', tags: ['v3.0.0', 'v3.1.0']}, 'v3.1.0', /inherited tags/],
  ['old release baseline', {latest: 'v2.0.2'}, 'v3.0.0', /latest stable/],
  ['plan behind latest', {latest: 'v3.0.0'}, 'v1.9.0', /latest stable/],
  ['unsupported release baseline', {latest: 'preview'}, 'v1.9.0', /latest stable/],
  ['pull request', {context: {eventName: 'pull_request'}}, 'v0.1.0', /validated push/],
  ['feature branch', {context: {ref: 'refs/heads/feature'}}, 'v0.1.0', /validated push/],
  ['other fork', {context: {repo: {owner: 'someone', repo: 'laravel-data-migrations'}}}, 'v0.1.0', /validated push/],
  ['nonexact SHA', {context: {sha: 'master'}}, 'v0.1.0', /exact commit SHA/],
  ['tag creation race', {tagRace: true}, 'v0.1.0', /ref already exists/],
]) {
  test(`${name} fails closed`, async () => {
    const h = harness(options);
    await assert.rejects(h.run(plan), pattern);
    assert.equal(h.calls.filter(([kind]) => ['tag', 'release'].includes(kind)).length, 0);
  });
}

test('release creation failure leaves only the exact tag for owner recovery', async () => {
  const h = harness({releaseError: true});
  await assert.rejects(h.run('v0.1.0'), /release failed/);
  assert.equal(h.calls.filter(([kind]) => kind === 'tag').length, 1);
  assert.equal(h.calls.filter(([kind]) => kind === 'release').length, 0);
});
