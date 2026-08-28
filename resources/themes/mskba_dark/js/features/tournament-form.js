document.addEventListener('DOMContentLoaded', () => {
    const format = document.querySelector('[data-tournament-format]');
    const recruitment = document.querySelector('[data-tournament-recruitment-mode]');
    const enrollment = document.querySelector('[data-tournament-enrollment-policy]');
    const enrollmentSetting = document.querySelector('[data-tournament-enrollment-setting]');
    const roundRobinSetting = document.querySelector('[data-tournament-round-robin-setting]');
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

        const preformedTeams = recruitment.value === 'preformed_teams';
        if (enrollmentSetting instanceof HTMLElement) enrollmentSetting.hidden = !preformedTeams;
        if (roundRobinSetting instanceof HTMLElement) roundRobinSetting.hidden = !preformedTeams;
        if (enrollment instanceof HTMLSelectElement) {
            if (!preformedTeams) enrollment.value = 'fixed_pool';
            enrollment.disabled = !preformedTeams;
        }
        if (unconfirmedSetting instanceof HTMLElement) {
            unconfirmedSetting.hidden = recruitment.value !== 'individual_draft';
        }
    };

    format.addEventListener('change', sync);
    recruitment.addEventListener('change', sync);
    sync();
});
