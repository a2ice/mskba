@php
    use App\Modules\Acquisition\Domain\Enums\AcquisitionChannelEnum;
    use App\Modules\Acquisition\Domain\Enums\AcquisitionLandingTypeEnum;

    $editing = $campaign->exists;
    $title = $editing ? 'Кампания · '.$campaign->name : 'Новая кампания привлечения';
    $startsAt = old('starts_at', $campaign->starts_at?->format('Y-m-d\TH:i'));
    $endsAt = old('ends_at', $campaign->ends_at?->format('Y-m-d\TH:i'));

    $selectedChannelValue = old('channel', $campaign->channel?->value ?? AcquisitionChannelEnum::QR->value);
    $selectedChannel = AcquisitionChannelEnum::tryFrom($selectedChannelValue) ?? AcquisitionChannelEnum::QR;

    $selectedLandingTypeValue = old(
        'landing_type',
        $campaign->landing_type?->value ?? AcquisitionLandingTypeEnum::ONBOARDING->value,
    );
    $selectedLandingType = AcquisitionLandingTypeEnum::tryFrom($selectedLandingTypeValue)
        ?? AcquisitionLandingTypeEnum::ONBOARDING;

    $selectedVenueId = old('venue_id', $selectedVenue?->id ?? $campaign->venue_id ?? '');
    $selectedVenueLabel = $selectedVenue?->name ?? $campaign->venue?->name ?? '';
    $selectedLandingTargetId = old('landing_target_id', $selectedLandingTarget['id'] ?? '');
    $selectedLandingTargetLabel = $selectedLandingTarget['name'] ?? '';

    $locationVerificationEnabled = (bool) old(
        'location_verification_enabled',
        $editing ? $campaign->location_verification_enabled : $selectedChannel === AcquisitionChannelEnum::QR,
    );

    $locationLabels = [
        'not_requested' => 'Не запрашивалась',
        'verified' => 'Подтверждено',
        'mismatch' => 'Не совпало',
        'inaccurate' => 'Низкая точность',
        'denied' => 'Отказ',
        'unavailable' => 'Недоступна',
    ];

    $landingSearchUrl = $selectedLandingType->needsTarget()
        ? route('admin.acquisition.landing-candidates', ['type' => $selectedLandingType->value])
        : '';
@endphp

@extends('theme::partials.admin.list-shell', [
    'title' => $title,
    'subtitle' => $editing
        ? 'Настройки, посадочная, материалы и результаты конкретного источника привлечения.'
        : 'Создайте отдельную кампанию для QR-точки, рекламы, социальных сетей или партнёрского источника.',
])

