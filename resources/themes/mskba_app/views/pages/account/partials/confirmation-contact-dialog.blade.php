<div class="app-contact-wizard" data-contact-wizard
     data-store-url="{{ route('account.confirmation.contact.store') }}"
     data-resend-url="{{ route('account.confirmation.contacts.verification.store', ['contact' => '__ID__']) }}"
     data-confirm-url="{{ route('account.confirmation.contacts.verification.confirm', ['contact' => '__ID__']) }}"
     data-confirmation-url="{{ route('account.confirmation') }}"
     data-telegram-url="{{ route('account.telegram.link') }}"
     data-telegram-bot="{{ $telegramBotUsername }}">
    <div class="app-contact-wizard__trigger">
        @if ($primaryContact && ! $primaryContact->is_verified && $primaryContact->type === \App\Modules\Contact\Domain\Enums\ContactTypeEnum::EMAIL)
            <button type="button" class="button primary" data-contact-wizard-open
                    data-existing-contact="{{ $primaryContact->id }}"
                    data-email-value="{{ $primaryContact->value }}">Ввести код подтверждения</button>
        @endif
        <button type="button" class="button {{ $primaryContact ? 'secondary' : 'primary' }}" data-contact-wizard-open>
            {{ $primaryContact ? 'Добавить другой контакт' : 'Добавить контакт' }}
        </button>
    </div>
    <p class="app-contact-wizard__hint">После подтверждения первого контакта он автоматически становится основным. Позже его можно поменять в разделе «Контакты».</p>

    <dialog class="app-contact-wizard__dialog" aria-labelledby="contact-wizard-title">
        <div class="app-contact-wizard__header">
            <div>
                <p class="app-contact-wizard__eyebrow">Подтверждение аккаунта</p>
                <h2 id="contact-wizard-title">Добавить контакт</h2>
            </div>
            <button class="app-contact-wizard__close" type="button" data-wizard-close aria-label="Закрыть">×</button>
        </div>

        <div class="app-contact-wizard__body">
            <section data-wizard-step="method">
                <h3>Выбери способ связи</h3>
                <p>Контакт понадобится для подтверждения аккаунта и восстановления доступа.</p>
                <div class="app-contact-wizard__choices">
                    <label><input type="radio" name="confirmation_channel" value="email" checked><span><strong>Email</strong><small>Код подтверждения на почту</small></span></label>
                    <label><input type="radio" name="confirmation_channel" value="telegram" @disabled($telegramBotUsername === '')><span><strong>Telegram</strong><small>Авторизация через Telegram</small></span></label>
                    <label><input type="radio" name="confirmation_channel" value="vk" @disabled(! $vkEnabled)><span><strong>VK ID</strong><small>Авторизация через VK</small></span></label>
                    <label class="is-disabled"><input type="radio" name="confirmation_channel" value="phone" disabled><span><strong>Телефон</strong><small>SMS-подтверждение скоро появится</small></span></label>
                </div>
            </section>

            <section data-wizard-step="email" hidden>
                <h3>Укажи Email</h3>
                <p>Проверим адрес на сервере, затем отправим шестизначный код.</p>
                <label class="field-group" for="wizard-email">Адрес электронной почты
                    <input id="wizard-email" data-wizard-email type="email" inputmode="email" maxlength="255"
                           placeholder="name@example.com" autocomplete="email" required>
                </label>
            </section>

            <section data-wizard-step="code" hidden>
                <h3>Подтверди Email</h3>
                <p>Введи код из письма. Код действует 15 минут. Не более пяти попыток ввода.</p>
                <label class="field-group" for="wizard-code">Шестизначный код
                    <input id="wizard-code" data-wizard-code type="text" inputmode="numeric"
                           autocomplete="one-time-code" maxlength="6" pattern="[0-9]{6}" placeholder="000000">
                </label>
                <button type="button" class="app-contact-wizard__resend" data-wizard-resend>Отправить новый код</button>
            </section>

            <section data-wizard-step="telegram" hidden>
                <h3>Подключи Telegram</h3>
                <p>Авторизуйся через официальный Telegram. Используем подтверждённый Telegram ID — ввод @username не требуется.</p>
                <div data-wizard-telegram class="app-contact-wizard__telegram"></div>
            </section>

            <section data-wizard-step="vk" hidden>
                <h3>Подключи VK ID</h3>
                <p>Для подтверждения контакта потребуется официальная авторизация VK. После неё вернёшься на эту страницу.</p>
                @if ($vkEnabled)
                    <a class="button primary" href="{{ route('account.vk.link', ['redirect_to' => route('account.confirmation')]) }}">Продолжить через VK ID</a>
                @else
                    <p>Сейчас VK ID недоступен.</p>
                @endif
            </section>

            <p data-wizard-error class="app-contact-wizard__error" role="alert" hidden></p>
            <p data-wizard-feedback class="app-contact-wizard__feedback" role="status" aria-live="polite" hidden></p>
        </div>
        <div class="app-contact-wizard__footer">
            <button type="button" class="button secondary" data-wizard-back hidden>Назад</button>
            <button type="button" class="button primary" data-wizard-next>Далее</button>
        </div>
    </dialog>
</div>
