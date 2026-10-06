function initContextShare() {
    const button = document.querySelector('[data-context-share]');

    if (!button) {
        return;
    }

    const defaultLabel = button.textContent.trim();

    const showTemporaryLabel = (label) => {
        button.textContent = label;
        window.setTimeout(() => {
            button.textContent = defaultLabel;
        }, 1800);
    };

    button.addEventListener('click', async () => {
        const title = document.title;
        const url = window.location.href;

        if (navigator.share) {
            try {
                await navigator.share({ title, url });
                return;
            } catch (error) {
                if (error?.name === 'AbortError') {
                    return;
                }
            }
        }

        try {
            await navigator.clipboard.writeText(url);
            showTemporaryLabel('Ссылка скопирована');
        } catch {
            const fallback = document.createElement('textarea');
            fallback.value = url;
            fallback.setAttribute('readonly', '');
            fallback.style.position = 'fixed';
            fallback.style.opacity = '0';
            document.body.append(fallback);
            fallback.select();
            document.execCommand('copy');
            fallback.remove();
            showTemporaryLabel('Ссылка скопирована');
        }
    });
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initContextShare, { once: true });
} else {
    initContextShare();
}
