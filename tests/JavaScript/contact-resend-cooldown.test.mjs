import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const src = readFileSync(new URL('../../resources/themes/mskba_app/js/contact-resend-cooldown.js', import.meta.url), 'utf8')
  .replace('export function createContactResendCooldown', 'function createContactResendCooldown');

test('server countdown updates once per second, expires and stops its timer', () => {
  let now = 100000;
  let serial = 0;
  const timers = new Map();
  const rendered = [];
  const scope = {
    Date: { now: () => now },
    setInterval(fn) { const id = ++serial; timers.set(id, fn); return id; },
    clearInterval(id) { timers.delete(id); },
  };
  runInNewContext(src + '\nthis.make = createContactResendCooldown;', scope);
  const countdown = scope.make(n => rendered.push(n));
  countdown.set(57);
  assert.deepEqual(rendered, [57]);
  now += 1000;
  for (const fn of [...timers.values()]) fn();
  assert.deepEqual(rendered, [57, 56]);
  countdown.set(9); // authoritative server answer overrides previous remaining time
  assert.equal(rendered.at(-1), 9);
  assert.equal(timers.size, 1);
  now += 9000;
  for (const fn of [...timers.values()]) fn();
  assert.equal(rendered.at(-1), 0);
  assert.equal(timers.size, 0);
  countdown.dispose();
});

test('each email contact has independent countdown state', () => {
  let now = 200000;
  const timers = new Map();
  let serial = 0;
  const scope = {
    Date: { now: () => now },
    setInterval(fn) { const id = ++serial; timers.set(id, fn); return id; },
    clearInterval(id) { timers.delete(id); },
  };
  runInNewContext(src + '\nthis.make = createContactResendCooldown;', scope);
  const a = [], b = [];
  const first = scope.make(n => a.push(n));
  const second = scope.make(n => b.push(n));
  first.set(60);
  second.set(12);
  now += 12000;
  for (const fn of [...timers.values()]) fn();
  assert.equal(a.at(-1), 48);
  assert.equal(b.at(-1), 0);
  first.dispose();
  second.dispose();
  assert.equal(timers.size, 0);
});

test('modal reuses existing resend countdown rather than error for server 429', () => {
  const file = readFileSync(new URL('../../resources/themes/mskba_app/js/account-confirmation-contact-wizard.js', import.meta.url), 'utf8');
  assert.match(file, /applyServerCooldown\(error\.payload\.seconds_left\)/);
  assert.match(file, /setFeedback\('Код уже отправлен\. Введи его из письма\.'\)/);
  assert.match(file, /trigger\.dataset\.existingCooldownSeconds/);
});
