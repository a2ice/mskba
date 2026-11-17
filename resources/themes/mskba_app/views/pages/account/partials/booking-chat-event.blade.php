<article class="app-messages__bubble app-messages__bubble--system"
         data-rental-event-version="{{ $transition->booking_version }}"
         data-rental-at="{{ $transition->created_at?->toIso8601String() }}">
    <div class="app-messages__bubble-head">
        <strong>Бронирование: {{ $transition->to_status->label() }}</strong>
    </div>
    @if(filled($transition->reason))
        <p class="app-messages__bubble-body">{{ $transition->reason }}</p>
    @endif
    <time class="app-messages__timestamp" datetime="{{ $transition->created_at?->toIso8601String() }}">
        {{ $transition->created_at?->format('d.m.Y H:i') }}
    </time>
</article>
