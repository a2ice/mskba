@php
    use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;
@endphp

@extends('theme::layouts.account', ['title' => $title ?? 'Роль участника'])

@section('account-content')
    @if(isset($error))
        <div class="alert alert-danger">
            {{ $error['message'] }}
        </div>
    @endif

    @if(isset($participationRole))
        <div class="mb-4">
            <h3 class="h3 mb-3">{{ $role->label() }}</h3>

            <ul class="list-unstyled mb-0">
                <li class="list-unstyled mb-2">
                    Статус:
                    <span class="fw-bold">{{ $participationRole->status->label() }}</span>
                </li>
                <li class="list-unstyled mb-2">
                    Назначена:
                    <span class="fw-bold">{{ $participationRole->assigned_at?->format('d.m.Y H:i') ?? 'не указано' }}</span>
                </li>
                <li class="list-unstyled mb-2">
                    Источник:
                    <span class="fw-bold">{{ $participationRole->assigner?->label() ?? 'не указан' }}</span>
                </li>
                @if($participationRole->expires_at)
                    <li class="list-unstyled mb-2">
                        Действует до:
                        <span class="fw-bold">{{ $participationRole->expires_at->format('d.m.Y H:i') }}</span>
                    </li>
                @endif
                @if($participationRole->comment)
                    <li class="list-unstyled mb-2">
                        Комментарий:
                        <span class="fw-bold">{{ $participationRole->comment }}</span>
                    </li>
                @endif
            </ul>
        </div>

        @if($role === UserParticipationRoleEnum::PLAYER)
            <div>
                <h3 class="h4 mb-3">Профиль игрока</h3>

                @if($user->playerProfile)
                    <ul class="list-unstyled mb-0">
                        <li class="list-unstyled mb-2">
                            Рост:
                            <span class="fw-bold">{{ $user->playerProfile->height_cm ? $user->playerProfile->height_cm . ' см' : 'не указан' }}</span>
                        </li>
                        <li class="list-unstyled mb-2">
                            Вес:
                            <span class="fw-bold">{{ $user->playerProfile->weight_kg ? $user->playerProfile->weight_kg . ' кг' : 'не указан' }}</span>
                        </li>
                        <li class="list-unstyled mb-2">
                            Позиция:
                            <span class="fw-bold">{{ $user->playerProfile->position?->label() ?? 'не указана' }}</span>
                        </li>
                        <li class="list-unstyled mb-2">
                            Начало стажа:
                            <span class="fw-bold">{{ $user->playerProfile->experience_started_year ?? 'не указано' }}</span>
                        </li>
                        <li class="list-unstyled mb-2">
                            Стаж:
                            <span class="fw-bold">{{ $user->playerProfile->experience_years !== null ? $user->playerProfile->experience_years . ' лет' : 'не указан' }}</span>
                        </li>
                        @if($user->playerProfile->comment)
                            <li class="list-unstyled mb-2">
                                Комментарий игрока:
                                <span class="fw-bold">{{ $user->playerProfile->comment }}</span>
                            </li>
                        @endif
                    </ul>
                @else
                    <p class="mb-0">Профиль игрока пока не заполнен.</p>
                @endif
            </div>
        @else
            <p class="mb-0">Детальная карточка этой роли будет расширена позже.</p>
        @endif
    @endif
@endsection
