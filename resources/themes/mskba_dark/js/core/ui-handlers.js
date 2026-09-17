import $ from 'jquery';

const MODAL_URL_PARAM = 'modal';
const MODAL_STATE_URL_PARAM = 'modal_state';
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
        const action = triggerElement.data('modalAction') || triggerElement.data('modal-action') || 'open';
        const modalTarget = triggerElement.data('modalTarget') || triggerElement.data('modal-target');
        const modalSection = triggerElement.data('modalSection') || triggerElement.data('modal-section') || '';
        const modalRedirectUrl = triggerElement.data('authRedirectUrl') || triggerElement.data('auth-redirect-url') || '';
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

        const openedModal = $('.modal.is-open').last();
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

function replaceModalUrl(modal, state = null) {
    if (!window.history?.replaceState || modal.attr('data-modal-persist-url') === 'false') {
        return;
    }

    const id = String(modal.data('modal') || '');
    if (!id) {
        return;
    }

    const url = new URL(window.location.href);
    url.searchParams.set(MODAL_URL_PARAM, id);
    if (state === MODAL_STATE_MINIMIZED) {
        url.searchParams.set(MODAL_STATE_URL_PARAM, MODAL_STATE_MINIMIZED);
    } else {
        url.searchParams.delete(MODAL_STATE_URL_PARAM);
    }
    window.history.replaceState(window.history.state, '', url);
}

function clearModalUrl(modal) {
    if (!window.history?.replaceState || modal.attr('data-modal-persist-url') === 'false') {
        return;
    }

    const url = new URL(window.location.href);
    if (url.searchParams.get(MODAL_URL_PARAM) !== String(modal.data('modal') || '')) {
        return;
    }

    const remaining = $('.modal.is-open').last();
    if (remaining.length && !remaining.is(modal)) {
        replaceModalUrl(remaining, remaining.hasClass('is-minimized') ? MODAL_STATE_MINIMIZED : null);
        return;
    }

    url.searchParams.delete(MODAL_URL_PARAM);
    url.searchParams.delete(MODAL_STATE_URL_PARAM);
    window.history.replaceState(window.history.state, '', url);
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

    modal.removeAttr('hidden').addClass('is-open');
    modal[options.minimized ? 'addClass' : 'removeClass']('is-minimized');
    modal.find('.modal__dialog').attr('aria-modal', options.minimized ? 'false' : 'true');
    updateMinimizeControl(modal, Boolean(options.minimized));
    refreshBodyModalState();

    if (options.syncUrl !== false) {
        replaceModalUrl(modal, options.minimized ? MODAL_STATE_MINIMIZED : null);
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

    modal.addClass('is-minimized');
    modal.find('.modal__dialog').attr('aria-modal', 'false');
    updateMinimizeControl(modal, true);
    refreshBodyModalState();

    if (options.syncUrl !== false) {
        replaceModalUrl(modal, MODAL_STATE_MINIMIZED);
    }

    restoreModalTriggerFocus(modal);
    $(document).trigger('modal:minimized', [modal]);
}

function restoreModal(modalInput, options = {}) {
    const modal = initializeModal(modalInput);
    if (!modal.hasClass('is-open')) {
        openModal(modal, options);
        return;
    }

    modal.removeClass('is-minimized');
    modal.find('.modal__dialog').attr('aria-modal', 'true');
    updateMinimizeControl(modal, false);
    refreshBodyModalState();

    if (options.syncUrl !== false) {
        replaceModalUrl(modal);
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

    if (options.syncUrl !== false) {
        clearModalUrl(modal);
    }

    $(document).trigger('modal:closed', [modal]);
    restoreModalTriggerFocus(modal);
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
}

function modalFromUrl() {
    const url = new URL(window.location.href);
    const id = url.searchParams.get(MODAL_URL_PARAM);
    if (!id) {
        return $();
    }

    return $('[data-modal]').filter(function () {
        return String($(this).data('modal')) === id;
    }).first();
}

function restoreModalFromUrl() {
    const url = new URL(window.location.href);
    const modal = modalFromUrl();
    if (!modal.length) {
        return;
    }

    const minimized = url.searchParams.get(MODAL_STATE_URL_PARAM) === MODAL_STATE_MINIMIZED;
    openModal(modal, { minimized, syncUrl: false });
}

function bindModalUrlRestore() {
    refreshModalViewport();
    window.addEventListener('resize', refreshModalViewport);
    window.visualViewport?.addEventListener('resize', refreshModalViewport);
    window.visualViewport?.addEventListener('scroll', refreshModalViewport);
    window.addEventListener('popstate', restoreModalFromUrl);

    window.setTimeout(() => {
        const url = new URL(window.location.href);
        if (url.searchParams.get(MODAL_STATE_URL_PARAM) === MODAL_STATE_MINIMIZED) {
            restoreModalFromUrl();
        }
    }, 0);

    const openAfterLoad = () => window.setTimeout(() => {
        const url = new URL(window.location.href);
        if (url.searchParams.get(MODAL_STATE_URL_PARAM) !== MODAL_STATE_MINIMIZED) {
            restoreModalFromUrl();
        }
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
bindModalUrlRestore();

window.MskbaModal = Object.freeze({
    open: (modal, options = {}) => openModal(modal, options),
    close: (modal, options = {}) => closeModal(modal, options),
    minimize: (modal, options = {}) => minimizeModal(modal, options),
    restore: (modal, options = {}) => restoreModal(modal, options),
});
