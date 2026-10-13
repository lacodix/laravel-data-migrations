'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {spawnSync} = require('node:child_process');
const script = path.join(__dirname, 'prepare-test-lane.php');
const manifest = JSON.parse(fs.readFileSync(path.join(__dirname, '../../composer.json'), 'utf8'));

test('published minimum support and tooling constraints stay explicit', () => {
  assert.equal(manifest.require.php, '^8.2');
  assert.equal(manifest.require['laravel/framework'], '^11.0|^12.0|^13.0');
  assert.equal(manifest['require-dev']['orchestra/testbench'], '^9.0|^10.0|^11.0');
  assert.equal(manifest['require-dev']['phpunit/phpunit'], '^11.5.50');
  assert.equal(manifest['minimum-stability'], undefined);
  assert.equal(manifest.config.policy, undefined);
});

for (const laravel of ['11.*', '12.*', '13.*', '^11.0|^12.0|^13.0']) {
  test(`security exceptions are isolated to the selected ${laravel} lane`, () => {
    const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'data-migrations-lane-'));
    try {
      const input = structuredClone(manifest);
      input.require['laravel/framework'] = laravel;
      if (!laravel.startsWith('^')) {
        input['require-dev']['orchestra/testbench'] = {'11.*': '^9.0', '12.*': '^10.0', '13.*': '^11.0'}[laravel];
      }
      fs.writeFileSync(path.join(dir, 'composer.json'), JSON.stringify(input));
      const result = spawnSync('php', [script], {cwd: dir, encoding: 'utf8'});
      const output = JSON.parse(fs.readFileSync(path.join(dir, 'composer.json'), 'utf8'));
      if (laravel.startsWith('^')) {
        assert.notEqual(result.status, 0);
        assert.deepEqual(output, input);
      } else {
        assert.equal(result.status, 0, result.stderr);
        assert.equal(output['minimum-stability'], 'stable');
        assert.equal(output.require.php, '^8.2');
        assert.equal(output['require-dev']['orchestra/testbench'], input['require-dev']['orchestra/testbench']);
        if (laravel === '11.*') {
          const exceptions = output.config.policy.advisories['ignore-id'];
          assert.deepEqual(Object.keys(exceptions).sort(),
            ['PKSA-d5tc-s1qs-h781', 'PKSA-m5cs-t1y6-qpcs', 'PKSA-3r5d-mb8f-1qw9', 'PKSA-mdq4-51ck-6kdq'].sort());
          assert.ok(Object.values(exceptions).every(reason => reason.startsWith('Laravel 11 CI only:')));
          assert.deepEqual(Object.keys(output.config.policy), ['advisories']);
          assert.deepEqual(Object.keys(output.config.policy.advisories), ['ignore-id']);
        } else {
          assert.equal(output.config.policy, undefined);
        }
      }
    } finally {
      fs.rmSync(dir, {recursive: true, force: true});
    }
  });
}
