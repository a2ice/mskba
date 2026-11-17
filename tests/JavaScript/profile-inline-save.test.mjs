import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const source = readFileSync(
    new URL('../../resources/themes/mskba_app/js/profile-inline-save.js', import.meta.url),
    'utf8',
).replace('export function initProfileInlineSave', 'function initProfileInlineSave');

function fixture() {
    const callbacks = new Map();
    const timers = new Map();
    const calls = [];
    const requests = [];
    let time = 1000;
    let nextId = 1;

    const classList = () => {
        const classes = new Set();
        return {
            toggle(name, enabled) { if (enabled) classes.add(name); else classes.delete(name); },
            contains(name) { return classes.has(name); },
            add(name) { classes.add(name); },
            remove(name) { classes.delete(name); },
        };
    };
    const fields = ['first_name', 'last_name'].map((name, index) => {
        const input = {
            value: ['Alice', 'Jones'][index], disabled: false,
            addEventListener(event, fn) { callbacks.set(name + ':input:' + event, fn); },
        };
        const button = {
            disabled: true, title: '', classList: classList(),
            attributes: {}, setAttribute(key, value) { this.attributes[key] = value; },
            addEventListener(event, fn) { callbacks.set(name + ':save:' + event, fn); },
        };
        const loader = { hidden: true };
        const cooldownHost = {
            attributes: {},
            getAttribute(key) { return this.attributes[key] ?? null; },
            setAttribute(key, value) { this.attributes[key] = value; },
            removeAttribute(key) { delete this.attributes[key]; },
        };
        const countdown = { hidden: true, textContent: '' };
        const error = { hidden: true, textContent: '' };
        const node = {
            dataset: { fieldName: name, savedValue: input.value },
            classList: classList(),
            querySelector(selector) {
                if (selector === 'label') return { firstChild: { textContent: name } };
                return ({
                    '[data-inline-profile-input]': input,
                    '[data-inline-profile-save]': button,
                    '[data-inline-profile-loading]': loader,
                    '[data-inline-profile-cooldown]': countdown,
                    '.app-confirmation__input-wrap': cooldownHost,
                    '[data-inline-profile-error]': error,
                })[selector] || null;
            },
        };
        return { input, button, loader, countdown, cooldownHost, error, node };
    });
    const allButton = {
        disabled: true, textContent: 'Сохранить всё',
        addEventListener(event, fn) { callbacks.set('save-all:' + event, fn); },
    };
    const feedback = { textContent: '', classList: classList() };
    const card = { classList: classList(), setAttribute() {} };
    const form = {
        action: '/account/profile',
        dataset: { saveUrl: '/account/profile' },
        querySelector(selector) {
            return ({
                '[data-inline-profile-save-all]': allButton,
                '[data-inline-profile-feedback]': feedback,
                'input[name="_token"]': { value: 'csrf' },
            })[selector] || null;
        },
        querySelectorAll() { return fields.map(field => field.node); },
        closest() { return card; },
    };
    const context = {
        Date: { now: () => time },
        setInterval(callback, delay) {
            const id = nextId++;
            timers.set(id, { callback, delay });
            return id;
        },
        clearInterval(id) { timers.delete(id); },
        fetch(url, params) {
            calls.push({ url, params, body: JSON.parse(params.body) });
            return new Promise((resolve, reject) => requests.push({ resolve, reject }));
        },
    };
    runInNewContext(source + '\nthis.init = initProfileInlineSave;', context);
    const editor = context.init(form);
    const tick = ms => {
        time += ms;
        for (const { callback } of [...timers.values()]) callback();
    };
    const update = (field, value) => {
        fields[field].input.value = value;
        callbacks.get(fields[field].node.dataset.fieldName + ':input:input')();
    };
    const finish = async (data, ok = true) => {
        const request = requests.shift();
        assert.ok(request, 'a request was started');
        request.resolve({
            ok,
            json: async () => data,
        });
        for (let i = 0; i < 5; i++) await Promise.resolve();
    };
    return { fields, editor, allButton, feedback, update, finish, tick, calls, timers, callbacks };
}

