@php
    use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
    use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
    use App\Modules\Event\Domain\Enums\EventResponsibilityStatusEnum;
    use App\Modules\Event\Domain\Enums\EventStatusEnum;
    use App\Modules\Event\Domain\Enums\EventTypeEnum;
    use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
    use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;

    $title = $event->title;
    $timezone = $event->venue->schedule?->timezone ?: config('app.timezone', 'Europe/Moscow');
    $startsAt = $event->starts_at->setTimezone($timezone);
    $endsAt = $event->ends_at->setTimezone($timezone);
    $confirmedParticipants = $event->participants
        ->where('status', EventParticipantStatusEnum::CONFIRMED)
        ->where('confirmation_version', $event->participation_confirmation_version)
        ->sortBy(fn ($participant) => $participant->role === EventParticipantRoleEnum::ORGANIZER ? 0 : 1)
        ->values();
    $needsReconfirmationParticipants = $event->participants
        ->filter(fn ($participant) => $participant->role !== EventParticipantRoleEnum::ORGANIZER
            && $participant->confirmation_version < $event->participation_confirmation_version)
        ->values();
    $tentativeParticipants = $event->participants
        ->where('status', EventParticipantStatusEnum::TENTATIVE)
        ->where('confirmation_version', $event->participation_confirmation_version)
        ->values();
    $declinedParticipants = $event->participants
        ->where('status', EventParticipantStatusEnum::LEFT)
        ->where('confirmation_version', $event->participation_confirmation_version)
        ->values();
    $confirmedCount = $confirmedParticipants->count();
    $miniGameFormats = $confirmedCount < 2
        ? collect()
        : collect(range(2, min(11, $confirmedCount)))
            ->map(fn (int $players): array => [
                'side_a_size' => (int) ceil($players / 2),
                'side_b_size' => (int) floor($players / 2),
            ])
            ->filter(fn (array $format): bool => $format['side_a_size'] <= 6 && $format['side_b_size'] <= 5)
            ->values();
    $defaultMiniGameFormat = $miniGameFormats->last() ?? ['side_a_size' => 1, 'side_b_size' => 1];
    $canManageComposition = ! in_array($event->status, [EventStatusEnum::CANCELLED, EventStatusEnum::DRAFT], true);
    $remainingPlaces = $event->max_participants === null
        ? null
        : max(0, $event->max_participants - $confirmedCount);
    $remainingPlacesWord = $remainingPlaces === null
        ? null
        : match (true) {
            $remainingPlaces % 10 === 1 && $remainingPlaces % 100 !== 11 => 'место',
            in_array($remainingPlaces % 10, [2, 3, 4], true) && ! in_array($remainingPlaces % 100, [12, 13, 14], true) => 'места',
            default => 'мест',
        };
    $isOrganizer = $currentParticipant?->role === EventParticipantRoleEnum::ORGANIZER;
    $hasPendingResponsibility = $currentParticipant?->responsibility_status === EventResponsibilityStatusEnum::PENDING
        && $currentParticipant->status === EventParticipantStatusEnum::CONFIRMED
        && $currentParticipant->confirmation_version === $event->participation_confirmation_version;
    $isFuture = $event->starts_at->isFuture();
    $isCompleted = $event->status === EventStatusEnum::COMPLETED;
    $isParticipationWindowOpen = $event->status === EventStatusEnum::PUBLISHED
        && $event->visibility === EventVisibilityEnum::PUBLIC
        && $isFuture;
    $isRegistrationOpen = $isParticipationWindowOpen
        && ($remainingPlaces === null || $remainingPlaces > 0);
    $canRespond = $isParticipationWindowOpen && ! $isOrganizer;
    $needsReconfirmation = $currentParticipant !== null
        && ! $isOrganizer
        && $currentParticipant->confirmation_version < $event->participation_confirmation_version;
    $currentResponse = $needsReconfirmation ? null : $currentParticipant?->status;
    $isFull = $remainingPlaces !== null && $remainingPlaces <= 0;
    $canConfirm = ! $isFull || $currentResponse === EventParticipantStatusEnum::CONFIRMED;
    $participationOptions = collect([
        EventParticipantStatusEnum::CONFIRMED->value => ['Пойду', 'ti-circle-check', 'is-going'],
        EventParticipantStatusEnum::LEFT->value => ['Не пойду', 'ti-circle-x', 'is-declined'],
        EventParticipantStatusEnum::TENTATIVE->value => ['Думаю', 'ti-help-circle', 'is-tentative'],
    ])->when(! $canConfirm, fn ($options) => $options->forget(EventParticipantStatusEnum::CONFIRMED->value));
    $showParticipationActions = auth()->guest()
        ? $isRegistrationOpen
        : $canRespond && $participationOptions->isNotEmpty();
    $venuePhotos = $event->venue->media->values();
    $address = preg_replace('/^Россия,\\s*/u', '', $event->venue->location?->address?->full_address ?: $event->venue->raw_address ?: '');
    $locationName = $event->venue->name;
    $coordinates = $event->venue->location?->address;
    $mapUrl = $coordinates?->latitude !== null && $coordinates?->longitude !== null
        ? 'https://yandex.ru/maps/?pt='.$coordinates->longitude.','.$coordinates->latitude.'&z=16&l=map'
        : null;
    $surfaceAmenity = $event->venue->amenities->first(function ($amenity) {
        $haystack = mb_strtolower(implode(' ', array_filter([$amenity->alias, $amenity->name, $amenity->description])));
        return str_contains($haystack, 'покрыт')
            || str_contains($haystack, 'surface')
            || str_contains($haystack, 'hard')
            || str_contains($haystack, 'паркет');
    });
    $surface = $surfaceAmenity?->pivot?->note ?: $surfaceAmenity?->name ?: 'Не указано';
    $bookingLabel = $event->booking?->status?->label() ?: 'Не указано';
    $organizer = $event->organizerActor?->user;
    $organizerProfile = $organizer?->profile;
    $organizerName = trim(implode(' ', array_filter([
        $organizerProfile?->first_name,
        $organizerProfile?->last_name,
    ]))) ?: $organizer?->username ?: 'Организатор';
    $organizerAvatar = $organizerProfile?->avatarUrl();
    $telegramUsername = $organizer?->telegramAccount?->username;
    if (! $telegramUsername && $organizer) {
        $telegramContact = $organizer->contacts->first(function ($contact) {
            return ($contact->type?->value ?? $contact->type) === 'telegram' && $contact->is_public;
        });
        $telegramUsername = $telegramContact?->meta['username'] ?? null;
    }
    $telegramUrl = $telegramUsername ? 'https://t.me/'.ltrim($telegramUsername, '@') : null;
    $recruitment = match (true) {
        $event->status === EventStatusEnum::CANCELLED => ['label' => 'Отменено', 'class' => 'is-closed'],
        $event->status === EventStatusEnum::COMPLETED => ['label' => 'Состоялось', 'class' => 'is-complete'],
        ! $isFuture => ['label' => 'Завершено', 'class' => 'is-closed'],
        $event->status !== EventStatusEnum::PUBLISHED => ['label' => 'Не опубликовано', 'class' => 'is-pending'],
        $isFull => ['label' => 'Мест нет', 'class' => 'is-closed'],
        default => ['label' => 'Идёт набор', 'class' => 'is-open'],
    };
    $resultState = match (true) {
        $event->status === EventStatusEnum::COMPLETED => ['label' => 'Состоялось', 'class' => 'is-complete'],
        $event->status === EventStatusEnum::CANCELLED => ['label' => 'Отменено', 'class' => 'is-cancelled'],
        $event->ends_at->isPast() => ['label' => 'Итог не указан', 'class' => 'is-pending'],
        $isFull => ['label' => 'Набор закрыт', 'class' => 'is-neutral'],
        default => ['label' => 'Запись закрыта', 'class' => 'is-neutral'],
    };
    $closedState = match (true) {
        $event->status === EventStatusEnum::CANCELLED => ['label' => 'Отменено', 'icon' => 'ti-circle-x'],
        $event->status === EventStatusEnum::COMPLETED || $event->ends_at->isPast() => ['label' => 'Завершено', 'icon' => 'ti-circle-check'],
        $isFull => ['label' => 'Запись закрыта', 'icon' => 'ti-users-minus'],
        default => ['label' => 'Запись недоступна', 'icon' => 'ti-lock'],
    };
