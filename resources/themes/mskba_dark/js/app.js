import $ from 'jquery';

window.$ = $;
window.jQuery = $;

const csrfToken = $('meta[name="csrf-token"]').attr('content');

$.ajaxSetup({
    headers: {
        'X-CSRF-TOKEN': csrfToken,
    },
});

import './core/ui-handlers.js';
// import './features/auth.js';

var header,
    headerHeight = 0;

// Adjust first screen padding on load and resize to prevent content from being hidden behind the fixed header
function paddingFirstScreen() {
    header = $('.site-header');
    headerHeight = header.outerHeight() + 100;
    $('.first-screen').css('padding-top', headerHeight);
}

$(window).on('load resize', function() {
    paddingFirstScreen();
});