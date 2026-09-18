import $ from 'jquery';

const TOOLTIP_SELECTOR = '[title]';
const SKIP_SELECTOR = '[data-tooltip-skip]';
const TITLE_VARIANT = 'title';
const QUESTION_VARIANT = 'question';
const FLOATING_TOOLTIP_ID = 'ui-tooltip-floating';
const FLOATING_TOOLTIP_OFFSET = 10;
const FLOATING_TOOLTIP_VIEWPORT_GAP = 8;
const BASE_MODAL_Z_INDEX = 320;
const TOUCH_TOOLTIP_MEDIA = window.matchMedia('(hover: none), (pointer: coarse)');
const INTERACTIVE_TOOLTIP_SELECTOR = 'a[href], button, input, select, textarea, summary, label, [role="button"]';

let floatingTooltip = null;
let activeTooltipElement = null;

function initTooltips(context = document) {
    $(context).find(TOOLTIP_SELECTOR).addBack(TOOLTIP_SELECTOR).each(function() {
        const element = $(this);

        if (element.closest(SKIP_SELECTOR).length) {
            return;
        }

        const title = String(element.attr('title') || '').trim();

        if (!title) {
            return;
        }

        element
            .removeAttr('title')
            .attr('data-tooltip-source', title);

        if (element.data('tooltipEnhanced')) {
            refreshEnhancedTooltip(element, title);
            return;
        }

        element.data('tooltipEnhanced', true);

        if (tooltipVariant(element) === TITLE_VARIANT) {
            enhanceVisualTooltip(element, title);
            return;
        }

        enhanceTextTooltip(element, title);
    });
}

function enhanceVisualTooltip(element, title) {
    element
        .addClass('ui-tooltip-source ui-tooltip-source--visual')
        .attr('data-tooltip', title);

    if (isIconOnlyTooltipSource(element)) {
        element.addClass('ui-tooltip-source--icon');
    }

    if (element.attr('aria-hidden') === 'true') {
        element.removeAttr('aria-hidden');
    }

    if (!element.attr('aria-label')) {
        element.attr('aria-label', title);
    }

    if (!isFocusable(element)) {
        element.attr('tabindex', '0');
    }
}

function isIconOnlyTooltipSource(element) {
    const iconSelector = 'i, svg, img, picture, [aria-hidden="true"]';
    const containsIcon = element.is(iconSelector)
        || element.find(iconSelector).length > 0;

    if (!containsIcon) {
        return false;
    }

    const textContent = element
        .clone()
        .find(iconSelector)
        .remove()
        .end()
        .text()
        .trim();

    return textContent === '';
}

function enhanceTextTooltip(element, title) {
    element
        .addClass('ui-tooltip-source ui-tooltip-source--text')
        .attr('data-tooltip', title);

    const trigger = $('<button>', {
        type: 'button',
        class: 'ui-tooltip-trigger',
        'aria-label': `Подсказка: ${title}`,
        'data-tooltip': title,
        'data-tooltip-generated': '1',
    }).text('?');

    if (isBlockLike(element) && !isInteractiveTooltipSource(element.get(0))) {
        element.append(trigger);
        return;
    }

    element.after(trigger);
}

function refreshEnhancedTooltip(element, title) {
    element.attr('data-tooltip', title);

    element
        .children('.ui-tooltip-trigger[data-tooltip-generated="1"]')
        .add(element.next('.ui-tooltip-trigger[data-tooltip-generated="1"]'))
        .attr('data-tooltip', title)
        .attr('aria-label', `Подсказка: ${title}`);
}

function tooltipVariant(element) {
    if (element.is('[data-tooltip-text]')) {
        return QUESTION_VARIANT;
    }

    if (
        element.is('[data-tooltip-visual], [data-tooltip-icon], .account-player-character-configurator__swatch')
        || isIconOnlyTooltipSource(element)
    ) {
        return TITLE_VARIANT;
    }

    // Presentation is semantic, not opt-in: readable text always gets the
    // text treatment. Legacy data-tooltip-variant="title" is intentionally
    // ignored here so it cannot silently suppress the question mark/underline.
    return QUESTION_VARIANT;
}

function isBlockLike(element) {
    return ['block', 'list-item', 'flex', 'grid'].includes(element.css('display'));
}

function isFocusable(element) {
    if (element.is('a[href], button, input, select, textarea, summary')) {
        return true;
    }

    const tabindex = element.attr('tabindex');

    return tabindex !== undefined && Number(tabindex) >= 0;
}

$(function() {
    initTooltips();
    bindFloatingTooltips();
    observeTooltips();
});

$(document).on('modal:opened', function(_event, modal) {
    // A tooltip opened from the background must never survive above a modal.
    hideActiveFloatingTooltip();
    initTooltips(modal);
});

$(document).on('modal:minimized modal:closed', function() {
    // The source can become hidden or leave the active UI when a modal changes state.
    hideActiveFloatingTooltip();
});

function observeTooltips() {
    if (!window.MutationObserver || !document.body) {
        return;
    }

    const observer = new MutationObserver((records) => {
        records.forEach((record) => {
            if (record.type === 'attributes') {
                initTooltips(record.target);
                return;
            }

            record.addedNodes.forEach((node) => {
                if (node instanceof Element) {
                    initTooltips(node);
                }
            });
        });
    });

    observer.observe(document.body, {
        subtree: true,
        childList: true,
        attributes: true,
        attributeFilter: ['title'],
    });
}

function usesTouchTooltipMode() {
    return TOUCH_TOOLTIP_MEDIA.matches;
}

function isDedicatedTooltipTrigger(element) {
    return element.classList.contains('ui-tooltip-trigger');
}

