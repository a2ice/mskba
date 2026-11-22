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
