import $ from 'jquery';
import '../../css/pages/home-flow-navigation.css';

const mountedFlows = new WeakMap();

function activeStepIndex(progressItems) {
    const index = progressItems.findIndex((item) => item.classList.contains('is-active'));
    return index >= 0 ? index : 0;
}

function selectedTypeButton(flow, flowType) {
    const selector = flowType === 'venue'
        ? '[data-home-venue-type].is-selected'
        : '[data-home-flow-type].is-selected';

    return flow.querySelector(selector);
}

function finalAction(panel) {
    return panel.querySelector('.home-flow-navigation__legacy-actions a[href], .home-flow-navigation__legacy-actions button:not([disabled])');
}

function eventVenueSelection(flow) {
    const selector = flow.querySelector('[data-venue-selector]');
    const valueInput = selector?.querySelector('[data-venue-selector-value]');

    if (selector && valueInput) {
        return valueInput.value ? {
            input: selector.querySelector('[data-venue-selector-input]'),
            selected: true,
        } : {
            input: selector.querySelector('[data-venue-selector-input]'),
            selected: false,
        };
    }

    const input = flow.querySelector('[data-home-flow-panel="search"] .home-flow-field input');

    return {
        input,
        selected: Boolean(String(input?.value || '').trim()),
    };
}

/**
 * Shared footer contract for homepage search wizards.
 *
 * The adapter owns only navigation UI. Domain steps keep owning their state and
 * validation; callbacks below delegate to those existing step transitions.
 */
export function mountHomeFlowNavigation({
    flow,
    panel,
    progressItems,
    canNext,
    canSkip,
    onBack,
    onNext,
    onSkip,
}) {
    if (!flow || !panel || progressItems.length === 0) {
        return null;
    }

    const existing = mountedFlows.get(flow);
    if (existing) {
        existing.sync();
        return existing;
    }

    const footer = document.createElement('div');
    footer.className = 'home-flow-wizard-nav';
    footer.dataset.homeFlowWizardNav = '';

    const back = document.createElement('button');
    back.type = 'button';
    back.className = 'btn btn--secondary home-flow-wizard-nav__back';
    back.dataset.homeFlowWizardBack = '';
    back.innerHTML = '<i class="ti ti-arrow-left"></i><span>Назад</span>';

    const skip = document.createElement('button');
    skip.type = 'button';
    skip.className = 'home-flow-wizard-nav__skip';
    skip.dataset.homeFlowWizardSkip = '';
    skip.textContent = 'Пропустить';

    const next = document.createElement('button');
    next.type = 'button';
    next.className = 'btn btn--primary home-flow-wizard-nav__next';
    next.dataset.homeFlowWizardNext = '';
    next.innerHTML = '<span>Далее</span><i class="ti ti-arrow-right"></i>';

    footer.append(back, skip, next);
    panel.append(footer);

    function sync() {
        const step = activeStepIndex(progressItems);
        const allowNext = canNext(step);
        const allowSkip = canSkip(step);

        back.disabled = step === 0;
        back.setAttribute('aria-disabled', String(step === 0));

        skip.hidden = !allowSkip;

        next.disabled = !allowNext;
        next.setAttribute('aria-disabled', String(!allowNext));

        footer.dataset.homeFlowStep = String(step);
    }

    back.addEventListener('click', () => {
        const step = activeStepIndex(progressItems);
        if (step <= 0) {
            return;
        }

        onBack(step);
        window.setTimeout(sync, 0);
    });

    skip.addEventListener('click', () => {
        const step = activeStepIndex(progressItems);
        if (!canSkip(step)) {
            return;
        }

        onSkip(step);
        window.setTimeout(sync, 0);
    });

    next.addEventListener('click', () => {
        const step = activeStepIndex(progressItems);
        if (!canNext(step)) {
            return;
        }

        onNext(step);
        window.setTimeout(sync, 0);
    });

    const observer = new MutationObserver(sync);
    progressItems.forEach((item) => {
        observer.observe(item, {
            attributes: true,
            attributeFilter: ['class', 'aria-current'],
        });
    });

    const api = {
        footer,
        sync,
        destroy() {
            observer.disconnect();
            footer.remove();
            mountedFlows.delete(flow);
        },
    };

    mountedFlows.set(flow, api);
    sync();

    return api;
}

