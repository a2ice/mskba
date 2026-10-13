<div data-direct-message-dialog
     data-access-url="{{ route('account.notifications.direct.access', ['user' => '__USER__']) }}"
     data-send-url="{{ route('account.notifications.direct.send', ['user' => '__USER__']) }}"
     @if ($directTestEntry ?? false) data-test-search-url="{{ route('account.notifications.direct.test.users') }}" @endif>
    <dialog class="mskba-modal app-contact-wizard__dialog app-messages__direct-dialog" aria-labelledby="direct-message-dialog-title">
        <header class="mskba-modal__header mskba-auth-top app-contact-wizard__header">
            <h2 id="direct-message-dialog-title" class="eyebrow accent mskba-auth-heading" tabindex="-1">Личное сообщение</h2>
            <button type="button" class="icon-button mskba-auth-close app-contact-wizard__close" data-direct-dialog-close aria-label="Закрыть окно">
                <svg aria-hidden="true"><use href="#close"/></svg>
            </button>
        </header>
        <div class="mskba-modal__body app-contact-wizard__body">
            @if ($directTestEntry ?? false)
                <div class="app-messages__direct-search" data-direct-test-search>
                    <p>Тестовый способ открыть личную переписку для проверки чёрного списка. В дальнейшем переписки будут запускаться из игр, команд и заявок.</p>
                    <label for="direct-test-recipient">Найти пользователя по имени или логину</label>
                    <input id="direct-test-recipient" type="search" minlength="2" maxlength="60" autocomplete="off" placeholder="Введите минимум 2 символа" data-direct-test-query>
                    <p class="app-messages__direct-search-status" role="status" data-direct-test-status>Начните вводить имя пользователя.</p>
                    <div class="app-messages__direct-search-results" data-direct-test-results></div>
                </div>
                <button type="button" class="button secondary app-messages__direct-back" data-direct-test-back hidden>Выбрать другого пользователя</button>
            @endif
            <p class="app-messages__direct-name" data-direct-recipient-name></p>
            <p role="status" data-direct-check>Проверяем возможность отправки сообщения…</p>
            <form data-direct-form hidden>
                <label class="field-group">Сообщение
                    <textarea name="body" rows="4" maxlength="500" placeholder="Введите текст сообщения…" required></textarea>
                    <small class="app-messages__char-count" data-direct-char-count>0 / 500</small>
                </label>
                <button type="submit" class="button primary">Отправить сообщение</button>
            </form>
            <p role="alert" data-direct-error hidden></p>
            <p role="status" data-direct-success hidden>Сообщение отправлено.</p>
        </div>
    </dialog>
</div>
