@extends('theme::layouts.app', ['title' => 'MSKBA — Московская баскетбольная ассоциация'])

@section('title', 'MSKBA — Московская баскетбольная ассоциация')

@section('content')
    <div class="mskba-page">
        <section class="hero" id="top">
            <div class="hero__backdrop" aria-hidden="true"></div>

            <div class="hero__scene" aria-hidden="true">
                <img class="hero__layer hero__layer--clouds" src="{{ asset('images/home/streetball/welcome-screen/clouds.png') }}" alt="">
                <img class="hero__layer hero__layer--paint-rear" src="{{ asset('images/home/streetball/welcome-screen/paint-rear.png') }}" alt="">
                <img class="hero__layer hero__layer--city" src="{{ asset('images/home/streetball/welcome-screen/city.png') }}" alt="">
                <img class="hero__layer hero__layer--paint-front" src="{{ asset('images/home/streetball/welcome-screen/paint-front.png') }}" alt="">
                <img class="hero__layer hero__layer--player" src="{{ asset('images/home/streetball/welcome-screen/player.png') }}" alt="">
                <img class="hero__layer hero__layer--rim" src="{{ asset('images/home/streetball/welcome-screen/rim.png') }}" alt="">
            </div>

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
    </div>
@endsection
