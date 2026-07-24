@php
    use App\Modules\Event\Domain\Enums\EventParticipantRoleEnum;
    use App\Modules\Event\Domain\Enums\EventParticipantStatusEnum;
    use App\Modules\Event\Domain\Enums\EventStatusEnum;
    use App\Modules\Event\Domain\Enums\EventVisibilityEnum;
    use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;

    $title = $event->title;
    $timezone = $event->venue->schedule?->timezone ?: config('app.timezone', 'Europe/Moscow');
    $startsAt = $event->starts_at->setTimezone($timezone);
    $endsAt = $event->ends_at->setTimezone($timezone);
    $confirmedParticipants = $event->participants
        ->where('status', EventParticipantStatusEnum::CONFIRMED)
        ->sortBy(fn ($participant) => $participant->role === EventParticipantRoleEnum::ORGANIZER ? 0 : 1)
        ->values();
    $confirmedCount = $confirmedParticipants->count();
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
    $isFuture = $event->starts_at->isFuture();
    $isCompleted = $event->status === EventStatusEnum::COMPLETED;
    $isRegistrationOpen = $event->status === EventStatusEnum::PUBLISHED
        && $event->visibility === EventVisibilityEnum::PUBLIC
        && $isFuture
        && ($remainingPlaces === null || $remainingPlaces > 0);
    $canRespond = $isRegistrationOpen && ! $isOrganizer;
    $currentResponse = $currentParticipant?->status;
    $isFull = $remainingPlaces !== null && $remainingPlaces <= 0;
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
            </section>

            @if($venuePhotos->count() > 1)
                <div class="event-hero-dots" aria-label="Фотографии площадки">
                    @foreach($venuePhotos as $index => $photo)
                        <button type="button" class="{{ $index === 0 ? 'is-active' : '' }}" data-event-hero-dot="{{ $index }}" aria-label="Фото {{ $index + 1 }}"></button>
                    @endforeach
                </div>
            @endif

            <section class="event-hero-info">
                <div class="event-hero-info__copy">
                    <h1>{{ $event->title }}</h1>
                    <div class="event-hero__meta">
                        <span><i class="ti ti-calendar-event" aria-hidden="true"></i>{{ $startsAt->format('d.m.Y') }}<b>·</b>{{ $startsAt->format('H:i') }}–{{ $endsAt->format('H:i') }}</span>
                        <span><i class="ti ti-map-pin" aria-hidden="true"></i>{{ $locationName }}</span>
                    </div>
                </div>
                <span class="event-recruitment {{ $recruitment['class'] }}">
                    <span aria-hidden="true"></span>{{ $recruitment['label'] }}
                </span>
            </section>

            @if($isRegistrationOpen)
                <section class="event-response" aria-label="Ответ на приглашение">
                    @foreach([
                        EventParticipantStatusEnum::CONFIRMED->value => ['Пойду', 'ti-circle-check', 'is-going'],
                        EventParticipantStatusEnum::LEFT->value => ['Не пойду', 'ti-circle-x', 'is-declined'],
                        EventParticipantStatusEnum::TENTATIVE->value => ['Думаю', 'ti-help-circle', 'is-tentative'],
                    ] as $statusValue => [$label, $icon, $class])
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
                                    @disabled(! $canRespond || ($statusValue === EventParticipantStatusEnum::CONFIRMED->value && $isFull && $currentResponse !== EventParticipantStatusEnum::CONFIRMED))
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
            @else
                <div class="event-response-closed" role="status">
                    <i class="ti ti-circle-check" aria-hidden="true"></i>
                    <strong>Завершено</strong>
                </div>
            @endif

            <section class="event-stat-grid">
                <div><i class="ti ti-users" aria-hidden="true"></i><span>Участники</span><strong>{{ $confirmedCount }}{{ $event->max_participants ? ' / '.$event->max_participants : '' }}</strong></div>
                <div><i class="ti ti-user-plus" aria-hidden="true"></i><span>Свободно</span><strong>{{ $remainingPlaces ?? 'Без лимита' }}</strong></div>
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

            <section class="event-participants">
                <div class="event-participants__heading">
                    <h2>Участники ({{ $confirmedCount }}) <span>Идут</span></h2>
                </div>
                <div class="event-participants__row">
                    @foreach($confirmedParticipants as $participant)
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
                            <div><strong>{{ $participantName }}</strong><span>{{ $participant->role === EventParticipantRoleEnum::ORGANIZER ? 'Организатор' : 'Идёт' }}</span></div>
                        </article>
                    @endforeach
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
                </div>
            </section>

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
                        @if($event->starts_at->isFuture() && ! in_array($event->status, [EventStatusEnum::CANCELLED, EventStatusEnum::COMPLETED], true))
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
@endsection
