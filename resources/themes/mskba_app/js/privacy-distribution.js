// Task 015: progressive UI enhancement for server-validated onboarding controls.
export function initPrivacyDistribution(form) {
    if (!form || form.dataset.privacyBound === '1') return;
    form.dataset.privacyBound = '1';
    const profile = form.querySelector('[data-privacy-profile]');
    const search = form.querySelector('[data-privacy-search]');
    const profileChildren = [...form.querySelectorAll('[data-privacy-profile-child]')];
    const searchChildren = [...form.querySelectorAll('[data-privacy-search-child]')];
    const avatar = form.querySelector('[name="public[avatar]"]');
    const playerRoot = form.querySelector('[data-privacy-player-root]');
    const playerChildren = [...form.querySelectorAll('[data-privacy-player-child]')];
    const playerPanel = form.querySelector('[data-privacy-player-children]');
    const searchGroup = form.querySelector('[data-privacy-search-group]');
    const publishing = [profile, ...profileChildren];
    const consent = form.querySelector('[data-privacy-consent]');
    const count = form.querySelector('[data-privacy-count]');

    function updateState() {
        const profileOpen = profile.checked;
        // A private profile must not be discoverable, even when old values
        // or a browser-restored form would otherwise tick the search switch.
        if (!profileOpen) search.checked = false;
        const searchOpen = profileOpen && search.checked;
        search.disabled = !profileOpen;
        searchGroup.classList.toggle('privacy-onboarding__group--inactive', !profileOpen);
        searchGroup.setAttribute('aria-disabled', String(!profileOpen));
        form.querySelector('[data-privacy-search-closed-text]').textContent = profileOpen
            ? 'Вы не появляетесь в поиске, новые сообщения и приглашения отключены.'
            : 'Сначала откройте профиль, чтобы настроить видимость в поиске.';

        form.querySelector('[data-privacy-profile-children]').hidden = !profileOpen;
        form.querySelector('[data-privacy-profile-closed]').hidden = profileOpen;
        for (const option of profileChildren) {
            option.disabled = !profileOpen;
        }
        if (playerPanel) {
            const playerOpen = profileOpen && playerRoot.checked;
            playerPanel.hidden = !playerOpen;
            playerRoot.setAttribute('aria-expanded', String(playerOpen));
            for (const option of playerChildren) option.disabled = !playerOpen;
        }

        form.querySelector('[data-privacy-search-children]').hidden = !searchOpen;
        form.querySelector('[data-privacy-search-closed]').hidden = searchOpen;
        for (const option of searchChildren) {
            option.disabled = !searchOpen;
        }

        const selected = publishing.filter(option => !option.disabled && option.checked).length;
        count.textContent = selected === 0
            ? 'Ничего не выбрано'
            : selected === 1 ? '1 пункт выбран'
                : selected < 5 ? selected + ' пункта выбраны' : selected + ' пунктов выбрано';

        const consentBody = form.querySelector('[data-privacy-consent-body]');
        consentBody.hidden = selected === 0;
        form.querySelector('[data-privacy-no-consent]').hidden = selected !== 0;
        consent.disabled = selected === 0;
        consent.required = selected > 0;
        if (selected === 0) consent.checked = false;
    }

    form.addEventListener('change', event => {
        if (event.target === profile) {
            if (profile.checked) {
                // Convenient initial proposal, never a forced permission:
                // both switches can be changed independently afterwards.
                if (avatar) avatar.checked = true;
                search.checked = true;
            } else {
                // Discard latent approvals on closure, rather than silently
                // restoring them if the user opens the profile again.
                for (const input of profileChildren) input.checked = false;
                search.checked = false;
                for (const select of searchChildren) select.value = 'nobody';
            }
        }
        if (event.target === search && !search.checked) {
            for (const select of searchChildren) select.value = 'nobody';
        }
        if (event.target === playerRoot && !playerRoot.checked) {
            for (const input of playerChildren) input.checked = false;
        }
        if (event.target === profile || event.target === search
            || event.target.matches('[data-privacy-option]')) {
            updateState();
        }
    });
    updateState();
}

initPrivacyDistribution(document.querySelector('[data-privacy-distribution]'));

