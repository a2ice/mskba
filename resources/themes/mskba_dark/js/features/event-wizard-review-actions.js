document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('[data-event-wizard]').forEach(initReviewActions);
});

function initReviewActions(form) {
    const review = form.querySelector('[data-wizard-step="review"]');
    const actions = form.querySelector('[data-wizard-actions]');
    const nextButton = form.querySelector('[data-wizard-next]');
    const submitButton = form.querySelector('[data-wizard-submit]');

    if (!review || !actions || !submitButton) return;

    const placeholder = document.createElement('span');
    placeholder.hidden = true;
    placeholder.dataset.wizardSubmitPlaceholder = '1';
    submitButton.before(placeholder);

    const sync = () => {
        const isReview = !review.hidden;

        if (isReview) {
            actions.hidden = false;
            if (nextButton) nextButton.hidden = true;
            if (submitButton.parentElement !== actions) actions.append(submitButton);
            submitButton.classList.add('event-wizard-submit--in-actions');
            return;
        }

        if (submitButton.parentElement === actions) placeholder.after(submitButton);
        submitButton.classList.remove('event-wizard-submit--in-actions');
    };

    const observer = new MutationObserver(sync);
    observer.observe(review, { attributes: true, attributeFilter: ['hidden'] });
    sync();
}
