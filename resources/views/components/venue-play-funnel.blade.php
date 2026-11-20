@php
    $isAppTheme = app(\App\Presentation\Theming\ThemeResolver::class)->active() === 'mskba_app';
    $primaryButton = $isAppTheme ? 'button primary' : 'btn btn--primary';
    $secondaryButton = $isAppTheme ? 'button secondary' : 'btn btn--secondary';
    $viewerIdentityIds = auth()->user()?->canonical()->identityIds() ?? [];
    $upcomingVenueEvents = \App\Modules\Event\Domain\Models\Event::query()
        ->with(['organizerActor.user', 'participants', 'venue.schedule', 'primaryGame'])
        ->where('venue_id', $venue->id)
        ->where('status', \App\Modules\Event\Domain\Enums\EventStatusEnum::PUBLISHED->value)
        ->where('visibility', \App\Modules\Event\Domain\Enums\EventVisibilityEnum::PUBLIC->value)
        ->where('ends_at', '>', now())
        ->orderBy('starts_at')->limit(20)->get();
    // Every public upcoming card on the venue page must have a detail pane,
    // even when it falls outside the first 20 items in the popup list.
    $venuePlayDetailEvents = $upcomingVenueEvents->concat($venuePlayDirectEvents ?? collect())
        ->unique('id')->values();
    $venuePlayDetailEvents->loadMissing(['organizerActor.user', 'participants', 'venue.schedule', 'primaryGame']);
