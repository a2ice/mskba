@php
    $title = 'Объединение аккаунтов';
    $first = $duplicate->user;
    $second = $duplicate->duplicateUser;
    $label = static function ($user): string {
        $profile = $user?->profile;
        $name = trim(implode(' ', array_filter([
            $profile?->first_name,
            $profile?->last_name,
        ])));
        return $name !== '' ? $name : ($user?->username ?: 'user #' . $user?->id);
    };
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
    <p>
        Подтверждённый Telegram уже связан с другим аккаунтом MSKBA. Если оба аккаунта ваши,
        выберите тот, который будет основным. Второй аккаунт не удаляется: он станет alias основного,
        а его старые связи сохранятся.
    </p>

    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    <form method="POST" action="{{ route('account.user-duplicates.merge', $duplicate) }}">
        @csrf

        <div class="form-check mb-3">
            <input class="form-check-input" type="radio" name="canonical_user_id" id="canonical-user-{{ $first->id }}" value="{{ $first->id }}" required>
            <label class="form-check-label" for="canonical-user-{{ $first->id }}">
                <strong>#{{ $first->id }} · {{ $label($first) }}</strong><br>
                <span>{{ $first->telegramAccount?->username ? '@'.$first->telegramAccount->username : 'Telegram не привязан' }}</span>
            </label>
        </div>

        <div class="form-check mb-3">
            <input class="form-check-input" type="radio" name="canonical_user_id" id="canonical-user-{{ $second->id }}" value="{{ $second->id }}" required>
            <label class="form-check-label" for="canonical-user-{{ $second->id }}">
                <strong>#{{ $second->id }} · {{ $label($second) }}</strong><br>
                <span>{{ $second->telegramAccount?->username ? '@'.$second->telegramAccount->username : 'Telegram не привязан' }}</span>
            </label>
        </div>

        <button class="btn btn--primary" type="submit">Объединить аккаунты</button>
    </form>
@endsection
