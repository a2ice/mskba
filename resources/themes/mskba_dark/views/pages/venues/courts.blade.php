@php
    $venueDisplayName = preg_replace('/\s*[-–—]\s*\d+\s+зал(?:а|ов)?\s*$/ui', '', $venue->name) ?: $venue->name;
    $courtCount = $courts->count();
    $courtCountLabel = ($courtCount % 10 === 1 && $courtCount % 100 !== 11)
        ? 'зал'
        : (in_array($courtCount % 10, [2, 3, 4], true) && ! in_array($courtCount % 100, [12, 13, 14], true) ? 'зала' : 'залов');
    $title = 'Залы · '.$venueDisplayName.' · '.$courtCount.' '.$courtCountLabel;
    $venueSidebarActive = 'courts';
@endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'account',
    'sectionClass' => 'account-section',
    'contentTitle' => $title,
    'sidebarLabel' => 'Управление площадкой',
])

@section('section-sidebar')
    @include('theme::partials.venues.internal-sidebar')
@endsection

@section('section-content')
    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif
    @if($errors->any())
        <div class="alert alert-danger">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card mb-4">
        <div class="card-body">
            <h2 class="h4 mb-2">Игровые залы площадки</h2>
            <p class="text-muted mb-0">
                Каждый зал — отдельное физическое игровое пространство. Количество колец определяет возможность деления на две половины. Новые залы по умолчанию наследуют разрешения на аренду от действующих условий площадки.
            </p>
        </div>
    </div>

    @foreach($courts as $court)
        <article class="card mb-3">
            <div class="card-body">
                <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap mb-3">
                    <div>
                        <strong>{{ $court->name }}</strong>
                        @if($court->is_primary)
                            <span class="badge text-bg-success ms-2">Основной</span>
                        @endif
                        <div class="text-muted small">/{{ $court->alias }}</div>
                    </div>
                    @if($canManageVenue && !$court->is_primary)
                        <form method="POST" action="{{ route('account.venues.courts.primary', [$venue->routeIdentifier(), $court->routeIdentifier()]) }}">
                            @csrf
                            <button type="submit" class="btn btn--secondary btn--sm">Сделать основным</button>
                        </form>
                    @endif
                </div>

                @if($canManageCourtMemberships[$court->id])
                    <a class="btn btn--secondary btn--sm mb-3"
                        href="{{ route('account.venues.commercial-memberships.index', [$venue, 'court_id' => $court->id]) }}">
                        Роли и разрешения этого зала
                    </a>
                @endif

                @if($canManageCourt[$court->id])
                <form method="POST" action="{{ route('account.venues.courts.update', [$venue->routeIdentifier(), $court->routeIdentifier()]) }}" data-court-form>
                    @csrf
                    @method('PUT')
                    <div class="row g-3">
                        <div class="col-md-5">
                            <label class="form-label" for="court-name-{{ $court->id }}">Название</label>
                            <input id="court-name-{{ $court->id }}" class="form-control" name="name" value="{{ $court->name }}" maxlength="120" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="court-alias-{{ $court->id }}">Alias</label>
                            <input id="court-alias-{{ $court->id }}" class="form-control" name="alias" value="{{ $court->alias }}" maxlength="120">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label" for="court-order-{{ $court->id }}">Порядок</label>
                            <input id="court-order-{{ $court->id }}" class="form-control" type="number" min="0" max="65535" name="sort_order" value="{{ $court->sort_order }}" required>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label" for="court-hoops-{{ $court->id }}">Количество колец</label>
                            <select id="court-hoops-{{ $court->id }}" class="form-select" name="hoops_count" data-court-hoops required>
                                <option value="1" @selected((int) ($court->hoops_count ?? 1) === 1)>1</option>
                                <option value="2" @selected((int) ($court->hoops_count ?? 1) === 2)>2</option>
                            </select>
                        </div>
                        <div class="col-md-8">
                            <label class="form-label" for="court-environment-{{ $court->id }}">Расположение игровой зоны</label>
                            <select id="court-environment-{{ $court->id }}" class="form-select" name="environment">
                                <option value="">Не указано</option>
                                @foreach($courtEnvironments as $environment)
                                    <option value="{{ $environment->value }}" @selected($court->environment?->value === $environment->value)>{{ $environment->label() }}</option>
                                @endforeach
                            </select>
                            <label class="form-label" for="court-surface-{{ $court->id }}">Покрытие</label>
                            <select id="court-surface-{{ $court->id }}" class="form-select" name="surface_type">
                                <option value="">Не указано</option>
                                @foreach($surfaceTypes as $surfaceType)
                                    <option value="{{ $surfaceType->value }}" @selected($court->surface_type?->value === $surfaceType->value)>{{ $surfaceType->label() }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>

                    <div class="mt-3 d-flex flex-column gap-2" data-court-scope-options>
                        <label>
                            <input type="hidden" name="allows_whole" value="0">
                            <input type="checkbox" name="allows_whole" value="1" @checked($court->allows_whole)>
                            Разрешить аренду всего зала
                        </label>
                        <label data-court-half-option>
                            <input type="hidden" name="allows_halves" value="0">
                            <input type="checkbox" name="allows_halves" value="1" @checked($court->allows_halves)>
                            Разрешать аренду половин
                        </label>
                        <small class="text-muted" data-court-halves-hint>Аренда половин доступна только для зала с двумя кольцами.</small>
                    </div>
                    <div class="row g-3 mt-2">
                        <div class="col-md-6">
                            <label class="form-label" for="court-short-description-{{ $court->id }}">Краткое описание</label>
                            <textarea id="court-short-description-{{ $court->id }}" class="form-control" name="short_description" rows="3" maxlength="500" placeholder="Наследовать описание площадки">{{ $court->short_description }}</textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label" for="court-full-description-{{ $court->id }}">Полное описание</label>
                            <textarea id="court-full-description-{{ $court->id }}" class="form-control" name="full_description" rows="6" maxlength="10000" placeholder="Наследовать описание площадки">{{ $court->full_description }}</textarea>
                        </div>
                    </div>
                    @include('venues.partials.court-amenities', ['court' => $court, 'courtAmenities' => $courtAmenities])
                    <button type="submit" class="btn btn--primary btn--sm mt-3">Сохранить зал / корт</button>
                </form>

                @include('theme::partials.venues.court-gallery-editor', [
                    'court' => $court,
                    'photos' => $courtPhotos[$court->id] ?? [],
                ])
                @endif

                @if($canManageCourtPolicy[$court->id])
                    <details class="mt-4"><summary class="fw-semibold">Условия доступа и стоимость аренды</summary>
                        <form method="POST" class="mt-3" action="{{ route('account.venues.courts.settings.update', [$venue->routeIdentifier(), $court->routeIdentifier()]) }}">
                            @csrf @method('PUT')
                            @foreach([
                                'requires_payment' => ['Оплата', 'Платно', 'Бесплатно'],
                                'requires_booking' => ['Запись', 'Требуется бронирование', 'Свободный доступ'],
                                'requires_booking_approval' => ['Подтверждение бронирования', 'Ручное', 'Автоматическое'],
                                'rental_enabled' => ['Онлайн-аренда', 'Разрешена', 'Запрещена'],
                            ] as $field => [$label, $yes, $no])
                                <label class="form-label mt-2">{{ $label }}
                                    <select class="form-select" name="{{ $field }}">
                                        <option value="inherit" @selected($court->{$field} === null)>Наследовать от площадки</option>
                                        <option value="yes" @selected($court->{$field} === true)>{{ $yes }}</option>
                                        <option value="no" @selected($court->{$field} === false)>{{ $no }}</option>
                                    </select>
                                </label>
                            @endforeach
                            <div class="row g-3 mt-2">
                                @foreach([
                                    'minimum_duration_minutes' => ['Минимальная аренда, мин', $policy?->minimum_duration_minutes, 15, 1440],
                                    'maximum_duration_minutes' => ['Максимальная аренда, мин', $policy?->maximum_duration_minutes, 15, 1440],
                                    'time_step_minutes' => ['Шаг времени, мин', $policy?->time_step_minutes, 5, 240],
                                    'minimum_lead_time_minutes' => ['Минимум до начала, мин', $policy?->minimum_lead_time_minutes, 0, 525600],
                                    'maximum_advance_days' => ['Бронирование вперёд, дней', $policy?->maximum_advance_days, 1, 730],
                                ] as $field => [$label, $inherited, $min, $max])
                                    <div class="col-md-4">
                                        <label class="form-label" for="court-{{ $court->id }}-{{ $field }}">{{ $label }}</label>
                                        <input class="form-control" type="number" id="court-{{ $court->id }}-{{ $field }}" name="{{ $field }}"
                                            min="{{ $min }}" max="{{ $max }}" value="{{ $court->{$field} ?? '' }}"
                                            placeholder="От площадки: {{ $inherited ?? '—' }}">
                                    </div>
                                @endforeach
                            </div>
                            <p class="small text-muted mt-3">Цена за шаг {{ $court->time_step_minutes ?? $policy?->time_step_minutes ?? '—' }} мин в {{ $policy?->currency ?? 'RUB' }}. Пустое поле наследуется от площадки.</p>
                            <label class="form-label mt-2">Весь зал
                                <input class="form-control" name="whole_price_per_step" value="{{ $court->whole_price_per_step_minor === null ? '' : number_format($court->whole_price_per_step_minor / 100, 2, '.', '') }}" placeholder="Наследовать">
                            </label>
                            <label class="form-label mt-2">Половина зала
                                <input class="form-control" name="half_price_per_step" value="{{ $court->half_price_per_step_minor === null ? '' : number_format($court->half_price_per_step_minor / 100, 2, '.', '') }}" placeholder="Наследовать">
                            </label>
                            <button class="btn btn--primary btn--sm mt-3" type="submit">Сохранить условия зала</button>
                        </form>
                    </details>
                    <details class="mt-3"><summary class="fw-semibold">Стоимость по временным слотам</summary>
                        @foreach($court->slotPrices()->orderBy('day_of_week')->orderBy('starts_at')->get() as $slotPrice)
                            <div class="d-flex gap-2 align-items-center mt-2 flex-wrap">
                                <span>День {{ $slotPrice->day_of_week }}, {{ substr($slotPrice->starts_at, 0, 5) }}:
                                    {{ $slotPrice->whole_price_per_step_minor === null ? '—' : number_format($slotPrice->whole_price_per_step_minor / 100, 2, ',', ' ') }}
                                    / {{ $slotPrice->half_price_per_step_minor === null ? '—' : number_format($slotPrice->half_price_per_step_minor / 100, 2, ',', ' ') }}</span>
                                <form method="POST" action="{{ route('account.venues.courts.prices.destroy', [$venue->routeIdentifier(), $court->routeIdentifier(), $slotPrice->id]) }}">
                                    @csrf @method('DELETE')
                                    <button type="submit" class="btn btn--danger btn--sm">Удалить</button>
                                </form>
                            </div>
                        @endforeach
                        <form method="POST" class="mt-3" action="{{ route('account.venues.courts.prices.store', [$venue->routeIdentifier(), $court->routeIdentifier()]) }}">
                            @csrf
                            <label class="form-label">День недели
                                <select class="form-select" name="day_of_week" required>
                                    @foreach([1=>'Пн',2=>'Вт',3=>'Ср',4=>'Чт',5=>'Пт',6=>'Сб',7=>'Вс'] as $day => $label)
                                        <option value="{{ $day }}">{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="form-label">Начало шага <input class="form-control" type="time" name="starts_at" required></label>
                            <label class="form-label">Весь зал <input class="form-control" name="whole_price_per_step" placeholder="Не переопределять"></label>
                            <label class="form-label">Половина <input class="form-control" name="half_price_per_step" placeholder="Не переопределять"></label>
                            <button type="submit" class="btn btn--secondary btn--sm mt-2">Добавить / обновить тариф</button>
                        </form>
                    </details>
                    @if($policy)
                        @include('theme::partials.venues.calendar-price-editor', [
                            'calendarPrices' => $court->calendarSlotPrices()->orderBy('date')->orderBy('starts_at')->get(),
                            'calendarStoreUrl' => route('account.venues.courts.calendar-prices.store', [$venue->routeIdentifier(), $court->routeIdentifier()]),
                            'calendarDeleteUrl' => route('account.venues.courts.calendar-prices.destroy', [$venue->routeIdentifier(), $court->routeIdentifier(), '__PRICE__']),
                            'calendarStep' => $court->time_step_minutes ?? $policy->time_step_minutes,
                            'calendarCurrency' => $policy->currency,
                            'calendarId' => 'court-calendar-'.$court->id,
                            'amounts' => app(\App\Modules\VenueBooking\Application\Services\MinorAmountParser::class),
                        ])
                    @endif
                @endif

                @if($canManageVenue && $courts->count() > 1)
                    <form method="POST" action="{{ route('account.venues.courts.destroy', [$venue->routeIdentifier(), $court->routeIdentifier()]) }}" class="mt-3" onsubmit="return confirm('Удалить этот зал?')">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="btn btn--danger btn--sm">Удалить</button>
                    </form>
                @endif
            </div>
        </article>
    @endforeach

    @if($canManageVenue)
    <div class="card mt-4">
        <div class="card-body">
            <h2 class="h4 mb-3">Добавить зал</h2>
            <form method="POST" action="{{ route('account.venues.courts.store', $venue->routeIdentifier()) }}" data-court-form>
                @csrf
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="court-new-name">Название</label>
                        <input id="court-new-name" class="form-control" name="name" value="{{ old('name') }}" maxlength="120" placeholder="Например: Малый зал" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="court-new-alias">Alias <span class="text-muted">(необязательно)</span></label>
                        <input id="court-new-alias" class="form-control" name="alias" value="{{ old('alias') }}" maxlength="120" placeholder="maliy-zal">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="court-new-order">Порядок</label>
                        <input id="court-new-order" class="form-control" type="number" min="0" max="65535" name="sort_order" value="{{ old('sort_order') }}" placeholder="авто">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="court-new-hoops">Количество колец</label>
                        <select id="court-new-hoops" class="form-select" name="hoops_count" data-court-hoops required>
                            <option value="1" @selected((int) old('hoops_count', $courtDefaults['hoops_count']) === 1)>1</option>
                            <option value="2" @selected((int) old('hoops_count', $courtDefaults['hoops_count']) === 2)>2</option>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label" for="court-new-environment">Расположение игровой зоны</label>
                        <select id="court-new-environment" class="form-select" name="environment">
                            <option value="">Не указано</option>
                            @foreach($courtEnvironments as $environment)
                                <option value="{{ $environment->value }}" @selected(old('environment') === $environment->value)>{{ $environment->label() }}</option>
                            @endforeach
                        </select>
                        <label class="form-label" for="court-new-surface">Покрытие</label>
                        <select id="court-new-surface" class="form-select" name="surface_type">
                            <option value="">Не указано</option>
                            @foreach($surfaceTypes as $surfaceType)
                                <option value="{{ $surfaceType->value }}" @selected(old('surface_type') === $surfaceType->value)>{{ $surfaceType->label() }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="mt-3 d-flex flex-column gap-2" data-court-scope-options>
                    <label>
                        <input type="hidden" name="allows_whole" value="0">
                        <input type="checkbox" name="allows_whole" value="1" @checked((bool) old('allows_whole', $courtDefaults['allows_whole']))>
                        Разрешить аренду всего зала
                    </label>
                    <label data-court-half-option>
                        <input type="hidden" name="allows_halves" value="0">
                        <input type="checkbox" name="allows_halves" value="1" @checked((bool) old('allows_halves', $courtDefaults['allows_halves']))>
                        Разрешать аренду половин
                    </label>
                    <small class="text-muted" data-court-halves-hint>Аренда половин доступна только для зала с двумя кольцами.</small>
                </div>
                <div class="row g-3 mt-2">
                    <div class="col-md-6">
                        <label class="form-label" for="court-new-short-description">Краткое описание</label>
                        <textarea id="court-new-short-description" class="form-control" name="short_description" rows="3" maxlength="500" placeholder="Наследовать описание площадки">{{ old('short_description') }}</textarea>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="court-new-full-description">Полное описание</label>
                        <textarea id="court-new-full-description" class="form-control" name="full_description" rows="6" maxlength="10000" placeholder="Наследовать описание площадки">{{ old('full_description') }}</textarea>
                    </div>
                </div>
                @include('venues.partials.court-amenities', ['court' => null, 'courtAmenities' => $courtAmenities])
                <button type="submit" class="btn btn--primary btn--sm mt-3">Добавить зал / корт</button>
            </form>
        </div>
    </div>
@endif
@endsection
