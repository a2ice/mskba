@php($personPreview = app(\App\Modules\Identity\Application\Services\PublicUserProfileService::class)->preview($person, auth()->user()))
<button type="button" class="sports-section-public__person-card js-handler" data-handler="modal" data-modal-action="open" data-modal-target="embedded-entity-preview" data-entity-preview-trigger data-entity-type="user" data-entity-preview-url="{{ app(\App\Modules\Identity\Application\Services\PublicUserProfileService::class)->url($person, preview: true) }}">
    <div
        class="sports-section-public__person-avatar"
        @if($personPreview['avatar_restricted']) title="Отображение аватара запрещено в настройках профиля" data-tooltip-variant="title" @endif
    >@if($personPreview['avatar_url'])<img src="{{ $personPreview['avatar_url'] }}" alt="">@else<i class="ti ti-user" aria-hidden="true"></i>@endif</div>
    <div><strong>{{ $personPreview['name'] }}</strong><span>{{ $personLabel }}</span></div>
</button>
