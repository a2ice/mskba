import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync} from 'node:fs';

const file = path => readFileSync(new URL(path, import.meta.url), 'utf8');

test('venue interest group is linked from the popup and has stable venue ID URL', () => {
    const routes = file('../../routes/web.php');
    const popup = file('../../resources/themes/mskba_app/views/pages/venues/show.blade.php');
    const page = file('../../resources/themes/mskba_app/views/pages/venues/interest-group.blade.php');
    const controller = file('../../app/Modules/PlayInterest/Presentation/Http/Controllers/VenueInterestGroupController.php');
    assert.match(routes, /venues\.interest-group/);
    assert.match(popup, /Перейти на страницу группы/);
    assert.match(popup, /venue' => \$venue->id/);
    assert.match(controller, /whereKey\(\$venue\)/);
    assert.match(controller, /private, no-store/);
    assert.match(page, /venueAudience\['total'\]/);
});

test('popup and group reuse identical participant partial and privacy projection', () => {
    const popup = file('../../resources/themes/mskba_app/views/pages/venues/show.blade.php');
    const page = file('../../resources/themes/mskba_app/views/pages/venues/interest-group.blade.php');
    const partial = file('../../resources/themes/mskba_app/views/pages/venues/partials/interest-users-list.blade.php');
    const service = file('../../app/Modules/PlayInterest/Application/Services/VenueSubscriptionAudience.php');
    assert.match(popup, /pages\.venues\.partials\.interest-users-list/);
    assert.match(page, /pages\.venues\.partials\.interest-users-list/);
    assert.match(partial, /can_view_details/);
    assert.match(partial, /public/);
    assert.match(service, /show_on_venue/);
});


test('static group list does not register itself as a modal with close() handler', () => {
    const js = file('../../resources/themes/mskba_app/js/venue-detail.js');
    const page = file('../../resources/themes/mskba_app/views/pages/venues/interest-group.blade.php');
    assert.match(page, /data-venue-interest-group-users/);
    assert.doesNotMatch(page, /data-venue-interests-dialog/);
    assert.match(js, /playersList = playersDialog \|\| document\.querySelector\('\[data-venue-interest-group-users\]'\)/);
    assert.match(js, /playersList\?\.addEventListener\('click'/);
});
