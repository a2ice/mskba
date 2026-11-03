<x-filament-panels::page>
    <div class="space-y-6">
        <section class="rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900">
            <h2 class="font-semibold text-gray-950 dark:text-white">Временный доступ к DEV Page Builder</h2>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                Токен действует один день и разрешает чтение, редактирование черновиков и публикацию HTML, CSS и JS.
                Он не работает на production. Не отправляй токен в переписку, сообщения GitHub или скриншоты:
                сохраняй его только в защищённых настройках подключаемого инструмента.
            </p>

            <div class="mt-5 max-w-lg space-y-3">
                <label for="agent-api-password" class="block text-sm font-medium text-gray-800 dark:text-gray-200">Подтверди пароль суперадминистратора</label>
                <input
                    id="agent-api-password"
                    type="password"
                    autocomplete="current-password"
                    wire:model="password"
                    class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-gray-900 shadow-sm focus:border-orange-500 focus:outline-none dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                />
                @error('password')
                    <p class="text-sm text-red-600">{{ $message }}</p>
                @enderror
                <div class="flex flex-wrap items-center gap-3">
                    <button
                        type="button"
                        wire:click="issue"
                        class="inline-flex items-center rounded-lg bg-orange-500 px-4 py-2 text-sm font-semibold text-white hover:bg-orange-600"
                    >Создать токен на 24 часа</button>
                    <button
                        type="button"
                        wire:click="revokeTokens"
                        wire:confirm="Отозвать все действующие токены агента Page Builder?"
                        class="inline-flex items-center rounded-lg border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-800 hover:bg-gray-50 dark:border-gray-700 dark:text-white dark:hover:bg-gray-800"
                    >Отозвать все токены агента</button>
                </div>
            </div>
        </section>

        @if($plainTextToken !== null)
            <section class="rounded-xl border border-orange-200 bg-orange-50 p-5 dark:border-orange-900 dark:bg-gray-900">
                <h2 class="font-semibold text-gray-950 dark:text-white">Новый токен · показывается только сейчас</h2>
                <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">Действует до {{ $expiresAt }}. После обновления страницы получить его повторно нельзя.</p>
                <textarea
                    readonly
                    spellcheck="false"
                    aria-label="Токен агента Page Builder"
                    class="mt-3 min-h-24 w-full resize-none rounded-lg border border-orange-200 bg-white p-3 font-mono text-xs text-gray-900 dark:border-gray-700 dark:bg-gray-950 dark:text-white"
                >{{ $plainTextToken }}</textarea>
            </section>
        @endif

        <p class="text-sm text-gray-600 dark:text-gray-300">
            API: <code>/api/v1/internal/page-builder/</code>. Все изменения фиксируются в истории Page Builder.
            Перед изменением необходимо получить SHA-256 текущей версии; публикация выполняется отдельным запросом.
        </p>
    </div>
</x-filament-panels::page>
