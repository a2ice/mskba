@php
    use App\Modules\Event\Domain\Enums\EventResponsibilityPermissionEnum;
    use App\Modules\Event\Domain\Enums\VenueBookingStatusEnum;
    use App\Modules\VenueBooking\Domain\Models\VenueBookingPolicy;

    $sourceBooking = $event->sourceBooking;
    $canRequestBooking = ($canManage ?? false)
        && ($effectivePermissions ?? collect())->contains(EventResponsibilityPermissionEnum::UPDATE_EVENT->value)
        && $event->status->value === 'published'
        && $event->ends_at->isFuture();
    $hasRentalPolicy = VenueBookingPolicy::query()
        ->where('venue_id', $event->venue_id)
        ->where('active_marker', true)
        ->where('is_enabled', true)->exists();
    $eventTimezone = $event->venue->schedule()->value('timezone') ?: 'Europe/Moscow';
@endphp

@if($event->booking_deferred && !($isEmbeddedGame ?? false))
    @if(($canManage ?? false) && ($interestThread = $event->venueInterests->sortByDesc('revision')->first()))
        <p class="mb-3"><a class="fc-link" href="{{ route('events.interests.show', ['event' => $event->routeIdentifier(), 'interest' => $interestThread->id]) }}">Переписка с площадкой по предзаявке</a></p>
    @endif
    @if($sourceBooking?->status === VenueBookingStatusEnum::CONFIRMED)
        <div class="alert alert-success mb-3"><strong>Зал забронирован.</strong> Бронь подтверждена площадкой.
            <a href="{{ route('account.venue-bookings.show', $sourceBooking) }}">Детали бронирования</a>
        </div>
    @elseif($sourceBooking)
        <div class="alert alert-info mb-3">
            <strong>Бронирование ещё не подтверждено.</strong>
            Статус заявки: {{ $sourceBooking->status->label() }}.
            До подтверждения площадка и время не гарантированы.
            @if($canRequestBooking)
                <a href="{{ route('account.venue-bookings.show', $sourceBooking) }}">Перейти к заявке</a>
            @endif
        </div>
    @else
        <div class="alert alert-warning mb-3">
            <strong>Площадка пока не забронирована.</strong>
            Время мероприятия предварительное. Организатор набирает участников и позднее оформит бронь.
            В любой момент интервал может быть занят другой заявкой.
        </div>
        @if($canRequestBooking)
            @if($hasRentalPolicy)
                <form method="POST" action="{{ route('events.booking.quote', $event->routeIdentifier()) }}" class="mb-4">
                    @csrf
                    <input type="hidden" name="expected_version" value="{{ $event->participation_confirmation_version }}">
                    <button type="submit" class="btn btn--primary btn--sm">Рассчитать и забронировать зал</button>
                    <a href="{{ route('events.edit', $event->routeIdentifier()) }}" class="btn btn--secondary btn--sm">Изменить место или время</a>
                </form>
            @elseif($event->venue->hasFreeAccess())
                <form method="POST" action="{{ route('events.booking.store', $event->routeIdentifier()) }}" class="mb-4">
                    @csrf
                    <input type="hidden" name="expected_version" value="{{ $event->participation_confirmation_version }}">
                    <button type="submit" class="btn btn--primary btn--sm">Забронировать бесплатную площадку</button>
                </form>
            @else
                <p class="text-muted mb-3">Условия аренды сейчас не опубликованы. Можно изменить время или площадку либо связаться с владельцем.</p>
                <a href="{{ route('events.edit', $event->routeIdentifier()) }}" class="btn btn--secondary btn--sm mb-3">Изменить место или время</a>
            @endif
        @endif
    @endif
@endif

@if($event->venueRevisions->isNotEmpty() && !($isEmbeddedGame ?? false))
    <section class="card mb-4" aria-label="История изменений площадки и времени">
        <div class="card-body">
            <h2 class="h5 mb-3">История изменений места и времени</h2>
            @foreach($event->venueRevisions->sortByDesc('revision') as $revision)
                <div class="mb-3">
                    <small class="text-muted">Ревизия № {{ $revision->revision }} · {{ $revision->created_at->format('d.m.Y H:i') }}</small>
                    <div><del class="text-muted">
                        {{ $revision->previousVenue?->name ?? 'Площадка удалена' }}
                        @if($revision->previousCourt) · {{ $revision->previousCourt->name }} @endif
                        · {{ $revision->previous_starts_at->setTimezone($eventTimezone)->format('d.m.Y H:i') }}–{{ $revision->previous_ends_at->setTimezone($eventTimezone)->format('H:i') }}
                    </del></div>
                    <div><strong>
                        {{ $revision->nextVenue?->name ?? 'Площадка удалена' }}
                        @if($revision->nextCourt) · {{ $revision->nextCourt->name }} @endif
                        · {{ $revision->next_starts_at->setTimezone($eventTimezone)->format('d.m.Y H:i') }}–{{ $revision->next_ends_at->setTimezone($eventTimezone)->format('H:i') }}
                    </strong></div>
                    @if($revision->reason)<p class="text-muted mb-0">{{ $revision->reason }}</p>@endif
                </div>
            @endforeach
            <p class="text-muted mb-0">При изменении места или времени участникам требуется повторное подтверждение участия.</p>
        </div>
    </section>
@endif
