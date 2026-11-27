import { test } from 'node:test';
import assert from 'node:assert/strict';
import { validatePlan, selectDestinations, verifySelection, delivery } from '../../scripts/editorial-news-agent-bridge.mjs';

const plan = { alias: 'news', title: 'Title', summary: 'Summary', content: 'Content',
    cover: 'ops/editorial-news/covers/news.jpg', telegram_chat_titles: ['MSKBA Official'], vk_group_id: 174334075 };
const registry = { telegram: [{ id: 7, title: 'MSKBA official' }, { id: 8, title: 'Other' }], vk: { available: true, group_id: 174334075 } };
test('reject arbitrary paths and request fields', () => {
    assert.throws(() => validatePlan({ ...plan, cover: '../../secret.jpg' }));
    assert.throws(() => validatePlan({ ...plan, url: 'https://example.com' }));
    assert.throws(() => validatePlan({ ...plan, telegram_chat_titles: ['Official', 'official'] }));
    assert.equal(validatePlan(plan), plan);
});
test('only select explicitly named unique targets and checked VK id', () => {
    assert.deepEqual(selectDestinations(plan, registry), { telegram_chat_ids: [7], publish_in_vk: true, vk_group_id: 174334075 });
    assert.throws(() => selectDestinations(plan, { ...registry, telegram: [...registry.telegram, { id: 9, title: 'MSKBA Official' }] }));
    assert.throws(() => selectDestinations(plan, { ...registry, vk: { available: false } }));
    assert.throws(() => selectDestinations({ ...plan, vk_group_id: 123 }, registry));
    const expired = { ...registry, vk: { available: false, can_defer: true, group_id: 174334075 } };
    assert.throws(() => selectDestinations(plan, expired));
    assert.equal(selectDestinations({ ...plan, allow_vk_wait: true }, expired).defer_vk_until_authorized, true);
});
test('pending is not delivered, mismatch fails closed', () => {
    const selection = selectDestinations(plan, registry);
    const item = { status: 'published', url: 'https://mskba.ru/news', telegram_publications: [{ chat_id: 7, status: 'published', message_id: 11 }], vk_publication: { group_id: 174334075, status: 'pending', post_id: null } };
    assert.equal(delivery(item, selection), 'pending');
    assert.equal(delivery({ ...item, vk_publication: { ...item.vk_publication, status: 'awaiting_auth' } }, selection), 'awaiting_auth');
    assert.equal(delivery({ ...item, vk_publication: { ...item.vk_publication, status: 'published', post_id: 12 } }, selection), 'published');
    assert.equal(delivery({ ...item, vk_publication: { ...item.vk_publication, status: 'unknown' } }, selection), 'failed');
    assert.throws(() => verifySelection({ ...item, telegram_publications: [{ chat_id: 8 }] }, selection));
});


test('optional SEO fields are accepted and constrained', () => {
    const valid = { alias: 'basketball', title: 'Basketball', summary: 'News', content: 'Text',
        cover: 'ops/editorial-news/covers/news.jpg', telegram_chat_titles: [], vk_group_id: null,
        meta_title: 'SEO headline', meta_description: 'Readable summary', meta_keywords: 'basketball, Moscow' };
    assert.equal(validatePlan(valid).meta_title, 'SEO headline');
    assert.throws(() => validatePlan({ ...valid, meta_description: 'x'.repeat(321) }), /Invalid meta_description/);
    assert.throws(() => validatePlan({ ...valid, meta_keywords: 7 }), /Invalid meta_keywords/);
});


test('cover is optional for every channel', () => {
    const { cover, ...base } = plan;
    const site = { ...base, telegram_chat_titles: [], vk_group_id: null };
    assert.equal(validatePlan(site), site);
    assert.equal(validatePlan(base), base);
    assert.equal(validatePlan({ ...site, telegram_chat_titles: ['MSKBA Official'] }).cover, undefined);
    assert.equal(validatePlan({ ...site, vk_group_id: 174334075 }).cover, undefined);
    assert.throws(() => validatePlan({ ...site, cover: null }), /Invalid cover path/);
    assert.throws(() => validatePlan({ ...site, cover: '../../secret.jpg' }), /Invalid cover path/);
    assert.throws(() => validatePlan({ ...site, allow_vk_wait: true }), /Invalid VK deferral/);
    assert.equal(validatePlan({ ...site, cover }).cover, cover);
});

test('site-only delivery needs no social post or cover, but rejects unexpected destinations', () => {
    const selection = { telegram_chat_ids: [], publish_in_vk: false };
    const item = { status: 'published', url: 'https://mskba.ru/feed/news', has_cover: false,
        telegram_publications: [], vk_publication: null };
    assert.equal(delivery(item, selection), 'published');
    assert.throws(() => delivery({ ...item, telegram_publications: [{ chat_id: 7 }] }, selection), /recipients/);
    assert.throws(() => delivery({ ...item, vk_publication: { group_id: 174334075 } }, selection), /recipients/);
});

test('VK text requires configured wall, not photo authorization; photo mode never falls back', () => {
    const { cover, ...textPlan } = plan;
    const expired = { ...registry, vk: { available: false, text_available: true, can_defer: true, group_id: 174334075 } };
    assert.deepEqual(selectDestinations(textPlan, expired), { telegram_chat_ids: [7], publish_in_vk: true, vk_group_id: 174334075 });
    assert.throws(() => selectDestinations(plan, expired), /unavailable/);
    assert.throws(() => selectDestinations(textPlan, expired, true), /unavailable/);
    assert.throws(() => selectDestinations(textPlan, { ...expired, vk: { ...expired.vk, text_available: false } }), /unavailable/);
});
