@php
    $buttonClass = $buttonClass ?? 'button primary';
@endphp
<section class="venue-favorite-follow" data-venue-favorite-follow data-state="unknown" aria-label="Интерес к площадке" @if(!empty($eventUrl)) data-create-event-url="{{ $eventUrl }}" @endif>
    <p class="venue-favorite-follow__message" data-venue-favorite-follow-message role="status" aria-live="polite">Проверяем избранное…</p>
    <button type="button" class="{{ $buttonClass }} venue-favorite-follow__action"
        data-venue-favorite-follow-action aria-pressed="false" disabled>
        <svg class="venue-favorite-follow__icon" viewBox="0 0 24 24" aria-hidden="true">
            <path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 1 0-7.8 7.8L12 21l8.8-8.6a5.5 5.5 0 0 0 0-7.8Z"/>
        </svg>
        <span data-venue-favorite-follow-label>Проверяем избранное…</span>
    </button>
</section>
