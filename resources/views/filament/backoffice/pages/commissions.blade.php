<x-filament-panels::page>
    <x-filament::section heading="Глобальные комиссии">
        <div class="grid gap-4 md:grid-cols-3">
            <div>
                <div class="text-sm text-gray-500 dark:text-gray-400">Сущность</div>
                <div class="font-medium">Площадки</div>
            </div>
            <div>
                <div class="text-sm text-gray-500 dark:text-gray-400">Действие</div>
                <div class="font-medium">Аренда</div>
            </div>
            <div>
                <div class="text-sm text-gray-500 dark:text-gray-400">Комиссия MSKBA</div>
                <div class="font-medium">{{ $this->globalRate() }}</div>
            </div>
        </div>
        <div class="mt-3 text-xs text-gray-500 dark:text-gray-400">
            Версия: {{ $this->globalVersion() }}. Индивидуальные ставки площадок и залов настраиваются в редакторе площадки.
        </div>
    </x-filament::section>
</x-filament-panels::page>
