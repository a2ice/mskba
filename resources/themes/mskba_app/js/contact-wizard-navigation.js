// One source of truth for the shared contact wizard's footer and Back behavior.
// A preselected entry (subscription +) has no method-selection step to revisit.
export function contactWizardNavigation(step, methodLocked = false) {
    if (step === 'done') {
        return { footer: false, back: false, next: false, backTarget: null, backLabel: 'Назад' };
    }
    if (step === 'method') {
        return { footer: true, back: false, next: true, backTarget: null, backLabel: 'Назад' };
    }
    if (step === 'verification') {
        // Email was entered in the prior step, even for preselected + flows.
        return { footer: true, back: true, next: false, backTarget: 'value', backLabel: 'Назад' };
    }
    return {
        footer: true,
        back: true,
        next: step === 'value',
        backTarget: methodLocked ? 'close' : 'method',
        backLabel: methodLocked ? 'Отмена' : 'Назад',
    };
}