function prepareCurrentHomeFlow(flow) {
    if (!flow || !['event', 'venue'].includes(flow.dataset.homeFlow || '')) {
        return null;
    }

    const panel = flow.querySelector('[data-home-flow-panel="search"]');
    const steps = panel?.querySelector('.home-flow-steps');
    const progressItems = steps ? [...steps.querySelectorAll('[data-home-flow-step], span')] : [];

    if (!panel || progressItems.length === 0) {
        return null;
    }

    const flowType = flow.dataset.homeFlow;
    const legacyActions = [...panel.querySelectorAll('.home-flow-modal__actions')];

    legacyActions.forEach((actions) => {
        if (!actions.closest('.home-flow-wizard-nav')) {
            actions.classList.add('home-flow-navigation__legacy-actions');
        }
    });

    const legacySkip = flowType === 'event'
        ? panel.querySelector('.home-flow-location-any')
        : null;

    if (legacySkip) {
        legacySkip.classList.add('home-flow-navigation__legacy-skip');
    }

    let allowTypeAdvance = false;

    const api = mountHomeFlowNavigation({
        flow,
        panel,
        progressItems,

        canNext(step) {
            if (step === 0) {
                return selectedTypeButton(flow, flowType) !== null;
            }

            if (flowType === 'event' && step === 1) {
                return eventVenueSelection(flow).selected;
            }

            if (step === progressItems.length - 1) {
                return finalAction(panel) !== null;
            }

            return true;
        },

        canSkip(step) {
            return flowType === 'event'
                && step === 1
                && legacySkip !== null;
        },

        onBack(step) {
            progressItems[step - 1]?.click();
        },

        onSkip(step) {
            if (flowType === 'event' && step === 1) {
                legacySkip?.click();
            }
        },

        onNext(step) {
            if (step === 0) {
                const selected = selectedTypeButton(flow, flowType);
                if (!selected) {
                    return;
                }

                allowTypeAdvance = true;
                selected.click();
                return;
            }

            if (flowType === 'event' && step === 1) {
                const selection = eventVenueSelection(flow);
                if (!selection.selected || !selection.input) {
                    return;
                }

                selection.input.dispatchEvent(new KeyboardEvent('keydown', {
                    key: 'Enter',
                    code: 'Enter',
                    bubbles: true,
                    cancelable: true,
                }));
                return;
            }

            if (step === progressItems.length - 1) {
                finalAction(panel)?.click();
            }
        },
    });

    if (!api) {
        return null;
    }

    // The legacy event wizard and the venue compatibility layer used to advance
    // immediately after selecting a type. Preserve their internal state update,
    // then return to the same step unless the click came from the shared footer.
    flow.addEventListener('click', (event) => {
        const choice = event.target.closest(
            flowType === 'venue'
                ? '[data-home-venue-type]'
                : '[data-home-flow-type]'
        );

        if (!choice || !panel.contains(choice)) {
            return;
        }

        if (allowTypeAdvance) {
            allowTypeAdvance = false;
            window.setTimeout(api.sync, 0);
            return;
        }

        if (activeStepIndex(progressItems) > 0) {
            progressItems[0]?.click();
        }

        window.setTimeout(() => {
            choice.focus();
            api.sync();
        }, 0);
    });

    if (flowType === 'event') {
        const venueValue = flow.querySelector('[data-venue-selector-value]');

        venueValue?.addEventListener('change', () => {
            if (!venueValue.value) {
                window.setTimeout(api.sync, 0);
                return;
            }

            // home-event-venue-bridge historically advances after a real venue
            // selection. Undo only that automatic transition; the footer's
            // explicit Next uses Enter and therefore is not affected here.
            window.setTimeout(() => {
                if (activeStepIndex(progressItems) > 1) {
                    progressItems[1]?.click();
                }

                api.sync();
            }, 0);
        });
    }

    return api;
}

$(document).on('modal:opened.homeFlowNavigation', function (_event, modal) {
    const flow = modal.find('[data-home-flow]').get(0);
    if (!flow) {
        return;
    }

    window.setTimeout(() => {
        prepareCurrentHomeFlow(flow)?.sync();
    }, 0);
});

$(document).on('click.homeFlowNavigation', '[data-home-flow-tab]', function () {
    const modal = $(this).closest('.modal');
    const flow = modal.find('[data-home-flow]').get(0);

    window.setTimeout(() => {
        mountedFlows.get(flow)?.sync();
    }, 0);
});
