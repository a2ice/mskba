const STORAGE_KEY = 'mskba.team-join-intent';

document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-team-join-auth-intent]');

    if (!trigger) {
        return;
    }

    sessionStorage.setItem(STORAGE_KEY, trigger.dataset.teamJoinAuthIntent || '');
}, true);

document.addEventListener('DOMContentLoaded', () => {
    const form = document.querySelector('[data-team-join-auto-form]');

    if (!form) {
        return;
    }

    const intent = form.dataset.teamJoinAutoForm || '';

    if (!intent || sessionStorage.getItem(STORAGE_KEY) !== intent) {
        return;
    }

    sessionStorage.removeItem(STORAGE_KEY);
    removeIntentFromAddressBar();
    form.requestSubmit();
});

function removeIntentFromAddressBar() {
    const url = new URL(window.location.href);
    url.searchParams.delete('team_join_intent');
    window.history.replaceState({}, '', url.toString());
}
