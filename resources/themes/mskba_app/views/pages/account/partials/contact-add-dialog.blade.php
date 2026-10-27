<div class="app-contact-wizard" data-contacts-add
     data-add-url="{{ route('account.contacts.store') }}"
     data-list-url="{{ route('account.contacts') }}"
     data-telegram-url="{{ route('account.telegram.link') }}"
     data-telegram-start-url="{{ route('account.telegram.bot.link.start') }}"
     data-telegram-status-url="{{ route('account.telegram.bot.link.status') }}"
     data-telegram-bot="{{ $telegramBotUsername }}"
     @if ($contactEmulationEnabled ?? false) data-emulate-url="{{ route('account.contacts.provider-emulation') }}" @endif>
    @unless($contactDialogOnly ?? false)
        <button type="button" class="button primary" data-contact-add-open>Добавить контакт</button>
    @endunless
    <dialog class="mskba-modal app-contact-wizard__dialog" data-contact-add-modal aria-labelledby="contact-add-title">
        <header class="mskba-modal__header mskba-auth-top app-contact-wizard__header">
            <h2 id="contact-add-title" tabindex="-1" class="eyebrow accent mskba-auth-heading">Добавить контакт</h2>
            <button type="button" class="icon-button mskba-auth-close app-contact-wizard__close" data-contact-add-close aria-label="Закрыть окно"><svg aria-hidden="true"><use href="#close"/></svg></button>
        </header>
        <div class="mskba-modal__body mskba-scroll app-contact-wizard__body">
            <section data-contact-add-step="method">
                <div class="app-contact-wizard__choices">
                    <label><input type="radio" name="new_contact_channel" value="email" checked><span><strong>Email</strong></span></label>
                    <label><input type="radio" name="new_contact_channel" value="phone"><span><strong>Телефон</strong><small>Можно добавить, SMS-подтверждение пока недоступно</small></span></label>
                    <label><input type="radio" name="new_contact_channel" value="telegram" @disabled($telegramBotUsername === '' && ! ($contactEmulationEnabled ?? false))><span><strong>Telegram</strong></span></label>
                    <label @unless($vkEnabled || ($contactEmulationEnabled ?? false)) title="VK пока недоступен: не настроена авторизация" @endunless><input type="radio" name="new_contact_channel" value="vk" @disabled(! $vkEnabled && ! ($contactEmulationEnabled ?? false))><span><strong>VK</strong></span></label>
                </div>
            </section>
            <section data-contact-add-step="value" hidden>
                <label class="field-group" for="contacts-add-value">
                    <span data-contact-add-value-label>Контакт</span>
                    <input id="contacts-add-value" data-contact-add-value type="email"
                           maxlength="255" autocomplete="email" placeholder="name@example.com" required>
                </label>
            </section>
            <section data-contact-add-step="verification" hidden>
                <h3>Подтверждение Email</h3>
                <p>Мы отправили шестизначный код на указанный адрес. Введи код, чтобы включить Email-уведомления.</p>
                <label class="field-group" for="contact-add-verification-code">Код подтверждения
                    <input id="contact-add-verification-code" data-contact-add-code type="text" inputmode="numeric"
                           autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="000000">
                </label>
                <div class="app-contact-wizard__trigger">
                    <button type="button" class="button primary" data-contact-add-verify>Подтвердить код</button>
                    <button type="button" class="button secondary" data-contact-add-resend>Отправить код повторно</button>
                </div>
            </section>
            <section data-contact-add-step="telegram" hidden>
                <p>Для подключения Telegram необходимо перейти в официальный Telegram-бот и там следовать инструкциям.</p>
                <a data-bot-link href="#" class="button primary" target="_blank" rel="noopener noreferrer" hidden>Перейти в Telegram-бот</a>
                <p data-bot-status role="status" aria-live="polite"></p>
            </section>
            <section data-contact-add-step="vk" hidden>
                <h3>Подключи VK ID</h3>
                <p>После авторизации через VK контакт будет добавлен и подтверждён автоматически.</p>
                @if ($vkEnabled)
                    <a class="button primary" href="{{ route('account.vk.link', ['redirect_to' => route('account.contacts')]) }}">Продолжить через VK ID</a>
                @endif
            </section>
            @if ($contactEmulationEnabled ?? false)
                <div data-contact-add-step="emulation" hidden>
                    @include('theme::pages.account.partials.contact-provider-emulation', ['emulationOutcomeId' => 'contacts-provider-emulation-outcome'])
                </div>
            @endif
            <section data-contact-add-step="done" hidden>
                <h3>Контакт добавлен</h3>
                <p data-contact-add-success>Новый контакт появился в списке ниже. Подтверждение можно выполнить отдельно.</p>
                <div class="app-contact-wizard__trigger">
                    <button type="button" class="button primary" data-contact-add-again>Добавить ещё контакт</button>
                    <button type="button" class="button secondary" data-contact-add-close>Закрыть</button>
                </div>
            </section>
            <p class="app-contact-wizard__error" data-contact-add-error role="alert" hidden></p>
        </div>
        <div class="mskba-modal__footer app-contact-wizard__footer" data-contact-add-footer>
            <button type="button" class="button secondary" data-contact-add-back hidden>Назад</button>
            <button type="button" class="button primary" data-contact-add-next>Далее</button>
        </div>
    </dialog>
</div>
