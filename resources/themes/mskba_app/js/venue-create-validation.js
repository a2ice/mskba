/* Inline HTML and Laravel validation for both venue-creation entrypoints. */
document.querySelectorAll('[data-mskba-venue-create]').forEach(form => {
    let errorIndex = 0;
    let focusScheduled = false;
    const addressField = () => form.querySelector('[data-address-suggest-input]');
    const status = form.querySelector('[data-venue-create-status]');

    const labelFor = field => String(field.dataset.validationLabel
        || field.closest('label')?.firstChild?.textContent || 'Поле').trim().replace(/\s*\*$/, '');
    const validationText = field => {
        if (field.validity?.valueMissing) return field.tagName === 'SELECT'
            ? 'Выберите значение в поле «' + labelFor(field) + '».'
            : 'Заполните поле «' + labelFor(field) + '».';
        if (field.validity?.tooShort) return 'Минимум ' + field.minLength + ' символа(ов).';
        if (field.validity?.tooLong) return 'Превышена допустимая длина.';
        if (field.validity?.typeMismatch) return 'Проверьте правильность значения.';
        return field.validationMessage || 'Проверьте значение поля.';
    };
    function clearError(field) {
        if (!field) return;
        const id = field.dataset.venueErrorId;
        if (id) {
            document.getElementById(id)?.remove();
            delete field.dataset.venueErrorId;
            const described = (field.getAttribute('aria-describedby') || '').split(/\s+/)
                .filter(value => value && value !== id).join(' ');
            if (described) field.setAttribute('aria-describedby', described);
            else field.removeAttribute('aria-describedby');
        }
        field.removeAttribute('aria-invalid');
    }
    function showError(field, message) {
        if (!field || !message) return;
        clearError(field);
        const error = document.createElement('span');
        error.className = 'app-venue-create__field-error';
        error.id = 'venue-create-error-' + (++errorIndex);
        error.setAttribute('role', 'alert');
        error.textContent = message;
        const host = field === addressField()
            ? field.closest('.address-suggest__input-wrap') : field.closest('label');
        if (field === addressField() && host) host.after(error);
        else if (host) host.append(error);
        else field.after(error);
        field.dataset.venueErrorId = error.id;
        field.setAttribute('aria-invalid', 'true');
        const previous = field.getAttribute('aria-describedby') || '';
        field.setAttribute('aria-describedby', (previous + ' ' + error.id).trim());
    }
    function focusFirstError() {
        if (focusScheduled) return;
        focusScheduled = true;
        requestAnimationFrame(() => {
            focusScheduled = false;
            const first = form.querySelector('[aria-invalid="true"]');
            if (!first) return;
            first.focus({preventScroll: true});
            first.scrollIntoView({behavior:'smooth', block:'center'});
        });
    }
    function fieldForServerKey(key) {
        if (key === 'location' || key.startsWith('location.') || key === 'raw_address') {
            return addressField();
        }
        const bracketed = key.replace(/\.([^.]*)/g, '[$1]');
        return Array.from(form.elements).find(element => element.name === bracketed) || null;
    }

    form.addEventListener('invalid', event => {
        event.preventDefault();
        showError(event.target, validationText(event.target));
        focusFirstError();
    }, true);

    form.addEventListener('input', event => {
        if (event.target?.matches('input, textarea')) clearError(event.target);
    }, true);
    form.addEventListener('change', event => {
        if (event.target?.matches('input, select, textarea')) clearError(event.target);
    }, true);
    form.addEventListener('address-suggest:selected', () => clearError(addressField()));

    // A typed string does not count as an accepted suggestion.
    form.addEventListener('submit', event => {
        if (form.querySelector('[data-address-selected]')?.value === '1') return;
        event.preventDefault();
        event.stopImmediatePropagation();
        showError(addressField(), 'Выберите точный адрес из подсказок или воспользуйтесь геолокацией.');
        if (status) status.textContent = '';
        focusFirstError();
    }, true);

    form.addEventListener('venue-create:server-errors', event => {
        const errors = event.detail?.errors || {};
        for (const [key, messages] of Object.entries(errors)) {
            const field = fieldForServerKey(key);
            if (field) showError(field, Array.isArray(messages) ? messages[0] : String(messages));
        }
        focusFirstError();
    });
});
