<div data-direct-message-dialog
     data-access-url="{{ route('account.notifications.direct.access', ['user' => '__USER__']) }}"
     data-send-url="{{ route('account.notifications.direct.send', ['user' => '__USER__']) }}">
    <dialog class="mskba-modal app-contact-wizard__dialog app-messages__direct-dialog" aria-labelledby="direct-message-dialog-title">
        <header class="mskba-modal__header mskba-auth-top app-contact-wizard__header">
            <h2 id="direct-message-dialog-title" class="eyebrow accent mskba-auth-heading" tabindex="-1">Личное сообщение</h2>
            <button type="button" class="icon-button mskba-auth-close app-contact-wizard__close" data-direct-dialog-close aria-label="Закрыть окно">
                <svg aria-hidden="true"><use href="#close"/></svg>
            </button>
        </header>
        <div class="mskba-modal__body app-contact-wizard__body">
            <p class="app-messages__direct-name" data-direct-recipient-name></p>
            <p role="status" data-direct-check>Проверяем возможность отправки сообщения…</p>
            <form data-direct-form hidden>
                <label class="field-group">Сообщение
                    <textarea name="body" rows="4" maxlength="2000" placeholder="Введите текст сообщения…" required></textarea>
                </label>
                <button type="submit" class="button primary">Отправить сообщение</button>
            </form>
            <p role="alert" data-direct-error hidden></p>
            <p role="status" data-direct-success hidden>Сообщение отправлено.</p>
        </div>
    </dialog>
</div>
