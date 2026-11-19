@php
    $isAppTheme = app(\App\Presentation\Theming\ThemeResolver::class)->active() === 'mskba_app';
    $primaryButton = $isAppTheme ? 'button primary' : 'btn btn--primary';
    $upcomingVenueEvents = \App\Modules\Event\Domain\Models\Event::query()
        ->with(['participants', 'venue.schedule'])
        ->where('venue_id', $venue->id)
        ->where('status', \App\Modules\Event\Domain\Enums\EventStatusEnum::PUBLISHED->value)
        ->where('visibility', \App\Modules\Event\Domain\Enums\EventVisibilityEnum::PUBLIC->value)
        ->where('ends_at', '>', now())
        ->orderBy('starts_at')->limit(20)->get();
@endphp
<dialog class="mskba-modal venue-play-dialog {{ $isAppTheme ? 'venue-play-dialog--app' : '' }}"
    data-venue-play-dialog data-venue-id="{{ $venue->id }}"
    aria-labelledby="venue-play-title">
    <header class="mskba-modal__header venue-play-dialog__header">
        <h2 id="venue-play-title">Хочу здесь играть</h2>
        <button type="button" class="icon-button venue-play-dialog__close" data-venue-play-close aria-label="Закрыть">
            <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18 6l-12 12"/><path d="M6 6l12 12"/></svg>
        </button>
    </header>
    <div class="mskba-modal__body venue-play-dialog__body mskba-scroll">
        <div class="venue-play-dialog__events">
            @forelse($upcomingVenueEvents as $upcoming)
                @php
                    $venueEventStart = $upcoming->starts_at?->setTimezone(
                        $upcoming->venue?->schedule?->timezone ?: config('app.timezone', 'Europe/Moscow')
                    );
                @endphp
                <a class="venue-play-dialog__event" href="{{ route('events.show', $upcoming->routeIdentifier()) }}">
                    <span class="venue-play-dialog__event-meta">{{ $upcoming->type->label() }}
                        @if($venueEventStart) · {{ $venueEventStart->format('d.m.Y H:i') }}@endif
                        @if($upcoming->booking_deferred) · время предварительное @endif
                    </span>
                    <strong>{{ $upcoming->title }}</strong>
                    <small>{{ $upcoming->participants->where('status', \App\Modules\Event\Domain\Enums\EventParticipantStatusEnum::CONFIRMED)->count() }} пойдут · Подробнее</small>
                </a>
            @empty
                <p class="venue-play-dialog__empty">На этой площадке пока нет предстоящих мероприятий.</p>
            @endforelse
        </div>
    </div>
    <footer class="mskba-modal__footer venue-play-dialog__footer">
        <button type="button" class="{{ $primaryButton }}" data-quick-event-open
            data-quick-event-venue-id="{{ $venue->id }}">Создать мероприятие</button>
    </footer>
</dialog>
@once
<style>
.venue-play-dialog{
    width:min(690px,calc(100vw - 24px));max-width:calc(100vw - 24px);
    max-height:90dvh;padding:0;overflow:hidden;color:var(--text,#fff);
}
.venue-play-dialog[open]{display:flex;flex-direction:column}
.venue-play-dialog:not([open]){display:none}
.venue-play-dialog:not(.venue-play-dialog--app){
    border:1px solid var(--line,#59606a);border-radius:16px;
    background:var(--surface,#1b1f27);box-shadow:0 20px 85px #0008;
}
.venue-play-dialog:not(.venue-play-dialog--app)::backdrop{background:rgba(0,0,0,.64)}
.venue-play-dialog:not(.venue-play-dialog--app)[open]>.venue-play-dialog__header::before{
    content:"⠿";align-self:center;justify-self:center;
    color:var(--muted,#929aa4);font-size:1rem;line-height:1;pointer-events:none;
}
.venue-play-dialog__header{
    display:grid;grid-template-columns:16px minmax(0,1fr) 44px;
    align-items:center;gap:12px;padding:20px 22px;
    border-bottom:1px solid var(--line,#59606a);
    cursor:grab;touch-action:none;user-select:none;
}
.venue-play-dialog__header h2{margin:0;font-size:clamp(19px,2vw,25px);line-height:1.3;min-width:0}
.venue-play-dialog__header .venue-play-dialog__close{
    width:44px;height:44px;display:flex;align-items:center;justify-content:center;
    justify-self:end;border:1px solid var(--line,#59606a);
    border-radius:12px;background:var(--surface,#1b1f27);color:inherit;cursor:pointer;
}
.venue-play-dialog__close svg{
    width:20px;height:20px;fill:none;stroke:currentColor;
    stroke-width:2;stroke-linecap:round;
}
.venue-play-dialog__body{
    flex:1 1 auto;min-height:0;overflow-y:auto;padding:20px 22px;
}
.venue-play-dialog__events{display:grid;gap:10px}
.venue-play-dialog__event{
    display:grid;gap:6px;width:100%;text-align:left;padding:14px;
    border:1px solid var(--line,#5c6570);border-radius:12px;
    background:var(--field,#272b32);color:inherit;text-decoration:none;cursor:pointer;
}
.venue-play-dialog__event:hover,.venue-play-dialog__event:focus-visible{
    border-color:var(--accent,#ea7628);
}
.venue-play-dialog__event-meta{font-size:13px;color:var(--muted,#929aa4)}
.venue-play-dialog__event strong{line-height:1.35}
.venue-play-dialog__event small{color:var(--muted,#929aa4)}
.venue-play-dialog__empty{margin:0;padding:24px 14px;text-align:center;color:var(--muted,#929aa4)}
.venue-play-dialog__footer{
    display:flex;justify-content:flex-end;flex:0 0 auto;
    padding:16px 22px;border-top:1px solid var(--line,#59606a);
}
.venue-play-dialog__footer :is(.button,.btn){min-height:44px}
@media(max-width:540px){
    .venue-play-dialog__header,.venue-play-dialog__body{padding-inline:16px}
    .venue-play-dialog__footer{padding:16px}
    .venue-play-dialog__footer :is(.button,.btn){width:100%}
}
</style>
@endonce
