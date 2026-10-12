<div class="app-contact-wizard" data-contacts-add
     data-add-url="{{ route('account.contacts.store') }}"
     data-list-url="{{ route('account.contacts') }}"
     data-telegram-url="{{ route('account.telegram.link') }}"
     data-telegram-bot="{{ $telegramBotUsername }}">
    <button type="button" class="button primary" data-contact-add-open>Добавить контакт</button>
    <dialog class="app-contact-wizard__dialog" data-contact-add-modal aria-labelledby="contact-add-title">
        <div class="app-contact-wizard__header">
            <div>
                <p class="app-contact-wizard__eyebrow">Контакты</p>
                <h2 id="contact-add-title">Добавить контакт</h2>
            </div>
            <button type="button" class="app-contact-wizard__close" data-contact-add-close aria-label="Закрыть">×</button>
        </div>
        <div class="app-contact-wizard__body">
            <section data-contact-add-step="method">
                <h3>Выбери способ связи</h3>
                <p>Email и телефон добавляются в список, после чего их можно подтвердить. Telegram и VK подтверждаются во время авторизации.</p>
                <div class="app-contact-wizard__choices">
                    <label><input type="radio" name="new_contact_channel" value="email" checked><span><strong>Email</strong><small>Подтверждение кодом из письма</small></span></label>
                    <label><input type="radio" name="new_contact_channel" value="phone"><span><strong>Телефон</strong><small>Можно добавить, SMS-подтверждение пока недоступно</small></span></label>
                    <label><input type="radio" name="new_contact_channel" value="telegram" @disabled($telegramBotUsername === '')><span><strong>Telegram</strong><small>Официальная авторизация Telegram</small></span></label>
                    <label><input type="radio" name="new_contact_channel" value="vk" @disabled(! $vkEnabled)><span><strong>VK ID</strong><small>Официальная авторизация VK ID</small></span></label>
                </div>
            </section>
            <section data-contact-add-step="value" hidden>
                <h3 data-contact-add-value-title>Новый контакт</h3>
                <p data-contact-add-value-description>Укажи контакт. Проверим формат и доступность на сервере.</p>
                <label class="field-group" for="contacts-add-value">
                    <span data-contact-add-value-label>Контакт</span>
                    <input id="contacts-add-value" data-contact-add-value type="email"
                           maxlength="255" autocomplete="email" placeholder="name@example.com" required>
                </label>
            </section>
            <section data-contact-add-step="telegram" hidden>
                <h3>Подключи Telegram</h3>
                <p>Авторизуйся через официальный Telegram. Контакт автоматически подтвердится и появится в списке.</p>
                <div data-contact-add-telegram class="app-contact-wizard__telegram"></div>
            </section>
            <section data-contact-add-step="vk" hidden>
                <h3>Подключи VK ID</h3>
                <p>После авторизации через VK контакт будет добавлен и подтверждён автоматически.</p>
                @if ($vkEnabled)
                    <a class="button primary" href="{{ route('account.vk.link', ['redirect_to' => route('account.contacts')]) }}">Продолжить через VK ID</a>
                @endif
            </section>
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
        <div class="app-contact-wizard__footer" data-contact-add-footer>
            <button type="button" class="button secondary" data-contact-add-back hidden>Назад</button>
            <button type="button" class="button primary" data-contact-add-next>Далее</button>
        </div>
    </dialog>
</div>
