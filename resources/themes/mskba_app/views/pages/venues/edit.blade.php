@php
    $title = $venue->name;
    $breadcrumbs = [
        ['label' => 'Аккаунт', 'url' => route('account')],
        ['label' => 'Мои контракты', 'url' => route('account.contracts')],
        ['label' => 'Мои площадки', 'url' => route('account.contracts.venues')],
        ['label' => $venue->name],
    ];
    $route = $venue->routeIdentifier();
    $pending = $venue->hasPendingModerationRequest();
    $revision = $venueRevision?->payload ?? [];
    $details = array_replace([
        'name' => $venue->name, 'type' => $venue->type->value,
        'access_type' => $venue->requires_payment === null ? 'unknown' : ($venue->requires_payment ? 'paid' : 'free'),
        'requires_booking_approval' => $venue->requires_booking_approval,
        'short_description' => $venue->short_description,
        'full_description' => $venue->full_description,
    ], (array) ($revision['details'] ?? []));
    $tags = (array) ($revision['tags'] ?? $venue->tags->pluck('name')->all());
    $address = $venue->location?->address;
    $location = array_replace([
        'raw_address' => $address?->full_address ?? $venue->raw_address,
        'city_id' => $address?->city_id, 'district_id' => $address?->district_id,
        'city' => $address?->city, 'street' => $address?->street, 'building' => $address?->building,
        'postal_code' => $address?->postal_code,
        'latitude' => $address?->latitude, 'longitude' => $address?->longitude,
        'metro_station_ids' => $venue->location?->metroStations?->pluck('id')->all() ?? [],
    ], (array) ($revision['location'] ?? []));
    $facilities = (array) ($revision['facilities'] ?? []);
    $specs = (array) ($facilities['characteristics'] ?? $venue->characteristics?->toArray() ?? []);
    $amenityIds = (array) ($facilities['amenity_ids'] ?? $venue->amenities->pluck('id')->all());
    $tabs = [
        'general' => 'Основное', 'address' => 'Адрес и метро',
        'facilities' => 'Удобства', 'courts' => 'Залы и корты',
        'schedule' => 'Расписание', 'photos' => 'Фотографии',
        'contracts' => 'Контракты', 'rental' => 'Аренда',
    ];
    $canRentalTab = ($editor['rental'] ?? false) || ($editor['offers'] ?? false) || ($editor['memberships'] ?? false);
    $canTab = static fn (string $key): bool => $key === 'rental' ? $canRentalTab : (bool) ($editor[$key] ?? false);
    $mutateUrl = route('account.venues.update', $route);
    $saveDisabled = $pending;
    $contractManager = null;
    if ($editor['contracts'] ?? false) {
        $contractManager = $venue->memberships()
            ->whereIn('user_id', auth()->user()->canonical()->identityIds())
            ->first();
    }
@endphp

@extends('theme::layouts.account', ['title' => $title, 'breadcrumbs' => $breadcrumbs])

@section('account-heading')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => $venue->name,
        'intro' => 'Редактирование площадки',
    ])
@endsection

