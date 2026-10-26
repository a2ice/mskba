import './header.js';
import './features/appearance-preferences.js';
import './notification-counts.js';
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
import './features/home-venues-map-expand.js';
import './account-favorites.js';
import './venue-favorites.js';

const authRoot = document.querySelector('[data-mskba-auth-dialog]');
if (authRoot) {
    const options = JSON.parse(authRoot.dataset.options || '{}');
    createApp(AuthDialog, { options }).mount(authRoot);
}

const interestRoot = document.querySelector('[data-mskba-play-intent-root]');
if (interestRoot) {
    const options = JSON.parse(interestRoot.dataset.options || '{}');
    createApp(PlayIntentDialog, { options }).mount(interestRoot);
}

// Inertia is intentionally bootstrapped only on the isolated ui-preview route.
// Legacy theme DOM enhancers and jQuery are not imported here.
