// Switch the stylesheet only after the server accepts the preference.
document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-design-theme-picker]');
    if (!form) return;
    const select = form.querySelector('select[name="design_theme_id"]');
    const status = form.querySelector('[data-theme-preference-status]');
    const submit = form.querySelector('button[type="submit"]');
    if (!select || !status || !submit) return;

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        submit.disabled = true;
        status.textContent = 'Сохраняем…';
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
                body: new FormData(form),
            });
            if (!response.ok) throw new Error('Ошибка сохранения настроек.');
            const data = await response.json();
            const stylesheet = document.getElementById('mskba-appearance-css');
            if (stylesheet) stylesheet.href = data.css_url;
            document.documentElement.dataset.designTheme = data.theme;
            document.documentElement.dataset.appearanceMode = data.mode;
            select.dataset.current = data.preference_id ?? '';
            status.textContent = 'Тема «' + data.name + '» применена.';
        } catch (error) {
            select.value = select.dataset.current || '';
            status.textContent = 'Не получилось изменить тему. Попробуй ещё раз.';
        } finally {
            submit.disabled = false;
        }
    });
});
