<x-filament-panels::page>
    <div class="space-y-4">
        <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-700 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
            <strong>GraphQL API</strong> · <code>/api/graphql</code>
            <p class="mt-1">
                Здесь можно изучать схему, запускать запросы и просматривать JSON. Площадкам токен не нужен.
                Для запроса <code>me</code> вставьте свой временный DEV Bearer-токен ниже.
                Токен не сохраняется в браузере или в базе; не вставляйте чужие или production-токены.
            </p>
            <div class="mt-3 flex flex-wrap items-end gap-3">
                <label class="flex min-w-56 flex-1 flex-col gap-1 text-xs" for="mskba-graphiql-token">
                    Bearer-токен DEV (необязательно)
                    <input
                        id="mskba-graphiql-token"
                        type="password"
                        autocomplete="off"
                        spellcheck="false"
                        placeholder="Вставьте токен для запроса me"
                        class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-900 dark:border-gray-600 dark:bg-gray-800 dark:text-gray-100"
                    />
                </label>
                <button
                    type="button"
                    id="mskba-graphiql-clear-token"
                    class="rounded-lg border border-gray-300 px-3 py-2 text-sm font-medium hover:bg-gray-100 dark:border-gray-600 dark:hover:bg-gray-800"
                >Очистить токен</button>
            </div>
        </div>

        <div wire:ignore>
            <div
                id="mskba-graphiql-root"
                class="mskba-graphiql-root"
                data-endpoint="{{ url('/api/graphql') }}"
                aria-label="GraphiQL — редактор запросов GraphQL MSKBA"
            ></div>
        </div>
    </div>

    @vite('resources/js/backoffice-graphiql.jsx')
</x-filament-panels::page>
