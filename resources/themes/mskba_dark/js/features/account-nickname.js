const form = document.querySelector('[data-account-nickname-form]');

if (form) {
    const input = form.querySelector('input[name="nickname"]');
    const submit = form.querySelector('[data-account-nickname-submit]');
    const status = form.querySelector('[data-account-nickname-status]');

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!input || !submit || !status) return;

        submit.disabled = true;
        status.textContent = 'Сохраняем…';
        status.classList.remove('text-danger', 'text-success');

        try {
            const response = await fetch(form.action, {
                method: 'PATCH',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || '',
                },
                body: JSON.stringify({ nickname: input.value }),
            });
            const payload = await response.json().catch(() => ({}));

            if (!response.ok) {
                const fieldError = payload.errors?.nickname?.[0];
                throw new Error(fieldError || payload.message || 'Не удалось сохранить никнейм.');
            }

            input.value = payload.nickname || '';
            status.textContent = payload.message || 'Сохранено.';
            status.classList.add('text-success');
        } catch (error) {
            status.textContent = error.message || 'Не удалось сохранить никнейм.';
            status.classList.add('text-danger');
        } finally {
            submit.disabled = false;
        }
    });
}
