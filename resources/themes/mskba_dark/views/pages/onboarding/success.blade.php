@php
    use App\Modules\Identity\Domain\Enums\UserParticipationRoleEnum;

    $user = auth()->user()?->canonical();
    $isConfirmed = $user?->isConfirmed() ?? false;
    $confirmationUrl = route('account.confirmation');
    $confirmationHelpUrl = route('faq.welcome').'#account-confirmation';

    $commonRequirement = [
        'label' => 'Понадобится подтверждённый аккаунт',
        'url' => $isConfirmed ? null : $confirmationUrl,
        'help_url' => $confirmationHelpUrl,
        'done' => $isConfirmed,
    ];

    $actionsByRole = [
        UserParticipationRoleEnum::PLAYER->value => [
            [
                'icon' => 'ti-ball-basketball',
                'title' => 'Найти игру или тренировку',
                'text' => 'Посмотри ближайшие мероприятия, выбери подходящий формат и присоединяйся.',
                'url' => route('events.index'),
                'cta' => 'Смотреть мероприятия',
                'requirement' => null,
            ],
            [
                'icon' => 'ti-whistle',
                'title' => 'Найти секцию и тренера',
                'text' => 'Выбирай секции по формату, возрасту, расписанию и условиям набора.',
                'url' => route('sports-sections.index'),
                'cta' => 'Смотреть секции',
                'requirement' => null,
            ],
            [
                'icon' => 'ti-users-group',
                'title' => 'Найти или создать команду',
                'text' => 'Собирай постоянный состав, приглашай игроков и участвуй в играх командой.',
                'url' => route('teams.index'),
                'cta' => 'Перейти к командам',
                'requirement' => $commonRequirement,
            ],
        ],
        UserParticipationRoleEnum::COACH->value => [
            [
                'icon' => 'ti-whistle',
                'title' => 'Открыть свою секцию',
                'text' => 'Создай страницу секции, укажи расписание и условия набора и начинай собирать игроков.',
                'url' => route('account.sports-sections.create'),
                'cta' => 'Создать секцию',
                'requirement' => $commonRequirement,
            ],
            [
                'icon' => 'ti-calendar-event',
                'title' => 'Провести тренировку',
                'text' => 'Создай тренировку, выбери площадку и собери участников.',
                'url' => route('events.wizard'),
                'cta' => 'Создать мероприятие',
                'requirement' => $commonRequirement,
            ],
            [
                'icon' => 'ti-users-group',
                'title' => 'Работать с командой',
                'text' => 'Создавай состав, приглашай игроков и связывай команду с тренировочным процессом.',
                'url' => route('teams.index'),
                'cta' => 'Перейти к командам',
                'requirement' => $commonRequirement,
            ],
        ],
        UserParticipationRoleEnum::VENUE_RELATED->value => [
            [
                'icon' => 'ti-building-stadium',
                'title' => 'Добавить площадку',
                'text' => 'Создай карточку площадки, укажи условия, расписание и данные для аренды.',
                'url' => route('venues.create'),
                'cta' => 'Добавить площадку',
                'requirement' => $commonRequirement,
            ],
            [
                'icon' => 'ti-calendar-dollar',
                'title' => 'Работать с бронированиями',
                'text' => 'Настраивай расписание и стоимость и принимай заявки после подтверждения управления площадкой.',
                'url' => route('venues.index'),
                'cta' => 'Смотреть площадки',
                'requirement' => $commonRequirement,
            ],
            [
                'icon' => 'ti-calendar-event',
                'title' => 'Привлекать мероприятия',
                'text' => 'Площадка может использоваться в играх, тренировках и турнирах организаторов.',
                'url' => route('events.index'),
                'cta' => 'Смотреть мероприятия',
                'requirement' => null,
            ],
        ],
        UserParticipationRoleEnum::ORGANIZER->value => [
            [
                'icon' => 'ti-calendar-event',
                'title' => 'Создать игру или тренировку',
                'text' => 'Выбери формат, время и площадку, настрой набор участников и управляй мероприятием.',
                'url' => route('events.wizard'),
                'cta' => 'Создать мероприятие',
                'requirement' => $commonRequirement,
            ],
            [
                'icon' => 'ti-trophy',
                'title' => 'Организовать турнир',
                'text' => 'Создай турнир и управляй участниками, расписанием и игровым процессом.',
                'url' => route('tournaments.create'),
                'cta' => 'Создать турнир',
                'requirement' => $commonRequirement,
            ],
            [
                'icon' => 'ti-building-stadium',
                'title' => 'Подобрать площадку',
                'text' => 'Найди подходящий зал или уличную площадку по расположению и условиям.',
                'url' => route('venues.index'),
                'cta' => 'Найти площадку',
                'requirement' => null,
            ],
        ],
        UserParticipationRoleEnum::REFEREE->value => [
            [
                'icon' => 'ti-whistle',
                'title' => 'Найти мероприятия',
                'text' => 'Посмотри текущие игры и тренировки, где может понадобиться судейство.',
                'url' => route('events.index'),
                'cta' => 'Смотреть мероприятия',
                'requirement' => null,
            ],
            [
                'icon' => 'ti-trophy',
                'title' => 'Следить за турнирами',
                'text' => 'Открывай текущие и будущие турниры и следи за их расписанием.',
                'url' => route('tournaments.index'),
                'cta' => 'Смотреть турниры',
                'requirement' => null,
            ],
            [
                'icon' => 'ti-adjustments',
                'title' => 'Настроить роль судьи',
                'text' => 'Проверь данные и настройки, связанные с ролью судьи в твоём профиле.',
                'url' => route('account.participation-role', ['role' => UserParticipationRoleEnum::REFEREE->value]),
                'cta' => 'Настроить роль',
                'requirement' => null,
            ],
        ],
        UserParticipationRoleEnum::STATISTICIAN->value => [
            [
                'icon' => 'ti-chart-bar',
                'title' => 'Открыть игры',
                'text' => 'Найди матчи и мероприятия, для которых можно вести игровую статистику.',
                'url' => route('events.index'),
                'cta' => 'Смотреть мероприятия',
                'requirement' => null,
            ],
            [
                'icon' => 'ti-trophy',
                'title' => 'Открыть турниры',
                'text' => 'Посмотри соревнования и их игровой контекст.',
                'url' => route('tournaments.index'),
                'cta' => 'Смотреть турниры',
                'requirement' => null,
            ],
            [
                'icon' => 'ti-adjustments',
                'title' => 'Настроить роль статиста',
                'text' => 'Проверь данные и настройки роли статиста в своём профиле.',
                'url' => route('account.participation-role', ['role' => UserParticipationRoleEnum::STATISTICIAN->value]),
                'cta' => 'Настроить роль',
                'requirement' => null,
            ],
        ],
        UserParticipationRoleEnum::MEDIA->value => [
            [
                'icon' => 'ti-camera',
                'title' => 'Найти события для съёмки',
                'text' => 'Посмотри игры, тренировки и другие события, вокруг которых можно создавать контент.',
                'url' => route('events.index'),
                'cta' => 'Смотреть мероприятия',
                'requirement' => null,
            ],
            [
                'icon' => 'ti-users-group',
                'title' => 'Посмотреть команды',
                'text' => 'Открывай команды и профили участников, с которыми можно работать как медиа.',
                'url' => route('teams.index'),
                'cta' => 'Смотреть команды',
                'requirement' => null,
            ],
            [
                'icon' => 'ti-adjustments',
                'title' => 'Настроить роль медиа',
                'text' => 'Проверь данные и настройки, связанные с медиа-ролью в профиле.',
                'url' => route('account.participation-role', ['role' => UserParticipationRoleEnum::MEDIA->value]),
                'cta' => 'Настроить роль',
                'requirement' => null,
            ],
        ],
    ];

    $exploreActions = [
        [
            'icon' => 'ti-ball-basketball',
            'title' => 'Игры и тренировки',
            'text' => 'Посмотри, где и когда играют участники портала.',
            'url' => route('events.index'),
            'cta' => 'Смотреть мероприятия',
            'requirement' => null,
        ],
        [
            'icon' => 'ti-building-stadium',
            'title' => 'Площадки',
            'text' => 'Найди баскетбольные залы и уличные площадки.',
            'url' => route('venues.index'),
            'cta' => 'Смотреть площадки',
            'requirement' => null,
        ],
        [
            'icon' => 'ti-users-group',
            'title' => 'Команды',
            'text' => 'Познакомься с командами и участниками сообщества.',
            'url' => route('teams.index'),
            'cta' => 'Смотреть команды',
            'requirement' => null,
        ],
    ];
