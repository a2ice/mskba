{{-- Venue detail uses the same fixed action position as contextual help. --}}
@if(request()->routeIs('venues.show', 'venues.courts.show'))
    <a class="app-context-help-launcher app-context-help-launcher--venue-create"
       href="{{ route('venues.create') }}" data-venue-floating-create
       aria-label="Добавить площадку" title="Добавить площадку">
        <span class="app-context-help-launcher__mark" aria-hidden="true">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M12 5v14M5 12h14"/></svg>
        </span>
        <span class="app-context-help-launcher__label">Добавить площадку</span>
    </a>
@else
    {{-- Shared MSKBA App help: the label is visual only; accessible name remains stable. --}}
    <button type="button" class="app-context-help-launcher" data-help-launcher
            aria-label="Помощь по этой странице" title="Помощь по этой странице">
        <span class="app-context-help-launcher__label" data-help-intro-label aria-hidden="true">Помощь</span>
        <span class="app-context-help-launcher__mark" aria-hidden="true">?</span>
    </button>
@endif
