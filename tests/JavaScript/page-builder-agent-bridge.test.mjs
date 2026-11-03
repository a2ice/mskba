import assert from 'node:assert/strict';
import test from 'node:test';

import {
  applyExactEdits,
  sha256,
  validatePlan,
} from '../../scripts/page-builder-agent-bridge.mjs';

const source = '.mskba-v3-venues__shooter { left: 10px; }\n';
const target = '.mskba-v3-venues__shooter { right: 10px; }\n';

function plan(overrides = {}) {
  return {
    version: 1,
    environment: 'development',
    confirm: 'DEV_ONLY_PAGE_BUILDER',
    operation: 'apply',
    kind: 'components',
    key: 'venues-scene-v3',
    field: 'css',
    reason: 'Корректировка размещения игрока',
    publish: false,
    expected_draft_sha256: sha256(source),
    expected_published_sha256: sha256(source),
    expected_result_sha256: sha256(target),
    edits: [{
      type: 'replace_once',
      from: 'left: 10px',
      to: 'right: 10px',
    }],
    ...overrides,
  };
}

test('only an explicitly scoped DEV operation is allowed', () => {
  assert.equal(validatePlan(plan()).operation, 'apply');
  for (const value of [
    { environment: 'production' },
    { confirm: 'PROD_ONLY' },
    { operation: 'execute' },
    { kind: 'users' },
    { key: '../../somewhere' },
    { field: 'php' },
    { expected_draft_sha256: '0' },
    { publish: 'true' },
  ]) {
    assert.throws(() => validatePlan(plan(value)));
  }
});

test('inspect cannot contain any edits or publish intent', () => {
  const common = {
    version: 1, environment: 'development', confirm: 'DEV_ONLY_PAGE_BUILDER',
    operation: 'inspect', kind: 'components', key: 'venues-scene-v3',
  };
  assert.equal(validatePlan(common).operation, 'inspect');
  assert.throws(() => validatePlan({ ...common, publish: true }));
  assert.throws(() => validatePlan({ ...common, edits: [] }));
});

test('replace_once must match a single exact occurrence', () => {
  const edited = applyExactEdits(source, plan().edits);
  assert.equal(edited, target);
  assert.equal(sha256(edited), plan().expected_result_sha256);

  assert.throws(() => applyExactEdits(source, [{ type: 'replace_once', from: 'wrong', to: 'new' }]));
  assert.throws(() => applyExactEdits('x x', [{ type: 'replace_once', from: 'x', to: 'y' }]));
});

test('HTML publishing needs an additional explicit confirmation', () => {
  assert.throws(() => validatePlan(plan({ field: 'html', publish: true })));
  assert.equal(
    validatePlan(plan({ field: 'html', publish: true, confirm_html_publish: 'PUBLISH_HTML_EXPLICITLY' })).field,
    'html',
  );
});

test('rollback is restricted to CSS/JS and requires the exact published SHA', () => {
  const common = {
    version: 1, environment: 'development', confirm: 'DEV_ONLY_PAGE_BUILDER',
    operation: 'rollback', kind: 'pages', key: 'home',
    field: 'js', reason: 'Вернуть предыдущую публикацию',
    expected_published_sha256: sha256('console.log(1)'),
  };
  assert.equal(validatePlan(common).operation, 'rollback');
  assert.throws(() => validatePlan({ ...common, field: 'html' }));
  assert.throws(() => validatePlan({ ...common, expected_published_sha256: '' }));
});

test('pages accept only home or numeric IDs', () => {
  assert.throws(() => validatePlan(plan({ kind: 'pages', key: 'venues' })));
  assert.equal(validatePlan(plan({ kind: 'pages', key: 'home' })).key, 'home');
});


test('result hash may be computed on runner when exact source stays private', () => {
  const { expected_result_sha256, ...withoutResult } = plan();
  assert.ok(expected_result_sha256);
  assert.equal(validatePlan(withoutResult).operation, 'apply');
  assert.throws(() => validatePlan(plan({ expected_result_sha256: 'not-a-sha' })));
});


test('pending editor changes require explicit approval', () => {
  assert.equal(validatePlan(plan()).allow_pending_draft, undefined);
  assert.equal(validatePlan(plan({ allow_pending_draft: true })).allow_pending_draft, true);
  assert.throws(() => validatePlan(plan({ allow_pending_draft: 'yes' })));
});
