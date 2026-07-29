@php $title = 'Профиль'; @endphp

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
    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
    @endif

    @if(isset($error))
        <div class="alert alert-danger">
            {{ $error['message'] }}
        </div>
    @endif


    @if ($user)
        <ul class="list-unstyled mb-3">
            <li class="list-unstyled mb-2">
                Логин:
                <span class="fw-bold">{{ $user->username }}</span>
            </li>
            <li class="list-unstyled mb-2">
                Статус:
                <span class="fw-bold">{{ $user->status->label() }}</span>
            </li>
            <li class="list-unstyled mb-2">
                Имя:
                <span class="fw-bold">{{ $user->profile?->first_name }}</span>
            </li>
            <li class="list-unstyled mb-2">
                Фамилия:
                <span class="fw-bold">{{ $user->profile?->last_name }}</span>
            </li>
            <li class="list-unstyled mb-2">
                Отчество:
                <span class="fw-bold">{{ $user->profile?->middle_name }}</span>
            </li>
            <li class="list-unstyled mb-2">
                Пол:
                <span class="fw-bold">{{ $user->profile?->gender?->label() }}</span>
            </li>
            <li class="list-unstyled mb-2">
                Возраст:
                <span class="fw-bold">{{ $user->profile?->age }}</span>
            </li>
            <li class="list-unstyled mb-2 d-none">
                <span title="Системная роль определяет уровень доступа и разрешения пользователя в рамках платформы. Роли в проекте отражают конкретные функции и обязанности пользователя в рамках отдельных проектов, в которых он участвует.">Роль:</span>
                <span class="fw-bold">{{ $user->system_role->label() }}</span>
            </li>
            <li class="list-unstyled mb-2">
                Роли в проекте:
                @forelse($user->participationRoles as $participationRole)
                    <a
                        href="{{ route('account.participation-role', ['role' => $participationRole->role->value]) }}"
                        class="fc-link fw-bold"
                    >{{ $participationRole->role->label() }}</a>@if(!$loop->last),@endif
                @empty
                    <a href="{{ route('account.roles') }}" class="fc-link fw-bold">Выбрать роль</a>
                @endforelse
            </li>
            <li class="list-unstyled mb-2">
                Основной контакт:
                @if($primaryContact)
                    <span class="fw-bold">{{ $primaryContact->type->label() }}: {{ $primaryContact->displayValue() }}</span>
                    @if($primaryContact->is_verified)
                        <span class="badge bg-success">Подтвержден</span>
                    @else
                        <span class="badge bg-warning text-dark">Не подтвержден</span>
                    @endif
                @else
                    <a href="{{ route('account.contacts') }}" class="fc-link">Добавить контакт</a>
                @endif
            </li>
            <li class="list-unstyled mb-2">
                Дата регистрации:
                <span class="fw-bold">{{ $user->created_at->format('d.m.Y H:i') }}</span>
            </li>
        </ul>

        @if($user->status === \App\Modules\Identity\Domain\Enums\UserStatusEnum::UNCONFIRMED)
            <hr>

            @if($primaryVerifiedContact)
                <p class="mb-4">Основной контакт подтвержден. Продолжите настройку профиля, чтобы подтвердить аккаунт и получить доступ ко всем функциям платформы.</p>
                <div class="mt-4">
                    <a href="{{ route('account.confirmation') }}" class="btn btn--primary btn--sm">Подтвердить аккаунт</a>
                </div>
            @elseif($primaryContact)
                <p class="mb-4">Подтвердите основной контакт, чтобы получить возможность подтвердить аккаунт и получить доступ ко всем функциям платформы.</p>
                <div class="mt-4">
                    <a href="{{ route('account.contacts') }}" class="btn btn--secondary btn--sm">Перейти к контактам</a>
                </div>
            @else
                <p class="mb-4">Добавьте и подтвердите контакт, чтобы получить возможность подтвердить аккаунт и получить доступ ко всем функциям платформы.</p>
                <div class="mt-4">
                    <a href="{{ route('account.contacts') }}" class="btn btn--secondary btn--sm">Перейти к контактам</a>
                </div>
            @endif
        @endif
    @endif
@endsection
