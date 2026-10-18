// Temporary staging-only diagnostic. Loaded by Page Builder only on the root URL.
// Do not use requestAnimationFrame or throttle here: inspect native scroll events
// independently of the rolling-ball controller.
(() => {
    const indicator = document.createElement('output');
    indicator.id = 'mskba-scrolltop-debug';
    indicator.setAttribute('aria-hidden', 'true');
    indicator.style.cssText = [
        'position:fixed',
        'left:0',
        'right:0',
        'bottom:0',
        'z-index:2147483647',
        'box-sizing:border-box',
        'margin:0',
        'padding:6px 10px calc(6px + env(safe-area-inset-bottom))',
        'background:#ccc',
        'color:red',
        'font:600 13px/1.35 ui-monospace,SFMono-Regular,Menlo,Consolas,monospace',
        'font-variant-numeric:tabular-nums',
        'white-space:pre-wrap',
        'pointer-events:none',
    ].join(';');
    document.body.append(indicator);

    let previousY = window.scrollY;
    let previousTime = performance.now();
    let delta = 0;
    let elapsed = 0;
    let maxDelta = 0;
    let scrollEvents = 0;

    function update() {
        const signedDelta = delta >= 0 ? '+' : '';
        indicator.textContent =
            `scrollTop: ${window.scrollY.toFixed(1)} px   ΔY: ${signedDelta}${delta.toFixed(1)} px\n` +
            `max|ΔY|: ${maxDelta.toFixed(1)} px   events: ${scrollEvents}   Δt: ${elapsed.toFixed(0)} ms`;
    }

    window.addEventListener('scroll', () => {
        const now = performance.now();
        const currentY = window.scrollY;
        delta = currentY - previousY;
        elapsed = now - previousTime;
        maxDelta = Math.max(maxDelta, Math.abs(delta));
        scrollEvents += 1;
        previousY = currentY;
        previousTime = now;
        // Update for *every* scroll event. The display itself can still paint
        // only once per refresh interval; the max delta and count preserve evidence.
        update();
    }, { passive: true });

    update();
})();
