@php
    // Blade @include has its own variable scope: do not rely on the editor
    // partial to define this flag for sibling footer/dialog includes.
    $canEditPersonalProfile = isset($user) && ! $user->isBlocked() && (
        ! $profileConfirmed || app(\App\Modules\Identity\Application\Services\UserOperationalPermissionChecker::class)
            ->allows($user, \App\Modules\Identity\Domain\Enums\UserOperationalPermissionEnum::EDIT_OWN_PROFILE)
    );
@endphp
@if($canEditPersonalProfile ?? true)
    <button type="submit" class="button primary" data-inline-profile-save-all
        @if ($profilePersonalInDialog ?? false) form="app-player-personal-form" @endif disabled>Применить</button>
@endif
@if ($profileConfirmed && ! ($canEditPersonalProfile ?? false) && $activeProfileChangeRequest === null)
    <button type="button" class="button secondary" data-profile-change-request-open>Запросить изменение подтверждённых данных</button>
@endif
