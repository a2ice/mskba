<x-filament-panels::page>
    <div class="space-y-6">
        {{-- Use the panel's own layout primitives. Arbitrary Tailwind utilities
             are not guaranteed to be present in the compiled Filament theme. --}}
        <x-filament::section
            heading="Создать временный токен"
            description="Доступ для работы с HTML, CSS и JS через API Page Builder на DEV."
            icon="heroicon-o-key"
        >
            <div class="space-y-5">
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Токен действует 24 часа. Для его создания подтверди пароль своей учётной записи.
                    Доступ к production он не предоставляет.
                </p>

                <form wire:submit="issue" class="space-y-5">
                    <div class="max-w-lg space-y-2">
                        <label
                            for="agent-api-password"
                            class="text-sm font-medium text-gray-950 dark:text-white"
                        >
                            Пароль суперадминистратора
                        </label>

                        <x-filament::input.wrapper :valid="! $errors->has('password')">
                            <x-filament::input
                                id="agent-api-password"
                                type="password"
                                autocomplete="current-password"
                                wire:model="password"
                                placeholder="Введи пароль для подтверждения"
                            />
                        </x-filament::input.wrapper>

                        @error('password')
                            <p class="text-sm text-danger-600 dark:text-danger-400" role="alert">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="flex flex-wrap items-center gap-3">
                        <x-filament::button
                            type="submit"
                            icon="heroicon-o-key"
                            wire:target="issue"
                            wire:loading.attr="disabled"
                        >
                            Создать токен
                        </x-filament::button>
                    </div>
                </form>
            </div>
        </x-filament::section>

        @if ($plainTextToken !== null)
            <x-filament::section
                heading="Токен создан"
                description="Скопируй его сейчас — после обновления страницы повторно посмотреть токен будет невозможно."
                icon="heroicon-o-check-circle"
            >
                <div class="space-y-4">
                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        Действует до <strong>{{ $expiresAt }}</strong>.
                    </p>

                    <div class="max-w-3xl space-y-2">
                        <label for="agent-api-token" class="text-sm font-medium text-gray-950 dark:text-white">
                            Токен для подключения
                        </label>

                        <x-filament::input.wrapper>
                            <x-filament::input
                                id="agent-api-token"
                                type="text"
                                :value="$plainTextToken"
                                readonly
                                autocomplete="off"
                                spellcheck="false"
                                aria-label="Временный токен Page Builder"
                                class="font-mono"
                            />
                        </x-filament::input.wrapper>
                    </div>

                    <p class="text-sm text-gray-600 dark:text-gray-400">
                        Не размещай токен в переписке, скриншотах или репозитории.
                        Добавь его в защищённые настройки GitHub Actions.
                    </p>
                </div>
            </x-filament::section>
        @endif

        <x-filament::section
            heading="Отозвать доступ"
            description="При необходимости можно немедленно отключить все ранее выданные токены агента Page Builder."
            icon="heroicon-o-shield-exclamation"
        >
            <x-filament::button
                type="button"
                color="gray"
                outlined
                icon="heroicon-o-x-circle"
                wire:click="revokeTokens"
                wire:confirm="Отозвать все действующие токены агента Page Builder?"
                wire:target="revokeTokens"
                wire:loading.attr="disabled"
            >
                Отозвать все токены
            </x-filament::button>
        </x-filament::section>

        <x-filament::section
            heading="Подключение API"
            description="Подключение выполняется через защищённый клиент, а публикация изменений — отдельным действием."
            icon="heroicon-o-code-bracket"
            collapsible
            collapsed
        >
            <div class="space-y-3 text-sm text-gray-600 dark:text-gray-400">
                <p><strong>Адрес API:</strong> <code>/api/v1/internal/page-builder/</code></p>
                <p>
                    Перед изменением клиент получает текущую версию и SHA-256.
                    При несовпадении контрольной суммы запись отклоняется.
                    История изменений и откат сохраняются в Page Builder.
                </p>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
