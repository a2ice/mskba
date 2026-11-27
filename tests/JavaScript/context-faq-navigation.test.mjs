import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
const js = readFileSync(new URL('../../resources/themes/mskba_app/js/context-dialogs.js', import.meta.url), 'utf8');

test('contextual venue FAQ opens the real section and navigates to its parent levels', () => {
    const setup = js.slice(js.indexOf('    function syncBack()'), js.indexOf('    const lookup ='));
    assert.ok(setup.includes('function openContextHelp'));
    const calls = [], back = { addEventListener(name, fn) { this.click = fn; } };
    const make = new Function('back', 'load', 'showDialog', 'initialSection', 'help', `
        let faqArticle = null, faqSection = null, pageHelp = null, returnToPageHelp = false, helpContext = '', waitingForAuth = false;
        ${setup}
        return { openContextHelp, setState(section, article, page = null) {
            faqSection = section; faqArticle = article; pageHelp = page; syncBack();
        }};
    `);
    const fixture = make(back, (...args) => calls.push(args), () => {}, 'venues', { dataset: { helpContext: 'venues' } });
    fixture.openContextHelp({ dataset: {} });
    assert.deepEqual(calls.pop(), ['venues']);
    fixture.setState('venues', 'existing-guide');
    assert.equal(back.hidden, false);
    back.click();
    assert.deepEqual(calls.pop(), ['venues']);
    fixture.setState('venues', null);
    back.click();
    assert.deepEqual(calls.pop(), []);
    fixture.setState(null, null);
    assert.equal(back.hidden, true);
});

test('invitation is scoped to the venues section and the existing creation guide, never the FAQ root', () => {
    const snippet = js.slice(js.indexOf('        const showVenueInvitation ='), js.indexOf('        if (faq.article) {', js.indexOf('        const showVenueInvitation =')));
    const show = new Function('venueCreateTemplate', 'faqSection', 'faq', 'articleList', snippet + ';return Boolean(showVenueInvitation);');
    const template = { content: { cloneNode: () => 'banner' } };
    const rendered = [];
    const list = { append: item => rendered.push(item) };
    assert.equal(show(template, null, {}, list), false);
    assert.equal(show(template, 'events', {}, list), false);
    assert.equal(show(template, 'venues', {}, list), true);
    assert.equal(show(template, 'venues', { article: { is_venue_creation_guide: true } }, list), true);
    assert.equal(show(template, 'venues', { article: { is_venue_creation_guide: false } }, list), false);
    assert.deepEqual(rendered, ['banner', 'banner']);
});

test('back is beside the section selector with a 5px gap, not in the title', () => {
    const blade = readFileSync(new URL('../../resources/themes/mskba_app/views/partials/context-action-dialogs.blade.php', import.meta.url), 'utf8');
    const css = readFileSync(new URL('../../resources/themes/mskba_app/css/context-dialogs.css', import.meta.url), 'utf8');
    assert.match(blade, /<div class="app-context-help__navigation">\s*<button[^>]*data-help-back[\s\S]*?<\/button>\s*<div class="app-context-help__lookup" data-help-combobox>/);
    assert.match(css, /\.app-context-help__navigation\s*\{[^}]*gap:5px;/);
    assert.match(blade, /<h4>Много площадок не бывает!<\/h4>/);
    assert.match(blade, /<p>Знаете баскетбольный зал или уличную площадку, которой еще нет на портале\?<\/p>/);
});
