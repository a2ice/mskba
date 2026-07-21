@php $title = 'Настройки аккаунта'; @endphp

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

    @if(isset($error))
        <div class="alert alert-danger">
            {{ $error['message'] }}
        </div>
    @endif

    @if ($user)

        @if(session('status'))
            <div class="alert alert-success">
                {{ session('status') }}
            </div>
        @endif

        <section class="account-settings-card" aria-labelledby="account-password-title">
            <h2 id="account-password-title" class="h3 mb-3">
                {{ $user->password === null ? 'Установить пароль' : 'Изменить пароль' }}
            </h2>

            <p class="text-muted mb-4">
                @if($user->password === null)
                    После установки пароля вы сможете входить в web-версию по логину или подтверждённому контакту.
                @else
                    Для безопасности подтвердите текущий пароль перед его изменением.
                @endif
            </p>

            <form method="POST" action="{{ route('account.settings.password.update') }}" class="account-password-form">
                @csrf
                @method('PUT')

                @if($user->password !== null)
                    <div class="form-group field mb-3">
                        <label for="currentPassword" class="form-label">Текущий пароль</label>
                        <input
                            id="currentPassword"
                            type="password"
                            name="current_password"
                            class="form-control @error('current_password') is-invalid @enderror"
                            autocomplete="current-password"
                            required
                        >
                        @error('current_password')
                            <div class="invalid-feedback">{{ $message }}</div>
                        @enderror
                    </div>
                @endif

                <div class="form-group field mb-3">
                    <label for="newPassword" class="form-label">Новый пароль</label>
                    <input
                        id="newPassword"
                        type="password"
                        name="password"
                        class="form-control @error('password') is-invalid @enderror"
                        autocomplete="new-password"
                        required
                    >
                    @error('password')
                        <div class="invalid-feedback">{{ $message }}</div>
                    @enderror
                </div>

                <div class="form-group field mb-4">
                    <label for="newPasswordConfirmation" class="form-label">Повторите новый пароль</label>
                    <input
                        id="newPasswordConfirmation"
                        type="password"
                        name="password_confirmation"
                        class="form-control"
                        autocomplete="new-password"
                        required
                    >
                </div>

                <p class="text-muted mb-4">
                    Не менее {{ \App\Modules\Identity\Domain\ValueObjects\PasswordVO::MIN_LENGTH }} символов: заглавная и строчная латинские буквы, цифра и специальный символ.
                </p>

                <button type="submit" class="btn btn--primary btn--sm">
                    {{ $user->password === null ? 'Установить пароль' : 'Изменить пароль' }}
                </button>
            </form>
        </section>

    @endif

@endsection
