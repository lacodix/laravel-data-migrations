'use strict';

// Preserve the planner skip/label policy. The first Lacodix namespace release
// is explicitly v3.0.0, including a fork with no tags or releases. Unknown higher
// baselines require an owner decision; later releases retain the action plan.
function version(tag) {
  const match = /^v?(\d+)\.(\d+)(?:\.(\d+))?$/.exec(tag);
  if (!match) return null;
  const parts = match.slice(1).map(value => Number(value || 0));
  if (!parts.every(Number.isSafeInteger)) throw new Error(`Invalid version: ${tag}`);
  return parts;
}

function compare(a, b) {
  for (let i = 0; i < 3; i++) {
    if (a[i] !== b[i]) return a[i] - b[i];
  }
  return 0;
}

module.exports = async function release({github, context, core, plannedTag}) {
  if (context.eventName !== 'push' || context.ref !== 'refs/heads/master' ||
      context.repo.owner !== 'lacodix' || context.repo.repo !== 'laravel-data-migrations') {
    throw new Error('Release requires a validated push to Lacodix master.');
  }
  if (!/^[0-9a-f]{40}$/.test(context.sha)) throw new Error('Expected an exact commit SHA.');
  if (!plannedTag) return;
  if (!/^v\d+\.\d+\.\d+$/.test(plannedTag)) throw new Error('Expected a stable vMAJOR.MINOR.PATCH plan.');

  const repo = context.repo;
  const branch = await github.rest.repos.getBranch({...repo, branch: 'master'});
  if (branch.data.commit.sha !== context.sha) {
    core.info('Master has moved; skip this older CI run.');
    return;
  }

  let latest = null;
  try {
    latest = (await github.rest.repos.getLatestRelease(repo)).data;
  } catch (error) {
    if (error.status !== 404) throw error;
  }

  const tags = await github.paginate(github.rest.repos.listTags, {...repo, per_page: 100});
  const stableVersions = tags.map(tag => version(tag.name)).filter(Boolean);
  const highest = stableVersions.reduce((a, b) => compare(a, b) >= 0 ? a : b, [0, 0, 0]);
  let tag = plannedTag;
  if (!latest) {
    if (compare(highest, [2, 0, 2]) > 0) {
      throw new Error('Unexpected bootstrap baseline above upstream 2.0.2; owner decision required.');
    }
    if (!['v1.0.0', 'v0.1.0', 'v0.0.1', 'v3.0.0'].includes(plannedTag)) {
      throw new Error('Unexpected first-release plan; refusing to guess a baseline.');
    }
    tag = 'v3.0.0';
    core.info(`First Lacodix release: baseline ${highest.join('.')} -> ${tag}.`);
  } else {
    const baseline = version(latest.tag_name);
    if (!baseline || compare(baseline, [3, 0, 0]) < 0 || compare(version(tag), baseline) <= 0) {
      throw new Error('Release plan does not advance the latest stable release.');
    }
  }
  if (compare(version(tag), highest) <= 0) throw new Error('Release plan does not exceed inherited tags.');

  // Never reuse, delete or retarget a tag, including non-semver names.
  try {
    await github.rest.git.getRef({...repo, ref: `tags/${tag}`});
    throw new Error(`Tag ${tag} already exists; owner intervention required.`);
  } catch (error) {
    if (error.status !== 404) throw error;
  }

  // Check again after planning to avoid publishing an obsolete run.
  const current = await github.rest.repos.getBranch({...repo, branch: 'master'});
  if (current.data.commit.sha !== context.sha) {
    core.info('Master moved during planning; skip this older CI run.');
    return;
  }
  // Atomic ref creation also refuses a tag created between our check and publish.
  await github.rest.git.createRef({...repo, ref: `refs/tags/${tag}`, sha: context.sha});
  await github.rest.repos.createRelease({
    ...repo,
    tag_name: tag,
    target_commitish: context.sha,
    name: tag,
    body: latest ? '' : `First Lacodix release; inherited stable tag baseline: ${highest.join('.')}.`,
    draft: false,
    prerelease: false,
    generate_release_notes: true,
  });
};