// Toast uses the approved design-system .toast. The static notice remains
// visible without JavaScript; this nudge is once per tab until next session.
const reminderToast = document.querySelector('[data-privacy-reminder-toast]');
if (reminderToast) {
    let alreadyShown = false;
    try {
        const key = 'mskba:privacy-onboarding-reminder';
        alreadyShown = sessionStorage.getItem(key) === 'shown';
        if (!alreadyShown) sessionStorage.setItem(key, 'shown');
    } catch {
        // Browser privacy restrictions must never interrupt registration.
    }

    if (!alreadyShown) {
        reminderToast.hidden = false;
        reminderToast.querySelector('[data-privacy-toast-close]')?.addEventListener('click', () => {
            reminderToast.hidden = true;
        });
    }
}


/** Task 019 contact preview only: selectable tabs, no persistence or OTP. */
export function initNotificationPreview(root) {
    if (!root || root.dataset.notificationBound === '1') return;
    root.dataset.notificationBound = '1';
    const workspace = root.querySelector('[data-notification-workspace]');
    if (!workspace) return;

    const tabs = [...root.querySelectorAll('[data-notification-tab]')];
    let active = null;

    const tabFor = kind => tabs.find(tab => tab.dataset.notificationTab === kind);
    const opened = () => tabs.filter(tab => !tab.hidden);

    function activate(kind, focus = false) {
        active = kind;
        for (const tab of tabs) {
            const selected = !tab.hidden && tab.dataset.notificationTab === kind;
            const button = tab.querySelector('[role="tab"]');
            button.setAttribute('aria-selected', String(selected));
            button.tabIndex = selected ? 0 : -1;
        }
        root.querySelectorAll('[data-notification-draft]').forEach(panel => {
            panel.hidden = panel.dataset.notificationDraft !== kind;
        });
        if (focus) tabFor(kind)?.querySelector('[role="tab"]')?.focus({ preventScroll: true });
    }

    root.addEventListener('click', event => {
        const add = event.target.closest('[data-notification-add]');
        if (add) {
            const kind = add.dataset.notificationAdd;
            const tab = tabFor(kind);
            if (!tab) return;
            tab.hidden = false;
            workspace.hidden = false;
            activate(kind);
            // Keep focus on the user's selected action rather than opening
            // keyboard / browser autocomplete in a new contact input.
            return;
        }

        const select = event.target.closest('[data-notification-select]');
        if (select) {
            activate(select.dataset.notificationSelect, true);
            return;
        }

        const close = event.target.closest('[data-notification-close]');
        if (!close) return;
        const kind = close.dataset.notificationClose;
        const tab = tabFor(kind);
        if (!tab || tab.hidden) return;
        const wasActive = kind === active;
        const closedIndex = tabs.indexOf(tab);
        tab.hidden = true;
        // Closing a draft discards only its unsaved local contact input.
        tab.querySelector('[role="tab"]').setAttribute('aria-selected', 'false');
        const panel = root.querySelector('[data-notification-draft="' + kind + '"]');
        panel?.querySelectorAll('input:not([disabled])').forEach(input => { input.value = ''; });
        if (opened().length === 0) {
            workspace.hidden = true;
            active = null;
            panel.hidden = true;
            root.querySelector('[data-notification-add="' + kind + '"]')?.focus({ preventScroll: true });
            return;
        }
        if (wasActive) {
            // Prefer the next visible tab to the right, otherwise the previous.
            const next = tabs.slice(closedIndex + 1).find(t => !t.hidden)
                || [...tabs.slice(0, closedIndex)].reverse().find(t => !t.hidden);
            activate(next.dataset.notificationTab, true);
        } else {
            activate(active);
            tabFor(active)?.querySelector('[role="tab"]')?.focus({ preventScroll: true });
        }
    });

    root.addEventListener('keydown', event => {
        const button = event.target.closest('[role="tab"][data-notification-select]');
        if (!button || !['ArrowRight', 'ArrowLeft', 'Home', 'End'].includes(event.key)) return;
        const items = opened();
        if (!items.length) return;
        event.preventDefault();
        const index = items.findIndex(tab => tab.dataset.notificationTab === button.dataset.notificationSelect);
        let next;
        if (event.key === 'Home') next = items[0];
        else if (event.key === 'End') next = items[items.length - 1];
        else next = items[(index + (event.key === 'ArrowRight' ? 1 : -1) + items.length) % items.length];
        activate(next.dataset.notificationTab, true);
    });
}

initNotificationPreview(document.querySelector('[data-onboarding-notification-preview]'));
