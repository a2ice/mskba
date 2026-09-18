@php
    $editing = $campaign->exists;
    $title = $editing ? 'Кампания · '.$campaign->name : 'Новая acquisition-кампания';
    $startsAt = old('starts_at', $campaign->starts_at?->format('Y-m-d\TH:i'));
    $endsAt = old('ends_at', $campaign->ends_at?->format('Y-m-d\TH:i'));
    $selectedVenueId = old('venue_id', $campaign->venue_id ?? '');
    $selectedVenueLabel = $campaign->venue?->name ?? '';
    $locationLabels = [
        'not_requested' => 'Не запрашивалась',
        'verified' => 'Подтверждено',
        'mismatch' => 'Не совпало',
        'inaccurate' => 'Низкая точность',
        'denied' => 'Отказ',
        'unavailable' => 'Недоступна',
    ];
@endphp

@extends('theme::partials.admin.list-shell', [
    'title' => $title,
    'subtitle' => $editing
        ? 'Настройки, материалы и результаты конкретного канала привлечения.'
        : 'Создайте отдельную кампанию для физической QR-точки, рекламы или партнёрского источника.',
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
    >
        @csrf
        @if($editing) @method('PUT') @endif

        <section class="section-card admin-acquisition-card">
            <div class="admin-acquisition-card__header">
                <div>
                    <p class="admin-kicker">Кампания</p>
                    <h2>Основные параметры</h2>
                </div>
                <label class="form-check form-switch">
                    <input
                        class="form-check-input"
                        type="checkbox"
                        name="is_active"
                        value="1"
                        @checked(old('is_active', $editing ? $campaign->is_active : true))
                    >
                    <span class="form-check-label">Активна</span>
                </label>
            </div>

            <div class="admin-acquisition-grid">
                <label class="form-field">
                    <span class="form-label">Название</span>
                    <input
                        class="form-control"
                        name="name"
                        maxlength="160"
                        required
                        value="{{ old('name', $campaign->name) }}"
                        placeholder="Листовка · Школа 1794 · главный вход"
                    >
                </label>

                <label class="form-field">
                    <span class="form-label">Канал</span>
                    <select class="form-select" name="channel" required>
                        @foreach($channels as $channel)
                            <option
                                value="{{ $channel->value }}"
                                @selected(old('channel', $campaign->channel?->value ?? 'qr') === $channel->value)
                            >{{ $channel->label() }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="form-field">
                    <span class="form-label">Публичный код</span>
                    <input
                        class="form-control"
                        name="public_code"
                        maxlength="64"
                        pattern="[A-Za-z0-9_-]{2,64}"
                        value="{{ old('public_code', $campaign->public_code) }}"
                        placeholder="Можно оставить пустым"
                    >
                    <span class="form-hint">Используется в /join/{code}. Для новой кампании может быть сгенерирован автоматически.</span>
                </label>

                <label class="form-field">
                    <span class="form-label">Радиус геопроверки, м</span>
                    <input
                        class="form-control"
                        type="number"
                        name="verification_radius_m"
                        min="25"
                        max="5000"
                        required
                        value="{{ old('verification_radius_m', $campaign->verification_radius_m ?? 250) }}"
                    >
                </label>

                <label class="form-field">
                    <span class="form-label">Начало</span>
                    <input class="form-control" type="datetime-local" name="starts_at" value="{{ $startsAt }}">
                </label>

                <label class="form-field">
                    <span class="form-label">Окончание</span>
                    <input class="form-control" type="datetime-local" name="ends_at" value="{{ $endsAt }}">
                </label>
            </div>
        </section>

        <section class="section-card admin-acquisition-card">
            <p class="admin-kicker">Контекст</p>
            <h2>Площадка и физическая точка</h2>
            <p class="admin-muted">
                Площадка необязательна: acquisition-кампания может относиться к партнёру, рекламе или мероприятию.
                Для QR возле площадки связь нужна для контекста landing и добровольной геопроверки.
            </p>

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

            <div class="admin-acquisition-grid mt-3">
                <label class="form-field">
                    <span class="form-label">Место размещения</span>
                    <input
                        class="form-control"
                        name="placement"
                        maxlength="160"
                        value="{{ old('placement', data_get($campaign->metadata, 'placement')) }}"
                        placeholder="Например: стенд у главного входа"
                    >
                    <span class="form-hint">Позволяет отличать несколько QR-источников на одной площадке.</span>
                </label>

                <label class="form-field admin-acquisition-grid__wide">
                    <span class="form-label">Внутренняя заметка</span>
                    <textarea class="form-control" name="notes" rows="3" maxlength="1000" placeholder="Не показывается посетителю">{{ old('notes', data_get($campaign->metadata, 'notes')) }}</textarea>
                </label>
            </div>
        </section>

        <div class="admin-acquisition-save">
            <button type="submit" class="btn btn--primary">
                {{ $editing ? 'Сохранить кампанию' : 'Создать кампанию' }}
            </button>
        </div>
    </form>

    @if($editing)
        <section class="section-card admin-acquisition-card">
            <p class="admin-kicker">Материалы</p>
            <h2>Ссылка, QR и A4-листовка</h2>
            <p class="admin-muted">
                Текущая листовка — встроенный HTML-шаблон. PDF формируется из того же HTML, поэтому preview и печатный результат используют один источник.
            </p>

            <div class="admin-acquisition-url">
                <code>{{ route('acquisition.join', ['campaignCode' => $campaign->public_code]) }}</code>
            </div>

            <div class="admin-row-actions admin-acquisition-actions">
                <a href="{{ route('acquisition.join', ['campaignCode' => $campaign->public_code]) }}" target="_blank" rel="noopener" class="btn btn--secondary">Открыть /join</a>
                <a href="{{ route('admin.acquisition.flyer.preview', $campaign) }}" target="_blank" rel="noopener" class="btn btn--secondary">Preview A4</a>
                <a href="{{ route('admin.acquisition.flyer.pdf', $campaign) }}" class="btn btn--primary">Скачать PDF</a>
                <a href="{{ route('admin.acquisition.qr.svg', $campaign) }}" target="_blank" rel="noopener" class="btn btn--secondary">QR SVG</a>
                <a href="{{ route('admin.acquisition.qr.png', $campaign) }}" class="btn btn--secondary">QR PNG</a>
            </div>
        </section>

        <section class="section-card admin-acquisition-card">
            <p class="admin-kicker">Attribution</p>
            <h2>Результаты кампании</h2>

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
