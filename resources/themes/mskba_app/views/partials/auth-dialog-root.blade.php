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
            // The new theme has not migrated legal pages yet. In local preview,
            // link to the published canonical documents instead of a fallback page.
            'privacyPolicy' => app()->environment('local')
                ? 'https://mskba.ru/privacy'
                : route('privacy.policy', [], false),
            'consent' => app()->environment('local')
                ? 'https://mskba.ru/personal-data-consent'
                : route('personal-data.consent', [], false),
            'roles' => $roles,
        ];
    @endphp
    <div id="mskba-auth-dialog-root" data-mskba-auth-dialog data-options='@json($authOptions)'></div>
@endguest