@section('account-content')
<div class="app-venue-editor" data-venue-editor>
    <nav class="app-venue-editor__tabs app-player-workspace__tabs" aria-label="Разделы редактирования площадки">
        @foreach($tabs as $key => $label)
            @if($canTab($key))
                <a href="{{ route('account.venues.edit', ['alias' => $route, 'tab' => $key]) }}"
                    @class(['is-active' => $tab === $key])
                    @if($tab === $key) aria-current="page" @endif>{{ $label }}</a>
            @else
                <span class="app-venue-editor__tab-disabled" aria-disabled="true" title="Нет разрешения для этого раздела">{{ $label }}</span>
            @endif
        @endforeach
    </nav>

    @if(session('status') || session('photo_status'))
        <div class="app-venue-editor__notice" role="status">{{ session('status') ?: session('photo_status') }}</div>
    @endif
    @if(session('error') || session('photo_error') || $errors->any())
        <div class="app-venue-editor__error" role="alert">
            {{ session('error') ?: session('photo_error') ?: $errors->first() }}
        </div>
    @endif
    @if($pending && in_array($tab, ['general', 'address', 'facilities'], true))
        <div class="app-venue-editor__notice app-venue-editor__notice--muted">Площадка на модерации. Изменения временно недоступны.</div>
    @endif

    @if(in_array($tab, ['general','address','facilities'], true))
        <form method="POST" action="{{ $mutateUrl }}" class="app-venue-editor__form">
            @csrf @method('PUT')
            <input type="hidden" name="editor_tab" value="{{ $tab }}">
            @if($tab !== 'general')
                <input type="hidden" name="name" value="{{ $details['name'] }}">
                <input type="hidden" name="type" value="{{ $details['type'] }}">
                <input type="hidden" name="short_description" value="{{ $details['short_description'] }}">
                <input type="hidden" name="full_description" value="{{ $details['full_description'] }}">
                <input type="hidden" name="tags" value="{{ implode(', ', $tags) }}">
                <input type="hidden" name="access_type" value="{{ $details['access_type'] }}">
                <input type="hidden" name="requires_booking_approval" value="{{ (int) ($details['requires_booking_approval'] ?? false) }}">
            @endif
            <fieldset @disabled($saveDisabled) class="app-venue-editor__fieldset">
                @if($tab === 'general')
                    <section class="panel app-venue-editor__panel">
                        <h2>Основные данные</h2>
                        <div class="app-venue-editor__grid">
                            <label class="app-venue-editor__field">Название площадки
                                <input type="text" name="name" minlength="3" maxlength="255" required value="{{ old('name', $details['name']) }}">
                            </label>
                            <label class="app-venue-editor__field">Тип площадки
                                <select name="type" required>
                                    @foreach($types as $item)
                                        <option value="{{ $item->value }}" @selected(old('type', $details['type']) === $item->value)>{{ $item->label() }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="app-venue-editor__field">URL-алиас
                                <input type="text" value="{{ $venue->alias }}" disabled>
                            </label>
                            <label class="app-venue-editor__field">Статус модерации
                                <input type="text" value="{{ $venue->status->label() }}" disabled>
                            </label>
                            <label class="app-venue-editor__field">Условия посещения
                                <select name="access_type">
                                    @foreach(['unknown' => 'Не указано', 'free' => 'Бесплатно', 'paid' => 'Платно'] as $value => $label)
                                        <option value="{{ $value }}" @selected(old('access_type', $details['access_type']) === $value)>{{ $label }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="app-venue-editor__field app-venue-editor__field--check">
                                <input type="hidden" name="requires_booking_approval" value="0">
                                <input type="checkbox" name="requires_booking_approval" value="1"
                                    @checked((bool) old('requires_booking_approval', $details['requires_booking_approval']))>
                                Требуется подтверждение бронирования
                            </label>
                            <label class="app-venue-editor__field app-venue-editor__field--full">Краткое описание
                                <textarea name="short_description" rows="3" maxlength="500">{{ old('short_description', $details['short_description']) }}</textarea>
                            </label>
                            <label class="app-venue-editor__field app-venue-editor__field--full">Полное описание
                                <textarea name="full_description" rows="6" maxlength="10000">{{ old('full_description', $details['full_description']) }}</textarea>
                            </label>
                            <label class="app-venue-editor__field app-venue-editor__field--full">Теги
                                <input name="tags" type="text" maxlength="1000" value="{{ old('tags', implode(', ', $tags)) }}">
                            </label>
                        </div>
                    </section>
                @elseif($tab === 'address')
                    <section class="panel app-venue-editor__panel">
                        <h2>Адрес и метро</h2>
                        <div class="app-venue-editor__grid">
                            <label class="app-venue-editor__field app-venue-editor__field--full">Полный адрес
                                <input name="location[raw_address]" type="text" maxlength="1000" required
                                    value="{{ old('location.raw_address', $location['raw_address']) }}"
                                    data-venue-editor-address data-suggest-url="{{ route('integrations.address-suggest') }}"
                                    autocomplete="off">
                                <span class="app-venue-editor__suggestions" data-venue-editor-suggestions hidden></span>
                            </label>
                            @foreach([
                                'city' => 'Город', 'street' => 'Улица', 'building' => 'Дом / корпус',
                                'postal_code' => 'Индекс', 'latitude' => 'Широта', 'longitude' => 'Долгота',
                            ] as $key => $label)
                                <label class="app-venue-editor__field">{{ $label }}
                                    <input type="text" name="location[{{ $key }}]" value="{{ old('location.'.$key, $location[$key] ?? '') }}"
                                        @if(in_array($key,['city','street','building','latitude','longitude'], true)) required @endif>
                                </label>
                            @endforeach
                            <input type="hidden" name="location[address_selected]" value="1">
                            <input type="hidden" name="location[city_id]" value="{{ old('location.city_id', $location['city_id'] ?? '') }}">
                            <input type="hidden" name="location[district_id]" value="{{ old('location.district_id', $location['district_id'] ?? '') }}">
                            <label class="app-venue-editor__field app-venue-editor__field--full">Станции метро
                                <select name="location[metro_station_ids][]" multiple size="6">
                                    @foreach($metros as $metro)
                                        <option value="{{ $metro->id }}" @selected(in_array($metro->id, old('location.metro_station_ids', $location['metro_station_ids'] ?? [])))>{{ $metro->name }}@if($metro->lineName) · {{ $metro->lineName }}@endif</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>
                    </section>
                @elseif($tab === 'facilities')
                    <section class="panel app-venue-editor__panel">
                        <h2>Характеристики</h2>
                        <input type="hidden" name="facilities_present" value="1">
                        <div class="app-venue-editor__grid">
                            @foreach([
                                'hoops_count' => ['Количество колец', [1 => '1', 2 => '2']],
                                'hoops_condition' => ['Состояние колец', [1 => '1 — очень плохое', 2 => '2', 3 => '3', 4 => '4', 5 => '5 — отличное']],
                                'surface_condition' => ['Состояние покрытия', [1 => '1 — очень плохое', 2 => '2', 3 => '3', 4 => '4', 5 => '5 — отличное']],
                            ] as $key => [$label, $options])
                                <label class="app-venue-editor__field">{{ $label }}
                                    <select name="characteristics[{{ $key }}]">
                                        <option value="">Не указано</option>
                                        @foreach($options as $value => $option)
                                            <option value="{{ $value }}" @selected((string) old('characteristics.'.$key, $specs[$key] ?? '') === (string) $value)>{{ $option }}</option>
                                        @endforeach
                                    </select>
                                </label>
                            @endforeach
                            <label class="app-venue-editor__field">Разметка
                                <select name="characteristics[marking_condition]">
                                    <option value="">Не указана</option>
                                    @foreach(\App\Modules\Venue\Domain\Enums\VenueMarkingConditionEnum::cases() as $item)
                                        <option value="{{ $item->value }}" @selected(old('characteristics.marking_condition', $specs['marking_condition'] ?? '') === $item->value)>{{ $item->label() }}</option>
                                    @endforeach
                                </select>
                            </label>
                        </div>
                    </section>
                    <section class="panel app-venue-editor__panel">
                        <h2>Общие удобства площадки</h2>
                        <div class="app-venue-editor__amenities">
                            @forelse($amenities as $amenity)
                                <label class="app-venue-editor__amenity">
                                    <input type="checkbox" name="amenity_ids[]" value="{{ $amenity->id }}"
                                        @checked(in_array($amenity->id, old('amenity_ids', $amenityIds)))>
                                    {{ $amenity->name }}
                                </label>
                            @empty
                                <p>Удобства пока не добавлены.</p>
                            @endforelse
                        </div>
                    </section>
                @endif
                <div class="app-venue-editor__actions">
                    <button type="submit" class="button primary" @disabled($pending)>Сохранить</button>
                    <a class="button secondary" href="{{ route('venues.show', $route) }}">Просмотр площадки</a>
                </div>
            </fieldset>
        </form>
        @if($tab === 'general' && ! $pending)
            @include('components.entity-banner-editor', [
                'model' => $venue,
                'context' => 'account',
                'bannerOverride' => app(\App\Modules\Media\Application\Services\VenueBannerDraftManager::class)->preview($venue),
            ])
        @endif
    @elseif($tab === 'photos')
        <section class="panel app-venue-editor__panel">
            <h2>Фотографии площадки</h2>
            <div class="app-venue-editor__gallery">
                @forelse($venuePhotos as $photo)
                    <article class="app-venue-editor__photo">
                        <img src="{{ $photo['url'] }}" alt="Фотография площадки" loading="lazy">
                        <div class="app-venue-editor__photo-actions">
                            @if($photo['is_featured'])<strong>Основное</strong>@else
                                <form action="{{ route('account.venues.photos.activate', [$route, $photo['id']]) }}" method="POST">
                                    @csrf @method('PATCH')<button type="submit" class="app-venue-editor__link">Сделать основным</button>
                                </form>
                            @endif
                            <form method="POST" action="{{ route('account.venues.photos.destroy', [$route, $photo['id']]) }}"
                                data-venue-confirm-delete>
                                @csrf @method('DELETE')<button type="submit" class="app-venue-editor__link">Удалить</button>
                            </form>
                        </div>
                    </article>
                @empty
                    <p>Фотографий пока нет.</p>
                @endforelse
            </div>
            <form method="POST" enctype="multipart/form-data" action="{{ route('account.venues.photos.store', $route) }}"
                class="app-venue-editor__upload">
                @csrf
                <label class="app-venue-editor__field">Добавить фотографию (JPG, PNG, WebP · до 10 МБ)
                    <input type="file" name="photo" accept="image/png,image/jpeg,image/webp" required>
                </label>
                <button class="button primary" type="submit">Загрузить</button>
            </form>
        </section>
    @elseif($tab === 'courts')
        <section class="panel app-venue-editor__panel">
            <div class="app-venue-editor__panel-head"><h2>Залы и корты</h2>
                <a class="button secondary" href="{{ route('account.venues.courts.index', $route) }}">Управлять залами</a>
            </div>
            <div class="app-venue-editor__court-list">
                @foreach($venue->courts as $court)
                    <div class="app-venue-editor__court">
                        <div><strong>{{ $court->name }}</strong>
                            <p>{{ $court->environment?->label() ?? 'Тип не указан' }} · {{ $court->surface_type?->label() ?? 'Покрытие не указано' }}</p>
                        </div>
                        <a class="button secondary" href="{{ route('account.venues.courts.index', $route) }}">Настроить</a>
                    </div>
                @endforeach
            </div>
        </section>
    @elseif($tab === 'schedule')
        @include('theme::pages.venues.partials.editor-schedule', ['venue' => $venue, 'route' => $route])
    @elseif($tab === 'contracts')
        <section class="panel app-venue-editor__panel">
            <div class="app-venue-editor__panel-head"><h2>Контракты площадки</h2>
                <a class="button secondary" href="{{ route('account.contracts.venues') }}">Мои контракты</a>
            </div>
            @if($contractManager)
                <a class="button primary" href="{{ route('account.contracts.manage', $contractManager->id) }}">Управление контрактами</a>
            @else
                <p>Управление контрактами доступно через действующий контракт площадки.</p>
            @endif
        </section>
    @elseif($tab === 'rental')
        <section class="panel app-venue-editor__panel">
            <h2>Аренда площадки</h2>
            <div class="app-venue-editor__feature-list">
                @foreach([
                    ['rental', 'Условия аренды', route('account.venues.booking-policy.edit', $venue->id)],
                    ['rental', 'Тарифные условия', route('account.venues.pricing-rules.index', $venue->id)],
                    ['offers', 'Промокоды', route('account.venues.group-offers.index', $venue->id)],
                    ['memberships', 'Коммерческие роли', route('account.venues.commercial-memberships.index', $venue->id)],
                ] as [$permission, $label, $url])
                    @if($editor[$permission])
                        <a class="app-venue-editor__feature" href="{{ $url }}"><span>{{ $label }}</span><span aria-hidden="true">→</span></a>
                    @else
                        <span class="app-venue-editor__feature is-disabled" aria-disabled="true">{{ $label }} <small>Нет доступа</small></span>
                    @endif
                @endforeach
                <span class="app-venue-editor__feature is-disabled" aria-disabled="true">Комиссии <small>Редактируются в Filament</small></span>
            </div>
        </section>
    @endif
</div>
@endsection
