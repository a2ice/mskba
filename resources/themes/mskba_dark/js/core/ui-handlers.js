import $ from 'jquery';

const MODAL_URL_PARAM = 'modal';
const MODAL_STATE_URL_PARAM = 'modal_state';
const MODAL_TRAY_URL_PARAM = 'modal_tray';
const MODAL_STATE_MINIMIZED = 'minimized';
const MODAL_FOCUSABLE_SELECTOR = [
    'a[href]',
    'button:not([disabled])',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

const handlers = {
    toggleClass(trigger, params) {
        const paramsStr = params || '';
        if (!paramsStr) {
            return;
        }

        const parsedParams = paramsStr.split(';');
        const target = parsedParams[1] ? $(parsedParams[1]) : $(trigger);
        target.toggleClass(parsedParams[0]);

        if (parsedParams[0] === 'nav-shown' && target.is('body')) {
            const isOpen = target.hasClass('nav-shown');

            $('[data-nav-toggle]')
                .attr('aria-expanded', isOpen ? 'true' : 'false')
                .attr('aria-label', isOpen ? 'Закрыть основное меню' : 'Открыть основное меню');
        }
    },

    modal(trigger) {
        const triggerElement = $(trigger);
        const action = trigger.getAttribute('data-modal-action') || 'open';
        const modalTarget = trigger.getAttribute('data-modal-target');
        const modalSection = trigger.getAttribute('data-modal-section') || '';
        const modalRedirectUrl = trigger.getAttribute('data-auth-redirect-url') || '';
        const modal = modalTarget ? $('[data-modal="' + modalTarget + '"]') : triggerElement.closest('[data-modal]');

        if (!modal.length) {
            return;
        }

        if (action === 'close') {
            closeModal(modal);
            return;
        }

        if (action === 'minimize') {
            minimizeModal(modal);
            return;
        }

        if (action === 'restore') {
            restoreModal(modal);
            return;
        }

        modal.data('modalInitialSection', modalSection);
        modal.data('authRedirectUrl', modalRedirectUrl);
        modal.data('modalTrigger', trigger);
        $('body').removeClass('nav-shown');
        openModal(modal);
    },

    closeAlert(trigger) {
        const alert = $(trigger).closest('.alert');
        if (!alert.length) {
            return;
        }

        alert.remove();
    },

    historyBack(trigger) {
        if (canUseNavigationApiBack()) {
            window.navigation.back();
            return;
        }

        if (!supportsNavigationApi() && canUseLegacySameSiteHistoryBack()) {
            window.history.back();
            return;
        }

        window.location.assign(resolveHistoryFallback(trigger));
    },
};

function supportsNavigationApi() {
    return Boolean(
        window.navigation
        && typeof window.navigation.back === 'function'
        && typeof window.navigation.canGoBack === 'boolean'
    );
}

function canUseNavigationApiBack() {
    return supportsNavigationApi() && window.navigation.canGoBack;
}

function canUseLegacySameSiteHistoryBack() {
    if (window.history.length <= 1 || !document.referrer) {
        return false;
    }

    try {
        const referrerUrl = new URL(document.referrer);
        return referrerUrl.origin === window.location.origin;
    } catch (error) {
        return false;
    }
}

function resolveHistoryFallback(trigger) {
    const explicitFallback = trigger?.getAttribute?.('data-history-fallback');
    if (explicitFallback) {
        return explicitFallback;
    }

    const breadcrumbLinks = document.querySelectorAll('.page-breadcrumbs__link[href]');
    const breadcrumbParent = breadcrumbLinks[breadcrumbLinks.length - 1]?.getAttribute('href');
    if (breadcrumbParent) {
        return breadcrumbParent;
    }

    const currentUrl = new URL(window.location.href);
    const segments = currentUrl.pathname.split('/').filter(Boolean);

    if (segments.length <= 1) {
        return '/';
    }

    segments.pop();
    return `/${segments.join('/')}`;
}

function bindActionHandlers() {
    $(document).on('click', '[data-handler]', function(e) {
        const trigger = $(this);
        const handlerName = trigger.data('handler');
        const handler = handlers[handlerName];
        const params = trigger.data('params') || '';

        if (typeof handler !== 'function') {
            return;
        }

        e.preventDefault();

        handler(this, params);
    });
}

function bindModalBackgroundClose() {
    $(document).on('click', '.modal', function(event) {
        if ($(this).hasClass('is-minimized')) {
            return;
        }

        const dialog = $(event.target).closest('.modal__dialog');
        if (dialog.length) {
            return;
        }

        closeModal($(this));
    });
}

function bindModalEscClose() {
    $(document).on('keydown', function(event) {
        if (event.key === 'Tab') {
            trapModalFocus(event);
            return;
        }

        if (event.key !== 'Escape') {
            return;
        }

        const openedModal = $('.modal.is-open:not(.is-minimized)').last();
        if (!openedModal.length) {
            return;
        }

        closeModal(openedModal);
    });
}

function modalElement(modal) {
    if (modal?.jquery) {
        return modal.first();
    }

    return $(modal).first();
}

function modalTitleSource(modal) {
    const sources = [...modal.get(0).querySelectorAll('[data-modal-title-source]')];

    return sources.find((source) => !source.closest('[hidden]')) || sources[0] || null;
}

function syncModalTitle(modal) {
    const heading = modal.find('[data-modal-title]').first();
    const source = modalTitleSource(modal);

    if (!heading.length) {
        return;
    }

    const title = source?.textContent?.trim() || 'Окно';
    if (heading.text() !== title) {
        heading.text(title);
    }

    updateModalTrayTab(modal);
}

function initializeModal(modalInput) {
    const modal = modalElement(modalInput);
    const element = modal.get(0);

    if (!element || element.dataset.modalInitialized === '1') {
        return modal;
    }

    element.dataset.modalInitialized = '1';
    const titleSources = element.querySelectorAll('.modal_title:not([data-modal-title]), [id^="modal-title-"]');
    titleSources.forEach((title) => {
        title.dataset.modalTitleSource = '1';
        title.classList.add('modal__source-title');
    });

    const heading = element.querySelector('[data-modal-title]');
    if (heading) {
        heading.dataset.modalFallbackTitle = element.dataset.modal || 'Окно';
    }

    const footerSource = element.querySelector('[data-modal-footer]');
    let footer = element.querySelector('[data-modal-footer-container]');
    if (footerSource && !footer) {
        footer = document.createElement('footer');
        footer.className = 'modal__footer';
        footer.dataset.modalFooterContainer = '';
        element.querySelector('.modal__dialog')?.append(footer);
    }
    if (footerSource && footer && footerSource !== footer) {
        footer.append(footerSource);
    }

    syncModalTitle(modal);

    const observer = new MutationObserver(() => syncModalTitle(modal));
    observer.observe(element, {
        subtree: true,
        childList: true,
        characterData: true,
        attributes: true,
        attributeFilter: ['hidden'],
    });

    return modal;
}

function updateMinimizeControl(modal, minimized) {
    const control = modal.find('[data-modal-action="minimize"], [data-modal-action="restore"]').first();
    if (!control.length) {
        return;
    }

    const action = minimized ? 'restore' : 'minimize';
    const label = minimized ? 'Развернуть окно' : 'Свернуть окно';
    control.attr({
        'data-modal-action': action,
        'aria-label': label,
        title: minimized ? 'Развернуть' : 'Свернуть',
    });
    control.find('i').attr('class', minimized ? 'ti ti-window-maximize' : 'ti ti-minus');
}

function refreshBodyModalState() {
    const expanded = $('.modal.is-open:not(.is-minimized)');
    const body = $('body');
    const hasContentModal = expanded.filter(function () {
        return $(this).closest('.site-content').length > 0;
    }).length > 0;

    body[expanded.length > 0 ? 'addClass' : 'removeClass']('modal-open');
    body[hasContentModal ? 'addClass' : 'removeClass']('content-modal-open');
}

function modalId(modal) {
    return String(modal.attr('data-modal') || '').trim();
}

function modalPersistsInUrl(modal) {
    return modal.attr('data-modal-persist-url') !== 'false';
}

function modalById(id) {
    return $('[data-modal]').filter(function () {
        return modalId($(this)) === id;
    }).first();
}

function syncModalUrl() {
    if (!window.history?.replaceState) {
        return;
    }

    const persistent = $('.modal.is-open').filter(function () {
        return modalPersistsInUrl($(this));
    });
    const expanded = persistent.filter(':not(.is-minimized)').last();
    const minimized = persistent.filter('.is-minimized');
    const minimizedIdSet = new Set(minimized.map(function () {
        return modalId($(this));
    }).get().filter(Boolean));
    const minimizedIds = $('[data-modal-tray-tab]').map(function () {
        return this.getAttribute('data-modal-tray-tab');
    }).get().filter((id) => minimizedIdSet.has(id));
    minimized.each(function () {
        const id = modalId($(this));
        if (id && !minimizedIds.includes(id)) {
            minimizedIds.push(id);
        }
    });

    const url = new URL(window.location.href);
    const expandedId = modalId(expanded);
    if (expandedId) {
        url.searchParams.set(MODAL_URL_PARAM, expandedId);
    } else {
        url.searchParams.delete(MODAL_URL_PARAM);
    }
    url.searchParams.delete(MODAL_STATE_URL_PARAM);
    if (minimizedIds.length) {
        url.searchParams.set(MODAL_TRAY_URL_PARAM, minimizedIds.join(','));
    } else {
        url.searchParams.delete(MODAL_TRAY_URL_PARAM);
    }

    window.history.replaceState(window.history.state, '', url);
}

function withoutModalState(url = window.location.href) {
    const cleanUrl = new URL(url, window.location.origin);
    cleanUrl.searchParams.delete(MODAL_URL_PARAM);
    cleanUrl.searchParams.delete(MODAL_STATE_URL_PARAM);
    cleanUrl.searchParams.delete(MODAL_TRAY_URL_PARAM);

    return cleanUrl.toString();
}

function ensureModalTray() {
    let tray = $('[data-modal-tray]').first();
    if (tray.length) {
        return tray;
    }

    tray = $('<aside>', {
        class: 'modal-tray',
        'data-modal-tray': '',
        'aria-label': 'Свёрнутые окна',
        hidden: true,
    }).append($('<div>', {
        class: 'modal-tray__tabs',
        role: 'tablist',
        'data-modal-tray-tabs': '',
    }));
    $('body').append(tray);

    if (window.ResizeObserver) {
        const observer = new ResizeObserver(() => updateModalTrayOffset());
        observer.observe(tray.get(0));
    }

    return tray;
}

function modalTrayTitle(modal) {
    return modal.find('[data-modal-title]').first().text().trim() || modalId(modal) || 'Окно';
}

function modalTrayTab(modal) {
    const id = modalId(modal);
    if (!id) {
        return $();
    }

    return ensureModalTray().find('[data-modal-tray-tab]').filter(function () {
        return this.getAttribute('data-modal-tray-tab') === id;
    }).first();
}

function ensureModalTrayTab(modal) {
    let tab = modalTrayTab(modal);
    if (tab.length) {
        return tab;
    }

    const id = modalId(modal);
    if (!id) {
        return $();
    }

    const restore = $('<button>', {
        class: 'modal-tray__restore',
        type: 'button',
        role: 'tab',
        title: 'Развернуть окно',
        'aria-label': `Развернуть окно «${modalTrayTitle(modal)}»`,
        'data-modal-tray-restore': id,
    }).append(
        $('<i>', { class: 'ti ti-window-maximize', 'aria-hidden': 'true' }),
        $('<span>', { class: 'modal-tray__title', text: modalTrayTitle(modal) }),
    );
    const close = $('<button>', {
        class: 'modal-tray__close',
        type: 'button',
        title: 'Закрыть окно',
        'aria-label': `Закрыть окно «${modalTrayTitle(modal)}»`,
        'data-modal-tray-close': id,
    }).append($('<i>', { class: 'ti ti-x', 'aria-hidden': 'true' }));

    tab = $('<div>', {
        class: 'modal-tray__tab',
        'data-modal-tray-tab': id,
    }).append(restore, close);
    ensureModalTray().find('[data-modal-tray-tabs]').append(tab);

    return tab;
}

function updateModalTrayTab(modalInput) {
    const modal = modalElement(modalInput);
    if (!modal.hasClass('is-minimized')) {
        return;
    }

    const title = modalTrayTitle(modal);
    const tab = ensureModalTrayTab(modal);
    tab.find('.modal-tray__title').text(title);
    tab.find('[data-modal-tray-restore]').attr('aria-label', `Развернуть окно «${title}»`);
    tab.find('[data-modal-tray-close]').attr('aria-label', `Закрыть окно «${title}»`);
}

function updateModalTrayOffset() {
    const tray = $('[data-modal-tray]').first();
    const height = tray.length && !tray.prop('hidden') ? Math.ceil(tray.get(0).getBoundingClientRect().height) : 0;
    document.documentElement.style.setProperty('--modal-tray-height', `${height}px`);
}

function refreshModalTray() {
    const tray = ensureModalTray();
    const minimized = $('.modal.is-open.is-minimized');
    const ids = new Set(minimized.map(function () {
        return modalId($(this));
    }).get().filter(Boolean));

    tray.find('[data-modal-tray-tab]').each(function () {
        if (!ids.has(this.getAttribute('data-modal-tray-tab'))) {
            $(this).remove();
        }
    });
    minimized.each(function () {
        updateModalTrayTab($(this));
    });

    const hasTabs = ids.size > 0;
    tray.prop('hidden', !hasTabs).css('--modal-tray-count', Math.max(ids.size, 1));
    $('body').toggleClass('has-modal-tray', hasTabs);
    updateModalTrayOffset();
}

function bindModalTray() {
    $(document).on('click', '[data-modal-tray-restore]', function () {
        restoreModal(modalById(this.getAttribute('data-modal-tray-restore')), { exclusive: true });
    });

    $(document).on('click', '[data-modal-tray-close]', function () {
        closeModal(modalById(this.getAttribute('data-modal-tray-close')), { restoreFocus: false });
    });

    $(document).on('keydown', '[data-modal-tray-restore]', function (event) {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) {
            return;
        }

        const tabs = $('[data-modal-tray-restore]:visible').get();
        const currentIndex = tabs.indexOf(this);
        if (currentIndex < 0 || tabs.length < 2) {
            return;
        }

        event.preventDefault();
        const nextIndex = event.key === 'Home'
            ? 0
            : event.key === 'End'
                ? tabs.length - 1
                : (currentIndex + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
        tabs[nextIndex].focus({ preventScroll: true });
        tabs[nextIndex].scrollIntoView({ inline: 'nearest', block: 'nearest' });
    });
}

function focusModal(modal) {
    window.requestAnimationFrame(() => {
        const autofocus = modal.find('[autofocus]:visible').first().get(0);
        const dialog = modal.find('.modal__dialog').first().get(0);
        (autofocus || dialog)?.focus({ preventScroll: true });
    });
}

function restoreModalTriggerFocus(modal) {
    const trigger = modal.data('modalTrigger');
    if (trigger instanceof HTMLElement && trigger.isConnected) {
        trigger.focus({ preventScroll: true });
    }
}

function openModal(modalInput, options = {}) {
    const modal = initializeModal(modalInput);
    if (!modal.length) {
        return;
    }

    const wasOpen = modal.hasClass('is-open');
    if (wasOpen && modal.hasClass('is-minimized') && !options.minimized) {
        restoreModal(modal, options);
        return;
    }
    if (wasOpen && !modal.hasClass('is-minimized') && options.minimized) {
        minimizeModal(modal, options);
        return;
    }

    modal.removeAttr('hidden').addClass('is-open');
    modal[options.minimized ? 'addClass' : 'removeClass']('is-minimized');
    modal.find('.modal__dialog').attr('aria-modal', options.minimized ? 'false' : 'true');
    updateMinimizeControl(modal, Boolean(options.minimized));
    refreshBodyModalState();
    refreshModalTray();

    if (options.syncUrl !== false) {
        syncModalUrl();
    }

    if (!wasOpen) {
        $(document).trigger('modal:opened', [modal]);
    }

    if (!options.minimized) {
        focusModal(modal);
    }
}

function minimizeModal(modalInput, options = {}) {
    const modal = initializeModal(modalInput);
    if (!modal.hasClass('is-open')) {
        openModal(modal, { ...options, minimized: true });
        return;
    }

    const activeElement = document.activeElement;
    const shouldMoveFocusToTray = activeElement instanceof HTMLElement
        && modal.get(0)?.contains(activeElement);

    modal.addClass('is-minimized');
    modal.find('.modal__dialog').attr('aria-modal', 'false');
    updateMinimizeControl(modal, true);
    refreshBodyModalState();
    refreshModalTray();

    if (shouldMoveFocusToTray) {
        modalTrayTab(modal)
            .find('[data-modal-tray-restore]')
            .first()
            .get(0)
            ?.focus({ preventScroll: true });
    }

    if (options.syncUrl !== false) {
        syncModalUrl();
    }

    $(document).trigger('modal:minimized', [modal]);
}

function restoreModal(modalInput, options = {}) {
    const modal = initializeModal(modalInput);
    if (!modal.length) {
        return;
    }

    if (options.exclusive) {
        $('.modal.is-open:not(.is-minimized)').not(modal).each(function () {
            minimizeModal($(this), { syncUrl: false });
        });
    }

    if (!modal.hasClass('is-open')) {
        openModal(modal, options);
        return;
    }

    modal.removeClass('is-minimized');
    modal.find('.modal__dialog').attr('aria-modal', 'true');
    updateMinimizeControl(modal, false);
    refreshBodyModalState();
    refreshModalTray();

    if (options.syncUrl !== false) {
        syncModalUrl();
    }

    $(document).trigger('modal:restored', [modal]);
    focusModal(modal);
}

function closeModal(modalInput, options = {}) {
    const modal = modalElement(modalInput);
    if (!modal.length) {
        return;
    }

    modal.attr('hidden', true).removeClass('is-open is-minimized');
    modal.find('.modal__dialog').attr('aria-modal', 'true');
    updateMinimizeControl(modal, false);
    refreshBodyModalState();
    refreshModalTray();

    if (options.syncUrl !== false) {
        syncModalUrl();
    }

    $(document).trigger('modal:closed', [modal]);
    if (options.restoreFocus !== false) {
        restoreModalTriggerFocus(modal);
    }
}

function trapModalFocus(event) {
    const modal = $('.modal.is-open:not(.is-minimized)').last();
    if (!modal.length) {
        return;
    }

    const focusable = modal.find(MODAL_FOCUSABLE_SELECTOR).filter(':visible').get();
    if (!focusable.length) {
        event.preventDefault();
        modal.find('.modal__dialog').first().trigger('focus');
        return;
    }

    const first = focusable[0];
    const last = focusable[focusable.length - 1];
    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
    } else if (!modal.get(0).contains(document.activeElement)) {
        event.preventDefault();
        first.focus();
    }
}

