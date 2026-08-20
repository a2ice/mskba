@php
    $title = 'VK ID';
    $user = auth()->user()?->canonical();
    $linkedVkAccount = $user
        ? \App\Modules\Vk\Domain\Models\VkAccount::query()
            ->whereIn('user_id', $user->identityIds())
            ->orderByRaw('CASE WHEN user_id = ? THEN 0 ELSE 1 END', [$user->id])
            ->orderByDesc('last_auth_at')
            ->first()
        : null;
@endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'account',
    'sectionClass' => 'account-section',
    'contentTitle' => $title,
    'sidebarLabel' => 'Навигация аккаунта',
    'wrapSidebarPanel' => false,
    'sidebarPartial' => 'theme::partials.account.sidebar',
])

@section('section-content')
    @if(session('success'))
        <div class="alert alert-success mb-3">{{ session('success') }}</div>
    @endif
    @if(session('warning'))
        <div class="alert alert-warning mb-3">{{ session('warning') }}</div>
    @endif
    @if(session('error'))
        <div class="alert alert-danger mb-3">{{ session('error') }}</div>
    @endif

    @if(trim((string) config('vk.app_id')) === '')
        <div class="alert alert-warning">Подключение VK ID сейчас недоступно.</div>
    @else
        <p class="mb-3">
            VK ID позволяет входить в MSKBA без отдельного пароля. Мы сохраняем только неизменяемый VK ID,
            имя, фамилию и аватар; токены доступа не сохраняются.
        </p>

        @if($linkedVkAccount)
            <div class="alert alert-info mb-3">
                Сейчас связан VK ID {{ $linkedVkAccount->vk_user_id }}
                @if($linkedVkAccount->first_name || $linkedVkAccount->last_name)
                    — {{ trim($linkedVkAccount->first_name.' '.$linkedVkAccount->last_name) }}
                @endif
                . Повторное подтверждение обновит данные связи.
            </div>
        @endif

        <a class="btn btn--primary btn--sm" href="{{ route('account.vk.link') }}">
            {{ $linkedVkAccount ? 'Подтвердить VK ID повторно' : 'Подключить VK ID' }}
        </a>
    @endif
@endsection
