import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

const source = path => readFileSync(new URL(path, import.meta.url), 'utf8');

test('venue slot payload marks ended public events using absolute end timestamp', () => {
    const handler = source('../../app/Modules/Venue/Application/UseCases/ShowVenueHandler.php');
    assert.match(handler, /isPastEvent = \$isPublicEvent && \$event->ends_at->lessThanOrEqualTo\(CarbonImmutable::now\('UTC'\)\)/);
    assert.match(handler, /'isPastEvent' => \$isPastEvent/);
    assert.match(handler, /'canJoin' => \$canJoin/);
});

test('past event cards are muted, remain navigable and preserve keyboard focus', () => {
    const blade = source('../../resources/themes/mskba_app/views/pages/venues/show.blade.php');
    const css = source('../../resources/themes/mskba_app/css/venue-detail.css');
    assert.match(blade, /app-venue-activity--past' => \$isPastEvent/);
    assert.match(blade, /data-event-past="true"/);
    assert.match(blade, /Мероприятие завершилось/);
    assert.match(blade, /data-venue-slot-open/);
    assert.doesNotMatch(blade, /@disabled\(\$isPastEvent\)/);
    assert.match(css, /\.app-venue-activity--past:hover\s*\{[^}]*border-color:var\(--line\)/);
    assert.match(css, /\.app-venue-activity:focus-visible\{outline:/);
});
