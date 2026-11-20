@php
    $organizerRoleUser = auth()->user()?->canonical();
    $organizerRolePresent = $organizerRoleUser?->participationRoles()
        ->where('role', \App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum::ORGANIZER->value)->exists() ?? false;
@endphp
@if(!$organizerRolePresent)
    <input type="hidden" name="add_organizer_role" value="0">
    <label class="check-label {{ $organizerToggleClass ?? '' }}">
        <input type="checkbox" name="add_organizer_role" value="1" data-event-organizer-role
            @checked((string) old('add_organizer_role', '1') === '1')>
        <span>Добавить мне роль «Организатор мероприятий»</span>
    </label>
@endif