@section('section-content')
    <div class="admin-section-toolbar">
        <a href="{{ route('admin.acquisition.index') }}" class="btn btn--secondary btn--sm">
            <i class="ti ti-arrow-left" aria-hidden="true"></i>
            К списку
        </a>
        @if($editing)
            <span class="admin-muted">ID {{ $campaign->id }}</span>
        @endif
    </div>

    @if(session('success'))
        <div class="alert alert-success">{{ session('success') }}</div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger">
            <strong>Проверьте данные кампании.</strong>
            <ul class="mb-0 mt-2">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form
        method="POST"
        action="{{ $editing ? route('admin.acquisition.update', $campaign) : route('admin.acquisition.store') }}"
        class="admin-acquisition-form"
        data-admin-acquisition-form
    >
        @csrf
        @if($editing) @method('PUT') @endif

        <section class="section-card admin-acquisition-card">
            <div class="admin-acquisition-card__header">
                <div>
                    <p class="admin-kicker">Кампания</p>
                    <h2>Основные параметры</h2>
                    <p class="admin-muted">Название и канал нужны для внутреннего учёта и аналитики.</p>
                </div>

                @include('theme::partials.forms.toggle', [
                    'id' => 'acquisition-active',
                    'name' => 'is_active',
                    'checked' => (bool) old('is_active', $editing ? $campaign->is_active : true),
                    'title' => 'Кампания активна',
                    'description' => 'Можно выключить вручную независимо от периода действия.',
                    'wrapperClass' => 'admin-acquisition-toggle',
                ])
            </div>

            <div class="admin-acquisition-grid">
                <div class="admin-acquisition-field">
                    <label class="form-label" for="acquisition-name">Название</label>
                    <input
                        id="acquisition-name"
                        class="form-control"
                        name="name"
                        maxlength="160"
                        required
                        value="{{ old('name', $campaign->name) }}"
                        placeholder="Листовка · Школа 1794 · главный вход"
                    >
                </div>

                <div class="admin-acquisition-field">
                    <label class="form-label" for="acquisition-channel">Канал</label>
                    <select id="acquisition-channel" class="form-select" name="channel" required data-acquisition-channel>
                        @foreach($channels as $channel)
                            <option
                                value="{{ $channel->value }}"
                                data-supports-physical="{{ $channel->supportsPhysicalContext() ? '1' : '0' }}"
                                data-supports-location="{{ $channel->supportsLocationVerification() ? '1' : '0' }}"
                                data-supports-materials="{{ $channel->supportsPrintableMaterials() ? '1' : '0' }}"
                                @selected($selectedChannelValue === $channel->value)
                            >{{ $channel->label() }}</option>
                        @endforeach
                    </select>
                    <p class="form-hint">Набор дополнительных полей ниже зависит от выбранного канала.</p>
                </div>

                <div class="admin-acquisition-field admin-acquisition-grid__wide">
                    <label class="form-label" for="acquisition-code">Публичный код</label>
                    <input
                        id="acquisition-code"
                        class="form-control"
                        name="public_code"
                        maxlength="64"
                        pattern="[A-Za-z0-9_-]{2,64}"
                        value="{{ old('public_code', $campaign->public_code) }}"
                        placeholder="Можно оставить пустым"
                    >
                    <p class="form-hint">Используется в адресе <code>/go/{code}</code>. Для новой кампании код может быть сгенерирован автоматически.</p>
                </div>
            </div>
        </section>

        <section class="section-card admin-acquisition-card">
            <div>
                <p class="admin-kicker">Период</p>
                <h2>Период действия</h2>
                <p class="admin-muted">Оставьте оба поля пустыми для бессрочной кампании. Ручной тумблер «Кампания активна» имеет приоритет.</p>
            </div>

            <div class="admin-acquisition-grid">
                <div class="admin-acquisition-field">
                    <label class="form-label" for="acquisition-starts-at">Начало действия <span class="admin-acquisition-optional">необязательно</span></label>
                    <input id="acquisition-starts-at" class="form-control" type="datetime-local" name="starts_at" value="{{ $startsAt }}">
                    <p class="form-hint">До этой даты существующая кампания покажет посетителю страницу «Кампания ещё не началась».</p>
                </div>

                <div class="admin-acquisition-field">
                    <label class="form-label" for="acquisition-ends-at">Окончание действия <span class="admin-acquisition-optional">необязательно</span></label>
                    <input id="acquisition-ends-at" class="form-control" type="datetime-local" name="ends_at" value="{{ $endsAt }}">
                    <p class="form-hint">После этой даты посетитель увидит страницу «Кампания завершена».</p>
                </div>
            </div>
        </section>

        <section class="section-card admin-acquisition-card">
            <div>
                <p class="admin-kicker">Посадочная</p>
                <h2>Куда вести посетителя</h2>
                <p class="admin-muted">По умолчанию используется текущий onboarding: приветствие, выбор роли, регистрация или вход.</p>
            </div>

            <div class="admin-acquisition-grid">
                <div class="admin-acquisition-field admin-acquisition-grid__wide">
                    <label class="form-label" for="acquisition-landing-type">Посадочная страница</label>
                    <select id="acquisition-landing-type" class="form-select" name="landing_type" required data-acquisition-landing-type>
                        @foreach($landingTypes as $landingType)
                            <option
                                value="{{ $landingType->value }}"
                                data-needs-target="{{ $landingType->needsTarget() ? '1' : '0' }}"
                                @selected($selectedLandingTypeValue === $landingType->value)
                            >{{ $landingType->label() }}</option>
                        @endforeach
                    </select>
                </div>

                <div
                    class="admin-acquisition-field admin-acquisition-grid__wide"
                    data-acquisition-landing-target
                    data-search-base-url="{{ route('admin.acquisition.landing-candidates') }}"
                    @if(! $selectedLandingType->needsTarget()) hidden @endif
                >
                    @include('theme::partials.forms.entity-predictive-search', [
                        'id' => 'acquisitionLandingTarget',
                        'name' => 'landing_target_id',
                        'label' => 'Целевая сущность',
                        'placeholder' => 'Начните вводить название…',
                        'searchUrl' => $landingSearchUrl,
                        'minimumLength' => 2,
                        'required' => true,
                        'selectedId' => $selectedLandingTargetId,
                        'selectedLabel' => $selectedLandingTargetLabel,
                        'initialMessage' => $selectedLandingTargetId
                            ? 'Выбрано: '.$selectedLandingTargetLabel
                            : 'Введите не менее 2 символов и выберите вариант.',
                    ])
                </div>
            </div>

            @if($editing)
                <div class="admin-acquisition-url">
                    <span>Вход кампании</span>
                    <code>{{ route('acquisition.entry', ['campaignCode' => $campaign->public_code]) }}</code>
                </div>
            @endif
        </section>

        <section
            class="section-card admin-acquisition-card"
            data-acquisition-physical-context
            @if(! $selectedChannel->supportsPhysicalContext()) hidden @endif
        >
            <div>
                <p class="admin-kicker">Контекст</p>
                <h2>Площадка и физическая точка</h2>
                <p class="admin-muted">
                    Этот блок нужен для офлайн-размещения. Для онлайн-каналов он скрывается и не участвует в кампании.
                </p>
            </div>

            <div class="admin-acquisition-field">
                @include('theme::partials.forms.entity-predictive-search', [
                    'id' => 'acquisitionVenue',
                    'name' => 'venue_id',
                    'label' => 'Площадка',
                    'placeholder' => 'Начните вводить название или адрес…',
                    'searchUrl' => route('admin.acquisition.venues'),
                    'minimumLength' => 2,
                    'required' => false,
                    'selectedId' => $selectedVenueId,
                    'selectedLabel' => $selectedVenueLabel,
                    'initialMessage' => $selectedVenueId
                        ? 'Выбрано: '.$selectedVenueLabel
                        : 'Необязательно. Введите не менее 2 символов и выберите площадку.',
                ])
            </div>

            <div class="admin-acquisition-field">
                <label class="form-label" for="acquisition-placement">Место размещения <span class="admin-acquisition-optional">необязательно</span></label>
                <input
                    id="acquisition-placement"
                    class="form-control"
                    name="placement"
                    maxlength="160"
                    value="{{ old('placement', data_get($campaign->metadata, 'placement')) }}"
                    placeholder="Например: стенд у главного входа"
                >
                <p class="form-hint">Позволяет отличать несколько физических источников на одной площадке.</p>
            </div>

            <div
                data-acquisition-location-capability
                @if(! $selectedChannel->supportsLocationVerification()) hidden @endif
            >
                @include('theme::partials.forms.toggle', [
                    'id' => 'acquisition-location-verification',
                    'name' => 'location_verification_enabled',
                    'checked' => $locationVerificationEnabled,
                    'title' => 'Проверять присутствие рядом с площадкой',
                    'description' => 'Добровольно запросим геолокацию посетителя и сравним её с координатами площадки.',
                    'wrapperClass' => 'admin-acquisition-toggle admin-acquisition-toggle--boxed',
                    'inputAttributes' => ['data-acquisition-location-toggle' => true],
                ])

                <div
                    class="admin-acquisition-field admin-acquisition-radius"
                    data-acquisition-radius
                    @if(! $locationVerificationEnabled) hidden @endif
                >
                    <label class="form-label" for="acquisition-radius">Радиус геопроверки, м</label>
                    <input
                        id="acquisition-radius"
                        class="form-control"
                        type="number"
                        name="verification_radius_m"
                        min="25"
                        max="5000"
                        value="{{ old('verification_radius_m', $campaign->verification_radius_m ?? 250) }}"
                    >
                    <p class="form-hint">Для QR возле площадки обычно достаточно 100–250 м. Система дополнительно учитывает точность GPS.</p>
                </div>
            </div>
        </section>

        <section class="section-card admin-acquisition-card">
            <div>
                <p class="admin-kicker">Служебное</p>
                <h2>Внутренняя заметка</h2>
            </div>

            <div class="admin-acquisition-field">
                <label class="form-label" for="acquisition-notes">Заметка <span class="admin-acquisition-optional">необязательно</span></label>
                <textarea
                    id="acquisition-notes"
                    class="form-control"
                    name="notes"
                    rows="3"
                    maxlength="1000"
                    placeholder="Например: А4 в прозрачной рамке, размещено 18.09"
                >{{ old('notes', data_get($campaign->metadata, 'notes')) }}</textarea>
                <p class="form-hint">Не показывается посетителю.</p>
            </div>
        </section>

        <div class="admin-acquisition-save">
            <button type="submit" class="btn btn--primary">
                {{ $editing ? 'Сохранить кампанию' : 'Создать кампанию' }}
            </button>
        </div>
    </form>

    @if($editing)
        <section
            class="section-card admin-acquisition-card"
            data-acquisition-qr-materials
            @if(! $selectedChannel->supportsPrintableMaterials()) hidden @endif
        >
            <div>
                <p class="admin-kicker">Материалы QR</p>
                <h2>QR и A4-листовка</h2>
                <p class="admin-muted">QR ведёт на нейтральный адрес кампании <code>/go/{code}</code>; оттуда посетитель попадает на выбранную посадочную.</p>
            </div>

            <div class="admin-row-actions admin-acquisition-actions">
                <a href="{{ route('acquisition.entry', ['campaignCode' => $campaign->public_code]) }}" target="_blank" rel="noopener" class="btn btn--secondary">Открыть кампанию</a>
                <a href="{{ route('admin.acquisition.flyer.preview', $campaign) }}" target="_blank" rel="noopener" class="btn btn--secondary">Preview A4</a>
                <a href="{{ route('admin.acquisition.flyer.pdf', $campaign) }}" class="btn btn--primary">Скачать PDF</a>
                <a href="{{ route('admin.acquisition.qr.svg', $campaign) }}" target="_blank" rel="noopener" class="btn btn--secondary">QR SVG</a>
                <a href="{{ route('admin.acquisition.qr.png', $campaign) }}" class="btn btn--secondary">QR PNG</a>
            </div>
        </section>

        <section class="section-card admin-acquisition-card">
            <div>
                <p class="admin-kicker">Attribution</p>
                <h2>Результаты кампании</h2>
            </div>

            <div class="admin-acquisition-metrics">
                <div><strong>{{ $stats['visits'] }}</strong><span>переходов</span></div>
                <div><strong>{{ $stats['linked_visits'] }}</strong><span>связанных визитов</span></div>
                <div><strong>{{ $stats['linked_users'] }}</strong><span>уникальных пользователей</span></div>
                <div><strong>{{ $stats['location_statuses']['verified'] ?? 0 }}</strong><span>подтвердили локацию</span></div>
            </div>

            <div class="admin-acquisition-breakdowns">
                <div>
                    <h3>Геопроверка</h3>
                    @forelse($stats['location_statuses'] as $status => $total)
                        <div class="admin-acquisition-breakdown-row">
                            <span>{{ $locationLabels[$status] ?? $status }}</span>
                            <strong>{{ $total }}</strong>
                        </div>
                    @empty
                        <p class="admin-muted">Переходов пока нет.</p>
                    @endforelse
                </div>
                <div>
                    <h3>Первое намерение</h3>
                    @forelse($stats['personas'] as $persona => $total)
                        <div class="admin-acquisition-breakdown-row">
                            <span>{{ $persona }}</span>
                            <strong>{{ $total }}</strong>
                        </div>
                    @empty
                        <p class="admin-muted">Persona пока не выбирали.</p>
                    @endforelse
                </div>
            </div>

            @if($stats['recent_visits']->isNotEmpty())
                <h3 class="mt-4">Последние переходы</h3>
                <div class="admin-table-wrap">
                    <table class="admin-table">
                        <thead>
                            <tr>
                                <th>Когда</th>
                                <th>Пользователь</th>
                                <th>Persona</th>
                                <th>Геопроверка</th>
                                <th>Расстояние</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach($stats['recent_visits'] as $visit)
                                <tr>
                                    <td>{{ $visit->visited_at?->format('d.m.Y H:i') }}</td>
                                    <td>{{ $visit->user?->username ?? 'Гость' }}</td>
                                    <td>{{ $visit->persona?->value ?? '—' }}</td>
                                    <td>{{ $locationLabels[$visit->location_status] ?? $visit->location_status }}</td>
                                    <td>{{ $visit->distance_to_venue_m !== null ? $visit->distance_to_venue_m.' м' : '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif
@endsection
