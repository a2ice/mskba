import $ from 'jquery';

window.$ = $;
window.jQuery = $;

const csrfToken = $('meta[name="csrf-token"]').attr('content');

$.ajaxSetup({
    headers: {
        'Accept': 'application/json',
        'X-CSRF-TOKEN': csrfToken,
    },
});

import './core/ui-handlers.js';
import './features/auth.js';
import './features/countdown.js';
import './features/account-confirmation-wizard.js';
import './features/tooltips.js';

var header,
    headerHeight = 0;
const HEADER_BACKGROUND_SCROLL_DISTANCE = 360;

// Adjust first screen padding on load and resize to prevent content from being hidden behind the fixed header
function paddingFirstScreen() {
    header = $('.site-header');
    headerHeight = header.outerHeight();
    $('.first-screen').css('padding-top', headerHeight);
}

function updateHeaderBackground() {
    header = header && header.length ? header : $('.site-header');

    if (!header.length) {
        return;
    }

    const progress = Math.min(window.scrollY / HEADER_BACKGROUND_SCROLL_DISTANCE, 1);
    header.css('--header-bg-alpha', progress.toFixed(3));
}

$(window).on('load resize', function() {
    paddingFirstScreen();
    updateHeaderBackground();
});

$(window).on('scroll', updateHeaderBackground);
