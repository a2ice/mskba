{{--
    MSKBA Home Page Blade Template
    CSS: resources/themes/mskba_streetball/css/mskba-home.css через Vite theme input
--}}

@php
    $events = $events ?? [
        [
            'date' => '24 мая',
            'city' => 'Москва',
            'title' => 'MSKBA Street Cup',
            'format' => '3x3',
            'image' => asset('images/home/events/street-cup.jpg'),
            'url' => '#',
        ],
        [
            'date' => '7 июня',
            'city' => 'Москва',
            'title' => 'Summer Madness',
            'format' => '5x5',
            'image' => asset('images/home/events/summer-madness.jpg'),
            'url' => '#',
        ],
        [
            'date' => '21 июня',
            'city' => 'Москва',
            'title' => 'Rookies Challenge',
            'format' => '3x3',
            'image' => asset('images/home/events/rookies.jpg'),
            'url' => '#',
        ],
    ];

    $media = $media ?? [
        [
            'title' => 'Финал MSKBA Cup',
            'duration' => '03:12',
            'image' => asset('images/home/media/final.jpg'),
            'url' => '#',
        ],
        [
            'title' => 'Улицы. Игра. Мы.',
            'duration' => '01:58',
            'image' => asset('images/home/media/street.jpg'),
            'url' => '#',
        ],
    ];

    $partners = $partners ?? ['GORILLA', 'JÖGEL', 'BASKETBALL STORE', '2K SPORT', 'SLAM', 'NIKE'];
@endphp

@extends('theme::layouts.app', ['title' => 'MSKBA — Московская баскетбольная ассоциация'])

@section('title', 'MSKBA — Московская баскетбольная ассоциация')

@section('content')
    <main class="mskba-page">
        @include('theme::partials.header')

        <section class="hero" id="top">
            <div class="hero__backdrop" aria-hidden="true"></div>
            <div class="hero__paint" aria-hidden="true"></div>

            <div class="hero__content">
                <p class="section-kicker">Streetball culture</p>

                <h1 class="hero__title">
                    <span>MSKB</span><em>A</em>
                </h1>

                <p class="hero__subtitle">Московская баскетбольная ассоциация</p>

                <p class="hero__text">
                    MSKBA — больше чем баскетбол.<br>
                    Это улицы. Это стиль жизни.<br>
                    Это мы.
                </p>

                <a href="#join" class="btn btn--primary btn--xl">
                    Стать частью движения
                    <span class="icon icon--arrow-right" aria-hidden="true"></span>
                </a>

                <div class="hero-socials" aria-label="Социальные сети">
                    <a href="#">Instagram</a>
                    <a href="#">Telegram</a>
                    <a href="#">VK</a>
                    <a href="#">YouTube</a>
                </div>
            </div>

            <div class="hero__player" aria-hidden="true">
                <img src="{{ asset('images/home/hero-player.png') }}" alt="">
            </div>

            <aside class="hero-stats" aria-label="Статистика MSKBA">
                <div class="hero-stat">
                    <strong>5+</strong>
                    <span>лет движению</span>
                </div>
                <div class="hero-stat">
                    <strong>200+</strong>
                    <span>турниров в год</span>
                </div>
                <div class="hero-stat">
                    <strong>10K+</strong>
                    <span>игроков</span>
                </div>
            </aside>

            <a href="#events" class="mouse-scroll" aria-label="Прокрутить к событиям">
                <span class="mouse-scroll__wheel"></span>
            </a>
        </section>

        <section class="events-section" id="events">
            <div class="section-head">
                <div>
                    <p class="section-kicker">Турниры</p>
                    <h2>Ближайшие<br>события</h2>
                </div>

                <a href="#" class="link-arrow">
                    Все турниры
                    <span class="icon icon--arrow-right" aria-hidden="true"></span>
                </a>
            </div>

            <div class="events-layout">
                <div class="event-cards">
                    @foreach($events as $event)
                        <article class="event-card" style="--event-bg: url('{{ $event['image'] }}')">
                            <div class="event-card__meta">
                                <span class="event-card__date">{{ $event['date'] }}</span>
                                <span class="event-card__city">
                                    <span class="icon icon--pin" aria-hidden="true"></span>
                                    {{ $event['city'] }}
                                </span>
                            </div>

                            <div class="event-card__body">
                                <h3>{{ $event['title'] }}</h3>
                                <strong>{{ $event['format'] }}</strong>
                            </div>

                            <a href="{{ $event['url'] }}" class="btn btn--dark btn--sm">
                                Подробнее
                                <span class="icon icon--arrow-right" aria-hidden="true"></span>
                            </a>
                        </article>
                    @endforeach
                </div>

                <article class="join-panel" id="join">
                    <p>Регистрация</p>
                    <h3>Открыта</h3>
                    <span>Собери команду<br>и заяви о себе</span>
                    <a href="#" class="round-arrow" aria-label="Перейти к регистрации">
                        <span class="icon icon--arrow-right" aria-hidden="true"></span>
                    </a>
                </article>
            </div>
        </section>

        <section class="media-about-grid" id="media">
            <div class="media-block">
                <div class="section-head section-head--compact">
                    <div>
                        <p class="section-kicker">Медиа</p>
                        <h2>Видео<br>и атмосфера</h2>
                    </div>
                    <a href="#" class="link-arrow">Смотреть все <span class="icon icon--arrow-right" aria-hidden="true"></span></a>
                </div>

                <div class="featured-video" style="--video-bg: url('{{ asset('images/home/media/cypher.jpg') }}')">
                    <button class="play-button" type="button" aria-label="Смотреть видео">
                        <span class="icon icon--play" aria-hidden="true"></span>
                    </button>
                </div>

                <h3 class="featured-video__title">MSKBA Cypher 2024</h3>
                <p class="featured-video__duration">02:45</p>
            </div>

            <div class="media-list" aria-label="Другие видео">
                @foreach($media as $item)
                    <a href="{{ $item['url'] }}" class="media-item">
                        <span class="media-item__thumb" style="--thumb-bg: url('{{ $item['image'] }}')">
                            <span class="play-button play-button--sm">
                                <span class="icon icon--play" aria-hidden="true"></span>
                            </span>
                        </span>
                        <span class="media-item__content">
                            <strong>{{ $item['title'] }}</strong>
                            <small>{{ $item['duration'] }}</small>
                        </span>
                        <span class="icon icon--arrow-right" aria-hidden="true"></span>
                    </a>
                @endforeach
            </div>

            <article class="about-panel" id="about">
                <div class="about-panel__content">
                    <p class="section-kicker">О движении</p>
                    <h2>Баскетбол<br>— это <span>улицы</span></h2>
                    <p>
                        Мы объединяем игроков, команды и болельщиков. Развиваем культуру стритбола в Москве и за её пределами.
                        Создаём площадки для настоящих.
                    </p>
                    <a href="#" class="btn btn--ghost btn--wide">
                        Подробнее о нас
                        <span class="icon icon--arrow-right" aria-hidden="true"></span>
                    </a>
                </div>
                <img class="about-panel__image" src="{{ asset('images/home/about-player.png') }}" alt="Игрок MSKBA">
            </article>
        </section>

        <section class="partners-section" aria-label="Партнёры">
            <p class="section-kicker">Партнёры</p>
            <div class="partners-list">
                @foreach($partners as $partner)
                    <span>{{ $partner }}</span>
                @endforeach
            </div>
        </section>
    </main>
@endsection
