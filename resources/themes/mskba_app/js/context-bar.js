// Progressive enhancement of native details; no dependency on legacy UI handlers.
const contextBar = document.querySelector('[data-app-context-bar]');

if (contextBar) {
    const menu = contextBar.querySelector('[data-app-context-actions]');
    const summary = menu?.querySelector('summary');
    const status = contextBar.querySelector('[data-app-context-status]');

    document.addEventListener('pointerdown', (event) => {
        if (menu?.open && !menu.contains(event.target)) menu.open = false;
    });
    document.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape' || !menu?.open) return;
        // A nested submenu closes first, otherwise the main menu.
        const nested = [...menu.querySelectorAll('details[open]')].at(-1);
        if (nested) nested.open = false;
        else {
            menu.open = false;
            summary?.focus();
        }
        event.preventDefault();
    });
    menu?.querySelector('[data-app-context-back]')?.addEventListener('click', (event) => {
        const fallback = event.currentTarget.dataset.fallback;
        menu.open = false;
        try {
            if (document.referrer && new URL(document.referrer).origin === location.origin) {
                history.back();
                return;
            }
        } catch { /* Direct navigation uses a known parent. */ }
        location.assign(fallback || '/');
    });
    const shareButton = menu?.querySelector('[data-app-context-share]');
    shareButton?.addEventListener('click', async () => {
        const feedback = (message) => {
            if (status) status.textContent = message;
            shareButton.textContent = message;
            window.setTimeout(() => { shareButton.textContent = 'Поделиться'; }, 1800);
        };
        if (navigator.share) {
            try {
                await navigator.share({ title: document.title, url: location.href });
                menu.open = false;
                return;
            } catch (error) {
                if (error?.name === 'AbortError') return;
            }
        }
        try {
            await navigator.clipboard.writeText(location.href);
            feedback('Ссылка скопирована');
        } catch {
            // Use the same fallback as the old theme when Clipboard API is unavailable.
            const fallback = document.createElement('textarea');
            fallback.value = location.href;
            fallback.setAttribute('readonly', '');
            fallback.style.position = 'fixed';
            fallback.style.opacity = '0';
            document.body.append(fallback);
            fallback.select();
            const copied = document.execCommand('copy');
            fallback.remove();
            feedback(copied ? 'Ссылка скопирована' : 'Не удалось скопировать ссылку');
        }
    });
}

// Only account pages with an unconfirmed canonical user render this dialog.
// The Overview badge opens the FAQ; the contextual Actions link opens the confirmation page.
const accountConfirmationGuide = document.querySelector('[data-account-confirmation-guide-dialog]');
if (accountConfirmationGuide && contextBar) {
    let returnFocus = null;
    contextBar.closest('body').querySelectorAll('[data-account-confirmation-guide-open]').forEach(trigger => {
        trigger.addEventListener('click', () => {
            const menu = contextBar.querySelector('[data-app-context-actions]');
            if (menu?.contains(trigger) && menu.open) {
                menu.open = false;
                returnFocus = menu.querySelector('summary');
            } else {
                returnFocus = trigger;
            }
            accountConfirmationGuide.showModal();
            accountConfirmationGuide.querySelector('h2[tabindex="-1"]')?.focus({ preventScroll: true });
        });
    });
    accountConfirmationGuide.querySelectorAll('[data-account-confirmation-guide-close]').forEach(button => {
        button.addEventListener('click', () => accountConfirmationGuide.close());
    });
    accountConfirmationGuide.addEventListener('close', () => {
        if (returnFocus?.isConnected) returnFocus.focus({ preventScroll: true });
        returnFocus = null;
    });
}
