// One-time attention animation for the global MSKBA App FAQ button.
// A right-anchored, overflow-clipped pill reveals the word from right to left.
// The help button remains usable as a simple circle if JavaScript is unavailable.
export const HELP_LAUNCHER_DELAY_MS = 2000;

export function initHelpLauncherIntro(doc = document, host = window) {
    const launcher = doc.querySelector('[data-help-launcher]');
    const label = launcher?.querySelector('[data-help-launcher-label]');
    if (!launcher || !label) return null;

    // Respect operating-system accessibility preferences. No delayed hiding.
    if (host.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return launcher;

    launcher.classList.add('is-pending-intro');
    let delayTimer = null;
    let finished = false;

    function complete() {
        if (finished) return;
        finished = true;
        if (delayTimer !== null) host.clearTimeout(delayTimer);
        launcher.classList.remove('is-pending-intro', 'is-introducing');
    }

    function schedule() {
        if (finished) return;

        // Measure the actual rendered label: avoids hardcoding Russian text width,
        // works with every appearance mode and both mobile/desktop font sizes.
        const baseSize = parseFloat(host.getComputedStyle(launcher).getPropertyValue('--app-help-size')) || 50;
        const labelWidth = label.getBoundingClientRect().width;
        launcher.style.setProperty('--app-help-expanded-width', `${Math.ceil(baseSize + labelWidth)}px`);

        delayTimer = host.setTimeout(() => {
            if (finished) return;
            launcher.classList.remove('is-pending-intro');
            launcher.classList.add('is-introducing');
        }, HELP_LAUNCHER_DELAY_MS);
    }

    // A keyboard user focusing the temporarily hidden control immediately gets
    // the normal visible button, with no delayed/colliding intro animation.
    launcher.addEventListener('focus', complete, { once: true });
    launcher.addEventListener('click', complete, { once: true });
    launcher.addEventListener('animationend', (event) => {
        if (event.animationName === 'app-help-intro') complete();
    });

    if (doc.readyState === 'complete') schedule();
    else host.addEventListener('load', schedule, { once: true });

    return launcher;
}

initHelpLauncherIntro();
