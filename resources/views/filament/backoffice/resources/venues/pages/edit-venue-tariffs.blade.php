<x-filament-panels::page>
    @php
        $policy = $this->policy();
        $step = $this->step();
        $weekdays = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'];
    @endphp

    <x-filament::section heading="Объект и период" description="Недельный тариф или цены на конкретную дату. Источник импорта и ручные переопределения отображаются в одной сетке.">
        <div class="grid gap-4 md:grid-cols-3">
            <label class="space-y-2">
                <span class="block text-sm font-medium">Площадка или зал</span>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="courtId">
                        <option value="">Вся площадка</option>
                        @foreach($this->courts() as $option)
                            <option value="{{ $option->id }}">{{ $option->publicName() }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
            <label class="space-y-2">
                <span class="block text-sm font-medium">Период</span>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="mode">
                        <option value="weekly">Недельный шаблон</option>
                        <option value="date">Конкретная дата</option>
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
            @if($this->mode === 'date')
                <label class="space-y-2">
                    <span class="block text-sm font-medium">Дата</span>
                    <x-filament::input.wrapper>
                        <x-filament::input type="date" wire:model.live="date" />
                    </x-filament::input.wrapper>
                </label>
            @endif
        </div>
        <p class="mt-3 text-sm text-gray-500 dark:text-gray-400">
            Стоимость за шаг {{ $step }} мин · {{ $policy->currency }}.
            Сумма аренды считается по отдельным шагам, а не по целому диапазону.
            Смена объекта меняет отображение, но не сохраняет незавершённые правки.
        </p>
        @error('date') <p class="mt-2 text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
        @error('venue') <p class="mt-2 text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
    </x-filament::section>

    <form wire:submit="saveRanges" class="space-y-4">
        <x-filament::section heading="Назначить тарифы" description="Добавьте несколько диапазонов, например 07:00–16:00 и 16:00–23:30. Все диапазоны сохраняются за одну операцию, с проверкой пересечений.">
            <div class="space-y-4">
                @foreach($this->ranges as $index => $range)
                    <div wire:key="tariff-range-{{ $index }}" class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                        <div class="mb-4 flex flex-wrap items-center justify-between gap-2">
                            <h3 class="font-semibold">Диапазон {{ $index + 1 }}</h3>
                            <x-filament::button color="gray" size="sm" type="button" wire:click="removeRange({{ $index }})">Удалить диапазон</x-filament::button>
                        </div>
                        @if($this->mode === 'weekly')
                            <fieldset class="mb-4">
                                <legend class="mb-2 text-sm font-medium">Дни недели</legend>
                                <div class="flex flex-wrap gap-x-4 gap-y-2">
                                    @foreach($weekdays as $day => $label)
                                        <label class="flex items-center gap-2 text-sm">
                                            <input type="checkbox" class="rounded border-gray-300 text-primary-600"
                                                wire:model="ranges.{{ $index }}.days" value="{{ $day }}">
                                            <span>{{ $label }}</span>
                                        </label>
                                    @endforeach
                                </div>
                            </fieldset>
                        @endif
                        <div class="grid gap-4 md:grid-cols-5">
                            <label class="space-y-2">
                                <span class="block text-sm font-medium">Начало</span>
                                <x-filament::input.wrapper>
                                    <x-filament::input type="time" step="{{ $step * 60 }}" wire:model="ranges.{{ $index }}.starts_at" />
                                </x-filament::input.wrapper>
                            </label>
                            <label class="space-y-2">
                                <span class="block text-sm font-medium">Конец</span>
                                <x-filament::input.wrapper>
                                    <x-filament::input type="time" step="{{ $step * 60 }}" wire:model="ranges.{{ $index }}.ends_at" />
                                </x-filament::input.wrapper>
                            </label>
                            <label class="space-y-2">
                                <span class="block text-sm font-medium">Весь зал, {{ $policy->currency }}</span>
                                <x-filament::input.wrapper>
                                    <x-filament::input type="text" inputmode="decimal" placeholder="Наследовать"
                                        wire:model="ranges.{{ $index }}.whole_price_per_step" />
                                </x-filament::input.wrapper>
                            </label>
                            <label class="space-y-2">
                                <span class="block text-sm font-medium">Половина, {{ $policy->currency }}</span>
                                <x-filament::input.wrapper>
                                    <x-filament::input type="text" inputmode="decimal" placeholder="Наследовать"
                                        wire:model="ranges.{{ $index }}.half_price_per_step" />
                                </x-filament::input.wrapper>
                            </label>
                            <label class="space-y-2">
                                <span class="block text-sm font-medium">Действие</span>
                                <x-filament::input.wrapper>
                                    <x-filament::input.select wire:model="ranges.{{ $index }}.operation">
                                        <option value="set">Назначить цену</option>
                                        <option value="clear">Очистить ручную цену</option>
                                    </x-filament::input.select>
                                </x-filament::input.wrapper>
                            </label>
                        </div>
                        <p class="mt-2 text-xs text-gray-500 dark:text-gray-400">
                            Конец диапазона не включается. Для конца суток используйте 00:00.
                            Очистка возвращает цену, полученную из интеграции или наследования.
                        </p>
                        @foreach(['starts_at', 'ends_at', 'days', 'whole_price_per_step', 'half_price_per_step'] as $field)
                            @error("ranges.$index.$field") <p class="mt-1 text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                        @endforeach

                        <div class="mt-4 border-t border-gray-200 pt-3 dark:border-gray-700">
                            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                <h4 class="font-medium">Исключения внутри диапазона</h4>
                                <x-filament::button color="gray" size="sm" type="button"
                                    wire:click="addException({{ $index }})">Добавить исключение</x-filament::button>
                            </div>
                            @forelse(($range['exceptions'] ?? []) as $exceptionIndex => $exception)
                                <div wire:key="tariff-exception-{{ $index }}-{{ $exceptionIndex }}" class="mb-3 grid items-end gap-3 rounded-lg bg-gray-50 p-3 dark:bg-gray-800 md:grid-cols-6">
                                    <label class="space-y-2">
                                        <span class="block text-sm">Шаг с</span>
                                        <x-filament::input.wrapper>
                                            <x-filament::input type="time" step="{{ $step * 60 }}"
                                                wire:model="ranges.{{ $index }}.exceptions.{{ $exceptionIndex }}.starts_at" />
                                        </x-filament::input.wrapper>
                                    </label>
                                    @if($this->mode === 'weekly')
                                        <label class="space-y-2">
                                            <span class="block text-sm">День</span>
                                            <x-filament::input.wrapper>
                                                <x-filament::input.select wire:model="ranges.{{ $index }}.exceptions.{{ $exceptionIndex }}.day_of_week">
                                                    <option value="">Все выбранные</option>
                                                    @foreach($weekdays as $day => $label)
                                                        <option value="{{ $day }}">{{ $label }}</option>
                                                    @endforeach
                                                </x-filament::input.select>
                                            </x-filament::input.wrapper>
                                        </label>
                                    @endif
                                    <label class="space-y-2">
                                        <span class="block text-sm">Весь зал</span>
                                        <x-filament::input.wrapper>
                                            <x-filament::input type="text" inputmode="decimal" wire:model="ranges.{{ $index }}.exceptions.{{ $exceptionIndex }}.whole_price_per_step" />
                                        </x-filament::input.wrapper>
                                    </label>
                                    <label class="space-y-2">
                                        <span class="block text-sm">Половина</span>
                                        <x-filament::input.wrapper>
                                            <x-filament::input type="text" inputmode="decimal" wire:model="ranges.{{ $index }}.exceptions.{{ $exceptionIndex }}.half_price_per_step" />
                                        </x-filament::input.wrapper>
                                    </label>
                                    <label class="space-y-2">
                                        <span class="block text-sm">Действие</span>
                                        <x-filament::input.wrapper>
                                            <x-filament::input.select wire:model="ranges.{{ $index }}.exceptions.{{ $exceptionIndex }}.operation">
                                                <option value="set">Изменить</option>
                                                <option value="clear">Вернуть источник</option>
                                            </x-filament::input.select>
                                        </x-filament::input.wrapper>
                                    </label>
                                    <x-filament::button color="gray" type="button" size="sm"
                                        wire:click="removeException({{ $index }}, {{ $exceptionIndex }})">Убрать</x-filament::button>
                                </div>
                                @error("ranges.$index.exceptions.$exceptionIndex.starts_at")
                                    <p class="text-sm text-danger-600" role="alert">{{ $message }}</p>
                                @enderror
                            @empty
                                <p class="text-sm text-gray-500 dark:text-gray-400">Нет исключений: вся стоимость диапазона одинакова.</p>
                            @endforelse
                        </div>
                    </div>
                @endforeach
                @error('ranges') <p class="text-sm text-danger-600" role="alert">{{ $message }}</p> @enderror
                <div class="flex flex-wrap gap-3">
                    <x-filament::button type="button" color="gray" wire:click="addRange" :disabled="count($this->ranges) >= 16">
                        Добавить диапазон
                    </x-filament::button>
                    <x-filament::button type="submit" icon="heroicon-o-check">Сохранить все диапазоны</x-filament::button>
                </div>
            </div>
        </x-filament::section>
    </form>

    <x-filament::section heading="Действующие тарифы и источник" description="Последовательные слоты объединены в диапазоны. Раскройте диапазон, чтобы изменить отдельный временной шаг. Статус занятости импортированных слотов не редактируется.">
        <div class="space-y-5">
            @foreach($this->effectiveGrid() as $day)
                <section>
                    <h3 class="mb-2 font-semibold">{{ $day['label'] }}</h3>
                    <div class="space-y-2">
                        @foreach($day['ranges'] as $range)
                            <details class="rounded-lg border border-gray-200 dark:border-gray-700">
                                <summary class="flex cursor-pointer flex-wrap items-center justify-between gap-3 p-3">
                                    <span class="font-medium">{{ $range['start'] }}–{{ $range['end'] }}</span>
                                    <span class="text-sm">
                                        Весь: {{ $this->formatPrice($range['whole']) }} · Половина: {{ $this->formatPrice($range['half']) }}
                                        {{ $policy->currency }} / {{ $step }} мин
                                    </span>
                                    <span class="text-xs text-gray-500 dark:text-gray-400">{{ $range['source'] }}</span>
                                </summary>
                                <div class="grid gap-2 border-t border-gray-200 p-3 dark:border-gray-700 sm:grid-cols-2 lg:grid-cols-4">
                                    @foreach($range['cells'] as $cell)
                                        <div class="space-y-2 rounded-lg border border-gray-200 p-3 dark:border-gray-700">
                                            <div class="font-medium">{{ $cell['time'] }}</div>
                                            <div class="text-sm">
                                                {{ $this->formatPrice($cell['whole']) }} / {{ $this->formatPrice($cell['half']) }} {{ $policy->currency }}
                                            </div>
                                            <div class="text-xs text-gray-500 dark:text-gray-400">
                                                {{ $cell['source'] }} @if($cell['availability']) · {{ $cell['availability'] }} @endif
                                            </div>
                                            <x-filament::button color="gray" size="sm" type="button"
                                                wire:click="editStep({{ $day['day'] }}, '{{ $cell['time'] }}')">
                                                Изменить шаг
                                            </x-filament::button>
                                        </div>
                                    @endforeach
                                </div>
                            </details>
                        @endforeach
                    </div>
                </section>
            @endforeach
        </div>
    </x-filament::section>
</x-filament-panels::page>
