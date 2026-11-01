@if(!empty($url) && auth()->check())
<a
    class="entity-edit-link"
    href="{{ $url }}"
    title="Редактировать"
    aria-label="Редактировать"
    data-tooltip-variant="title"
    data-tooltip-icon
>
    <i class="ti ti-pencil" aria-hidden="true"></i>
</a>
@endif
