// Resume an explicitly requested "save my intent" after registration/onboarding.
// Never replay on login without the one-time submitted draft in sessionStorage.
const key = 'mskba:play-intent:pending:v1';
function returnToIntent() {
    let draft;
    try { draft = JSON.parse(sessionStorage.getItem(key) || 'null'); } catch { return; }
    if (!draft || typeof draft.path !== 'string' || !draft.path.startsWith('/venues/')) return;
    if (Date.now() - draft.createdAt > 30 * 60 * 1000) {
        try { sessionStorage.removeItem(key); } catch {}
        return;
    }
    const authenticated = document.querySelector('meta[name="mskba-authenticated"]')?.content === '1';
    if (!authenticated || document.querySelector('[data-onboarding-pending]')) return;
    if (location.pathname !== draft.path) location.replace(draft.path);
}
window.addEventListener('mskba:onboarding-completed', returnToIntent);
returnToIntent();