test('AJAX spinner ends on server response and starts visible 5-second countdown', async () => {
    const f = fixture();
    f.update(0, 'Alicia');
    assert.equal(f.fields[0].button.disabled, false);
    const pending = f.editor.save([f.editor.states[0]]);
    assert.equal(f.fields[0].loader.hidden, false);
    assert.equal(f.fields[0].input.disabled, true);
    assert.equal(f.fields[1].input.disabled, false, 'other fields remain available during a single save');
    assert.equal(f.fields[0].countdown.hidden, true, 'spinner is visible, not countdown, during AJAX');

    await f.finish({ values: { first_name: 'Alicia' }, message: 'Данные сохранены.' });
    await pending;
    assert.equal(f.feedback.textContent, 'Данные сохранены.', 'server result immediately visible');
    assert.equal(f.fields[0].loader.hidden, true);
    assert.equal(f.fields[0].input.disabled, false, 'input re-enabled immediately');
    assert.equal(f.fields[0].countdown.textContent, '5с');
    assert.equal(f.fields[0].countdown.hidden, false);
    assert.equal(f.fields[0].button.disabled, false, 'hoverable for native tooltip');
    assert.equal(f.fields[0].button.attributes['aria-disabled'], 'true');
    assert.equal(f.fields[0].cooldownHost.getAttribute('data-cooldown-tooltip'), 'ожидайте',
        'the entire input surface owns the tooltip');
    assert.equal(f.fields[0].button.title, '', 'no duplicate native button tooltip');

    f.tick(1000);
    assert.equal(f.fields[0].countdown.textContent, '4с');
    f.tick(4000);
    assert.equal(f.fields[0].countdown.hidden, true);
    assert.equal(f.fields[0].button.classList.contains('is-cooling'), false);
    assert.equal(f.fields[0].cooldownHost.getAttribute('data-cooldown-tooltip'), null,
        'field-wide tooltip disappears when cooldown finishes');
    f.update(0, 'Alicia V2');
    assert.equal(f.fields[0].button.disabled, false, 'diskette reactivates after cooldown and a change');
});

test('cooldown on one field does not block save of another or bulk save', async () => {
    const f = fixture();
    f.update(0, 'Alicia');
    let pending = f.editor.save([f.editor.states[0]]);
    await f.finish({ values: { first_name: 'Alicia' } });
    await pending;
    f.update(0, 'Alicia V2');
    await f.editor.save([f.editor.states[0]]);
    assert.equal(f.calls.length, 1, 'clickable countdown cannot resend while cooling');
    f.update(1, 'Smith');
    assert.equal(f.fields[0].button.attributes['aria-disabled'], 'true');
    assert.equal(f.fields[1].button.disabled, false);
    assert.equal(f.allButton.disabled, false);
    assert.equal(f.allButton.textContent, 'Сохранить остальные');
    pending = f.editor.save(f.editor.states.filter(f.editor.dirty), true);
    assert.deepEqual(Object.keys(f.calls[1].body), ['last_name'], 'bulk save skips the cooling field');
    await f.finish({ values: { last_name: 'Smith' } });
    await pending;
    assert.equal(f.fields[1].input.disabled, false);
    assert.equal(f.fields[1].countdown.textContent, '5с');
    assert.equal(f.fields[0].countdown.hidden, false);
});

test('server validation errors appear immediately without starting cooldown', async () => {
    const f = fixture();
    f.update(0, 'bad');
    const pending = f.editor.save([f.editor.states[0]]);
    await f.finish({ message: 'Ошибка валидации', errors: { first_name: ['Недопустимое значение.'] } }, false);
    await pending;
    assert.equal(f.fields[0].loader.hidden, true);
    assert.equal(f.fields[0].input.disabled, false);
    assert.equal(f.fields[0].error.textContent, 'Недопустимое значение.');
    assert.equal(f.fields[0].countdown.hidden, true, 'user can retry a corrected value');
    assert.equal(f.fields[0].cooldownHost.getAttribute('data-cooldown-tooltip'), null,
        'server validation errors do not leave behind any waiting tooltip');
    assert.equal(f.fields[0].button.disabled, false);
});

test('tooltip is styled on the full input wrapper during cooldown and works on hover/focus', () => {
    const css = readFileSync(new URL('../../resources/themes/mskba_app/css/account-confirmation.css', import.meta.url), 'utf8');
    assert.match(css, /\.app-confirmation__input-wrap\[data-cooldown-tooltip\]::after\s*\{/);
    assert.match(css, /\.app-confirmation__input-wrap\[data-cooldown-tooltip\]:is\(:hover,:focus-within\)::after/);
});
