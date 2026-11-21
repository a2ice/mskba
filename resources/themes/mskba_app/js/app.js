import './header.js';
import { bindRentalInvoiceActions, bindInvoiceIssuanceFields } from '../../../js/rental-invoice-card.js';
bindRentalInvoiceActions(document);
document.addEventListener('DOMContentLoaded', () => bindInvoiceIssuanceFields());
import './mobile-primary-bar.js';
import './cookie-consent.js';
import './features/appearance-preferences.js';
import './notification-counts.js';
import './favorite-counts.js';
import { initActionToasts } from './action-toasts.js';
import { initModalDragging } from './modal-dragging.js';
initModalDragging();
initActionToasts();
import './operator-presence.js';
import './context-bar.js';
import './context-dialogs.js';
import './privacy-distribution.js';
import './privacy-onboarding-dialog.js';
import './distribution-consent-document-dialog.js';
import './account-team-placeholder.js';
import './account-roles.js';
import './account-profile.js';
import './account-confirmation-contact-wizard.js';
import './account-confirmation-profile.js';
import './account-confirmation-review.js';
import './account-messages.js';
import './account-dashboard.js';
import './account-dashboard-reorder.js';
import './account-thread-chat.js';
import './account-team-request-chat.js';
import './account-booking-conversation.js';
import './account-mobile-sticky.js';
import './account-direct-messages.js';
import './account-contacts-add.js';
import './account-contacts-delete.js';
import './account-delete.js';
import './account-contacts-resend.js';
import './account-contacts-expiry.js';
import './profile-thread-invite.js';
import { createApp } from 'vue';
import AuthDialog from './components/AuthDialog.vue';
import PlayIntentDialog from './components/PlayIntentDialog.vue';
import './play-interest-continuation.js';
import './play-interest-mine.js';
import './venue-interest-summary.js';
import './venue-detail.js';
import './venue-choice.js';
import './venue-rental-search.js';
import './venue-play-funnel.js';
import './event-share.js';
import './event-quick-create.js';
import '../../../js/event-social-wall.js';
import './quick-event-venue-selector.js';
import './venue-favorite-main.js';
import './venue-rental-review.js';
import './venue-editor.js';
import './group-offer-copy.js';
import './contracts-workspace.js';
import '../css/contracts-workspace.css';
import './venue-map-dialog.js';
import './features/home-venues-map-expand.js';
import './features/site-media-lqip.js';

import './player-profile-workspace.js';
import './player-generation-options.js';
// Reusable Git-owned studio: lazy interactive modal + resume after auth/onboarding.
import './uniforms/bootstrap.js';
import '../css/player-generation-options.css';
import './venue-create-validation.js';
import './balance-topup.js';
import './venue-create-name.js';
// Reuse the production location suggestion and reverse-geolocation adapter.
import '../../mskba_dark/js/features/address-suggest.js';
import '../css/player-profile-workspace.css';
import './account-favorites.js';
import './venue-favorites.js';
import './venue-catalog.js';
import './shop-categories.js';
import './shop-price-range.js';
import '../css/account-collection.css';

const authRoot = document.querySelector('[data-mskba-auth-dialog]');
if (authRoot) {
    const options = JSON.parse(authRoot.dataset.options || '{}');
    createApp(AuthDialog, { options }).mount(authRoot);
}

// Mount the same universal dialog once, even on pages with only favorite hearts.
const interestRoot = document.querySelector('[data-mskba-play-intent-root]') || (() => {
    const root=document.createElement('div');
    root.dataset.mskbaPlayIntentRoot='1';
    document.body.appendChild(root);
    return root;
})();
{
    const options=interestRoot.dataset.options?JSON.parse(interestRoot.dataset.options):{
        authenticated:document.querySelector('meta[name="mskba-authenticated"]')?.content==='1',
        endpoints:{
            favoriteStatuses:'/favorites/venue-statuses',favoritesToggle:'/favorites/toggle',
            store:'/account/play-intents',base:'/account/play-intents',
            amenities:'/play-intents/amenities',venuesSearch:'/venues/search',
            channels:'/account/subscription-channels',mine:'/account/subscriptions',
        },
    };
    createApp(PlayIntentDialog,{options}).mount(interestRoot);
}
// Favorite hearts for venues and other supported entity types are handled by
// venue-favorites.js; the single Vue dialog only provides post-save context.

// Inertia is intentionally bootstrapped only on the isolated ui-preview route.
// Legacy theme DOM enhancers and jQuery are not imported here.


// Deep link from the event page to the same read-only rental calendar.
const eventAvailabilityUrl = new URL(window.location.href);
const eventAvailabilityDialog = document.querySelector('[data-rental-search-dialog]');
if (eventAvailabilityDialog && eventAvailabilityUrl.searchParams.get('availability') === '1') {
    const query = eventAvailabilityUrl.searchParams;
    const date = query.get('availability_date') || '';
    const time = query.get('availability_time') || '';
    const courtId = query.get('availability_court') || '';
    eventAvailabilityDialog.dispatchEvent(new CustomEvent('mskba:availability-open', {detail:{
        date: /^\d{4}-\d{2}-\d{2}$/.test(date) ? date : '',
        time: /^([01]\d|2[0-3]):[0-5]\d$/.test(time) ? time : '',
        courtId: /^\d+$/.test(courtId) ? courtId : '',
    }}));
    for (const key of ['availability','availability_date','availability_time','availability_court']) query.delete(key);
    history.replaceState(history.state, '', eventAvailabilityUrl);
}
