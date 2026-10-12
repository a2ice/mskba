import { createContactResendCooldown } from './contact-resend-cooldown.js';

// Cooldowns are scoped to each contact and initialized from the server's
// current seconds. No additional polling or resend requests are necessary.
function initContactResendCounters() {
    document.querySelectorAll('[data-contact-resend-form]').forEach(form => {
        if (form.dataset.cooldownInitialized === 'true') return;
        form.dataset.cooldownInitialized = 'true';

        const row = form.closest('.app-account-contacts__item');
        const button = form.querySelector('[data-contact-resend-button]');
        const hint = row?.querySelector('[data-contact-resend-hint]');
        const count = hint?.querySelector('[data-contact-resend-countdown]');
        if (!row || !button || !hint || !count) return;

        const cooldown = createContactResendCooldown(seconds => {
            const waiting = seconds > 0;
            button.disabled = waiting;
            button.dataset.tooltip = waiting ? 'Ожидайте' : 'Отправить новый код';
            button.setAttribute('aria-label', waiting ? 'Отправка доступна позже' : 'Отправить новый код');
            hint.hidden = !waiting;
            count.textContent = String(seconds);
        });
        cooldown.set(form.dataset.cooldownSeconds || 0);
    });
}

initContactResendCounters();
document.addEventListener('mskba:contacts-list-updated', initContactResendCounters);