@endphp

@extends('theme::layouts.app', [
    'title' => 'Добро пожаловать в MSKBA',
    'metaDescription' => 'Аккаунт MSKBA готов. Выберите первое действие и продолжите настройку профиля.',
])

@section('styles')
    <link rel="stylesheet" href="{{ asset('css/acquisition-success.css') }}">
@endsection

@section('content')
    <section class="acquisition-success">
        <div class="inner acquisition-success__inner">
            <header class="acquisition-success__hero">
                <span class="acquisition-success__check" aria-hidden="true"><i class="ti ti-check"></i></span>
                <div>
                    <span class="acquisition-success__eyebrow">Готово</span>
                    <h1>Добро пожаловать</h1>
                    <p>
                        @if($activeRoles->isEmpty())
                            Аккаунт готов. Роль пока не выбрана — можно спокойно посмотреть портал и настроить её позже.
                        @else
                            Аккаунт готов. Ниже мы собрали действия отдельно по каждой из твоих ролей — так проще сразу понять, с чего начать.
                        @endif
                    </p>
                </div>
            </header>

            @unless($isConfirmed)
                <aside class="acquisition-success__verification">
                    <div class="acquisition-success__verification-icon"><i class="ti ti-shield-check" aria-hidden="true"></i></div>
                    <div>
                        <strong>Следующий полезный шаг — подтвердить аккаунт</strong>
                        <p>Открытые разделы уже доступны, а для некоторых действий от своего имени понадобится подтверждённый аккаунт.</p>
                    </div>
                    <div class="acquisition-success__verification-actions">
                        <a class="btn btn--primary btn--sm" href="{{ $confirmationUrl }}">Подтвердить аккаунт</a>
                        <a class="acquisition-success__help-link" href="{{ $confirmationHelpUrl }}">Как это работает?</a>
                    </div>
                </aside>
            @endunless

            <div class="acquisition-success__heading">
                <span class="acquisition-success__eyebrow">Что дальше</span>
                <h2>Доступные действия</h2>
            </div>

            <div class="acquisition-success__role-groups">
                @forelse($activeRoles as $role)
                    @php $actions = $actionsByRole[$role->value] ?? []; @endphp
                    <section class="acquisition-success-role">
                        <header class="acquisition-success-role__header">
                            <div>
                                <span class="acquisition-success__eyebrow">Роль · {{ $role->label() }}</span>
                                <h2>Доступные действия</h2>
                            </div>
                            <p>{{ $role->description() }}</p>
                        </header>

                        <div class="acquisition-success__actions">
                            @foreach($actions as $index => $action)
                                <article class="acquisition-success-card {{ $index === 0 ? 'acquisition-success-card--primary' : '' }}">
                                    <div class="acquisition-success-card__top">
                                        <span class="acquisition-success-card__number">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                                        <span class="acquisition-success-card__icon"><i class="ti {{ $action['icon'] }}" aria-hidden="true"></i></span>
                                    </div>
                                    <h3>{{ $action['title'] }}</h3>
                                    <p>{{ $action['text'] }}</p>

                                    @if($action['requirement'])
                                        <div class="acquisition-success-card__requirement {{ $action['requirement']['done'] ? 'is-done' : '' }}">
                                            <i class="ti {{ $action['requirement']['done'] ? 'ti-circle-check' : 'ti-lock' }}" aria-hidden="true"></i>
                                            <span>
                                                {{ $action['requirement']['done'] ? 'Требование выполнено: аккаунт подтверждён' : $action['requirement']['label'] }}
                                                @unless($action['requirement']['done'])
                                                    · <a href="{{ $action['requirement']['help_url'] }}">как подтвердить</a>
                                                @endunless
                                            </span>
                                        </div>
                                    @endif

                                    <a class="btn {{ $index === 0 ? 'btn--primary' : 'btn--secondary-bordered' }} btn--sm" href="{{ $action['url'] }}">
                                        {{ $action['cta'] }}
                                        <i class="ti ti-arrow-right" aria-hidden="true"></i>
                                    </a>
                                </article>
                            @endforeach
                        </div>
                    </section>
                @empty
                    <section class="acquisition-success-role acquisition-success-role--explore">
                        <header class="acquisition-success-role__header">
                            <div>
                                <span class="acquisition-success__eyebrow">Роль пока не выбрана</span>
                                <h2>Можно начать с просмотра</h2>
                            </div>
                            <p>Роль можно добавить в любой момент. Пока доступны основные открытые разделы портала.</p>
                        </header>

                        <div class="acquisition-success__actions">
                            @foreach($exploreActions as $index => $action)
                                <article class="acquisition-success-card {{ $index === 0 ? 'acquisition-success-card--primary' : '' }}">
                                    <div class="acquisition-success-card__top">
                                        <span class="acquisition-success-card__number">{{ str_pad((string) ($index + 1), 2, '0', STR_PAD_LEFT) }}</span>
                                        <span class="acquisition-success-card__icon"><i class="ti {{ $action['icon'] }}" aria-hidden="true"></i></span>
                                    </div>
                                    <h3>{{ $action['title'] }}</h3>
                                    <p>{{ $action['text'] }}</p>
                                    <a class="btn {{ $index === 0 ? 'btn--primary' : 'btn--secondary-bordered' }} btn--sm" href="{{ $action['url'] }}">
                                        {{ $action['cta'] }}
                                        <i class="ti ti-arrow-right" aria-hidden="true"></i>
                                    </a>
                                </article>
                            @endforeach
                        </div>
                    </section>
                @endforelse
            </div>

            <footer class="acquisition-success__footer">
                <div>
                    <strong>Роли можно менять в любое время</strong>
                    <span>Добавляй новые роли или отключай ненужные — системные права доступа от этого не меняются.</span>
                </div>
                <a class="btn btn--secondary btn--sm" href="{{ route('account.roles') }}">Настроить роли</a>
            </footer>
        </div>
    </section>
@endsection