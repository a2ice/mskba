@extends('theme::layouts.section-sidebar', [
    'title' => $event->title,
    'sectionId' => 'events',
    'sectionClass' => 'events-section game-public-page',
    'contentTitle' => $event->title,
    'contentSubtitle' => $event->parentEvent ? 'Мини-игра' : 'Игра',
])

@php
    $sides = $event->gameSides->keyBy('slot');
    $roster = $event->gameRosterEntries->groupBy('game_side_id');
    $stats = $event->gamePlayerStatistics->keyBy('user_id');
    $sideA = $sides->get('A');
    $sideB = $sides->get('B');
    $hasScore = $sideA?->score !== null && $sideB?->score !== null;
    $venueName = $event->venue->name;
    $venueAddress = preg_replace('/^Россия,\\s*/u', '', $event->venue->location?->address?->full_address ?: $event->venue->raw_address ?: '');
    $venueCoordinates = $event->venue->location?->address;
    $hasVenueMap = $venueCoordinates?->latitude !== null && $venueCoordinates?->longitude !== null;
    $name = static function ($user): string {
        $profile = $user->profile;
        return trim(implode(' ', array_filter([$profile?->first_name, $profile?->last_name])))
            ?: $user->username
            ?: 'Пользователь #'.$user->id;
    };
@endphp

@section('section-sidebar')
    <div class="section-sidebar-block">
        <h2 class="section-sidebar-block__title">Игра</h2>
        <ul class="sidebar-nav nav flex-column">
            <li class="nav-item active"><a class="nav-link active" href="{{ route('events.show', $event->routeIdentifier()) }}">Обзор игры</a></li>
            @if($event->parentEvent)
                <li class="nav-item"><a class="nav-link" href="{{ route('events.show', $event->parentEvent->routeIdentifier()) }}">К мероприятию</a></li>
            @endif
            @if($canManage)
                <li class="nav-item"><a class="nav-link" href="{{ route('events.game.manage', $event->routeIdentifier()) }}">Управление</a></li>
            @endif
        </ul>
    </div>
@endsection

@section('section-content')
    <section class="section-card game-public-summary mb-3">
        <div class="game-public-summary__meta">
            <span class="eyebrow">Формат {{ $event->gameDetail->formatLabel() }}</span>
            @if($event->gameDetail->is_time_scheduled)
                <span><i class="ti ti-calendar-event" aria-hidden="true"></i>{{ $event->starts_at->format('d.m.Y · H:i') }}–{{ $event->ends_at->format('H:i') }}</span>
            @else
                <span><i class="ti ti-clock" aria-hidden="true"></i>Время не задано</span>
            @endif
            @if($hasVenueMap)
                <button
                    type="button"
                    class="event-hero__location js-handler"
                    data-handler="modal"
                    data-modal-action="open"
                    data-modal-target="event-venue-map"
                    data-event-map-open
                >
                    <i class="ti ti-map-pin" aria-hidden="true"></i>{{ $venueName }}
                </button>
            @else
                <span><i class="ti ti-map-pin" aria-hidden="true"></i>{{ $venueName }}</span>
            @endif
            @if($event->parentEvent)
                <span>
                    <i class="ti ti-calendar-event" aria-hidden="true"></i>
                    Мероприятие:
                    <a href="{{ route('events.show', $event->parentEvent->routeIdentifier()) }}" target="_blank" rel="noopener noreferrer">{{ $event->parentEvent->title }}</a>
                </span>
            @endif
        </div>
        <div class="game-public-score" aria-label="Счёт игры">
            <div><strong>{{ $sideA?->display_name ?: 'Команда A' }}</strong><span>{{ $sideA?->score ?? '—' }}</span></div>
            <b>:</b>
            <div><strong>{{ $sideB?->display_name ?: 'Команда B' }}</strong><span>{{ $sideB?->score ?? '—' }}</span></div>
        </div>
        @unless($hasScore)
            <p class="form-hint">Итоговый счёт пока не указан.</p>
        @endunless
        @if($canManage)
            <a class="btn btn--primary btn--sm" href="{{ route('events.game.manage', $event->routeIdentifier()) }}">Редактировать игру и статистику</a>
        @endif
    </section>

    <section class="section-card mb-3">
        <span class="eyebrow">Состав</span>
        <h2>Игроки</h2>
        <div class="game-public-roster">
            @foreach(['A', 'B'] as $slot)
                @php $side = $sides->get($slot); @endphp
                <article class="game-side-card">
                    <h3>{{ $side?->display_name ?: 'Команда '.$slot }}</h3>
                    <div class="game-public-roster__players">
                        @forelse($roster->get($side?->id, collect()) as $entry)
                            <div class="game-public-player">
                                @if($entry->user->profile?->activeAvatar)
                                    <img src="{{ $entry->user->profile->activeAvatar->publicUrl() }}" alt="">
                                @else
                                    <span>{{ mb_strtoupper(mb_substr($name($entry->user), 0, 2)) }}</span>
                                @endif
                                <strong>{{ $name($entry->user) }}</strong>
                            </div>
                        @empty
                            <p>Состав пока не указан.</p>
                        @endforelse
                    </div>
                </article>
            @endforeach
        </div>
    </section>

    <section class="section-card mb-3">
        <span class="eyebrow">{{ $event->gameDetail->statistics_status->label() }}</span>
        <h2>Статистика игроков</h2>
        <div class="game-statistics-table-wrap">
            <table class="game-statistics-table game-statistics-table--readonly">
                <thead>
                <tr>
                    <th>Игрок</th>
                    @foreach($statisticsFields as $definition)
                        <th><span title="{{ $definition['tooltip'] }}" data-tooltip-variant="title" tabindex="0">{{ $definition['label'] }}</span></th>
                    @endforeach
                    <th><span title="Очки, рассчитанные по попаданиям игрока." data-tooltip-variant="title" tabindex="0">Очки</span></th>
                </tr>
                </thead>
                <tbody>
                @forelse($event->gameRosterEntries as $entry)
                    @php $stat = $stats->get($entry->user_id); @endphp
                    <tr>
                        <th>{{ $name($entry->user) }}</th>
                        @foreach($statisticsFields as $field => $definition)
                            <td>{{ $stat?->{$field} ?? 0 }}</td>
                        @endforeach
                        <td>{{ $stat?->points() ?? 0 }}</td>
                    </tr>
                @empty
                    <tr><td colspan="{{ count($statisticsFields) + 2 }}">Состав и статистика пока не заполнены.</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>

    @if($hasVenueMap)
        @component('theme::partials.modal.layout', [
            'id' => 'event-venue-map',
            'dialogClass' => 'venue-selector-map-modal__dialog event-venue-map-modal__dialog',
        ])
            <h2 class="modal_title" id="modal-title-event-venue-map">{{ $venueName }}</h2>
            <p class="venue-selector-map__message" data-event-map-message>Загружаем карту…</p>
            <div
                class="venue-selector-map"
                data-event-map
                data-yandex-map-api-key="{{ config('integrations.yandex.api_key') }}"
                data-latitude="{{ $venueCoordinates->latitude }}"
                data-longitude="{{ $venueCoordinates->longitude }}"
                data-title="{{ $venueName }}"
                data-address="{{ $venueAddress }}"
                aria-label="Площадка {{ $venueName }} на карте"
            ></div>
        @endcomponent
    @endif
@endsection
