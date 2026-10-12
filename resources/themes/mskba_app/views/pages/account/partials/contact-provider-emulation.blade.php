<section data-provider-emulation-step>
    <p class="app-contact-emulation__notice" role="note">
        Тестовый режим: реальная авторизация Telegram/VK не выполняется.
        Создаётся только тестовый контакт с ID test_emulate_001, test_emulate_002 и т. д.
    </p>
    <label class="field-group" for="{{ $emulationOutcomeId ?? 'provider-emulation-outcome' }}">
        Сценарий проверки
        <select id="{{ $emulationOutcomeId ?? 'provider-emulation-outcome' }}" data-provider-emulation-outcome>
            <option value="success">Успешная авторизация</option>
            <option value="denied">Доступ отклонён</option>
            <option value="cancelled">Пользователь отменил вход</option>
            <option value="unavailable">Сервис недоступен</option>
            <option value="timeout">Превышено время ожидания</option>
            <option value="conflict">Аккаунт уже привязан</option>
        </select>
    </label>
    <button type="button" class="button primary app-contact-emulation__run" data-provider-emulation-run>
        Проверить сценарий
    </button>
</section>
