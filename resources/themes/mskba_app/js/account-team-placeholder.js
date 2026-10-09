// Task 023: safe demo flow. Domain mutations remain disabled until approved.
const root = document.querySelector('[data-app-team-actions]');

if (root) {
    const placeholder = root.querySelector('[data-team-placeholder-dialog]');
    const placeholderHeading = placeholder?.querySelector('[data-team-placeholder-title]');
    const gate = root.querySelector('[data-team-registration-gate]');
    const gateHeading = gate?.querySelector('#team-registration-gate-title');
    const gateHelpTrigger = gate?.querySelector('[data-team-gate-help-trigger]');
    const gateTooltip = gate?.querySelector('[data-team-gate-tooltip]');

    let placeholderOpener = null;
    let placeholderOverflow = '';
    let gateOpener = null;
    let gateOverflow = '';
    let requestedAction = null;
    let gateHandoff = false;
    let resumeOpener = null;

    const isTeamAction = action => action === 'find' || action === 'create';

    function openPlaceholder(action, trigger = null) {
        if (!isTeamAction(action) || !placeholder || placeholder.open) return;
        placeholderOpener = trigger?.isConnected ? trigger : null;
        placeholderHeading.textContent = action === 'create' ? 'Создание команды' : 'Поиск команды';
        placeholderOverflow = document.body.style.overflow;
        placeholder.showModal();
        document.body.style.overflow = 'hidden';
        placeholderHeading.focus({ preventScroll: true });
    }

    function restorePlaceholderFocus() {
        document.body.style.overflow = placeholderOverflow;
        placeholderOpener?.focus({ preventScroll: true });
        placeholderOpener = null;
    }

    placeholder?.querySelectorAll('[data-team-placeholder-close]').forEach(button => {
        button.addEventListener('click', () => {
            placeholder.close();
            restorePlaceholderFocus();
        });
    });
    // Native Escape also emits 'close'.
    placeholder?.addEventListener('close', restorePlaceholderFocus);

    // Keep the help bubble in the dialog top layer, but out of its scrollable body.
    // Calculate placement relative to the ? control, without shifting any content.
    function positionGateTooltip() {
        if (!gate?.open || !gateTooltip || gateTooltip.hidden || !gateHelpTrigger) return;

        const anchor = gateHelpTrigger.getBoundingClientRect();
        const dialogRect = gate.getBoundingClientRect();
        const bubble = gateTooltip.getBoundingClientRect();
        const left = Math.min(
            Math.max(12, anchor.left + anchor.width / 2 - dialogRect.left - bubble.width / 2),
            Math.max(12, dialogRect.width - bubble.width - 12),
        );
        const top = Math.max(12, anchor.top - dialogRect.top - bubble.height - 8);
        gateTooltip.style.left = left + 'px';
        gateTooltip.style.top = top + 'px';
    }

    function setGateTooltipVisible(visible) {
        if (!gateHelpTrigger || !gateTooltip) return;
        gateTooltip.hidden = !visible;
        gateHelpTrigger.setAttribute('aria-expanded', String(visible));

        if (visible) {
            gateTooltip.style.visibility = 'hidden';
            positionGateTooltip();
            gateTooltip.style.visibility = '';
        }
    }

    // Keep alignment with the icon on mobile orientation changes and modal scroll.
    window.addEventListener('resize', positionGateTooltip);
    gate?.querySelector('.mskba-modal__body')?.addEventListener('scroll', positionGateTooltip, { passive: true });

    gateHelpTrigger?.addEventListener('mouseenter', () => setGateTooltipVisible(true));
    gateHelpTrigger?.addEventListener('mouseleave', () => {
        if (document.activeElement !== gateHelpTrigger) setGateTooltipVisible(false);
    });
    gateHelpTrigger?.addEventListener('focus', () => setGateTooltipVisible(true));
    gateHelpTrigger?.addEventListener('blur', () => setGateTooltipVisible(false));
    gateHelpTrigger?.addEventListener('click', () => setGateTooltipVisible(true));
    gate?.addEventListener('pointerdown', event => {
        if (!event.target.closest('[data-team-gate-help-trigger], [data-team-gate-tooltip]')) {
            setGateTooltipVisible(false);
        }
    });
    gate?.addEventListener('cancel', event => {
        if (gateTooltip && !gateTooltip.hidden) {
            event.preventDefault();
            setGateTooltipVisible(false);
        }
    });

    function openGate(action, trigger) {
        if (!gate || gate.open) return;
        requestedAction = action;
        gateOpener = trigger;
        gateHeading.textContent = action === 'create' ? 'Создание команды' : 'Поиск команды';
        if (gateTooltip) {
            gateTooltip.textContent = action === 'create'
                ? 'Всего один простой шаг и мы вернем тебя к созданию команды.'
                : 'Всего один простой шаг и мы вернем тебя к поиску команды.';
        }
        setGateTooltipVisible(false);
        gateOverflow = document.body.style.overflow;
        gate.showModal();
        document.body.style.overflow = 'hidden';
        gateHeading.focus({ preventScroll: true });
    }

    function cancelGate() {
        if (!gate?.open) return;
        gate.close();
        setGateTooltipVisible(false);
        // Restore synchronously for the explicit Cancel / X controls.
        // Native Escape is handled by the 'close' listener below.
        document.body.style.overflow = gateOverflow;
        gateOpener?.focus({ preventScroll: true });
        gateOpener = null;
        requestedAction = null;
    }

    gate?.querySelectorAll('[data-team-gate-cancel]').forEach(button => {
        button.addEventListener('click', cancelGate);
    });

    gate?.addEventListener('close', () => {
        setGateTooltipVisible(false);
        if (gateHandoff) {
            gateHandoff = false;
            return;
        }
        document.body.style.overflow = gateOverflow;
        gateOpener?.focus({ preventScroll: true });
        gateOpener = null;
        requestedAction = null;
    });

    gate?.querySelector('[data-team-gate-confirm]')?.addEventListener('click', () => {
        if (!gate.open || !isTeamAction(requestedAction)) return;
        const action = requestedAction;
        const trigger = gateOpener;
        resumeOpener = trigger;
        gateHandoff = true;
        gate.close();
        document.body.style.overflow = gateOverflow;
        // Existing onboarding logic owns the finish-registration modal.
        // No search/create mutation is queued or replayed.
        window.dispatchEvent(new CustomEvent('mskba:onboarding-request-action', {
            detail: { action, trigger },
        }));
        gateOpener = null;
        requestedAction = null;
    });

    root.querySelectorAll('[data-team-placeholder-action]').forEach(button => {
        button.addEventListener('click', () => {
            const action = button.dataset.teamPlaceholderAction;
            if (!isTeamAction(action)) return;
            if (document.querySelector('[data-onboarding-pending]')) {
                openGate(action, button);
            } else {
                openPlaceholder(action, button);
            }
        });
    });

    window.addEventListener('mskba:onboarding-completed', event => {
        const action = event.detail?.action;
        if (!isTeamAction(action)) return;
        // Only the onboarding module can claim successful server confirmation.
        // The demo action remains a non-mutating placeholder.
        const trigger = resumeOpener?.isConnected
            ? resumeOpener
            : root.querySelector('[data-team-placeholder-action="' + action + '"]');
        resumeOpener = null;
        openPlaceholder(action, trigger);
    });
    window.addEventListener('mskba:onboarding-continuation-cancelled', () => {
        resumeOpener = null;
    });
}
