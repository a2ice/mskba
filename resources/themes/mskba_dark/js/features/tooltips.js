import $ from 'jquery';

const TOOLTIP_SELECTOR = '[title]';
const SKIP_SELECTOR = '[data-tooltip-skip]';
const TITLE_VARIANT = 'title';
const QUESTION_VARIANT = 'question';
const FLOATING_TOOLTIP_ID = 'ui-tooltip-floating';
const FLOATING_TOOLTIP_OFFSET = 10;
const FLOATING_TOOLTIP_VIEWPORT_GAP = 8;

let floatingTooltip = null;
let activeTooltipElement = null;

function initTooltips(context = document) {
    $(context).find(TOOLTIP_SELECTOR).addBack(TOOLTIP_SELECTOR).each(function() {
        const element = $(this);

        if (element.data('tooltipEnhanced') || element.closest(SKIP_SELECTOR).length) {
            return;
        }

        const title = String(element.attr('title') || '').trim();

        if (!title) {
            return;
        }

        element
            .removeAttr('title')
            .attr('data-tooltip-source', title)
            .data('tooltipEnhanced', true);

        if (tooltipVariant(element) === TITLE_VARIANT) {
            enhanceTitleTooltip(element, title);
            return;
        }

        enhanceQuestionTooltip(element, title);
    });
}

function enhanceTitleTooltip(element, title) {
    element
        .addClass('ui-tooltip-source ui-tooltip-source--title')
        .attr('data-tooltip', title);

    if (isIconOnlyTooltipSource(element)) {
        element.addClass('ui-tooltip-source--icon');
    }

    if (!isFocusable(element)) {
        element.attr('tabindex', '0');
    }
}

function isIconOnlyTooltipSource(element) {
    if (element.is('[data-tooltip-icon]')) {
        return true;
    }

    const containsIcon = element.is('i, svg, img, picture')
        || element.find('i, svg, img, picture').length > 0;

    if (!containsIcon) {
        return false;
    }

    const textContent = element
        .clone()
        .find('i, svg, img, picture')
        .remove()
        .end()
        .text()
        .trim();

    return textContent === '';
}

function enhanceQuestionTooltip(element, title) {
    const trigger = $('<button>', {
        type: 'button',
        class: 'ui-tooltip-trigger',
        'aria-label': `Подсказка: ${title}`,
        'data-tooltip': title,
    }).text('?');

    if (isBlockLike(element)) {
        element.append(trigger);
        return;
    }

    element.after(trigger);
}

function tooltipVariant(element) {
    if (element.is('.account-player-character-configurator__swatch')) {
        return TITLE_VARIANT;
    }

    const variant = String(element.attr('data-tooltip-variant') || QUESTION_VARIANT).trim();

    return variant === TITLE_VARIANT ? TITLE_VARIANT : QUESTION_VARIANT;
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
});

$(document).on('modal:opened', function(_event, modal) {
    initTooltips(modal);
});

function bindFloatingTooltips() {
    $(document)
        .on('mouseenter focusin', '.ui-tooltip-trigger, .ui-tooltip-source', function() {
            showFloatingTooltip(this);
        })
        .on('mouseleave focusout', '.ui-tooltip-trigger, .ui-tooltip-source', function() {
            hideFloatingTooltip(this);
        });

    $(window).on('scroll resize', function() {
        if (activeTooltipElement) {
            positionFloatingTooltip(activeTooltipElement);
        }
    });
}

function showFloatingTooltip(element) {
    const tooltipText = String($(element).attr('data-tooltip') || '').trim();

    if (!tooltipText) {
        return;
    }

    activeTooltipElement = element;
    floatingTooltip = floatingTooltip || createFloatingTooltip();
    floatingTooltip
        .text(tooltipText)
        .removeAttr('hidden');

    positionFloatingTooltip(element);
}

function hideFloatingTooltip(element) {
    if (activeTooltipElement !== element) {
        return;
    }

    activeTooltipElement = null;

    if (floatingTooltip) {
        floatingTooltip.attr('hidden', true);
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
