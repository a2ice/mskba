import $ from 'jquery';

const TOOLTIP_SELECTOR = '[title]';
const SKIP_SELECTOR = '[data-tooltip-skip]';
const TITLE_VARIANT = 'title';
const QUESTION_VARIANT = 'question';

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

    if (!isFocusable(element)) {
        element.attr('tabindex', '0');
    }
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
});

$(document).on('modal:opened', function(_event, modal) {
    initTooltips(modal);
});
