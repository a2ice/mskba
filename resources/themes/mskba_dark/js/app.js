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
