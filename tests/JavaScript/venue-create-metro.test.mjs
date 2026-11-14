import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { runInNewContext } from 'node:vm';

const script = readFileSync(
    new URL('../../resources/themes/mskba_dark/js/features/address-suggest.js', import.meta.url), 'utf8',
);

test('app venue metro summary displays colored line chips, while legacy stays text-only', () => {
    const stations = [
        {textContent: 'Бескудниково (МЦД-1)', dataset: {lineColor: '#D93A78'}},
        {textContent: 'Петровско-Разумовская', dataset: {lineColor: '#not-a-color'}},
    ];
    function element() {
        return {
            className: '', children: [], textContent: '', style: {values: {}, setProperty(k, v) {this.values[k] = v;}},
            setAttribute(name, value) {this[name] = value;},
            append(...items) {this.children.push(...items);},
            replaceChildren() {this.children = []; this.textContent = '';},
            hasAttribute(key) {return key === 'data-address-metro-badges';},
        };
    }
    const summary = element();
    const legacy = element();
    legacy.hasAttribute = () => false;
    const document = {addEventListener() {}, createElement: element};
    runInNewContext(script + '\n updateMetroSummary(select, summary); updateMetroSummary(select, legacy);', {
        document, select: {selectedOptions: stations}, summary, legacy,
    });
    assert.equal(summary.children.length, 2);
    assert.equal(summary.children[0].className, 'app-venue-metro__chip');
    assert.equal(summary.children[0].children[0].style.values['--metro-dot'], '#D93A78');
    assert.equal(summary.children[1].children[0].style.values['--metro-dot'], '#64748b');
    assert.equal(legacy.textContent, 'Бескудниково (МЦД-1), Петровско-Разумовская');
});
