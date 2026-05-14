import $ from 'jquery';

window.$ = $;
window.jQuery = $;

const csrfToken = $('meta[name="csrf-token"]').attr('content');

if (csrfToken) {
    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': csrfToken
        }
    });
}

import './core/ui-handlers.js';
import './features/auth.js';

var header,
    headerHeight = 0;

$(window).on('load resize', function() {
    header = $('.site-header');
    headerHeight = header.outerHeight();
    $('.first-screen').css('padding-top', headerHeight);
});