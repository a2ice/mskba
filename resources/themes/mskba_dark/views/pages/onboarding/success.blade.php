@php
    use App\Modules\Acquisition\Domain\Enums\AcquisitionPersonaEnum;

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

    $actions = match ($persona) {
        AcquisitionPersonaEnum::PLAYER => [
            [
                'icon' => 'ti-ball-basketball',
                'title' => 'Найти игру или тренировку',
                'text' => 'Посмотрите ближайшие игры и тренировки, выберите подходящий формат и присоединяйтесь к баскетболу рядом.',
                'url' => route('events.index'),
                'cta' => 'Смотреть мероприятия',
                'requirement' => null,
            ],
            [
                'icon' => 'ti-users-group',
                'title' => 'Найти или создать команду',
                'text' => 'Собирайте постоянный состав, приглашайте игроков и участвуйте в играх уже как команда.',
                'url' => route('teams.index'),
                'cta' => 'Смотреть команды',
                'requirement' => $commonRequirement,
            ],
            [
                'icon' => 'ti-trophy',
                'title' => 'Участвовать в турнирах',
                'text' => 'Следите за наборами, форматами и расписанием турниров MSKBA.',
                'url' => route('tournaments.index'),
                'cta' => 'Смотреть турниры',
                'requirement' => null,
            ],
            [
                'icon' => 'ti-whistle',
                'title' => 'Найти секцию и тренера',
                'text' => 'Выбирайте секции по формату, возрасту, расписанию и условиям набора.',
                'url' => route('sports-sections.index'),
                'cta' => 'Смотреть секции',
                'requirement' => null,
            ],
        ],
        AcquisitionPersonaEnum::COACH => [
            [
                'icon' => 'ti-whistle',
                'title' => 'Открыть свою секцию',
                'text' => 'Создайте страницу секции, задайте условия набора, расписание и начинайте собирать игроков.',
                'url' => route('account.sports-sections.create'),
                'cta' => 'Создать секцию',
                'requirement' => $commonRequirement,
            ],
            [
                'icon' => 'ti-calendar-event',
                'title' => 'Провести тренировку',
                'text' => 'Создайте тренировку или игровую тренировку, выберите площадку и соберите участников.',
                'url' => route('events.wizard'),
                'cta' => 'Создать мероприятие',
                'requirement' => $commonRequirement,
            ],
            [
                'icon' => 'ti-users-group',
                'title' => 'Работать с командой',
                'text' => 'Создавайте состав, приглашайте игроков и связывайте команду с тренировочным процессом.',
                'url' => route('teams.index'),
                'cta' => 'Перейти к командам',
                'requirement' => $commonRequirement,
            ],
            [
                'icon' => 'ti-search',
                'title' => 'Посмотреть другие секции',
                'text' => 'Изучите, как уже оформлены секции и какие форматы тренировок представлены на портале.',
                'url' => route('sports-sections.index'),
                'cta' => 'Каталог секций',
                'requirement' => null,
            ],
        ],
        AcquisitionPersonaEnum::VENUE => [
            [
                'icon' => 'ti-building-stadium',
                'title' => 'Добавить площадку',
                'text' => 'Создайте карточку площадки, укажите залы, условия, расписание и данные для аренды.',
                'url' => route('venues.create'),
                'cta' => 'Добавить площадку',
                'requirement' => $commonRequirement,
            ],
            [
                'icon' => 'ti-calendar-dollar',
                'title' => 'Принимать заявки на аренду',
                'text' => 'После подтверждения управления площадкой можно настраивать расписание, стоимость и работать с заявками.',
                'url' => route('venues.index'),
                'cta' => 'Смотреть площадки',
                'requirement' => $commonRequirement,
            ],
            [
                'icon' => 'ti-calendar-event',
                'title' => 'Привлекать мероприятия',
                'text' => 'Площадка становится частью экосистемы игр, тренировок и турниров и доступна организаторам при выборе локации.',
                'url' => route('events.index'),
                'cta' => 'Смотреть мероприятия',
                'requirement' => null,
            ],
            [
                'icon' => 'ti-map-pin',
                'title' => 'Посмотреть площадки рядом',
                'text' => 'Сравните карточки, расписание и представление других площадок на портале.',
                'url' => route('venues.index'),
                'cta' => 'Каталог площадок',
                'requirement' => null,
            ],
        ],
        AcquisitionPersonaEnum::ORGANIZER => [
            [
                'icon' => 'ti-calendar-event',
                'title' => 'Создать игру или тренировку',
                'text' => 'Выберите формат, время и площадку, настройте набор участников и управляйте мероприятием из одного сценария.',
                'url' => route('events.wizard'),
                'cta' => 'Создать мероприятие',
                'requirement' => $commonRequirement,
            ],
            [
                'icon' => 'ti-trophy',
                'title' => 'Организовать турнир',
                'text' => 'Создавайте турнир, управляйте набором, составом участников, расписанием и игровым процессом.',
                'url' => route('tournaments.create'),
                'cta' => 'Создать турнир',
                'requirement' => $commonRequirement,
            ],
            [
                'icon' => 'ti-building-stadium',
                'title' => 'Подобрать площадку',
                'text' => 'Найдите подходящий зал или уличную площадку по расположению и доступным условиям.',
                'url' => route('venues.index'),
                'cta' => 'Найти площадку',
                'requirement' => null,
            ],
            [
                'icon' => 'ti-users-group',
                'title' => 'Собрать участников и команды',
                'text' => 'Используйте команды и профили участников как основу для регулярных игр и соревнований.',
                'url' => route('teams.index'),
                'cta' => 'Смотреть команды',
                'requirement' => null,
            ],
        ],
        AcquisitionPersonaEnum::EXPLORE => [
            [
                'icon' => 'ti-ball-basketball',
                'title' => 'Игры и тренировки',
                'text' => 'Посмотрите, где и когда играют участники MSKBA.',
                'url' => route('events.index'),
                'cta' => 'Смотреть мероприятия',
                'requirement' => null,
            ],
            [
                'icon' => 'ti-building-stadium',
                'title' => 'Площадки',
                'text' => 'Найдите баскетбольные залы и уличные площадки.',
                'url' => route('venues.index'),
                'cta' => 'Смотреть площадки',
                'requirement' => null,
            ],
            [
                'icon' => 'ti-users-group',
                'title' => 'Команды',
                'text' => 'Познакомьтесь с командами и найдите подходящую для себя.',
                'url' => route('teams.index'),
                'cta' => 'Смотреть команды',
                'requirement' => null,
            ],
            [
                'icon' => 'ti-trophy',
                'title' => 'Турниры',
                'text' => 'Следите за текущими и будущими соревнованиями.',
                'url' => route('tournaments.index'),
                'cta' => 'Смотреть турниры',
                'requirement' => null,
            ],
        ],
    };
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
                    <span class="acquisition-success__eyebrow">Добро пожаловать в MSKBA</span>
                    <h1>{{ $persona->label() }} — можно начинать</h1>
                    <p>
                        @if($isConfirmed)
                            Аккаунт подтверждён. Ниже — действия, с которых удобнее всего начать именно вам.
                        @else
                            Аккаунт создан. Смотреть открытые разделы можно уже сейчас, а для действий от своего имени понадобится подтверждение аккаунта.
                        @endif
                    </p>
                </div>
            </header>

            @unless($isConfirmed)
                <aside class="acquisition-success__verification">
                    <div class="acquisition-success__verification-icon"><i class="ti ti-shield-check" aria-hidden="true"></i></div>
                    <div>
                        <strong>Следующий полезный шаг — подтвердить аккаунт</strong>
                        <p>Нужен подтверждённый основной контакт. Для игрока и тренера также используются базовые данные профиля.</p>
                    </div>
                    <div class="acquisition-success__verification-actions">
                        <a class="btn btn--primary btn--sm" href="{{ $confirmationUrl }}">Подтвердить аккаунт</a>
                        <a class="acquisition-success__help-link" href="{{ $confirmationHelpUrl }}">Как это работает?</a>
                    </div>
                </aside>
            @endunless

            <div class="acquisition-success__heading">
                <span class="acquisition-success__eyebrow">Ваши первые возможности</span>
                <h2>С чего начать</h2>
            </div>

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

            <footer class="acquisition-success__footer">
                <div>
                    <strong>Вы не привязаны к одному сценарию</strong>
                    <span>Позже можно добавить другие роли и пользоваться всеми доступными разделами портала.</span>
                </div>
                <a class="btn btn--secondary btn--sm" href="{{ route('account') }}">Перейти в аккаунт</a>
            </footer>
        </div>
    </section>
@endsection
