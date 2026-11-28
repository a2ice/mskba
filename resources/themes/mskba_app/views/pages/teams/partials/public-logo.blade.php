@if($team->logo?->publicUrl())
    <img class="app-team-card__logo" src="{{ $team->logo->publicUrl() }}" alt="" loading="lazy">
@else
    <span class="app-team-card__logo app-community-logo-placeholder" aria-hidden="true">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="12" cy="12" r="9"/><path d="M3 12h18M12 3v18M6 5.3c5 4 5 9.4 0 13.4M18 5.3c-5 4-5 9.4 0 13.4"/></svg>
    </span>
@endif
