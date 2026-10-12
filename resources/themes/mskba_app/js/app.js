import './header.js';
import './notification-counts.js';
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
import './account-mobile-sticky.js';
import './account-direct-messages.js';
import './account-contacts-add.js';
import { createApp } from 'vue';
import AuthDialog from './components/AuthDialog.vue';

const authRoot = document.querySelector('[data-mskba-auth-dialog]');
if (authRoot) {
    const options = JSON.parse(authRoot.dataset.options || '{}');
    createApp(AuthDialog, { options }).mount(authRoot);
}

// Inertia is intentionally bootstrapped only on the isolated ui-preview route.
// Legacy theme DOM enhancers and jQuery are not imported here.
