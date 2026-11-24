<x-filament-panels::page>
    <x-filament::section heading="Последний подтверждённый деплой">
        <p class="text-sm text-gray-500">Окружение: {{ $environment }}. Сохранённая точка не является проверкой текущего состояния в реальном времени.</p>
        @if ($checkpoint)
            <dl class="grid gap-3 mt-4">
                <div><dt>Функциональная версия</dt><dd class="font-mono break-all">{{ $checkpoint['functional_version'] }}</dd></div>
                <div><dt>Functional SHA-256</dt><dd class="font-mono break-all">{{ $checkpoint['functional_sha256'] }}</dd></div>
                <div><dt>Git SHA</dt><dd class="font-mono break-all">{{ $checkpoint['git_sha'] }}</dd></div>
                <div><dt>Подтверждён в UTC</dt><dd>{{ $checkpoint['verified_at'] }}</dd></div>
            </dl>
        @else
            <p class="mt-4">Контрольная точка отсутствует. Соответствие не подтверждено.</p>
        @endif
    </x-filament::section>
    <x-filament::section heading="Сравнение main и dev">
        <p>Отчёт GitHub Actions сравнивает два точных дерева файлов и содержит полный список функциональных, служебных и сгенерированных различий. Для актуальной проверки развёрнутого кода запустите workflow вручную из main.</p>
        <a href="{{ $comparisonUrl }}" target="_blank" rel="noopener noreferrer" class="mt-3 inline-block underline">Открыть отчёты и запустить проверку</a>
        <p class="mt-2 text-sm text-gray-500">Данные БД, настройки окружения и состояние интеграций не входят в функциональный SHA-256.</p>
    </x-filament::section>
    <x-filament::section heading="История контрольных точек">
        @forelse ($history as $entry)
            <div class="py-2 border-b border-gray-200 dark:border-gray-700">
                <div>{{ $entry['verified_at'] }} · {{ $entry['functional_version'] }}</div>
                <div class="font-mono text-sm break-all">{{ $entry['git_sha'] }}</div>
            </div>
        @empty
            <p>История пока пуста.</p>
        @endforelse
    </x-filament::section>
</x-filament-panels::page>
