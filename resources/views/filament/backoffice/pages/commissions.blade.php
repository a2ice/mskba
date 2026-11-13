<x-filament-panels::page>
    <x-filament::section heading="Общая комиссия">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:1rem;flex-wrap:wrap">
            <div>
                <div style="font-size:1.65rem;font-weight:700;line-height:1.3">{{ $this->globalRate() }}</div>
                <p class="fi-color-gray" style="margin-top:.35rem;font-size:.875rem">
                    Применяется по умолчанию ко всем сущностям и действиям, пока не задана собственная ставка.
                </p>
            </div>
            <div style="font-size:.875rem">Версия: {{ $this->globalVersion() }}</div>
        </div>
    </x-filament::section>

    {{ $this->table }}

    <p style="font-size:.875rem;color:var(--gray-500,#6b7280)">
        Сейчас к ценам и расчёту бронирования подключено действие «Площадки / Аренда».
        Остальные сущности подготовлены для подключения их платёжных сценариев;
        до подключения соответствующего расчёта их комиссия не начисляется.
        Для площадок действует цепочка: общая ставка → аренда → площадка → зал.
    </p>
</x-filament-panels::page>
