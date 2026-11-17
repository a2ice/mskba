@guest
    @php
        $roles = array_map(
            fn (\App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum $role): array => [
                'value' => $role->value,
                'label' => $role->label(),
            ],
            \App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum::cases()
        );
        $authOptions = [
            'login' => route('auth.login', [], false),
            'register' => route('auth.register', [], false),
            'restore' => route('auth.restore', [], false),
            'vk' => trim((string) config('vk.app_id')) !== ''
                ? route('auth.vk.start', [], false)
                : null,
            'telegramBot' => ltrim(trim((string) config('telegram.bot_username')), '@'),
            'telegramLogin' => route('auth.telegram', [], false),
            'account' => route('account', [], false),
            // Both legal pages now exist in MSKBA App and share the content of
            // legacy pages with their modal fragments.
            'privacyPolicy' => route('privacy.policy', [], false),
            'consent' => route('personal-data.consent', [], false),
            'legalFragments' => [
                'consent' => route('legal.fragment.consent', [], false),
                'privacy' => route('legal.fragment.privacy', [], false),
            ],
            'roles' => $roles,
        ];
    @endphp
    <div id="mskba-auth-dialog-root" data-mskba-auth-dialog data-options='@json($authOptions)'></div>
@endguest
