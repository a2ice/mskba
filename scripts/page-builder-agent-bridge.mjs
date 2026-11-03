// GitHub Actions -> DEV Filament Page Builder API.
// No dependencies; credentials are provided only by the GitHub 'development'
// environment. All operations are scoped to https://dev.mskba.ru.
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { pathToFileURL } from 'node:url';

const API_ORIGIN = 'https://dev.mskba.ru';
const REQUEST_PATH_PATTERN = /^ops\/page-builder\/requests\/[a-z0-9][a-z0-9._-]*\.json$/;
const SHA_PATTERN = /^[0-9a-f]{64}$/;
const TARGETS = new Set(['components', 'pages', 'templates']);
const FIELDS = new Set(['html', 'css', 'js']);
const OPERATIONS = new Set(['inspect', 'apply', 'rollback']);

export const sha256 = (source) => createHash('sha256').update(source, 'utf8').digest('hex');

function assert(condition, message) {
  if (!condition) throw new Error(message);
}

function isObject(value) {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

export function validatePlan(input) {
  assert(isObject(input), 'Plan must be a JSON object.');
  assert(input.version === 1, 'Plan version must be 1.');
  assert(OPERATIONS.has(input.operation), 'Only inspect, apply and rollback are supported.');
  assert(input.environment === 'development', 'Only the development environment is supported.');
  assert(TARGETS.has(input.kind), 'Invalid Page Builder target kind.');
  assert(typeof input.key === 'string' && /^[a-zA-Z0-9_-]{1,100}$/.test(input.key),
    'Target key must be a simple slug, home or numeric ID.');
  if (input.kind === 'pages') {
    assert(input.key === 'home' || /^\d+$/.test(input.key), 'Page target must be home or a numeric ID.');
  }
  assert(input.confirm === 'DEV_ONLY_PAGE_BUILDER', 'Explicit DEV-only confirmation is required.');

  if (input.operation === 'inspect') {
    assert(!('edits' in input) && !('publish' in input), 'Inspect cannot carry writes.');
    return input;
  }

  assert(FIELDS.has(input.field), 'Field must be html, css or js.');
  assert(typeof input.reason === 'string' && input.reason.length >= 4 && input.reason.length <= 300,
    'An audit reason of 4-300 characters is required.');

  if (input.operation === 'rollback') {
    assert(['css', 'js'].includes(input.field), 'Automatic rollback is limited to CSS/JS.');
    assert(SHA_PATTERN.test(input.expected_published_sha256 ?? ''),
      'Rollback requires a fresh published SHA-256.');
    assert(!('edits' in input), 'Rollback must not carry edits.');
    return input;
  }

  assert(input.operation === 'apply', 'Unknown operation.');
  assert(SHA_PATTERN.test(input.expected_draft_sha256 ?? ''), 'Expected draft SHA-256 is missing.');
  assert(SHA_PATTERN.test(input.expected_published_sha256 ?? ''), 'Expected published SHA-256 is missing.');
  assert(SHA_PATTERN.test(input.expected_result_sha256 ?? ''), 'Expected final SHA-256 is missing.');
  assert(typeof input.publish === 'boolean', 'publish must be explicitly true or false.');
  assert(Array.isArray(input.edits) && input.edits.length > 0 && input.edits.length <= 20,
    'Plan requires 1-20 exact edits.');
  assert(input.edits.every((edit) => isObject(edit) && edit.type === 'replace_once'
    && typeof edit.from === 'string' && edit.from.length > 0
    && typeof edit.to === 'string' && edit.to !== edit.from),
  'Every edit must be a nonempty replace_once with different from/to strings.');
  if (input.field === 'html' && input.publish) {
    assert(input.confirm_html_publish === 'PUBLISH_HTML_EXPLICITLY',
      'HTML publication requires additional explicit confirmation.');
  }
  return input;
}

export function applyExactEdits(source, edits) {
  let result = source;
  for (const [index, edit] of edits.entries()) {
    const occurrences = result.split(edit.from).length - 1;
    assert(occurrences === 1,
      'Exact edit ' + (index + 1) + ' matched ' + occurrences + ' times (expected once).');
    result = result.replace(edit.from, edit.to);
  }
  assert(result !== source, 'The plan produced no changes.');
  return result;
}

function describeResource(kind, key) {
  return kind + '/' + key;
}

async function runPlan(plan, token) {
  assert(typeof token === 'string' && token.length > 0,
    'Missing MSKBA_PAGE_BUILDER_AGENT_TOKEN in the GitHub development environment.');

  const resourcePath = '/api/v1/internal/page-builder/'
    + encodeURIComponent(plan.kind) + '/' + encodeURIComponent(plan.key);

  async function request(method, suffix = '', payload) {
    const url = new URL(API_ORIGIN + resourcePath + suffix);
    assert(url.origin === API_ORIGIN, 'Refusing a non-DEV origin.');

    const headers = {
      Authorization: 'Bearer ' + token,
      Accept: 'application/json',
    };
    if (payload !== undefined) headers['Content-Type'] = 'application/json';

    let response;
    try {
      response = await fetch(url, {
        method, headers, body: payload === undefined ? undefined : JSON.stringify(payload),
        redirect: 'error',
        signal: AbortSignal.timeout(20000),
      });
    } catch (error) {
      throw new Error('DEV API request failed: ' + error.message);
    }
    const body = await response.text();
    if (!response.ok) {
      // Never log response bodies. A server error page could expose sensitive data.
      throw new Error('DEV Page Builder API responded HTTP ' + response.status
        + ' for ' + method + ' ' + url.pathname
        + (response.status === 409 ? ' (editor version conflict; re-inspect)' : ''));
    }
    let parsed;
    try { parsed = JSON.parse(body); } catch { throw new Error('Invalid API JSON response.'); }
    assert(isObject(parsed?.data) && isObject(parsed.data.sources),
      'Unexpected Page Builder API response.');
    return parsed.data;
  }

  const target = describeResource(plan.kind, plan.key);
  const before = await request('GET');
  const fields = plan.operation === 'inspect' ? ['html', 'css', 'js'] : [plan.field];

  for (const field of fields) {
    const source = before.sources[field];
    assert(isObject(source), 'Missing source for ' + field);
    console.log(target + ' ' + field
      + ' draft_sha256=' + source.draft_sha256
      + ' published_sha256=' + source.published_sha256
      + ' draft_bytes=' + Buffer.byteLength(source.draft ?? '', 'utf8')
      + ' published_bytes=' + Buffer.byteLength(source.published ?? '', 'utf8')
      + ' pending=' + Boolean(source.pending));
  }

  if (plan.operation === 'inspect') {
    console.log('Read-only inspect succeeded; source contents intentionally omitted from public Actions logs.');
    return;
  }

  const current = before.sources[plan.field];
  assert(isObject(current), 'Missing field.');
  assert(SHA_PATTERN.test(current.draft_sha256) && SHA_PATTERN.test(current.published_sha256),
    'Missing API SHA-256 values.');

  if (plan.operation === 'rollback') {
    assert(current.published_sha256 === plan.expected_published_sha256,
      'Published editor version changed since the rollback plan was approved.');
    const after = await request('POST', '/rollback', {
      field: plan.field,
      expected_published_sha256: current.published_sha256,
      reason: plan.reason,
    });
    console.log('Rollback successful: new published SHA-256 ' + after.sources[plan.field].published_sha256);
    return;
  }

  // Re-running a successfully applied request must not create another revision.
  if (current.draft_sha256 === plan.expected_result_sha256
    && (!plan.publish || current.published_sha256 === plan.expected_result_sha256)) {
    console.log('Already applied; preserving the existing Filament publication.');
    return;
  }

  assert(current.draft_sha256 === plan.expected_draft_sha256,
    'Draft changed since the plan was approved. No writes made.');
  assert(current.published_sha256 === plan.expected_published_sha256,
    'Published source changed since the plan was approved. No writes made.');
  const next = applyExactEdits(current.draft, plan.edits);
  assert(sha256(next) === plan.expected_result_sha256,
    'Patch result SHA-256 does not match the reviewed plan. No writes made.');
  assert(next.length <= 100000, 'Result exceeds the API limit of 100000 characters.');

  const staged = await request('PATCH', '/draft', {
    field: plan.field,
    value: next,
    expected_draft_sha256: current.draft_sha256,
    reason: plan.reason,
  });
  assert(staged.sources[plan.field].draft_sha256 === plan.expected_result_sha256,
    'Draft verification failed. Publication was not attempted.');
  console.log('Draft saved and verified: ' + plan.field + ' ' + plan.expected_result_sha256);

  if (!plan.publish) {
    console.log('Draft only. Nothing published.');
    return;
  }

  const published = await request('POST', '/publish', {
    field: plan.field,
    expected_draft_sha256: plan.expected_result_sha256,
    expected_published_sha256: current.published_sha256,
    reason: plan.reason,
  });
  assert(published.sources[plan.field].published_sha256 === plan.expected_result_sha256,
    'Publication verification failed. Inspect Filament before further changes.');
  const verified = await request('GET');
  assert(verified.sources[plan.field].published_sha256 === plan.expected_result_sha256,
    'Read-back mismatch after publishing. Inspect Filament.');
  console.log('Published and re-verified on DEV: ' + target + ' ' + plan.field
    + ' ' + plan.expected_result_sha256);
}

export async function main(args = process.argv.slice(2)) {
  assert(args.length === 1, 'Usage: node scripts/page-builder-agent-bridge.mjs <approved-request.json>');
  assert(REQUEST_PATH_PATTERN.test(args[0]), 'Request must reside in ops/page-builder/requests/*.json.');
  const plan = validatePlan(JSON.parse(readFileSync(args[0], 'utf8')));
  await runPlan(plan, process.env.MSKBA_PAGE_BUILDER_AGENT_TOKEN);
}

if (process.argv[1] && import.meta.url === pathToFileURL(process.argv[1]).href) {
  main().catch((error) => {
    console.error('DEV Page Builder bridge: ' + error.message);
    process.exitCode = 1;
  });
}