function refreshModalViewport() {
    const viewport = window.visualViewport;
    const root = document.documentElement;
    root.style.setProperty('--modal-viewport-left', `${viewport?.offsetLeft || 0}px`);
    root.style.setProperty('--modal-viewport-top', `${viewport?.offsetTop || 0}px`);
    root.style.setProperty('--modal-viewport-width', `${viewport?.width || window.innerWidth}px`);
    root.style.setProperty('--modal-viewport-height', `${viewport?.height || window.innerHeight}px`);
    updateModalTrayOffset();
}

function modalStateFromUrl() {
    const url = new URL(window.location.href);
    const modalIdFromUrl = String(url.searchParams.get(MODAL_URL_PARAM) || '').trim();
    const trayIds = String(url.searchParams.get(MODAL_TRAY_URL_PARAM) || '')
        .split(',')
        .map((id) => id.trim())
        .filter(Boolean);

    if (modalIdFromUrl && url.searchParams.get(MODAL_STATE_URL_PARAM) === MODAL_STATE_MINIMIZED) {
        trayIds.unshift(modalIdFromUrl);
    }

    const uniqueTrayIds = [...new Set(trayIds)];
    const expandedId = url.searchParams.get(MODAL_STATE_URL_PARAM) === MODAL_STATE_MINIMIZED
        ? ''
        : modalIdFromUrl;

    return {
        expandedId,
        trayIds: uniqueTrayIds.filter((id) => id !== expandedId),
    };
}