function isInteractiveTooltipSource(element) {
    return element.matches(INTERACTIVE_TOOLTIP_SELECTOR);
}

function shouldToggleTooltipOnTouch(element) {
    return isDedicatedTooltipTrigger(element) || !isInteractiveTooltipSource(element);
}

function bindFloatingTooltips() {
    const selector = '.ui-tooltip-trigger, .ui-tooltip-source';

    $(document)
        .on('mouseenter', selector, function() {
            if (!usesTouchTooltipMode()) {
                showFloatingTooltip(this);
            }
        })
        .on('mouseleave', selector, function() {
            if (!usesTouchTooltipMode()) {
                hideFloatingTooltip(this);
            }
        })
        .on('focusin', selector, function() {
            if (!usesTouchTooltipMode()) {
                showFloatingTooltip(this);
            }
        })
        .on('focusout', selector, function() {
            hideFloatingTooltip(this);
        })
        .on('click', selector, function(event) {
            if (!usesTouchTooltipMode()) {
                return;
            }

            if (!shouldToggleTooltipOnTouch(this)) {
                // Buttons/links must perform their action on the first tap without leaving
                // a synthetic hover/focus tooltip behind.
                hideActiveFloatingTooltip();
                return;
            }

            event.preventDefault();
            event.stopPropagation();

            if (activeTooltipElement === this) {
                hideActiveFloatingTooltip();
            } else {
                showFloatingTooltip(this);
            }
        })
        .on('pointerdown touchstart', function(event) {
            if (!usesTouchTooltipMode() || !activeTooltipElement) {
                return;
            }

            const target = event.target;
            if (target instanceof Node && activeTooltipElement.contains(target)) {
                return;
            }

            hideActiveFloatingTooltip();
        });

    $(window)
        .on('scroll resize', function() {
            if (activeTooltipElement) {
                positionFloatingTooltip(activeTooltipElement);
            }
        })
        .on('blur pagehide', hideActiveFloatingTooltip);
}

function showFloatingTooltip(element) {
    const tooltipText = String($(element).attr('data-tooltip') || '').trim();

    if (!tooltipText) {
        return;
    }

    const topModal = getTopVisibleModal();

    if (topModal && !topModal.contains(element)) {
        hideActiveFloatingTooltip();
        return;
    }

    activeTooltipElement = element;
    floatingTooltip = floatingTooltip || createFloatingTooltip();
    floatingTooltip
        .text(tooltipText)
        .css('z-index', topModal ? modalTooltipZIndex(topModal) : '')
        .removeAttr('hidden');

    positionFloatingTooltip(element);
}

function hideFloatingTooltip(element) {
    if (activeTooltipElement !== element) {
        return;
    }

    hideActiveFloatingTooltip();
}

function hideActiveFloatingTooltip() {
    activeTooltipElement = null;

    if (floatingTooltip) {
        floatingTooltip
            .attr('hidden', true)
            .css('z-index', '');
    }
}

function createFloatingTooltip() {
    let tooltip = $(`#${FLOATING_TOOLTIP_ID}`);

    if (tooltip.length) {
        return tooltip;
    }

    tooltip = $('<div>', {
        id: FLOATING_TOOLTIP_ID,
        class: 'ui-tooltip-floating',
        role: 'tooltip',
        hidden: true,
    });

    $('body').append(tooltip);

    return tooltip;
}

function getTopVisibleModal() {
    const modals = Array.from(document.querySelectorAll('.modal:not([hidden])'))
        .filter((modal) => modal.getClientRects().length > 0);

    if (modals.length === 0) {
        return null;
    }

    return modals.reduce((top, candidate) => {
        if (!top) return candidate;

        const topZ = modalZIndex(top);
        const candidateZ = modalZIndex(candidate);

        if (candidateZ > topZ) {
            return candidate;
        }

        if (candidateZ === topZ && (top.compareDocumentPosition(candidate) & Node.DOCUMENT_POSITION_FOLLOWING)) {
            return candidate;
        }

        return top;
    }, null);
}

function modalZIndex(modal) {
    const parsed = Number.parseInt(window.getComputedStyle(modal).zIndex, 10);

    return Number.isFinite(parsed) ? parsed : BASE_MODAL_Z_INDEX;
}

function modalTooltipZIndex(modal) {
    return String(modalZIndex(modal) + 20);
}

function positionFloatingTooltip(element) {
    if (!floatingTooltip) {
        return;
    }

    const rect = element.getBoundingClientRect();
    const tooltipElement = floatingTooltip.get(0);

    floatingTooltip.css({
        left: 0,
        top: 0,
    });

    const tooltipRect = tooltipElement.getBoundingClientRect();
    const viewportWidth = window.innerWidth;
    const viewportHeight = window.innerHeight;
    const preferredTop = rect.top - tooltipRect.height - FLOATING_TOOLTIP_OFFSET;
    const belowTop = rect.bottom + FLOATING_TOOLTIP_OFFSET;
    const top = preferredTop >= FLOATING_TOOLTIP_VIEWPORT_GAP
        ? preferredTop
        : Math.min(belowTop, viewportHeight - tooltipRect.height - FLOATING_TOOLTIP_VIEWPORT_GAP);
    const centeredLeft = rect.left + rect.width / 2 - tooltipRect.width / 2;
    const left = Math.min(
        Math.max(centeredLeft, FLOATING_TOOLTIP_VIEWPORT_GAP),
        viewportWidth - tooltipRect.width - FLOATING_TOOLTIP_VIEWPORT_GAP,
    );

    floatingTooltip.css({
        left: `${Math.max(FLOATING_TOOLTIP_VIEWPORT_GAP, left)}px`,
        top: `${Math.max(FLOATING_TOOLTIP_VIEWPORT_GAP, top)}px`,
    });
}
