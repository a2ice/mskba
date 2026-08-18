import $ from 'jquery';

const setHint = (widget, message) => {
    const hint = widget.querySelector('[data-reaction-hint]');
    if (!hint) {
        return;
    }

    hint.textContent = message;
    hint.hidden = false;

    window.clearTimeout(hint._reactionTimeout);
    hint._reactionTimeout = window.setTimeout(() => {
        hint.hidden = true;
    }, 2600);
};

const updateWidget = (widget, payload) => {
    const current = payload.viewer_reaction === null ? '' : String(payload.viewer_reaction);
    widget.dataset.reactionCurrent = current;

    const likes = widget.querySelector('[data-reaction-count="likes"]');
    const dislikes = widget.querySelector('[data-reaction-count="dislikes"]');
    if (likes) {
        likes.textContent = String(payload.likes ?? 0);
    }
    if (dislikes) {
        dislikes.textContent = String(payload.dislikes ?? 0);
    }

    widget.querySelectorAll('[data-reaction-value]').forEach((button) => {
        const active = String(button.dataset.reactionValue) === current;
        button.classList.toggle('is-active', active);
        button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
};

$(document).on('click', '[data-reaction-value]', function () {
    const button = this;
    const widget = button.closest('[data-reaction-widget]');
    if (!widget || widget.classList.contains('is-loading')) {
        return;
    }

    if (widget.dataset.reactionAuthenticated !== '1') {
        setHint(widget, 'Войдите, чтобы оценить');
        return;
    }

    const clickedValue = Number(button.dataset.reactionValue);
    const currentValue = widget.dataset.reactionCurrent === ''
        ? null
        : Number(widget.dataset.reactionCurrent);
    const nextValue = currentValue === clickedValue ? null : clickedValue;

    widget.classList.add('is-loading');
    widget.querySelectorAll('button').forEach((item) => {
        item.disabled = true;
    });

    $.ajax({
        url: widget.dataset.reactionUrl,
        method: 'PUT',
        contentType: 'application/json',
        data: JSON.stringify({ value: nextValue }),
    })
        .done((payload) => updateWidget(widget, payload))
        .fail((xhr) => {
            if (xhr.status === 401 || xhr.status === 419) {
                setHint(widget, 'Войдите заново, чтобы оценить');
                return;
            }

            setHint(widget, 'Не удалось сохранить оценку');
        })
        .always(() => {
            widget.classList.remove('is-loading');
            widget.querySelectorAll('button').forEach((item) => {
                item.disabled = false;
            });
        });
});
