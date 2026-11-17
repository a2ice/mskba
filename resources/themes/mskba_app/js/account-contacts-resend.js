import { createContactResendCooldown } from './contact-resend-cooldown.js';
import { refreshAccountContactsList } from './account-contacts-list.js';

// Both first requests and subsequent resends use AJAX. The server confirms
// successful SMTP submission and supplies the authoritative cooldown.
const counters = new Map();
const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';

function initContactResendCounters() {
    for (const [form, counter] of counters) {
        if (!form.isConnected) {
            counter.dispose();
            counters.delete(form);
        }
    }

    document.querySelectorAll('[data-contact-resend-form]').forEach(form => {
        if (counters.has(form)) return;
        const row = form.closest('.app-account-contacts__item');
        const button = form.querySelector('[data-contact-resend-button]');
        const timer = button.querySelector('[data-contact-resend-timer]');
        const icon = button.querySelector('[data-contact-resend-icon]');
        const error = row?.querySelector('[data-contact-resend-error]');
        if (!row || !button || !error) return;

        const isResend = button.classList.contains('app-account-contacts__icon-button');
        const initialMarkup = button.innerHTML;
        let busy = false;

        const clearError = () => {
            error.hidden = true;
            error.textContent = '';
        };
        const showError = message => {
            error.textContent = message;
            error.hidden = false;
        };
        const cooldown = createContactResendCooldown(seconds => {
            const waiting = seconds > 0;
            if (timer) { timer.hidden = !waiting; timer.textContent = seconds + "с"; }
            if (icon) icon.hidden = waiting;
            button.disabled = waiting || busy;
            if (isResend) {
                button.dataset.tooltip = waiting ? 'Повторная отправка через ' + seconds + ' с' : 'Отправить новый код';
                button.setAttribute('aria-label', waiting ? 'Повторная отправка через ' + seconds + ' секунд' : 'Отправить новый код');
            }
        });

        form.addEventListener('submit', async event => {
            event.preventDefault();
            if (busy || cooldown.secondsLeft > 0) return;
            busy = true;
            clearError();
            button.disabled = true;
            button.setAttribute('aria-busy', 'true');
            button.innerHTML = '<span class="app-account-contacts__resend-spinner" aria-hidden="true"></span>'
                + (isResend ? '' : '<span>Отправка…</span>');
            if (isResend) {
                button.dataset.tooltip = 'Отправка кода';
                button.setAttribute('aria-label', 'Отправка кода');
            }

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                    },
                    body: '{}',
                });
                const data = await response.json().catch(() => ({}));

                // Another tab or request may have started the cooldown.
                // Refresh the fragment to also reveal the verification-code form.
                if (response.ok || response.status === 429) {
                    const seconds = Math.max(0, Number(data.seconds_left) || 0);
                    cooldown.set(seconds);
                    try {
                        await refreshAccountContactsList();
                    } catch {
                        if (response.ok) {
                            showError('Код отправлен, но список не обновился. Обнови страницу для ввода кода.');
                        } else {
                            showError('Код уже отправлен. Обнови страницу для ввода кода.');
                        }
                    }
                } else {
                    const message = Object.values(data.errors || {}).flat().find(Boolean)
                        || data.message || 'Не удалось запросить код. Попробуй ещё раз.';
                    showError(message);
                }
            } catch {
                showError('Не удалось получить ответ сервера. Проверь почту перед повторным запросом.');
            } finally {
                busy = false;
                button.innerHTML = initialMarkup;
                button.removeAttribute('aria-busy');
                button.disabled = cooldown.secondsLeft > 0;
                if (timer) { timer.hidden = cooldown.secondsLeft <= 0; timer.textContent = cooldown.secondsLeft + "с"; }
                if (icon) icon.hidden = cooldown.secondsLeft > 0;
                // Refresh may replace this row; the countdown of a disconnected
                // form is disposed by initContactResendCounters().
                if (isResend) {
                    button.dataset.tooltip = button.disabled ? 'Ожидайте' : 'Отправить новый код';
                    button.setAttribute('aria-label', button.disabled ? 'Отправка доступна позже' : 'Отправить новый код');
                }
            }
        });

        counters.set(form, cooldown);
        cooldown.set(form.dataset.cooldownSeconds || 0);
    });
}

initContactResendCounters();
document.addEventListener('mskba:contacts-list-updated', initContactResendCounters);
