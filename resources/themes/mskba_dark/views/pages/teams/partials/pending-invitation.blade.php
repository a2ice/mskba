@php
    $invitedName = trim(implode(' ', array_filter([
        $invitation->user->profile?->first_name,
        $invitation->user->profile?->last_name,
    ]))) ?: $invitation->user->username;
    $invitedAvatar = $invitation->user->profile?->activeAvatar?->publicUrl()
        ?? asset($invitation->user->profile?->gender === \App\Modules\Identity\Domain\Enums\UserGenderEnum::FEMALE
            ? 'images/blank/avatar/avatar-female.png'
            : 'images/blank/avatar/avatar-male.png');
    $invitedRole = $invitation->sportRoles()->map(fn ($role) => $role->label())->join(', ')
        ?: $invitation->member_type?->label()
        ?: 'Участник';
@endphp
<article class="team-pending-invitation" data-pending-invitation-id="{{ $invitation->id }}">
    <img class="team-pending-invitation__avatar" src="{{ $invitedAvatar }}" alt="Аватар {{ $invitedName }}">
    <div class="team-pending-invitation__content">
        <strong>{{ $invitedName }}</strong>
        <span>{{ '@'.$invitation->user->username }}</span>
        <small>{{ $invitedRole }} · приглашение ожидает ответа</small>
    </div>
    <div class="team-pending-invitation__actions">
        <span class="team-pending-invitation__status">Ожидает</span>
        <button
            class="btn btn--outline btn--sm team-pending-invitation__revoke"
            type="button"
            data-pending-invitation-revoke
            data-revoke-url="{{ route('teams.invitations.respond', $invitation->id) }}"
        >Отозвать</button>
    </div>
</article>
