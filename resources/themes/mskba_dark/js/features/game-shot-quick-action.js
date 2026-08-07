const DOUBLE_SHOT_ACTION_DELAY = 300;

let pendingShotAction = null;
const bypassButtons = new WeakSet();

const getShotForm = () => document.querySelector('[data-game-shot-form]');

const updateShotRangeLabel = () => {
    const threePointInput = getShotForm()?.querySelector('input[name="range"][value="three"]');
    const label = threePointInput?.closest('label')?.querySelector('span');

    if (label) {
        label.textContent = 'Дальний';
    }
};

const triggerShotAction = (button, made) => {
    bypassButtons.add(button);
    button.click();
    bypassButtons.delete(button);

    const madeToggle = getShotForm()?.querySelector('[name="made"]');
    if (!madeToggle) return;

    madeToggle.checked = made;
    madeToggle.dispatchEvent(new Event('change', { bubbles: true }));
};

const cancelPendingShotAction = () => {
    if (!pendingShotAction) return;

    window.clearTimeout(pendingShotAction.timer);
    pendingShotAction = null;
};

const initShotQuickAction = () => {
    updateShotRangeLabel();
    document.querySelectorAll('[data-game-shot-open]').forEach((button) => {
        button.style.touchAction = 'manipulation';
    });
};

document.addEventListener('click', (event) => {
    const button = event.target.closest?.('[data-game-shot-open]');
    if (!button || bypassButtons.has(button)) return;

    event.preventDefault();
    event.stopPropagation();
    event.stopImmediatePropagation();

    const now = performance.now();
    const isDoubleAction = pendingShotAction
        && pendingShotAction.button === button
        && now - pendingShotAction.startedAt <= DOUBLE_SHOT_ACTION_DELAY + 80;

    if (isDoubleAction) {
        cancelPendingShotAction();
        triggerShotAction(button, true);
        return;
    }

    cancelPendingShotAction();

    const timer = window.setTimeout(() => {
        pendingShotAction = null;
        triggerShotAction(button, false);
    }, DOUBLE_SHOT_ACTION_DELAY);

    pendingShotAction = {
        button,
        timer,
        startedAt: now,
    };
}, true);

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initShotQuickAction, { once: true });
} else {
    initShotQuickAction();
}
