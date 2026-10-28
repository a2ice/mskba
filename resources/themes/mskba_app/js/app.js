import './header.js';
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
import './account-messages.js';
import './account-dashboard.js';
import './account-thread-chat.js';
import './account-mobile-sticky.js';
import './account-direct-messages.js';
import './account-contacts-add.js';
import './account-contacts-delete.js';
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
import './group-offer-copy.js';
import './venue-map-dialog.js';
import './features/home-venues-map-expand.js';
import './features/site-media-lqip.js';

import './account-favorites.js';
import './venue-favorites.js';

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
