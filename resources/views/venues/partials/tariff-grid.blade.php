<div class="app-rental-pricing venue-tariff-grid" data-tariff-grid>
    <style>
        .venue-tariff-grid{max-width:1160px;margin:0 auto;padding:1.25rem 0 3rem}
        .venue-tariff-grid [hidden]{display:none!important}
        .venue-tariff-grid__panel{margin-bottom:1rem;padding:1.25rem;border:1px solid var(--border-color,#d6d6d6);border-radius:12px}
        .venue-tariff-grid__fields{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:.9rem;align-items:end}
        .venue-tariff-grid label{display:flex;flex-direction:column;gap:.3rem;font-size:.9rem}
        .venue-tariff-grid input:not([type="checkbox"]),.venue-tariff-grid select{width:100%;min-width:0;padding:.65rem;border:1px solid var(--border-color,#bbb);border-radius:8px;background:var(--input-bg,transparent);color:inherit}
        .venue-tariff-grid .button{display:inline-flex;align-items:center;justify-content:center;padding:.55rem .9rem;margin:.2rem;border-radius:8px;cursor:pointer}
        .venue-tariff-grid__days{display:flex;flex-wrap:wrap;gap:.8rem;margin:.7rem 0}
        .venue-tariff-grid__days label{flex-direction:row;align-items:center}
        .venue-tariff-grid__group{margin:.6rem 0;border:1px solid var(--border-color,#ddd);border-radius:9px;overflow:hidden}
        .venue-tariff-grid__group summary{display:flex;gap:.7rem;justify-content:space-between;flex-wrap:wrap;align-items:center;padding:.8rem 1rem;cursor:pointer}
        .venue-tariff-grid__source{font-size:.8rem;opacity:.75}
        .venue-tariff-grid__steps{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:.5rem;padding:1rem;background:var(--surface-secondary,rgba(127,127,127,.05))}
        .venue-tariff-grid__slot{display:flex;flex-direction:column;gap:.3rem;padding:.55rem;border:1px solid var(--border-color,#ddd);border-radius:8px}
        .venue-tariff-grid__slot small,.venue-tariff-grid__help{font-size:.8rem;opacity:.8}
        .venue-tariff-grid__exceptions{display:grid;gap:.7rem;margin:.75rem 0}
        .venue-tariff-grid__exception{display:grid;grid-template-columns:repeat(5,minmax(0,1fr)) auto;gap:.5rem;align-items:end}
        .venue-tariff-grid__actions{display:flex;flex-wrap:wrap;gap:.5rem;margin-top:1rem}
        .venue-tariff-grid__dialog{max-width:540px;width:min(94vw,540px);padding:1.25rem;border:1px solid var(--border-color,#ccc);border-radius:12px;background:var(--card-bg,#fff);color:var(--text-color,#222)}
        .venue-tariff-grid__dialog::backdrop{background:rgba(0,0,0,.55)}
        @media(max-width:700px){.venue-tariff-grid__exception{grid-template-columns:repeat(2,minmax(0,1fr))}.venue-tariff-grid__panel{padding:.85rem}.venue-tariff-grid__group summary{align-items:flex-start}}
    </style>

    <div class="venue-tariff-grid__panel">
        <h2>Единая тарифная сетка</h2>
        <p class="venue-tariff-grid__help">
            Диапазоны формируются из последовательных шагов с одинаковой стоимостью. Источник, ручной тариф и исключения показываются в одной сетке.
            Все цены указаны за один шаг {{ $step }} мин, {{ $policy->currency }}.
        </p>
        @if(session('status'))<p role="status">{{ session('status') }}</p>@endif
        @if($errors->any())<p role="alert" class="app-rental-pricing__error">{{ $errors->first() }}</p>@endif

        <form method="GET" action="{{ route('account.venues.tariff-grid.edit', $venue->id) }}" class="venue-tariff-grid__fields" data-tariff-filter>
            <label>Объект
                <select name="court">
                    <option value="">Вся площадка</option>
                    @foreach($courts as $option)
                        <option value="{{ $option->id }}" @selected($court?->id === $option->id)>{{ $option->publicName() }}</option>
                    @endforeach
                </select>
            </label>
            <label>Тип тарифа
                <select name="mode" data-tariff-mode>
                    <option value="weekly" @selected($mode === 'weekly')>Недельный шаблон</option>
                    <option value="date" @selected($mode === 'date')>Конкретная дата</option>
                </select>
            </label>
            <label data-tariff-date-filter @if($mode !== 'date') hidden @endif>Дата
                <input type="date" name="date" value="{{ $date }}">
            </label>
            <button class="button secondary" type="submit">Показать тарифы</button>
        </form>
        @if($import)
            <p class="venue-tariff-grid__help">
                Последняя интеграция: {{ $import->first_date->format('d.m.Y') }}–{{ $import->last_date->format('d.m.Y') }}.
                Импортированные цены сохраняются отдельно от ручных корректировок.
                Редактирование здесь не меняет систему источника.
            </p>
        @endif
    </div>

    <div class="venue-tariff-grid__panel">
        <h3>{{ $mode === 'date' ? 'Тарифы на '.$date : 'Регулярные тарифы по дням недели' }}</h3>
        @foreach($rows as $day)
            <section aria-label="{{ $day['label'] }}">
                <h4>{{ $day['label'] }}</h4>
                @foreach($day['ranges'] as $range)
                    <details class="venue-tariff-grid__group">
                        <summary>
                            <strong>{{ $range['start'] }}–{{ $range['end'] }}</strong>
                            <span>
                                Весь зал: {{ number_format($range['whole']/100, 2, ',', ' ') }} ₽
                                · Половина: {{ number_format($range['half']/100, 2, ',', ' ') }} ₽
                            </span>
                            <span class="venue-tariff-grid__source">{{ $range['source'] }}</span>
                        </summary>
                        <div class="venue-tariff-grid__steps">
                            @foreach($range['cells'] as $cell)
                                <div class="venue-tariff-grid__slot">
                                    <strong>{{ $cell['time'] }}</strong>
                                    <span>{{ number_format($cell['whole']/100, 2, ',', ' ') }} ₽ / {{ number_format($cell['half']/100, 2, ',', ' ') }} ₽</span>
                                    <small>{{ $cell['source'] }}@if($cell['availability']) · {{ $cell['availability'] }}@endif</small>
                                    <button type="button" class="button secondary" data-tariff-edit-slot
                                        data-day="{{ $day['day'] }}" data-time="{{ $cell['time'] }}"
                                        data-whole="{{ number_format($cell['whole']/100, 2, '.', '') }}"
                                        data-half="{{ number_format($cell['half']/100, 2, '.', '') }}">
                                        Изменить шаг
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    </details>
                @endforeach
            </section>
        @endforeach
    </div>

    <section class="venue-tariff-grid__panel" id="tariff-range-editor">
        <h3>Назначить тарифные диапазоны</h3>
        <p class="venue-tariff-grid__help">
            Можно задать несколько диапазонов за один раз — например 07:00–16:00 и 16:00–23:30,
            каждому назначить собственную цену и исключения. Все цены сохраняются атомарно по шагам {{ $step }} мин.
            Очистка ручной корректировки возвращает наследуемый тариф или текущую цену интеграции.
        </p>
        <form method="POST" action="{{ route('account.venues.tariff-grid.store', $venue->id) }}" data-tariff-range-form>
            @csrf
            <input type="hidden" name="court_id" value="{{ $court?->id }}">
            <input type="hidden" name="mode" value="{{ $mode }}">
            <input type="hidden" name="date" value="{{ $date }}">
            <div data-tariff-ranges>
                @include('venues.partials.tariff-grid-range', ['index' => 0])
            </div>
            <template data-tariff-range-template>
                @include('venues.partials.tariff-grid-range', ['index' => '__RANGE__'])
            </template>
            <div class="venue-tariff-grid__actions">
                <button class="button secondary" type="button" data-tariff-add-range>Добавить диапазон</button>
                <button class="button primary" type="submit">Сохранить все диапазоны</button>
                <a class="button secondary" href="{{ route('account.venues.booking-policy.edit', $venue->id) }}">К условиям аренды</a>
            </div>
        </form>
    </section>

    <dialog class="venue-tariff-grid__dialog" data-tariff-slot-dialog aria-labelledby="tariff-slot-title">
        <h3 id="tariff-slot-title">Изменить отдельный шаг</h3>
        <form method="POST" action="{{ route('account.venues.tariff-grid.store', $venue->id) }}" data-tariff-slot-form>
            @csrf
            <input type="hidden" name="court_id" value="{{ $court?->id }}">
            <input type="hidden" name="mode" value="{{ $mode }}">
            <input type="hidden" name="date" value="{{ $date }}">
            <input type="hidden" name="days[]" value="1" data-slot-day>
            <input type="hidden" name="starts_at" data-slot-start>
            <input type="hidden" name="ends_at" data-slot-end>
            <p data-slot-heading></p>
            <div class="venue-tariff-grid__fields">
                <label>Весь зал, ₽<input name="whole_price_per_step" inputmode="decimal" data-slot-whole></label>
                <label>Половина, ₽<input name="half_price_per_step" inputmode="decimal" data-slot-half></label>
                <label>Действие
                    <select name="operation"><option value="set">Изменить</option><option value="clear">Вернуть цену источника</option></select>
                </label>
            </div>
            <div class="venue-tariff-grid__actions">
                <button class="button primary" type="submit">Сохранить шаг</button>
                <button class="button secondary" type="button" data-tariff-close-slot>Отмена</button>
            </div>
        </form>
    </dialog>
    <script>
        (() => {
            const root = document.querySelector('[data-tariff-grid]');
            if (!root) return;
            const filter = root.querySelector('[data-tariff-filter]');
            const syncFilter = () => {
                const date = root.querySelector('[data-tariff-date-filter]');
                date.hidden = filter.querySelector('[data-tariff-mode]').value !== 'date';
            };
            filter.querySelector('[data-tariff-mode]').addEventListener('change', syncFilter);
            syncFilter();

            const rangeForm = root.querySelector('[data-tariff-range-form]');
            const ranges = rangeForm.querySelector('[data-tariff-ranges]');
            const rangeTemplate = rangeForm.querySelector('[data-tariff-range-template]');
            let nextRangeIndex = 1;
            root.querySelector('[data-tariff-add-range]').addEventListener('click', () => {
                if (ranges.querySelectorAll('[data-tariff-range-item]').length >= 16) {
                    alert('Не более 16 диапазонов за одну операцию.');
                    return;
                }
                const holder = document.createElement('div');
                holder.innerHTML = rangeTemplate.innerHTML
                    .replaceAll('__RANGE__', String(nextRangeIndex))
                    .replaceAll('__NUMBER__', String(nextRangeIndex + 1));
                nextRangeIndex++;
                ranges.append(holder.firstElementChild);
            });
            ranges.addEventListener('click', e => {
                const remove = e.target.closest('[data-tariff-remove-range]');
                if (remove) {
                    if (ranges.querySelectorAll('[data-tariff-range-item]').length > 1) {
                        remove.closest('[data-tariff-range-item]').remove();
                    }
                    return;
                }
                const addException = e.target.closest('[data-tariff-add-exception]');
                if (addException) {
                    const item = addException.closest('[data-tariff-range-item]');
                    const collection = item.querySelector('[data-tariff-exceptions]');
                    if (collection.querySelectorAll('[data-tariff-exception]').length >= 100) return;
                    const index = Number(item.dataset.nextException || 0);
                    item.dataset.nextException = String(index + 1);
                    const template = item.querySelector('[data-tariff-exception-template]');
                    const holder = document.createElement('div');
                    holder.innerHTML = template.innerHTML.replaceAll('__EXCEPTION__', String(index));
                    collection.append(holder.firstElementChild);
                    return;
                }
                const removeException = e.target.closest('[data-tariff-remove-exception]');
                if (removeException) removeException.closest('[data-tariff-exception]').remove();
            });

            const dialog = root.querySelector('[data-tariff-slot-dialog]');
            const slotForm = root.querySelector('[data-tariff-slot-form]');
            const step = @json($step);
            root.querySelectorAll('[data-tariff-edit-slot]').forEach(button => button.addEventListener('click', () => {
                const time = button.dataset.time;
                const minute = Number(time.slice(0, 2)) * 60 + Number(time.slice(3, 5));
                const end = (minute + step) % 1440;
                const endTime = String(Math.floor(end / 60)).padStart(2, '0') + ':' + String(end % 60).padStart(2, '0');
                slotForm.querySelector('[data-slot-day]').value = button.dataset.day;
                slotForm.querySelector('[data-slot-start]').value = time;
                slotForm.querySelector('[data-slot-end]').value = endTime;
                slotForm.querySelector('[data-slot-whole]').value = button.dataset.whole;
                slotForm.querySelector('[data-slot-half]').value = button.dataset.half;
                slotForm.querySelector('[name="operation"]').value = 'set';
                slotForm.querySelector('[data-slot-heading]').textContent = time + '–' + endTime;
                dialog.showModal();
            }));
            root.querySelector('[data-tariff-close-slot]').addEventListener('click', () => dialog.close());

            rangeForm.addEventListener('submit', event => {
                if (rangeForm.querySelector('[name="mode"]').value !== 'weekly') return;
                for (const item of ranges.querySelectorAll('[data-tariff-range-item]')) {
                    if (!item.querySelector('input[type="checkbox"]:checked')) {
                        event.preventDefault();
                        alert('Выберите дни недели для каждого диапазона.');
                        return;
                    }
                }
            });
        })();
    </script>
</div>
