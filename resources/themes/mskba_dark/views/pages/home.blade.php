@extends('theme::layouts.app', ['title' => 'Главная страница'])

@section('content')

@php
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
        ],
        [
            'title' => 'Проверенное сообщество',
            'description' => 'Реальные игроки, честные рейтинги и отзывы',
            'icon' => 'shield',
        ],
    ];

    $liveStats = [
        ['value' => '84', 'label' => 'игрока онлайн', 'icon' => 'group'],
        ['value' => '12', 'label' => 'активных игр', 'icon' => 'ball'],
        ['value' => '5', 'label' => 'турниров на неделе', 'icon' => 'trophy'],
    ];
@endphp


<section class="home-welcome first-screen">
    <div class="home-welcome__image">
        <img src="{{ asset('images/home-court.png') }}" role="img" aria-label="Баскетбольная площадка">
    </div>

    <div class="home-welcome__overlay"></div>

    <div class="home-welcome__content inner">
        <div class="home-welcome__main">
            <div class="home-welcome__copy">
                <p class="home-welcome__eyebrow">
                    <i class="ti ti-ball-basketball icon" aria-hidden="true"></i>
                    <span>Площадки • Игры • Турниры</span>
                </p>

                <h1 class="home-welcome__title">
                    Играй в баскетбол<br>
                    <span class="home-welcome__title-secondline">где и когда удобно</span>
                </h1>

                <p class="home-welcome__subtitle">
                    Площадки, игры и турниры<br>
                    в Москве и области
                </p>

                <div class="home-welcome__actions" aria-label="Основные действия">
                    <a class="btn btn--primary btn--lg home-cta js-handler has-arrow-right-hover" data-handler="modal" data-modal-action="open" data-modal-target="venues" href="#venues">
                        <i class="ti ti-ball-basketball icon" aria-hidden="true"></i>
                        <span class="btn__stack">
                            <span class="btn__title">Поиграть</span>
                            <span class="btn__subtitle">Найти игру и присоединиться</span>
                        </span>
                        <i class="ti ti-arrow-right icon" aria-hidden="true"></i>
                    </a>

                    <a class="btn btn--secondary btn--lg home-cta js-handler has-arrow-right-hover" data-handler="modal" data-modal-action="open" data-modal-target="create-game" href="#venues">
                        <i class="ti ti-plus icon" aria-hidden="true"></i>
                        <span class="btn__stack">
                            <span class="btn__title">Добавить площадку</span>
                            <span class="btn__subtitle">Разместить площадку</span>
                        </span>
                        <i class="ti ti-arrow-right icon" aria-hidden="true"></i>
                    </a>
                </div>

                <a class="home-welcome__how link-action has-arrow-right-hover" href="#highlights">
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
