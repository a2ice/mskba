<x-filament-panels::page>
    <div class="space-y-4">
        <div class="rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-700 shadow-sm dark:border-gray-700 dark:bg-gray-900 dark:text-gray-300">
            <strong>REST API v1</strong> · <code>/api/v1/</code> · OpenAPI 3.1
            <p class="mt-1">Выберите метод, откройте параметры и нажмите <strong>Try it out</strong> → <strong>Execute</strong>.
                Для защищённых методов используйте <strong>Authorize</strong> и собственный DEV Bearer-токен.
                Не вводите здесь production-пароли и токены. Доступ к этому интерфейсу не даёт дополнительных API-прав.</p>
            <a href="{{ route('backoffice.rest-api.openapi') }}" class="mt-2 inline-block text-sm font-semibold text-primary-600 underline dark:text-primary-400"
                download="mskba-openapi-v1.json">Скачать OpenAPI 3.1 JSON для iOS/Android</a>
        </div>
        <div wire:ignore>
            <div id="mskba-swagger-ui" data-spec-url="{{ route('backoffice.rest-api.openapi') }}"
                aria-label="REST API MSKBA — Swagger UI"></div>
        </div>
    </div>
    @vite('resources/js/backoffice-swagger.js')
</x-filament-panels::page>
