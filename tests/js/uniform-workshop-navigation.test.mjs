import { strict as assert } from 'node:assert';
import test from 'node:test';
import { cycleUniformView, singleUniformSize, UNIFORM_VIEW_ORDER } from '../../resources/themes/mskba_app/js/uniforms/uniform-workshop-navigation.js';

test('right arrow cycles front → left → rear → right → front', () => {
  let view = 'front';
  const visited = [view];
  for (let i = 0; i < 4; i++) { view = cycleUniformView(view, 1); visited.push(view); }
  assert.deepEqual(visited, ['front', 'left', 'rear', 'right', 'front']);
  assert.deepEqual([...UNIFORM_VIEW_ORDER], visited.slice(0, 4));
});

test('left arrow cycles front → right → rear → left → front', () => {
  let view = 'front';
  const visited = [view];
  for (let i = 0; i < 4; i++) { view = cycleUniformView(view, -1); visited.push(view); }
  assert.deepEqual(visited, ['front', 'right', 'rear', 'left', 'front']);
});

test('roundtrip any view with opposite arrow', () => {
  for (const view of UNIFORM_VIEW_ORDER) {
    assert.equal(cycleUniformView(cycleUniformView(view, 1), -1), view);
  }
});

test('legacy multiple sizes collapse to a single stable selection', () => {
  assert.equal(singleUniformSize(['XL', 'XXL']), 'XL');
  assert.equal(singleUniformSize(['INVALID', 'S', 'M']), 'S');
  assert.equal(singleUniformSize(['XXL']), 'XXL');
  assert.equal(singleUniformSize([]), 'M');
  assert.equal(singleUniformSize(null), 'M');
});