@endphp
<dialog class="mskba-modal venue-play-dialog {{ $isAppTheme ? 'venue-play-dialog--app' : '' }}"
    data-venue-play-dialog data-venue-id="{{ $venue->id }}"
    data-authenticated="{{ auth()->check() ? '1' : '0' }}"
    aria-labelledby="venue-play-title">
    <header class="mskba-modal__header venue-play-dialog__header">
        <h2 id="venue-play-title" data-venue-play-title>Хочу здесь играть</h2>
        <button type="button" class="icon-button venue-play-dialog__close" data-venue-play-close aria-label="Закрыть">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6l-12 12"/><path d="M6 6l12 12"/></svg>
        </button>
    </header>
    <div class="mskba-modal__body venue-play-dialog__body mskba-scroll" data-venue-play-step="list">
        <div class="venue-play-dialog__events">
            @forelse($upcomingVenueEvents as $upcoming)
                @php
                    $venueEventStart = $upcoming->starts_at?->setTimezone(
                        $upcoming->venue?->schedule?->timezone ?: config('app.timezone', 'Europe/Moscow')
                    );
                @endphp
                <button type="button" class="venue-play-dialog__event" data-venue-play-preview="{{ $upcoming->id }}">
                    <span class="venue-play-dialog__event-meta">{{ $upcoming->type->label() }}
                        @if($venueEventStart) · {{ $venueEventStart->format('d.m.Y H:i') }}@endif
                        @if($upcoming->booking_deferred) · время предварительное @endif
                    </span>
                    <strong>{{ $upcoming->title }}</strong>
                    <small data-venue-play-card-count>{{ $upcoming->participants->where('status', \App\Modules\Event\Domain\Enums\EventParticipantStatusEnum::CONFIRMED)->count() }} пойдут · Подробнее</small>
                </button>
            @empty
                <p class="venue-play-dialog__empty">На этой площадке пока нет предстоящих мероприятий. Вы можете создать своё мероприятие, пригласить игроков или открыть его для всех, чтобы желающие могли присоединиться самостоятельно.@if($isAppTheme) А пока можно посмотреть, когда площадка свободна.@endif</p>
            @endforelse
        </div>
    </div>
    <footer class="mskba-modal__footer venue-play-dialog__footer" data-venue-play-list-footer @if($isAppTheme && $upcomingVenueEvents->isEmpty()) data-venue-play-empty-footer @endif>
        @if($isAppTheme && $upcomingVenueEvents->isEmpty())
            <button type="button" class="{{ $secondaryButton }}" data-venue-availability-open>Посмотреть занятость</button>
        @endif
        <button type="button" class="{{ $primaryButton }}" data-quick-event-open
            data-quick-event-venue-id="{{ $venue->id }}" data-venue-play-create>Создать мероприятие</button>
    </footer>
    <div class="mskba-modal__body venue-play-dialog__body mskba-scroll" data-venue-play-step="detail" hidden>
        @foreach($venuePlayDetailEvents as $upcoming)
            @php
                $participant = $upcoming->participants->first(fn ($member) => in_array((int) $member->user_id, $viewerIdentityIds, true));
                $currentStatus = $participant?->confirmation_version === $upcoming->participation_confirmation_version ? $participant?->status?->value : null;
                $organizer = $upcoming->organizerActor?->user?->canonical();
                $isMyEvent = $organizer && in_array((int) $organizer->id, $viewerIdentityIds, true);
                $venueEventStart = $upcoming->starts_at?->setTimezone($upcoming->venue?->schedule?->timezone ?: config('app.timezone', 'Europe/Moscow'));
            @endphp
            <article class="venue-play-dialog__detail" data-venue-play-pane="{{ $upcoming->id }}"
                data-event-status="{{ $currentStatus ?? '' }}"
                data-event-organizer="{{ $isMyEvent ? '1' : '0' }}"
                data-event-participation-url="{{ route('events.participation', $upcoming->routeIdentifier()) }}"
                data-event-wall-url="{{ route('events.wall.index', $upcoming->routeIdentifier()) }}"
                data-event-post-url="{{ route('events.wall.store', $upcoming->routeIdentifier()) }}"
                hidden>
                <div class="venue-play-dialog__summary">
                    <h3>{{ $upcoming->title }}</h3>
                    <p class="venue-play-dialog__event-meta">{{ $upcoming->type->label() }} · {{ $venueEventStart?->format('d.m.Y H:i') ?? 'Время уточняется' }}@if($upcoming->booking_deferred) · время предварительное @endif</p>
                    <p><strong>Описание:</strong> {{ $upcoming->description ?: 'Описание пока не добавлено.' }}</p>
                </div>
                @include('components.event-discussion', ['chatEvent' => $upcoming, 'chatCanRespond' => true])
            </article>
        @endforeach
    </div>
    <footer class="mskba-modal__footer venue-play-dialog__footer" data-venue-play-detail-footer hidden>
        <button type="button" class="{{ $secondaryButton }}" data-venue-play-back>Назад</button>
        @foreach($venuePlayDetailEvents as $upcoming)
            <a class="{{ $primaryButton }}" data-venue-play-event-link="{{ $upcoming->id }}" href="{{ route('events.show', $upcoming->routeIdentifier()) }}" hidden>К мероприятию</a>
        @endforeach
    </footer>
</dialog>
<dialog class="mskba-modal venue-play-dialog venue-play-auth-notice {{ $isAppTheme ? 'venue-play-dialog--app' : '' }}" data-venue-play-auth-notice
    aria-labelledby="venue-play-auth-title">
    <header class="mskba-modal__header venue-play-dialog__header"><h2 id="venue-play-auth-title">Нужна авторизация</h2>
        <button type="button" class="icon-button venue-play-dialog__close" data-venue-play-auth-close aria-label="Закрыть"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6l-12 12"/><path d="M6 6l12 12"/></svg></button>
    </header>
    <div class="mskba-modal__body venue-play-dialog__body mskba-scroll">
        <p>Чтобы продолжить, войди или зарегистрируйся. Мы сохраним выбранное мероприятие и твой ответ и вернём тебя к процессу.</p>
    </div>
    <footer class="mskba-modal__footer venue-play-dialog__footer">
        <button type="button" class="{{ $primaryButton }}" data-venue-play-auth-login>Войти</button>
    </footer>
</dialog>
@include('components.event-discussion-delete-dialog')
@include('components.event-discussion-styles')

