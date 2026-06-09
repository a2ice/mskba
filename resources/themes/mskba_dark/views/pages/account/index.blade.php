@extends('theme::layouts.account', ['title' => 'Профиль'])

@section('account-content')

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
                <span class="fw-bold">{{ $user->participation_role_labels }}</span>
            </li>
            <li class="list-unstyled mb-2">
                Email:
                @if ( $user->primaryEmail() )
                    <span class="fw-bold">{{ $user->primaryEmail()->value }}</span>
                    @if ($user->primaryEmail()->is_verified)
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
        @php
            if($user->primaryEmail()) {
                if($user->primaryEmail()->is_verified) {
                    $confirmationCondition = 'Все необходимые условия выполнены. Подтвердите аккаунт, чтобы получить доступ ко всем функциям платформы.';
                    $confirmationConditionActionBtn = '
                        <a href="' . route('account.confirmation') . '" class="btn btn--primary btn--sm">
                            Подтвердить аккаунт
                        </a>
                    ';
                } else {
                    $confirmationCondition = 'Подтвердите контакт, чтобы получить возможность подтвердить аккаунт и получить доступ ко всем функциям платформы.';
                    $confirmationConditionActionBtn = '
                        <a href="' . route('account.contacts') . '" class="btn btn--secondary btn--sm">
                            Перейти к контактам
                        </a>
                    ';
                }
            } else {
                $confirmationCondition = 'Добавьте и подтвердите контакт, чтобы получить возможность подтвердить аккаунт и получить доступ ко всем функциям платформы.';
                $confirmationConditionActionBtn = '
                    <a href="' . route('account.contacts') . '" class="btn btn--secondary btn--sm">
                        Перейти к контактам
                    </a>
                ';
            }
        @endphp
            <hr>
            <p class="mb-4">{{ $confirmationCondition }}</p>
            <div class="mt-4">
                {!! $confirmationConditionActionBtn !!}
            </div>
        @endif
    @endif
@endsection
