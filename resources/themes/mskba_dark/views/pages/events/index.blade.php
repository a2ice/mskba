@php
    use App\Modules\Event\Domain\Enums\EventStatusEnum;

    $title = 'Мероприятия';
    $activeFilterCount = collect([
        $period === 'past',
        filled($typeFilter),
        filled($dateFrom),
        filled($dateTo),
        filled($outcome),
        filled($venueId),
        $hasMiniGames,
    ])->filter()->count();
    $createUrl = route('events.create', array_filter(['type' => $selectedType?->value]));
    $pastToggleQuery = request()->query();
    if ($period === 'past') {
        unset($pastToggleQuery['period'], $pastToggleQuery['outcome']);
    } else {
        $pastToggleQuery['period'] = 'past';
    }
@endphp

@extends('theme::layouts.app', ['title' => $title])

@section('content')
    <section class="events-catalog first-screen">
        <div class="inner events-catalog__inner">
            @if(session('status')) <div class="alert alert-success mb-3">{{ session('status') }}</div> @endif

            <header class="events-catalog__header">
                <h1>{{ $title }}</h1>
                <button class="page-breadcrumbs__back events-catalog__back js-handler" type="button" data-handler="historyBack">
                    <i class="ti ti-arrow-left" aria-hidden="true"></i><span>Назад</span>
                </button>
            </header>

            <div class="events-catalog-filters__toolbar is-filters-collapsed">
                    <button class="btn btn--secondary events-catalog__options" type="button" data-event-filter-toggle aria-expanded="false">
                        <i class="ti ti-adjustments-horizontal" aria-hidden="true"></i><span>Фильтры</span><i class="ti ti-chevron-down" data-event-filter-toggle-icon aria-hidden="true"></i>
                        @if($activeFilterCount > 0)<b>{{ $activeFilterCount }}</b>@endif
                    </button>
                    @auth
                        <a class="btn btn--primary" href="{{ $createUrl }}"><i class="ti ti-plus"></i>Создать</a>
                    @else
                        <button type="button" class="btn btn--primary js-handler" data-handler="modal" data-modal-action="open" data-modal-target="auth-entry-classic" data-auth-redirect-url="{{ route('events.create', absolute: false) }}"><i class="ti ti-plus"></i>Создать</button>
                    @endauth
            </div>
            <form method="GET" action="{{ route('events.index') }}" class="events-catalog-filters" data-event-filters data-event-filter-body hidden>
                <div class="events-catalog-filters__quick">
                    <a @class(['events-filter-chip', 'is-active' => $period === 'past']) href="{{ route('events.index', $pastToggleQuery) }}">
                        <i class="ti {{ $period === 'past' ? 'ti-square-check' : 'ti-square' }}" aria-hidden="true"></i><span>Показывать прошедшие</span>
                    </a>
                    <label class="events-filter-chip">
                        <i class="ti ti-calendar-event" aria-hidden="true"></i>
                        <input type="date" name="date_from" value="{{ $dateFrom }}" aria-label="Дата мероприятия" onchange="this.form.submit()">
                    </label>
                    <label class="events-filter-chip events-filter-chip--toggle">
                        <input type="checkbox" name="has_mini_games" value="1" @checked($hasMiniGames) onchange="this.form.submit()">
                        <span><i class="ti ti-device-gamepad-2" aria-hidden="true"></i>Есть мини-игры</span>
                    </label>
                    <button class="events-filter-chip js-handler" type="button" data-handler="modal" data-modal-action="open" data-modal-target="events-nearby-development">
                        <i class="ti ti-navigation" aria-hidden="true"></i>Только рядом
                    </button>
                </div>

                <div class="events-catalog-filters__advanced" data-event-filter-panel>
                    <label class="field"><span class="form-label">Тип мероприятия</span><select class="form-select" name="type"><option value="">Все типы</option><option value="games" @selected($typeFilter === 'games')>Игры и игровые тренировки</option>@foreach($types as $type)<option value="{{ $type->value }}" @selected($selectedType === $type)>{{ $type->label() }}</option>@endforeach</select></label>
                    <label class="field"><span class="form-label">Площадка</span><select class="form-select" name="venue_id"><option value="">Все площадки</option>@foreach($filterVenues as $venue)<option value="{{ $venue->id }}" @selected($venueId === $venue->id)>{{ $venue->name }}</option>@endforeach</select></label>
                    <label class="field"><span class="form-label">Дата по</span><input class="form-control" type="date" name="date_to" value="{{ $dateTo }}"></label>
                    @if($period === 'past')
                        <label class="field"><span class="form-label">Итог</span><select class="form-select" name="outcome"><option value="">Все итоги</option><option value="completed" @selected($outcome === 'completed')>Состоялось</option><option value="cancelled" @selected($outcome === 'cancelled')>Отменено</option><option value="unmarked" @selected($outcome === 'unmarked')>Итог не указан</option></select></label>
                    @endif
                    <div class="events-catalog-filters__actions"><button class="btn btn--primary btn--sm">Применить</button><a class="btn btn--secondary btn--sm" href="{{ route('events.index') }}">Сбросить</a></div>
                </div>
                @error('date_to') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
            </form>

            @if($events->isEmpty())
                <div class="events-catalog__empty"><i class="ti ti-ball-basketball"></i><strong>Подходящих мероприятий пока нет</strong><span>Измените фильтры или создайте новое мероприятие.</span></div>
            @else
                <div class="events-catalog-list">
                    @foreach($events as $event)
                        @php
                            $timezone = $event->venue->schedule?->timezone ?: config('app.timezone');
                            $startsAt = $event->starts_at->setTimezone($timezone);
                            $endsAt = $event->ends_at->setTimezone($timezone);
                            $photo = $event->venue->media->first();
                            $isPast = $event->ends_at->isPast();
                            $isGame = $event->type->value === 'game';
                            $pastOutcome = match($event->status) {
                                EventStatusEnum::COMPLETED => 'Состоялось',
                                EventStatusEnum::CANCELLED => 'Отменено',
                                default => 'Итог не указан',
                            };
                            $address = $event->venue->raw_address ?: $event->venue->location?->address?->full_address;
                            $structuredShortAddress = implode(', ', array_filter([
                                $event->venue->location?->address?->street,
                                $event->venue->location?->address?->building,
                            ]));
                            $addressParts = array_values(array_filter(array_map('trim', explode(',', (string) $address))));
                            $shortAddress = $structuredShortAddress ?: implode(', ', array_slice($addressParts, -2));
                            $latitude = $event->venue->location?->address?->latitude;
                            $longitude = $event->venue->location?->address?->longitude;
                        @endphp
                        <article @class(['catalog-card', 'event-catalog-card', 'is-past' => $isPast])>
                            <a class="catalog-card__image event-catalog-card__image" href="{{ route('events.show', $event->routeIdentifier()) }}">
                                <img src="{{ $photo?->publicUrl() ?: asset('images/venue-placeholder.png') }}" alt="">
                            </a>
                            <div class="catalog-card__body event-catalog-card__content">
                                <div class="catalog-card__badges event-catalog-card__badges"><span class="catalog-card__badge event-type-badge event-type-badge--{{ $event->type->value }}">{{ $event->type->label() }}</span>@if($isPast)<span class="catalog-card__badge event-type-badge is-muted">{{ $pastOutcome }}</span>@endif</div>
                                <h2 class="catalog-card__title"><a href="{{ route('events.show', $event->routeIdentifier()) }}">{{ $event->title }}</a></h2>
                            </div>
                            <div class="event-catalog-card__meta">
                                @if($latitude !== null && $longitude !== null)
                                    <button class="event-catalog-card__location js-handler" type="button" data-handler="modal" data-modal-action="open" data-modal-target="events-catalog-map" data-catalog-map-open data-latitude="{{ $latitude }}" data-longitude="{{ $longitude }}" data-title="{{ $event->venue->name }}" data-address="{{ $address }}"><i class="ti ti-map-pin"></i><span>{{ $event->venue->name }}@if($shortAddress), {{ $shortAddress }}@endif</span></button>
                                @else
                                    <p><i class="ti ti-map-pin"></i><span>{{ $event->venue->name }}@if($shortAddress), {{ $shortAddress }}@endif</span></p>
                                @endif
                                <p><i class="ti ti-clock"></i><span>{{ $startsAt->format('d.m.Y · H:i') }}–{{ $endsAt->format('H:i') }}</span></p>
                                <p><i class="ti ti-users"></i><span>{{ $event->participants_count }}{{ $event->max_participants ? ' / '.$event->max_participants : ' / ∞' }} участников</span></p>
                            </div>
                            <aside class="catalog-card__actions event-catalog-card__games">
                                <strong @class(['is-empty' => $event->childGames->isEmpty()])><i class="ti ti-device-gamepad-2"></i>Мини-игры{{ $event->childGames->isNotEmpty() ? ': '.$event->childGames->count() : '' }}</strong>
                                @foreach($event->childGames->take(2) as $childGame)<span>{{ $childGame->title }}</span>@endforeach
                                @if($event->childGames->count() > 2)<small>+{{ $event->childGames->count() - 2 }} ещё</small>@endif
                                <a class="btn btn--secondary btn--sm" href="{{ route('events.show', $event->routeIdentifier()) }}">Подробнее<i class="ti ti-arrow-right"></i></a>
                            </aside>
                        </article>
                    @endforeach
                </div>
                <div class="events-catalog__pagination">{{ $events->links() }}</div>
            @endif
        </div>
    </section>

    @component('theme::partials.modal.layout', ['id' => 'events-nearby-development'])
        <div class="events-nearby-modal"><i class="ti ti-navigation"></i><h2 class="modal_title" id="modal-title-events-nearby-development">Функция в разработке</h2><p>Скоро здесь появится поиск мероприятий рядом с вашим текущим местоположением.</p></div>
    @endcomponent

    @component('theme::partials.modal.layout', ['id' => 'events-catalog-map', 'dialogClass' => 'venue-selector-map-modal__dialog event-venue-map-modal__dialog'])
        <h2 class="modal_title" id="modal-title-events-catalog-map" data-catalog-map-title>Площадка</h2>
        <p class="venue-selector-map__message" data-event-map-message>Загружаем карту…</p>
        <div class="venue-selector-map" data-event-map data-yandex-map-api-key="{{ config('integrations.yandex.api_key') }}" aria-label="Площадка на карте"></div>
    @endcomponent
@endsection
