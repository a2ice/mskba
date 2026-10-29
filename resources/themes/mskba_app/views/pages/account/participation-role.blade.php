@extends('theme::layouts.account', ['title' => isset($role) ? 'Параметры: '.$role->label() : 'Параметры роли'])

@section('account-heading')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => isset($role)
            ? ($role->value === 'player' ? $role->label() : 'Роль: '.$role->label())
            : 'Параметры роли',
        'intro' => isset($role) && $role->value === 'player'
            ? 'Создай свой игровой образ и настрой характеристики для игр, команд и турниров.'
            : (isset($role) ? $role->description() : 'Информация о твоём участии в проекте.'),
    ])
@endsection

@section('account-content')
    @if(isset($error))
        <div class="notice" role="alert">{{ $error['message'] }}</div>
    @elseif(isset($participationRole, $role))
        @if($role === \App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum::PLAYER)
            @include('theme::pages.account.partials.player-profile-workspace')
        @else
            <section class="panel app-role-details" aria-labelledby="app-role-details-title">
                <h2 id="app-role-details-title">Твоя роль</h2>
                <dl>
                    <div><dt>Статус</dt><dd>{{ $participationRole->status->label() }}</dd></div>
                    <div><dt>Назначена</dt><dd>{{ $participationRole->assigned_at?->format('d.m.Y') ?? 'Не указано' }}</dd></div>
                    <div><dt>Источник</dt><dd>{{ $participationRole->assigner?->label() ?? 'Не указан' }}</dd></div>
                </dl>
                <p>Индивидуальные настройки этой роли появятся здесь в следующем этапе разработки.</p>
                <a class="button secondary" href="{{ route('account.roles') }}">Вернуться к ролям</a>
            </section>
        @endif
    @endif
@endsection
