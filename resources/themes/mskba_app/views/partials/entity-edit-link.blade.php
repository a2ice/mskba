@if(!empty($url) && auth()->check())
<a class="app-entity-edit-link"
   href="{{ $url }}"
   title="Редактировать"
   aria-label="Редактировать"
   data-tooltip-variant="title"
   data-tooltip-icon>
    <svg aria-hidden="true" viewBox="0 0 24 24" width="19" height="19" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
        <path d="M12 20h9"/>
        <path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L9 17l-4 1 1-4z"/>
    </svg>
</a>
@endif