function restoreModalTrayFromUrl() {
    modalStateFromUrl().trayIds.forEach((id) => {
        const modal = modalById(id);
        if (modal.length) {
            openModal(modal, { minimized: true, syncUrl: false });
        }
    });
}

function restoreExpandedModalFromUrl() {
    const modal = modalById(modalStateFromUrl().expandedId);
    if (modal.length) {
        openModal(modal, { syncUrl: false });
    }
}

function reconcileModalsWithUrl() {
    const state = modalStateFromUrl();
    const desiredIds = new Set([...state.trayIds, state.expandedId].filter(Boolean));

    $('.modal.is-open').filter(function () {
        return modalPersistsInUrl($(this));
    }).each(function () {
        if (!desiredIds.has(modalId($(this)))) {
            closeModal($(this), { syncUrl: false, restoreFocus: false });
        }
    });
    restoreModalTrayFromUrl();
    restoreExpandedModalFromUrl();
}

function bindModalUrlRestore() {
    refreshModalViewport();
    window.addEventListener('resize', refreshModalViewport);
    window.visualViewport?.addEventListener('resize', refreshModalViewport);
    window.visualViewport?.addEventListener('scroll', refreshModalViewport);
    window.addEventListener('popstate', reconcileModalsWithUrl);

    window.setTimeout(() => {
        restoreModalTrayFromUrl();
    }, 0);

    const openAfterLoad = () => window.setTimeout(() => {
        restoreExpandedModalFromUrl();
    }, 1000);

    if (document.readyState === 'complete') {
        openAfterLoad();
    } else {
        window.addEventListener('load', openAfterLoad, { once: true });
    }

    $('[data-modal-open-on-load="1"]').each(function () {
        openModal($(this));
    });
}

bindActionHandlers();
bindModalBackgroundClose();
bindModalEscClose();
bindModalTray();
bindModalUrlRestore();

window.MskbaModal = Object.freeze({
    open: (modal, options = {}) => openModal(modal, options),
    close: (modal, options = {}) => closeModal(modal, options),
    minimize: (modal, options = {}) => minimizeModal(modal, options),
    restore: (modal, options = {}) => restoreModal(modal, options),
    withoutState: (url = window.location.href) => withoutModalState(url),
});
