// One reusable confirmation gate for account, player and future role profiles.
// Always check *saved* values. The backend independently validates again on POST.
document.querySelectorAll('[data-confirm-review-dialog]').forEach(dialog => {
    const form = document.getElementById(dialog.dataset.confirmReviewTarget);
    if (!form) return;

    const missingDialog = [...document.querySelectorAll('[data-confirm-missing-dialog]')]
        .find(item => item.dataset.confirmMissingTarget === form.id);
    const context = form.dataset.confirmContext || 'player';
    let accepted = false;
    let previousFocus = null;

    const sourceRow = name => {
        const root = context === 'confirmation'
            ? form : document.querySelector('[data-account-profile] [data-profile-personal-form]');
        return root?.querySelector('[data-inline-profile-field][data-field-name="' + name + '"]');
    };
    const requirementNodes = [...form.querySelectorAll('[data-confirm-requirement]')];

    const savedValue = node => {
        const name = node.dataset.confirmName || node.name;
        if (context === 'player') return String(node.value || '').trim();
        const row = sourceRow(name);
        return String(row?.dataset.savedValue ?? form.elements.namedItem(name)?.value ?? '').trim();
    };
    const display = (name, value) => {
        if (name === 'gender') return ({male: 'Мужской', female: 'Женский'})[value] || value;
        if (name === 'birth_date' && /^[0-9]{4}-[0-9]{2}-[0-9]{2}$/.test(value)) {
            const [year, month, day] = value.split('-');
            return [day, month, year].join('.');
        }
        if (name === 'height_cm' && value) return value + ' см';
        return value;
    };

    const requiredCheck = () => {
        const missing = [];
        let unsaved = false;
        for (const node of requirementNodes) {
            const name = node.dataset.confirmName || node.name;
            const value = savedValue(node);
            const label = node.dataset.confirmLabel;
            if (!value) missing.push({label, href: node.dataset.confirmHref || ''});
            if (context !== 'player') {
                const row = sourceRow(name);
                const input = row?.querySelector('[data-inline-profile-input]');
                if (input && String(input.value || '').trim() !== String(row.dataset.savedValue || '').trim()) {
                    unsaved = true;
                }
                const hiddenInput = form.querySelector('input[type="hidden"][name="' + name + '"]');
                if (hiddenInput && context === 'profile') hiddenInput.value = value;
            }
        }
        if (context !== 'player') {
            const root = context === 'confirmation'
                ? form : document.querySelector('[data-account-profile] [data-profile-personal-form]');
            if (root) {
                unsaved = unsaved || [...root.querySelectorAll('[data-inline-profile-field]')].some(row => {
                    const input = row.querySelector('[data-inline-profile-input]');
                    return input && String(input.value || '').trim() !== String(row.dataset.savedValue || '').trim();
                });
                const saving = [...root.querySelectorAll('[data-inline-profile-loading]')]
                    .some(loader => !loader.hidden);
                unsaved = unsaved || saving;
            }
            if (form.dataset.confirmContactVerified !== 'true') {
                missing.push({
                    label: 'Подтверждённый основной контакт',
                    href: form.dataset.confirmContactHref || '',
                });
            }
            if (unsaved) {
                missing.push({
                    label: 'Сохрани изменения в личных данных перед подтверждением',
                    href: context === 'confirmation' ? '#confirmation-profile-title' : '#app-profile-personal-heading',
                });
            }
        }
        return missing;
    };

    const openDialog = target => {
        if (!target?.showModal) return;
        target.showModal();
        target.querySelector('h2[tabindex="-1"]')?.focus({preventScroll: true});
    };
    const refreshReview = () => {
        dialog.querySelectorAll('[data-confirm-review-live]').forEach(target => {
            const field = target.dataset.confirmReviewLive;
            const node = requirementNodes.find(item => (item.dataset.confirmName || item.name) === field);
            if (!node) return;
            target.textContent = display(field, savedValue(node)) || 'Не указано';
        });
    };

    form.addEventListener('submit', event => {
        if (accepted) {
            accepted = false;
            return;
        }
        event.preventDefault();
        if (event.defaultPrevented && !form.checkValidity()) return;
        previousFocus = document.activeElement;
        const missing = requiredCheck();
        if (missing.length) {
            const list = missingDialog?.querySelector('[data-confirm-missing-list]');
            if (!list) return;
            list.replaceChildren();
            for (const item of missing) {
                const li = document.createElement('li');
                if (item.href) {
                    const link = document.createElement('a');
                    link.href = item.href;
                    link.textContent = item.label;
                    li.appendChild(link);
                } else {
                    li.textContent = item.label;
                }
                list.appendChild(li);
            }
            openDialog(missingDialog);
            return;
        }
        refreshReview();
        openDialog(dialog);
    });

    missingDialog?.querySelectorAll('[data-confirm-missing-close]').forEach(button => {
        button.addEventListener('click', () => missingDialog.close());
    });
    // A link to a field on this very page must dismiss the top-layer dialog;
    // otherwise the fragment scrolls behind an open modal.
    missingDialog?.addEventListener('click', event => {
        if (event.target.closest('a[href]')) missingDialog.close();
    });
    missingDialog?.addEventListener('close', () => {
        if (previousFocus?.isConnected) previousFocus.focus({preventScroll: true});
    });

    dialog.querySelectorAll('[data-confirm-review-close]').forEach(button => {
        button.addEventListener('click', () => dialog.close());
    });
    dialog.querySelector('[data-confirm-review-accept]')?.addEventListener('click', () => {
        accepted = true;
        dialog.close();
        form.requestSubmit();
    });
    dialog.addEventListener('close', () => {
        if (previousFocus?.isConnected) previousFocus.focus({preventScroll: true});
    });
});
