import './header.js';
import './context-bar.js';
import './privacy-distribution.js';
import './privacy-onboarding-dialog.js';
import { createApp } from 'vue';
import AuthDialog from './components/AuthDialog.vue';

const authRoot = document.querySelector('[data-mskba-auth-dialog]');
if (authRoot) {
    const options = JSON.parse(authRoot.dataset.options || '{}');
    createApp(AuthDialog, { options }).mount(authRoot);
}

// Inertia is intentionally bootstrapped only on the isolated ui-preview route.
// Legacy theme DOM enhancers and jQuery are not imported here.
