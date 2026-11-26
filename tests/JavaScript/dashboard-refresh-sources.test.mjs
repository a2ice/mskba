import test from 'node:test';
import assert from 'node:assert/strict';
import { affectedDashboardSources, mergeDashboardRefresh, sameDashboardIds } from '../../resources/themes/mskba_app/js/dashboard-refresh-sources.js';

test('only visible affected sources are requested, including rental action dependency', () => {
    const visible = ['wallet_summary', 'my_rental_requests', 'needs_attention', 'wallet_summary'];
    assert.deepEqual(affectedDashboardSources({ sources: ['wallet_summary'] }, visible), ['wallet_summary']);
    assert.deepEqual(affectedDashboardSources({ sources: ['my_rental_requests'] }, visible), ['my_rental_requests', 'needs_attention']);
    assert.deepEqual(affectedDashboardSources({ sources: ['venue_invitations'] }, visible), []);
});
test('catalogue and malformed invalidations require full reconciliation', () => {
    assert.equal(affectedDashboardSources({ catalogue: true }, []), null);
    assert.equal(affectedDashboardSources({ catalog_changed: true, sources: ['wallet_summary'] }, ['wallet_summary']), null);
    assert.equal(affectedDashboardSources({ sources: [1] }, []), null);
});
test('bursts union sources and full refresh dominates', () => {
    assert.deepEqual(mergeDashboardRefresh(['wallet_summary'], ['my_interests', 'wallet_summary']), ['wallet_summary', 'my_interests']);
    assert.equal(mergeDashboardRefresh(null, ['wallet_summary']), null);
    assert.equal(mergeDashboardRefresh(['wallet_summary'], null), null);
});
test('catalogue equality preserves drag order but rejects additions, removals and duplicates', () => {
    assert.equal(sameDashboardIds(['2', '1'], [1, 2]), true);
    assert.equal(sameDashboardIds(['2', '1'], [1]), false);
    assert.equal(sameDashboardIds(['2', '1'], [1, 3]), false);
    assert.equal(sameDashboardIds(['2', '1'], [1, 1]), false);
});
