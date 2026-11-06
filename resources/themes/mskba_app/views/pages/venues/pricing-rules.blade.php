@php
    $title = 'Тарифные условия';
    $money = app(\App\Modules\VenueBooking\Application\Services\MinorAmountParser::class);
    $currency = $policy?->currency ?? 'RUB';
    $c = $editing?->conditions ?? [];
    $formValue = static fn (string $key, mixed $default = ''): mixed => old($key, $default);
    $percent = in_array($editing?->operation, ['percent_discount', 'percent_surcharge'], true);
    $ruleValue = $editing === null ? '' : ($percent
        ? number_format((int) $editing->value / 100, 2, '.', '')
        : $money->format((int) $editing->value, $currency));
    $preview = session('rule_preview');
@endphp
@extends('theme::layouts.account', ['title' => $title.' · '.$venue->name])

@section('account-heading')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => $title,
        'subtitle' => $venue->name,
    ])
@endsection

@section('account-content')
<div class="app-rental-pricing">
    <div class="app-rental-pricing__actions">
        <a class="button secondary" href="{{ route('account.venues.booking-policy.edit', $venue->id) }}">Базовые цены</a>
        <a class="button secondary" href="{{ route('account.venues.courts.index', $venue->routeIdentifier()) }}">Залы</a>
    </div>
    @if(session('status'))<p class="app-rental-pricing__success" role="status">{{ session('status') }}</p>@endif
    @if($errors->any())
        <div class="app-rental-pricing__error" role="alert">
            @foreach($errors->all() as $message)<p>{{ $message }}</p>@endforeach
        </div>
    @endif

    <section class="panel app-rental-pricing__panel" aria-labelledby="pricing-rules-heading">
        <div class="app-rental-pricing__court-heading">
            <h2 id="pricing-rules-heading">Условия</h2>
            <a class="button secondary" href="{{ route('account.venues.pricing-rules.index', $venue->id) }}#rule-editor">Добавить условие</a>
        </div>

        @if($rules->isEmpty())
            <p class="app-rental-pricing__subtle">Условий пока нет. Используются цены по временным слотам.</p>
        @else
            <div class="app-rental-pricing__table-wrap">
                <table class="app-rental-pricing__table">
                    <thead>
                        <tr><th scope="col">Условие</th><th scope="col">Для кого</th><th scope="col">Приоритет</th><th scope="col">Версия и статус</th><th scope="col">Действия</th></tr>
                    </thead>
                    <tbody>
                    @foreach($rules as $rule)
                        <tr>
                            <td><strong>{{ $rule->name }}</strong>
                                @if($rule->description)<p class="app-rental-pricing__subtle">{{ $rule->description }}</p>@endif
                            </td>
                            <td>{{ $rule->venue_court_id ? ($courts->firstWhere('id', $rule->venue_court_id)?->name ?? 'Зал') : 'Все залы' }}</td>
                            <td>{{ $rule->priority }}</td>
                            <td>
                                №{{ $rule->version }}
                                · {{ $rule->status === 'draft' ? 'Черновик' : 'Опубликовано' }}
                                @unless($rule->is_enabled) · Выключено @endunless
                            </td>
                            <td>
                                <div class="app-rental-pricing__actions">
                                    <a class="button secondary" href="{{ route('account.venues.pricing-rules.index', [$venue->id, 'edit' => $rule->id]) }}#rule-editor">
                                        {{ $rule->status === 'draft' ? 'Изменить' : 'Новая редакция' }}
                                    </a>
                                    @if($rule->status === 'draft')
                                        <form method="POST" action="{{ route('account.venues.pricing-rules.publish', [$venue->id, $rule->id]) }}">
                                            @csrf<button class="button primary" type="submit">Опубликовать</button>
                                        </form>
                                        <form method="POST" action="{{ route('account.venues.pricing-rules.destroy', [$venue->id, $rule->id]) }}">
                                            @csrf @method('DELETE')<button class="button secondary" type="submit">Удалить</button>
                                        </form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="panel app-rental-pricing__panel" id="rule-editor" aria-labelledby="rule-editor-heading">
        <h2 id="rule-editor-heading">{{ $editing ? ($editing->status === 'draft' ? 'Редактировать черновик' : 'Новая редакция условия') : 'Новое условие' }}</h2>
        <form class="app-rental-pricing__form" method="POST" action="{{ route('account.venues.pricing-rules.save', $venue->id) }}">
            @csrf
            @if($editing)<input type="hidden" name="rule_id" value="{{ $editing->id }}">@endif

            <div class="app-rental-pricing__section">
                <div class="app-rental-pricing__grid">
                    <label class="field-group" for="pricing-rule-name"><span>Название</span>
                        <input id="pricing-rule-name" name="name" maxlength="120" required value="{{ $formValue('name', $editing?->name ?? '') }}">
                    </label>
                    <label class="field-group" for="pricing-rule-court"><span>Область действия</span>
                        <select id="pricing-rule-court" name="venue_court_id" @disabled($editing !== null)>
                            <option value="">Все залы площадки</option>
                            @foreach($courts as $court)
                                <option value="{{ $court->id }}" @selected((string) $formValue('venue_court_id', $editing?->venue_court_id ?? '') === (string) $court->id)>{{ $court->name }}</option>
                            @endforeach
                        </select>
                        @if($editing)<input type="hidden" name="venue_court_id" value="{{ $editing->venue_court_id }}">@endif
                    </label>
                    <label class="field-group" for="pricing-rule-priority"><span>Приоритет (меньше — раньше)</span>
                        <input id="pricing-rule-priority" type="number" name="priority" min="0" max="1000" required value="{{ $formValue('priority', $editing?->priority ?? 100) }}">
                    </label>
                    <label class="field-group" for="pricing-rule-description"><span>Описание</span>
                        <textarea id="pricing-rule-description" name="description" maxlength="2000" rows="2">{{ $formValue('description', $editing?->description ?? '') }}</textarea>
                    </label>
                </div>
                <div class="app-rental-pricing__actions">
                    <label class="check-label"><input type="checkbox" name="is_enabled" value="1" @checked((bool) $formValue('is_enabled', $editing?->is_enabled ?? true))><span>Активно после публикации</span></label>
                    <label class="check-label"><input type="checkbox" name="stop_processing" value="1" @checked((bool) $formValue('stop_processing', $editing?->stop_processing ?? false))><span>Не применять следующие правила</span></label>
                </div>
            </div>

            <div class="app-rental-pricing__section">
                <h2>Когда применять</h2>
                <div class="app-rental-pricing__grid">
                    <label class="field-group" for="pricing-rule-duration-min"><span>Длительность от, мин</span>
                        <input id="pricing-rule-duration-min" type="number" min="1" max="1440" name="min_duration_minutes" value="{{ $formValue('min_duration_minutes', $c['min_duration_minutes'] ?? '') }}">
                    </label>
                    <label class="field-group" for="pricing-rule-duration-max"><span>Длительность до, мин</span>
                        <input id="pricing-rule-duration-max" type="number" min="1" max="1440" name="max_duration_minutes" value="{{ $formValue('max_duration_minutes', $c['max_duration_minutes'] ?? '') }}">
                    </label>
                    <label class="field-group" for="pricing-rule-steps"><span>Минимум шагов аренды</span>
                        <input id="pricing-rule-steps" type="number" min="1" max="288" name="min_steps" value="{{ $formValue('min_steps', $c['min_steps'] ?? '') }}">
                    </label>
                    <label class="field-group" for="pricing-rule-from"><span>Дата с</span>
                        <input id="pricing-rule-from" type="date" name="date_from" value="{{ $formValue('date_from', $c['date_from'] ?? '') }}">
                    </label>
                    <label class="field-group" for="pricing-rule-to"><span>Дата по</span>
                        <input id="pricing-rule-to" type="date" name="date_to" value="{{ $formValue('date_to', $c['date_to'] ?? '') }}">
                    </label>
                    <label class="field-group" for="pricing-rule-scope"><span>Формат аренды</span>
                        <select id="pricing-rule-scope" name="scope" required>
                            @foreach(['all'=>'Любой', 'whole'=>'Весь зал', 'half'=>'Половина'] as $key => $label)
                                <option value="{{ $key }}" @selected($formValue('scope', $c['scope'] ?? 'all') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="field-group" for="pricing-rule-time-from"><span>Время с</span>
                        <input id="pricing-rule-time-from" type="time" name="time_from" value="{{ $formValue('time_from', $c['time_from'] ?? '') }}">
                    </label>
                    <label class="field-group" for="pricing-rule-time-to"><span>Время до</span>
                        <input id="pricing-rule-time-to" type="time" name="time_to" value="{{ $formValue('time_to', $c['time_to'] ?? '') }}">
                    </label>
                    <label class="field-group" for="pricing-rule-base"><span>Стоимость до правил от, коп.</span>
                        <input id="pricing-rule-base" type="number" name="min_base_minor" min="0" max="1000000000000" value="{{ $formValue('min_base_minor', $c['min_base_minor'] ?? '') }}">
                    </label>
                </div>
                <div class="app-rental-pricing__actions">
                    <span class="app-rental-pricing__subtle">Дни недели:</span>
                    @foreach(['Пн','Вт','Ср','Чт','Пт','Сб','Вс'] as $index => $day)
                        <label class="check-label">
                            <input type="checkbox" name="weekdays[]" value="{{ $index+1 }}" @checked(in_array($index+1, array_map('intval', (array) $formValue('weekdays', $c['weekdays'] ?? [])), true))>
                            <span>{{ $day }}</span>
                        </label>
                    @endforeach
                </div>
            </div>

            <div class="app-rental-pricing__section">
                <h2>Расчёт</h2>
                <div class="app-rental-pricing__grid">
                    <label class="field-group" for="pricing-rule-operation"><span>Формула</span>
                        <select id="pricing-rule-operation" name="operation" required>
                            @foreach([
                                'percent_discount' => 'Скидка, %',
                                'fixed_discount' => 'Скидка, фиксированная сумма',
                                'percent_surcharge' => 'Наценка, %',
                                'fixed_surcharge' => 'Наценка, фиксированная сумма',
                                'fixed_total' => 'Фиксированная итоговая цена',
                                'minimum_total' => 'Минимальная итоговая цена',
                            ] as $key => $label)
                                <option value="{{ $key }}" @selected($formValue('operation', $editing?->operation ?? 'percent_discount') === $key)>{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                    <label class="field-group" for="pricing-rule-value"><span>Величина, % или {{ $currency }}</span>
                        <input id="pricing-rule-value" type="text" name="value_text" inputmode="decimal" required
                            value="{{ $formValue('value_text', $ruleValue) }}">
                    </label>
                </div>
                <div class="app-rental-pricing__actions">
                    <button class="button primary" type="submit">Сохранить черновик</button>
                    @if($editing)<a class="button secondary" href="{{ route('account.venues.pricing-rules.index', $venue->id) }}#rule-editor">Сбросить</a>@endif
                </div>
            </div>
        </form>
    </section>

    @if($rules->whereNull('venue_court_id')->where('status', 'published')->isNotEmpty() && $courts->isNotEmpty())
        <section class="panel app-rental-pricing__panel" aria-labelledby="pricing-inheritance-heading">
            <h2 id="pricing-inheritance-heading">Исключения для залов</h2>
            <div class="app-rental-pricing__table-wrap">
                <table class="app-rental-pricing__table">
                    <thead><tr><th>Условие</th><th>Зал</th><th>Статус</th><th>Действие</th></tr></thead>
                    <tbody>
                    @foreach($rules->whereNull('venue_court_id')->where('status', 'published') as $rule)
                        @foreach($courts as $court)
                            @php $excluded = $overrides->contains(fn ($o) => $o->rule_key === $rule->rule_key && (int) $o->venue_court_id === (int) $court->id); @endphp
                            <tr>
                                <td>{{ $rule->name }}</td><td>{{ $court->name }}</td>
                                <td>{{ $excluded ? 'Выключено' : 'Наследуется' }}</td>
                                <td>
                                    <form method="POST" action="{{ route('account.venues.pricing-rules.exclude', $venue->id) }}">
                                        @csrf
                                        <input type="hidden" name="venue_court_id" value="{{ $court->id }}">
                                        <input type="hidden" name="rule_key" value="{{ $rule->rule_key }}">
                                        <input type="hidden" name="is_excluded" value="{{ $excluded ? '0' : '1' }}">
                                        <button type="submit" class="button secondary">{{ $excluded ? 'Восстановить' : 'Отключить' }}</button>
                                    </form>
                                </td>
                            </tr>
                        @endforeach
                    @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    <section class="panel app-rental-pricing__panel" aria-labelledby="pricing-preview-heading">
        <h2 id="pricing-preview-heading">Проверка расчёта</h2>
        @if($preview)
            <div class="app-rental-pricing__section">
                <p><strong>{{ $preview['court_name'] }}</strong> · {{ $preview['starts_at'] }} · {{ $preview['duration_minutes'] }} мин</p>
                <p>Базовая цена: <strong>{{ $money->format($preview['base_minor'], $preview['currency']) }} {{ $preview['currency'] }}</strong></p>
                @foreach($preview['adjustments'] as $adjustment)
                    <p>{{ $adjustment['name'] }} (v{{ $adjustment['version'] }}):
                        {{ $money->format($adjustment['before_minor'], $preview['currency']) }}
                        → {{ $money->format($adjustment['after_minor'], $preview['currency']) }} {{ $preview['currency'] }}
                    </p>
                @endforeach
                @if(isset($preview['commissions']))
                    <p>Аренда после скидок: {{ $money->format($preview['commissions']['provider_amount_minor'], $preview['currency']) }} {{ $preview['currency'] }}</p>
                    <p>Комиссия MSKBA: {{ $money->format($preview['commissions']['portal_fee_minor'], $preview['currency']) }} {{ $preview['currency'] }}</p>
                    <p>Комиссия агента: {{ $money->format($preview['commissions']['agent_fee_minor'], $preview['currency']) }} {{ $preview['currency'] }}</p>
                @endif
                <p class="app-rental-pricing__total">Итого: {{ $money->format($preview['total_minor'], $preview['currency']) }} {{ $preview['currency'] }}</p>
            </div>
        @endif
        <form class="app-rental-pricing__form" method="POST" action="{{ route('account.venues.pricing-rules.preview', $venue->id) }}">
            @csrf
            <div class="app-rental-pricing__grid">
                <label class="field-group" for="preview-court"><span>Зал</span>
                    <select id="preview-court" name="venue_court_id" required>
                        @foreach($courts as $court)<option value="{{ $court->id }}">{{ $court->name }}</option>@endforeach
                    </select>
                </label>
                <label class="field-group" for="preview-start"><span>Начало</span>
                    <input id="preview-start" name="starts_at" type="datetime-local" required
                        value="{{ now($venue->schedule?->timezone ?: 'Europe/Moscow')->addDay()->format('Y-m-d\T12:00') }}">
                </label>
                <label class="field-group" for="preview-duration"><span>Длительность, мин</span>
                    <input id="preview-duration" name="duration_minutes" type="number" min="15" max="1440" value="120" required>
                </label>
                <label class="field-group" for="preview-scope"><span>Формат</span>
                    <select id="preview-scope" name="scope">
                        <option value="whole">Весь зал</option><option value="half_a">Половина А</option><option value="half_b">Половина Б</option>
                    </select>
                </label>
            </div>
            <button class="button primary" type="submit">Проверить стоимость</button>
        </form>
        <p class="app-rental-pricing__subtle">Предварительный расчёт не создаёт бронь и не проверяет свободные слоты.</p>
    </section>
</div>
@endsection
