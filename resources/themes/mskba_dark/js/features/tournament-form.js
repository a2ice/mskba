document.addEventListener('DOMContentLoaded', () => {
    const format = document.querySelector('[data-tournament-format]');
    const recruitment = document.querySelector('[data-tournament-recruitment-mode]');
    const unconfirmedSetting = document.querySelector('[data-tournament-unconfirmed-setting]');
    if (!(format instanceof HTMLSelectElement) || !(recruitment instanceof HTMLSelectElement)) {
        return;
    }

    const sync = () => {
        const oneOnOne = format.value === 'streetball_1x1';
        if (oneOnOne) {
            recruitment.value = 'individual_draft';
        }
        recruitment.disabled = oneOnOne;
        let mirror = recruitment.form?.querySelector('input[data-tournament-recruitment-mirror]');
        if (oneOnOne && recruitment.form && !(mirror instanceof HTMLInputElement)) {
            mirror = document.createElement('input');
            mirror.type = 'hidden';
            mirror.name = 'recruitment_mode';
            mirror.dataset.tournamentRecruitmentMirror = '';
            recruitment.form.append(mirror);
        }
        if (mirror instanceof HTMLInputElement) {
            mirror.value = recruitment.value;
            mirror.disabled = !oneOnOne;
        }
        if (unconfirmedSetting instanceof HTMLElement) {
            unconfirmedSetting.hidden = recruitment.value !== 'individual_draft';
        }
    };

    format.addEventListener('change', sync);
    recruitment.addEventListener('change', sync);
    sync();
});
