@extends('theme::layouts.app', ['title' => 'Главная страница'])

@section('content')

@php
    $today = now((string) config('app.timezone', 'Europe/Moscow'))->toDateString();
    $todayGamesUrl = route('events.index', [
        'type' => 'games',
        'date_from' => $today,
        'date_to' => $today,
    ]);
    $createGameUrl = route('events.create', ['type' => 'game']);
    $currentGames = [
        [
            'title' => 'OPEN RUN',
            'venue' => 'Парк Горького',
            'time' => '19:30',
            'players' => '8 / 10',
            'badge' => 'Идёт',
            'badge_variant' => 'live',
            'image' => asset('images/home-court.png'),
        ],
        [
            'title' => 'STREET GAME',
            'venue' => 'Химки, Юбилейный пр-т',
            'time' => '20:00',
            'players' => '6 / 10',
            'badge' => 'Через 20 мин',
            'badge_variant' => 'soon',
            'image' => asset('images/home-court.png'),
        ],
        [
            'title' => '3X3 NIGHT',
            'venue' => 'Лужники, Северное поле',
            'time' => '21:00',
            'players' => '4 / 6',
            'badge' => 'Через 1 ч',
            'badge_variant' => 'soon',
            'image' => asset('images/home-court.png'),
        ],
    ];

    $highlightItems = [
        [
            'title' => 'Больше площадок',
            'description' => 'Находите и добавляйте площадки по всей Москве и области',
            'icon' => 'pin',
            'url' => route('venues'),
        ],
        [
            'title' => 'Легко собрать игру',
            'description' => 'Создавайте игры и находите игроков любого уровня',
            'icon' => 'group',
        ],
        [
            'title' => 'Турниры и события',
            'description' => 'Участвуйте в турнирах и следите за событиями',
            'icon' => 'trophy',
            'url' => route('tournaments.index'),
        ],
        [
            'title' => 'Проверенное сообщество',
            'description' => 'Реальные игроки, честные рейтинги и отзывы',
            'icon' => 'shield',
            'url' => route('faq.welcome'),
        ],
    ];

    $liveStats = [
        ['value' => '84', 'label' => 'игрока онлайн', 'icon' => 'group'],
        ['value' => '12', 'label' => 'активных игр', 'icon' => 'ball'],
        ['value' => '5', 'label' => 'турниров на неделе', 'icon' => 'trophy'],
    ];

    $primaryActions = [
        [
            'title' => 'Играть',
            'icon' => 'ti ti-ball-basketball',
            'modal' => 'games',
        ],
        [
            'title' => 'Площадки',
            'icon' => 'ti ti-map-pin',
            'url' => route('venues'),
        ],
        [
            'title' => 'Тренировки',
            'icon' => 'ti ti-barbell',
            'modal' => 'trainings',
        ],
        [
            'title' => 'Команды',
            'icon' => 'ti ti-users-group',
            'modal' => 'teams',
        ],
    ];
@endphp


<section class="home-welcome">
    <div class="home-welcome__image">
        <img src="{{ asset('images/home-court.png') }}" role="img" aria-label="Баскетбольная площадка">
    </div>

    <div class="home-welcome__overlay"></div>

    <div class="home-welcome__content inner">
        <div class="home-welcome__main">
            <div class="home-welcome__copy">
                <div class="home-welcome__badges" aria-label="Статистика сайта">
                    <div class="home-welcome__eyebrow" data-today-events-summary>
                        <span class="home-welcome__eyebrow-dot" aria-hidden="true"></span>
                        <a
                            href="{{ $todayGamesUrl }}"
                            data-today-events-link
                            @if($siteSummary->todayEvents === 0) hidden @endif
                        >{{ $siteSummary->todayEventsText() }}</a>
                        @auth
                            <a
                                class="site-summary-empty-action"
                                href="{{ $createGameUrl }}"
                                data-today-events-empty
                                @if($siteSummary->todayEvents > 0) hidden @endif
                            >Новая игра</a>
                        @else
                            <button
                                class="site-summary-empty-action js-handler"
                                type="button"
                                data-handler="modal"
                                data-modal-action="open"
                                data-modal-target="auth-entry-classic"
                                data-auth-redirect-url="{{ route('events.create', ['type' => 'game'], false) }}"
                                data-today-events-empty
                                @if($siteSummary->todayEvents > 0) hidden @endif
                            >Новая игра</button>
                        @endauth
                    </div>

                    <p
                        class="home-welcome__eyebrow home-welcome__eyebrow--online"
                        data-online-summary
                        @if($siteSummary->onlineUsers === 0) hidden @endif
                    >
                        <span class="home-welcome__eyebrow-dot home-welcome__eyebrow-dot--online" aria-hidden="true"></span>
                        <span><span data-online-users-count>{{ $siteSummary->onlineUsers }}</span> онлайн</span>
                    </p>
                </div>

                <h1 class="home-welcome__title">
                    Играй в баскетбол<br>
                    <span class="home-welcome__title-secondline">где и когда удобно</span>
                </h1>

                <p class="home-welcome__subtitle">
                    Площадки, игры, турниры, тренировки 
                    и самые важные баскетбольные события Москвы и области
                </p>

                <div class="home-welcome__actions" aria-label="Основные действия">
                    @foreach ($primaryActions as $action)
                        <a
                            @class([
                                'btn btn--secondary btn--lg home-cta',
                                'js-handler' => isset($action['modal']),
                            ])
                            href="{{ $action['url'] ?? '#'.$action['modal'] }}"
                            @isset($action['modal'])
                                data-handler="modal"
                                data-modal-action="open"
                                data-modal-target="{{ $action['modal'] }}"
                            @endisset
                        >
                            <i class="{{ $action['icon'] }} icon" aria-hidden="true"></i>
                            <span class="btn__title">{{ $action['title'] }}</span>
                        </a>
                    @endforeach

                </div>

                <a class="home-welcome__how link-action has-arrow-right-hover mb-5" href="#highlights">
                    <span>О проекте </span>
                    <i class="ti ti-arrow-right icon" aria-hidden="true"></i>
                </a>
            </div>
            <!--
            @include('theme::partials.home-currently-playing', [
                'games' => $currentGames,
                'stats' => $liveStats,
            ])
            -->
        </div>
    </div>
</section>

<section class="home-highlights-section inner" id="highlights">
    @include('theme::partials.home-highlights', [
        'items' => $highlightItems,
    ])
</section>

@endsection
