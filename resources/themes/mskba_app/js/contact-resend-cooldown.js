// One server-derived deadline; one live countdown for each contact.
export function createContactResendCooldown(onChange) {
    let deadline = 0;
    let timer = null;
    let lastValue = -1;

    function update() {
        const secondsLeft = Math.max(0, Math.ceil((deadline - Date.now()) / 1000));
        if (secondsLeft !== lastValue) {
            lastValue = secondsLeft;
            onChange(secondsLeft);
        }
        if (secondsLeft === 0 && timer !== null) {
            clearInterval(timer);
            timer = null;
        }
        return secondsLeft;
    }

    function set(seconds) {
        if (timer !== null) clearInterval(timer);
        timer = null;
        const value = Math.max(0, Math.ceil(Number(seconds) || 0));
        deadline = Date.now() + value * 1000;
        lastValue = -1;
        update();
        if (value > 0) timer = setInterval(update, 200);
    }

    function dispose() {
        if (timer !== null) clearInterval(timer);
        timer = null;
    }

    return { set, dispose, get secondsLeft() { return update(); } };
}