@endphp

@extends('theme::layouts.app', ['title' => $title])

@section('content')
    <section class="event-show first-screen">
        <div class="event-show__inner">
            @if(session('status')) <div class="alert alert-success event-show__alert">{{ session('status') }}</div> @endif
            @if(session('error')) <div class="alert alert-danger event-show__alert">{{ session('error') }}</div> @endif
            @if(session('photo_status')) <div class="alert alert-success event-show__alert">{{ session('photo_status') }}</div> @endif
            @if(session('photo_error') || $errors->has('photo')) <div class="alert alert-danger event-show__alert">{{ session('photo_error') ?: $errors->first('photo') }}</div> @endif

            <section class="event-hero" data-event-hero>
                <div class="event-hero__track" data-event-hero-track>
                    @forelse($venuePhotos as $index => $photo)
                        <figure class="event-hero__slide" data-event-hero-slide>
                            <img src="{{ $photo->publicUrl() }}" alt="{{ $photo->title ?: $event->venue->name }}">
                        </figure>
                    @empty
                        <figure class="event-hero__slide" data-event-hero-slide>
                            <img src="{{ asset('images/venue-placeholder.png') }}" alt="Фото площадки {{ $event->venue->name }}">
                        </figure>
                    @endforelse
                </div>

                <span class="event-hero__counter" data-event-hero-counter>1 / {{ max(1, $venuePhotos->count()) }}</span>
                @if($venuePhotos->count() > 1)
                    <div class="event-hero-dots" aria-label="Фотографии площадки">
                        @foreach($venuePhotos as $index => $photo)
                            <button type="button" class="{{ $index === 0 ? 'is-active' : '' }}" data-event-hero-dot="{{ $index }}" aria-label="Фото {{ $index + 1 }}"></button>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="event-hero-info">
                <div class="event-hero-info__copy">
                    <div class="event-hero-info__title">
                        <span
                            class="event-state-dot {{ $recruitment['class'] }}"
                            title="{{ $recruitment['label'] }}"
                            data-tooltip-variant="title"
                            data-tooltip-icon
                            aria-label="{{ $recruitment['label'] }}"
                        ></span>
                        <h1>{{ $event->title }}</h1>
                    </div>
                    <div class="event-hero__meta">
                        <span><i class="ti ti-calendar-event" aria-hidden="true"></i>{{ $startsAt->format('d.m.Y') }}<b>·</b>{{ $startsAt->format('H:i') }}–{{ $endsAt->format('H:i') }}</span>
                        @if($mapUrl)
                            <button
                                type="button"
                                class="event-hero__location js-handler"
                                data-handler="modal"
                                data-modal-action="open"
                                data-modal-target="event-venue-map"
                                data-event-map-open
                            >
                                <i class="ti ti-map-pin" aria-hidden="true"></i>{{ $locationName }}
                            </button>
                        @else
                            <span><i class="ti ti-map-pin" aria-hidden="true"></i>{{ $locationName }}</span>
                        @endif
                    </div>
                </div>
            </section>

            @if($hasPendingResponsibility)
                <section class="event-card event-responsibility-invitation">
                    <div>
                        <span class="eyebrow">Назначение</span>
                        <h2>Стать ответственным за мероприятие?</h2>
                        <p>Организатор приглашает вас помогать в проведении этого мероприятия. Назначение начнёт действовать только после вашего согласия.</p>
                    </div>
                    <div class="event-responsibility-invitation__actions">
                        <form method="POST" action="{{ route('events.participants.responsibility.respond', [$event->routeIdentifier(), $currentParticipant->id]) }}">
                            @csrf @method('PATCH')
                            <input type="hidden" name="decision" value="{{ EventResponsibilityStatusEnum::ACCEPTED->value }}">
                            <button class="btn btn--primary btn--sm" type="submit">Принять</button>
                        </form>
                        <form method="POST" action="{{ route('events.participants.responsibility.respond', [$event->routeIdentifier(), $currentParticipant->id]) }}">
                            @csrf @method('PATCH')
                            <input type="hidden" name="decision" value="{{ EventResponsibilityStatusEnum::DECLINED->value }}">
                            <button class="btn btn--secondary btn--sm" type="submit">Отклонить</button>
                        </form>
                    </div>
                </section>
            @endif

            @if($showParticipationActions)
                @if($needsReconfirmation)
                    <div class="alert alert-warning">Время или площадка изменились. Подтвердите участие повторно.</div>
                @endif
                <section
                    class="event-response"
                    style="--event-response-columns: {{ $participationOptions->count() }}"
                    aria-label="Ответ на приглашение"
                >
                    @foreach($participationOptions as $statusValue => [$label, $icon, $class])
                        @auth
                            <form method="POST" action="{{ route('events.participation', $event->routeIdentifier()) }}">
                                @csrf @method('PATCH')
                                <input type="hidden" name="status" value="{{ $statusValue }}">
                                <button
                                    type="submit"
                                    @class([
                                        'event-response__button',
                                        $class,
                                        'is-active' => $currentResponse?->value === $statusValue,
                                    ])
                                >
                                    <i class="ti {{ $icon }}" aria-hidden="true"></i><span>{{ $label }}</span>
                                </button>
                            </form>
                        @else
                            <button
                                type="button"
                                class="event-response__button {{ $class }} js-handler"
                                data-handler="modal"
                                data-modal-action="open"
                                data-modal-target="auth-entry-classic"
                            >
                                <i class="ti {{ $icon }}" aria-hidden="true"></i><span>{{ $label }}</span>
                            </button>
                        @endauth
                    @endforeach
                </section>
                <p class="event-response__hint">Участники видят, кто уже идёт — так проще понять состав игры.</p>
            @elseif(! $isRegistrationOpen)
                <div class="event-response-closed" role="status">
                    <span class="event-response-closed__main">
                        <i class="ti {{ $closedState['icon'] }}" aria-hidden="true"></i>
                        <strong>{{ $closedState['label'] }}</strong>
                    </span>
                    <span class="event-response-closed__result {{ $resultState['class'] }}">{{ $resultState['label'] }}</span>
                </div>
            @endif

            <section class="event-stat-grid">
                <div><i class="ti ti-users" aria-hidden="true"></i><span>Участники</span><strong>{{ $confirmedCount }}{{ $event->max_participants ? '/'.$event->max_participants : '' }}</strong></div>
                <div><i class="ti ti-ball-basketball" aria-hidden="true"></i><span>Тип</span><strong>{{ $event->type->label() }}</strong></div>
                <div><i class="ti ti-shield-check" aria-hidden="true"></i><span>Бронирование</span><strong>{{ $bookingLabel }}</strong></div>
            </section>

            <section class="event-card event-details">
                <h2>Подробности</h2>
                <dl>
                    <div><dt><i class="ti ti-calendar-event"></i>Дата и время</dt><dd>{{ $startsAt->format('d.m.Y') }} · {{ $startsAt->format('H:i') }}–{{ $endsAt->format('H:i') }}</dd></div>
                    <div><dt><i class="ti ti-map-pin"></i>Локация</dt><dd><a href="{{ route('venues.show', $event->venue->routeIdentifier()) }}">{{ $locationName }}</a></dd></div>
                    <div><dt><i class="ti ti-building-community"></i>Адрес</dt><dd>{{ $address ?: 'Не указано' }}</dd></div>
                    <div><dt><i class="ti ti-shoe"></i>Покрытие</dt><dd>{{ $surface }}</dd></div>
                    <div><dt><i class="ti ti-ticket"></i>Стоимость</dt><dd>{{ $event->venue->requires_payment ? 'По условиям площадки' : 'Бесплатно' }}</dd></div>
                    <div><dt><i class="ti ti-shield-check"></i>Бронирование</dt><dd>{{ $bookingLabel }}</dd></div>
                </dl>
                @if($mapUrl)
                    <a class="event-details__map" href="{{ $mapUrl }}" target="_blank" rel="noopener">
                        <i class="ti ti-map-2" aria-hidden="true"></i>Открыть на карте
                    </a>
                @endif
            </section>

            <section class="event-card event-about" data-event-description>
                <h2>О событии</h2>
                <div class="event-about__text" data-event-description-text>
                    {{ $event->description ?: 'Организатор пока не добавил описание мероприятия.' }}
                </div>
                <button type="button" class="event-about__toggle" data-event-description-toggle hidden>
                    <span>Показать больше</span><i class="ti ti-chevron-down" aria-hidden="true"></i>
                </button>
                @if($event->status === EventStatusEnum::CANCELLED && $event->cancellation_reason)
                    <p class="event-about__reason"><strong>Причина отмены:</strong> {{ $event->cancellation_reason }}</p>
                @endif
            </section>

            <section class="event-card event-organizer">
                <div class="event-person-avatar event-person-avatar--large">
                    @if($organizerAvatar)
                        <img src="{{ $organizerAvatar }}" alt="{{ $organizerName }}">
                    @else
                        <span>{{ mb_strtoupper(mb_substr($organizerName, 0, 2)) }}</span>
                    @endif
                </div>
                <div class="event-organizer__identity">
                    <h2>{{ $organizerName }} <i class="ti ti-shield-star" aria-hidden="true"></i></h2>
                    <strong>Организатор</strong>
                    <span>{{ $telegramUrl ? 'Связь через Telegram' : 'Контакт не опубликован' }}</span>
                </div>
                @if($telegramUrl)
                    <a class="event-organizer__message" href="{{ $telegramUrl }}" target="_blank" rel="noopener">
                        <i class="ti ti-brand-telegram" aria-hidden="true"></i>Написать
                    </a>
                @endif
            </section>

            @foreach([
                ['class' => 'is-going', 'title' => 'Участники', 'state' => 'Идут', 'memberState' => 'Идёт', 'items' => $confirmedParticipants],
                ['class' => 'is-tentative', 'title' => 'Подтверждение', 'state' => 'Ожидают', 'memberState' => 'Подтвердить повторно', 'items' => $needsReconfirmationParticipants],
                ['class' => 'is-tentative', 'title' => 'Думают', 'state' => null, 'memberState' => 'Думает', 'items' => $tentativeParticipants],
                ['class' => 'is-declined', 'title' => 'Не идут', 'state' => null, 'memberState' => 'Не идёт', 'items' => $declinedParticipants],
            ] as $group)
                @if($group['items']->isNotEmpty())
                    <section class="event-participants {{ $group['class'] }}">
                        <div class="event-participants__heading">
                            <h2>
                                {{ $group['title'] }} ({{ $group['items']->count() }})
                                @if($group['state']) <span>{{ $group['state'] }}</span> @endif
                            </h2>
                        </div>
                        <div class="event-participants__row">
                            @foreach($group['items'] as $participant)
                                @php
                                    $profile = $participant->user->profile;
                                    $participantName = trim(implode(' ', array_filter([$profile?->first_name, $profile?->last_name])))
                                        ?: $participant->user->username;
                                    $participantAvatar = $profile?->avatarUrl();
                                @endphp
                                <article class="event-participant-chip">
                                    <div class="event-person-avatar">
                                        @if($participantAvatar)
                                            <img src="{{ $participantAvatar }}" alt="{{ $participantName }}">
                                        @else
                                            <span>{{ mb_strtoupper(mb_substr($participantName, 0, 2)) }}</span>
                                        @endif
                                    </div>
                                    <div>
                                        <strong>{{ $participantName }}</strong>
                                        <span>
                                            {{ match (true) {
                                                $participant->role === EventParticipantRoleEnum::ORGANIZER => 'Организатор',
                                                $participant->responsibility_status === EventResponsibilityStatusEnum::ACCEPTED => 'Ответственный',
                                                default => $group['memberState'],
                                            } }}
                                        </span>
                                    </div>
                                </article>
                            @endforeach
                            @if($group['class'] === 'is-going')
                                @if($remainingPlaces === null)
                                    <article class="event-participant-chip event-participant-chip--remaining event-participant-chip--unlimited">
                                        <strong>Без лимита</strong>
                                    </article>
                                @elseif($remainingPlaces > 0)
                                    <article class="event-participant-chip event-participant-chip--remaining">
                                        <span class="event-participant-chip__plus" aria-hidden="true">+</span>
                                        <strong>Ещё {{ $remainingPlaces }} {{ $remainingPlacesWord }}</strong>
                                    </article>
                                @endif
                            @endif
                        </div>
                    </section>
                @endif
            @endforeach

            <button type="button" class="event-share" data-event-share data-share-url="{{ route('events.show', $event->routeIdentifier()) }}" data-share-title="{{ $event->title }}">
                <i class="ti ti-message-share" aria-hidden="true"></i>
                <span><strong>Поделиться в чате</strong><small>Пригласите друзей на событие</small></span>
                <i class="ti ti-chevron-right" aria-hidden="true"></i>
            </button>

            @if($canManage)
                <details class="event-card event-management">
                    <summary>
                        <span><i class="ti ti-settings" aria-hidden="true"></i>Управление мероприятием @if($isOrganizer)<small>Вы организатор</small>@endif</span>
                        <i class="ti ti-chevron-down"></i>
                    </summary>
                    <div class="event-management__body">
                        @if($event->type === EventTypeEnum::GAME && $event->gameDetail)
                            <section class="event-game-management-link">
                                <div>
                                    <span class="eyebrow">Игра</span>
                                    <h3>Состав, счёт и статистика</h3>
                                    <p>Зафиксируйте участников матча и внесите показатели игроков.</p>
                                </div>
                                <a class="btn btn--primary btn--sm" href="{{ route('events.game.manage', $event->routeIdentifier()) }}">Управлять игрой</a>
                            </section>
                        @endif

                        @if(in_array($event->type, [EventTypeEnum::TRAINING, EventTypeEnum::GAME_TRAINING], true))
                            <section
                                class="event-mini-games"
                                data-event-mini-games
                                data-confirmed-count="{{ $confirmedCount }}"
                                data-image-upload-surface
                            >
                                @include('theme::partials.image-upload-loading', [
                                    'text' => 'Обновляем доступный состав и формат игры…',
                                ])
                                <div>
                                    <span class="eyebrow">Мини-игры</span>
                                    <h3>Игры внутри тренировки</h3>
                                    <p>Состав выбирается только из подтверждённых участников этого мероприятия.</p>
                                </div>
                                @if($event->childGames->isNotEmpty())
                                    <div class="event-mini-games__list">
                                        @foreach($event->childGames as $childGame)
                                            <article>
                                                <div>
                                                    <strong>{{ $childGame->title }}</strong>
                                                    <span>
                                                        {{ $childGame->gameDetail?->is_time_scheduled
                                                            ? $childGame->starts_at->format('H:i').'–'.$childGame->ends_at->format('H:i')
                                                            : 'Время не задано' }}
                                                        · {{ $childGame->gameDetail?->formatLabel() }}
                                                    </span>
                                                </div>
                                                <a class="btn btn--secondary btn--sm" href="{{ route('events.game.manage', $childGame->routeIdentifier()) }}">Состав и статистика</a>
                                            </article>
                                        @endforeach
                                    </div>
                                @endif
                                @if($canManageComposition)
                                    <details class="event-mini-games__create">
                                        <summary class="btn btn--sm btn--secondary"><span class="fc-white">Добавить мини-игру</span></summary>
                                        <div class="event-mini-games__empty" data-mini-game-empty @if($confirmedCount >= 2) hidden @endif>
                                            <p>Для создания мини-игры нужны хотя бы два участника.</p>
                                            <a href="#event-participant-management" data-event-participant-focus>Добавить игроков</a>
                                        </div>
                                        <form
                                            method="POST"
                                            action="{{ route('events.games.store', $event->routeIdentifier()) }}"
                                            data-mini-game-form
                                            @if($confirmedCount < 2) hidden @endif
                                        >
                                            @csrf
                                            <div class="row g-3">
                                                <div class="col-12"><label class="form-label">Название</label><input class="form-control" name="title" value="{{ old('title', 'Мини-игра') }}" required></div>
                                                <div class="col-md-3"><label class="form-label">Начало</label><input class="form-control" type="time" name="starts_at" value="{{ old('starts_at') }}"></div>
                                                <div class="col-md-3"><label class="form-label">Окончание</label><input class="form-control" type="time" name="ends_at" value="{{ old('ends_at') }}"></div>
                                                <div class="col-md-6">
                                                    <label class="form-label" for="miniGameFormat">Формат игры</label>
                                                    <select id="miniGameFormat" class="form-control" data-mini-game-format>
                                                        @foreach($miniGameFormats as $format)
                                                            <option
                                                                value="{{ $format['side_a_size'] }}x{{ $format['side_b_size'] }}"
                                                                data-side-a="{{ $format['side_a_size'] }}"
                                                                data-side-b="{{ $format['side_b_size'] }}"
                                                                @selected(
                                                                    (int) old('side_a_size', $defaultMiniGameFormat['side_a_size']) === $format['side_a_size']
                                                                    && (int) old('side_b_size', $defaultMiniGameFormat['side_b_size']) === $format['side_b_size']
                                                                )
                                                            >
                                                                {{ $format['side_a_size'] }}×{{ $format['side_b_size'] }}
                                                            </option>
                                                        @endforeach
                                                    </select>
                                                    <input type="hidden" name="side_a_size" value="{{ old('side_a_size', $defaultMiniGameFormat['side_a_size']) }}" data-mini-game-side-a-size>
                                                    <input type="hidden" name="side_b_size" value="{{ old('side_b_size', $defaultMiniGameFormat['side_b_size']) }}" data-mini-game-side-b-size>
                                                    <p class="form-hint mb-0">Доступные форматы зависят от количества участников мероприятия.</p>
                                                </div>
                                                <div class="col-12"><p class="form-hint mb-0">Время необязательно. Если план неизвестен, оставьте оба поля пустыми.</p></div>
                                                <div class="col-md-6"><label class="form-label">Название команды A</label><input class="form-control" name="side_a_name" value="{{ old('side_a_name', 'Команда A') }}" maxlength="80" required></div>
                                                <div class="col-md-6"><label class="form-label">Название команды B</label><input class="form-control" name="side_b_name" value="{{ old('side_b_name', 'Команда B') }}" maxlength="80" required></div>
                                            </div>
                                            <div class="game-roster-grid mt-3">
                                                @foreach(['A', 'B'] as $slot)
                                                    <fieldset class="game-side-card">
                                                        <legend>Команда {{ $slot }}</legend>
                                                        @foreach($confirmedParticipants as $participant)
                                                            @php
                                                                $profile = $participant->user->profile;
                                                                $candidateName = trim(implode(' ', array_filter([$profile?->first_name, $profile?->last_name])))
                                                                    ?: $participant->user->username;
                                                            @endphp
                                                            @include('theme::partials.forms.toggle', [
                                                                'id' => 'mini-game-side-'.strtolower($slot).'-'.$participant->user_id,
                                                                'name' => 'side_'.strtolower($slot).'_user_ids[]',
                                                                'value' => $participant->user_id,
                                                                'title' => $candidateName,
                                                                'checked' => in_array($participant->user_id, old('side_'.strtolower($slot).'_user_ids', []), false),
                                                                'includeHiddenInput' => false,
                                                                'wrapperClass' => 'game-roster-toggle',
                                                                'inputAttributes' => [
                                                                    'data-mini-game-player-toggle' => true,
                                                                    'data-player-id' => $participant->user_id,
                                                                    'data-side' => $slot,
                                                                ],
                                                            ])
                                                        @endforeach
                                                        <div data-mini-game-roster="{{ $slot }}"></div>
                                                    </fieldset>
                                                @endforeach
                                            </div>
                                            <button class="btn btn--primary btn--sm" type="submit">Создать мини-игру</button>
                                        </form>
                                    </details>
                                @endif
                            </section>
                        @endif

                        @if($canManageComposition)
                            <section
                                id="event-participant-management"
                                class="event-participant-manager"
                                data-event-participant-manager
                                data-search-url="{{ route('events.participants.candidates', $event->routeIdentifier()) }}"
                            >
                                <div>
                                    <span class="eyebrow">Состав</span>
                                    <h3>Добавить участника</h3>
                                    <p>Поиск учитывает настройки видимости пользователей.</p>
                                </div>
                                <form method="POST" action="{{ route('events.participants.manage.store', $event->routeIdentifier()) }}" data-event-participant-form>
                                    @csrf
                                    <label class="form-label" for="eventParticipantSearch">Пользователь</label>
                                    <div class="predictive-search__input-wrap event-participant-search">
                                        <input
                                            id="eventParticipantSearch"
                                            class="form-control predictive-search__input"
                                            type="text"
                                            autocomplete="off"
                                            placeholder="Введите имя или логин"
                                            data-event-participant-search
                                        >
                                        <button
                                            type="button"
                                            class="predictive-search__control"
                                            data-event-participant-control
                                            hidden
                                            aria-label="Очистить поиск"
                                        ></button>
                                        <div
                                            class="predictive-search__list event-participant-search__results d-none"
                                            role="listbox"
                                            data-event-participant-results
                                        ></div>
                                    </div>
                                    <div class="predictive-search__message text-danger d-none" data-event-participant-message></div>
                                    <input type="hidden" name="user_id" data-event-participant-user-id>
                                    <p class="event-participant-search__selection" data-event-participant-selection hidden></p>
                                    <p class="event-participant-search__status" data-event-participant-status hidden></p>
                                    <button class="btn btn--primary btn--sm" type="submit" data-event-participant-submit disabled>Добавить</button>
                                </form>
                            </section>
                        @endif

                        @if($event->starts_at->isFuture() && ! in_array($event->status, [EventStatusEnum::CANCELLED, EventStatusEnum::COMPLETED], true))
                            @if($confirmedParticipants->where('role', EventParticipantRoleEnum::PARTICIPANT)->isNotEmpty())
                                <section class="event-responsibility-manager">
                                    <div>
                                        <span class="eyebrow">Ответственные</span>
                                        <h3>Назначения</h3>
                                        <p>Кандидат должен подтвердить назначение самостоятельно.</p>
                                    </div>
                                    <div class="event-responsibility-manager__list">
                                        @foreach($confirmedParticipants->where('role', EventParticipantRoleEnum::PARTICIPANT) as $participant)
                                            @php
                                                $profile = $participant->user->profile;
                                                $name = trim(implode(' ', array_filter([$profile?->first_name, $profile?->last_name])))
                                                    ?: $participant->user->username
                                                    ?: 'Пользователь #'.$participant->user_id;
                                                $responsibility = $participant->responsibility_status;
                                            @endphp
                                            <article class="event-responsibility-manager__item">
                                                <div>
                                                    <strong>{{ $name }}</strong>
                                                    <span class="{{ $responsibility?->value ? 'is-'.$responsibility->value : '' }}">
                                                        {{ $responsibility?->label() ?: 'Участник' }}
                                                    </span>
                                                </div>
                                                @if($responsibility === null || $responsibility === EventResponsibilityStatusEnum::DECLINED)
                                                    <form method="POST" action="{{ route('events.participants.responsibility.request', [$event->routeIdentifier(), $participant->id]) }}">
                                                        @csrf
                                                        <button class="btn btn--secondary btn--sm" type="submit">Назначить</button>
                                                    </form>
                                                @else
                                                    <form method="POST" action="{{ route('events.participants.responsibility.destroy', [$event->routeIdentifier(), $participant->id]) }}">
                                                        @csrf @method('DELETE')
                                                        <button class="btn btn--secondary btn--sm" type="submit">
                                                            {{ $responsibility === EventResponsibilityStatusEnum::PENDING ? 'Отменить запрос' : 'Снять' }}
                                                        </button>
                                                    </form>
                                                @endif
                                            </article>
                                        @endforeach
                                    </div>
                                </section>
                            @endif

                            <a class="btn btn--secondary btn--sm" href="{{ route('events.edit', $event->routeIdentifier()) }}">Редактировать</a>
                            <form method="POST" action="{{ route('events.cancel', $event->routeIdentifier()) }}" onsubmit="return confirm('Вы уверены, что хотите отменить мероприятие и освободить бронь?')">
                                @csrf
                                <label class="form-label" for="eventCancellationReason">Причина отмены</label>
                                <textarea id="eventCancellationReason" class="form-control" name="reason" rows="3" maxlength="1000"></textarea>
                                <button class="btn btn--danger btn--sm" type="submit">Отменить мероприятие</button>
                            </form>
                        @endif

                        @if($event->ends_at->isPast() && ! in_array($event->status, [EventStatusEnum::CANCELLED, EventStatusEnum::DRAFT], true))
                            <form method="POST" action="{{ route('events.result.update', $event->routeIdentifier()) }}">
                                @csrf @method('PUT')
                                <label class="form-label" for="eventResultDescription">Как прошло мероприятие</label>
                                <textarea id="eventResultDescription" class="form-control @error('result_description') is-invalid @enderror" name="result_description" rows="5" maxlength="10000">{{ old('result_description', $event->result_description) }}</textarea>
                                @error('result_description') <div class="invalid-feedback">{{ $message }}</div> @enderror
                                <button class="btn btn--primary btn--sm" type="submit">{{ $isCompleted ? 'Сохранить итоги' : 'Отметить состоявшимся' }}</button>
                            </form>
                        @endif
                    </div>
                </details>
            @endif

            @if($isCompleted)
                <section class="event-card">
                    <h2>Как это было</h2>
                    <p>{{ $event->result_description ?: 'Описание пока не добавлено.' }}</p>
                    @if($event->media->isNotEmpty())
                        <div class="event-result-photos" aria-label="Фотографии мероприятия">
                            @foreach($event->media as $index => $photo)
                                <button
                                    type="button"
                                    data-venue-gallery-item
                                    data-index="{{ $index }}"
                                    data-url="{{ $photo->publicUrl() }}"
                                    data-title="{{ $event->title }}"
                                    data-description=""
                                >
                                    <img src="{{ $photo->publicUrl() }}" alt="{{ $event->title }}">
                                </button>
                            @endforeach
                        </div>
                        <div class="venue-gallery-modal" data-venue-gallery-modal hidden>
                            <div class="venue-gallery-modal__backdrop" data-venue-gallery-close></div>
                            <section class="venue-gallery-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="event-gallery-modal-title">
                                <button type="button" class="venue-gallery-modal__close" data-venue-gallery-close aria-label="Закрыть"><i class="ti ti-x"></i></button>
                                <button type="button" class="venue-gallery-modal__nav venue-gallery-modal__nav--prev" data-venue-gallery-prev aria-label="Предыдущее фото"><i class="ti ti-chevron-left"></i></button>
                                <img src="" alt="" data-venue-gallery-image>
                                <button type="button" class="venue-gallery-modal__nav venue-gallery-modal__nav--next" data-venue-gallery-next aria-label="Следующее фото"><i class="ti ti-chevron-right"></i></button>
                                <div class="venue-gallery-modal__caption"><h3 id="event-gallery-modal-title" data-venue-gallery-title></h3><p data-venue-gallery-description></p></div>
                            </section>
                        </div>
                    @endif
                </section>

                @if($canManage)
                    <section class="venue-gallery-editor event-result-gallery" data-tooltip-skip data-image-upload-surface>
                        @include('theme::partials.image-upload-loading', ['text' => 'Загружаем фотографию…'])
                        <div class="venue-gallery-editor__heading"><div><h2>Фотографии</h2><p>До 12 изображений · JPEG, PNG или WebP · до 5 МБ</p></div><span>{{ $event->media->count() }}/12</span></div>
                        @if($event->media->count() < 12)
                            <form action="{{ route('events.result.photos.store', $event->routeIdentifier()) }}" method="POST" enctype="multipart/form-data" class="venue-gallery-editor__upload" data-image-upload data-image-upload-auto-submit>
                                @csrf
                                <label for="event-result-photo-input" class="btn btn--secondary btn--sm">Добавить фотографию</label>
                                <input id="event-result-photo-input" type="file" name="photo" accept="image/jpeg,image/png,image/webp" hidden>
                            </form>
                        @endif
                        @if($event->media->isNotEmpty())
                            <div class="venue-gallery-editor__items" aria-label="Фотографии мероприятия">
                                @foreach($event->media as $photo)
                                    <article class="venue-gallery-editor__item">
                                        <span class="venue-gallery-editor__preview"><img src="{{ $photo->publicUrl() }}" alt=""></span>
                                        <form action="{{ route('events.result.photos.destroy', [$event->routeIdentifier(), $photo->id]) }}" method="POST">@csrf @method('DELETE')<button type="submit" class="venue-gallery-editor__delete" aria-label="Удалить фотографию" onclick="return confirm('Вы уверены, что хотите удалить фотографию?')">×</button></form>
                                    </article>
                                @endforeach
                            </div>
                        @endif
                    </section>
                @endif
            @endif
        </div>
    </section>

    @if($mapUrl)
        @component('theme::partials.modal.layout', [
            'id' => 'event-venue-map',
            'dialogClass' => 'venue-selector-map-modal__dialog event-venue-map-modal__dialog',
        ])
            <h2 class="modal_title" id="modal-title-event-venue-map">{{ $locationName }}</h2>
            <p class="venue-selector-map__message" data-event-map-message>Загружаем карту…</p>
            <div
                class="venue-selector-map"
                data-event-map
                data-yandex-map-api-key="{{ config('integrations.yandex.api_key') }}"
                data-latitude="{{ $coordinates->latitude }}"
                data-longitude="{{ $coordinates->longitude }}"
                data-title="{{ $locationName }}"
                data-address="{{ $address }}"
                aria-label="Площадка {{ $locationName }} на карте"
            ></div>
        @endcomponent
    @endif
@endsection
