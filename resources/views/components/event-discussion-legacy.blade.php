{{-- Legacy event hosts retain their existing sport/registration controls and participant management. --}}
@if($eventWallCanView ?? false)
    <section class="{{ $isAppTheme ? 'panel venue-play-dialog--app' : 'event-social-wall' }}"
        data-venue-play-page data-event-discussion-legacy
        data-venue-play-pane="{{ $event->id }}" data-authenticated="{{ auth()->check() ? '1' : '0' }}"
        data-event-status="{{ $currentParticipant?->confirmation_version === $event->participation_confirmation_version ? $currentParticipant?->status?->value : '' }}"
        data-event-participation-url="{{ route('events.participation', $event->routeIdentifier()) }}"
        data-event-wall-url="{{ route('events.wall.index', $event->routeIdentifier()) }}"
        data-event-post-url="{{ route('events.wall.store', $event->routeIdentifier()) }}">
        @include('components.event-discussion', [
            'chatEvent' => $event,
            'chatCanRespond' => false,
            'chatShowRoster' => false,
            'primaryButton' => $isAppTheme ? 'button primary' : 'btn btn--primary',
            'secondaryButton' => $isAppTheme ? 'button secondary' : 'btn btn--secondary',
        ])
    </section>
    @include('components.event-discussion-delete-dialog', ['isAppTheme' => $isAppTheme])
    @include('components.event-discussion-styles')
@endif
