// Refresh the mounted chat when the operator returns from another browser tab.
// Background tabs may suspend WebSocket delivery and JavaScript polling, notably
// in Safari. The callback still uses the existing authorized Livewire refresh.
export const observeChatResume = (refresh, doc = document, win = window) => {
    const resume = () => {
        if (!doc.hidden) refresh();
    };

    doc.addEventListener('visibilitychange', resume);
    win.addEventListener('focus', resume);

    return () => {
        doc.removeEventListener('visibilitychange', resume);
        win.removeEventListener('focus', resume);
    };
};
