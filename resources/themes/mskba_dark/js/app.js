import $ from 'jquery';
import '../css/pages/team-roster-save.css';
import '../css/pages/team-pending-invitations.css';
import '../css/pages/team-member-panels.css';
import '../css/pages/game-live.css';
import '../css/pages/notification-toasts.css';
import '../css/pages/event-wizard.css';
import '../css/pages/event-wizard-mobile-actions-fix.css';

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
import './features/account-privacy.js';
import './features/account-telegram-link.js';
import './features/score-range.js';
import './features/tooltips.js';
import './features/catalog-filter-defaults.js';
import './features/mobile-sidebar-navigation.js';
import './features/image-upload.js';
import './features/telegram-mini-app.js';
import './features/address-suggest.js';
import './features/entity-predictive-search.js';
import './features/venue-map.js';
import './features/venue-catalog.js';
import './features/team-catalog.js';
import './features/team-name-suggestion.js';
import './features/team-pending-invitations.js';
import './features/team-management.js';
import './features/team-member-card-details.js';
import './features/venue-show.js';
import './features/venue-schedule-form.js';
import './features/event-create-form.js';
import './features/event-wizard-state-restore.js';
import './features/event-wizard.js';
import './features/event-wizard-venue.js';
import './features/standalone-game-create.js';
import './features/tournament-form.js';
import './features/tournament-application.js';
import './features/tournament-management.js';
import './features/tournament-check-in.js';
import './features/event-show.js';
import './features/game-shot-quick-action.js';
import './features/game-control.js';
import './features/game-lifecycle.js';
import './features/game-live.js';
import './features/standalone-game-recruitment.js';
import './features/notification-toasts.js';
import './features/coordination-form.js';
import './features/venue-selector.js';
import './features/embedded-entity-preview.js';
import './features/site-summary.js';
import './features/reactions.js';
import './features/admin-action-modals.js';
import './features/admin-venue-bulk-actions.js';
import './features/admin-user-bulk-actions.js';
import './features/admin-venue-duplicates.js';

import TomSelect from 'tom-select';
import 'tom-select/dist/css/tom-select.css';

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.predictive_select').forEach((select) => {
        new TomSelect(select, {
            create: false,
            maxItems: 1,
            placeholder: select.dataset.placeholder || 'Начните вводить...',
        });
    });

    document.querySelectorAll('.metro_select').forEach((select) => {
        new TomSelect(select, {
            create: false,
            placeholder: 'Начните вводить станцию метро...',
            plugins: ['remove_button'],
            render: {
                option(data, escape) {
                    return metroOptionTemplate(data, escape);
                },
                item(data, escape) {
                    return metroOptionTemplate(data, escape);
                },
            },
        });
    });
});

function metroOptionTemplate(data, escape) {
    const color = data.lineColor || '#666666';
    const lineName = data.lineName
        ? `<span class="metro-option__line">${escape(data.lineName)}</span>`
        : '';

    return `
        <div class="metro-option">
            <span class="metro-dot" style="background:${color};"></span>
            <span class="metro-option__name">${escape(data.text)}</span>
            ${lineName}
        </div>
    `;
}

var header,
    headerHeight = 0;
const HEADER_BACKGROUND_SCROLL_DISTANCE = 360;

function paddingFirstScreen() {
    header = $('.site-header');
    headerHeight = header.outerHeight();
    document.documentElement.style.setProperty('--site-header-height', `${Math.ceil(headerHeight || 0)}px`);
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