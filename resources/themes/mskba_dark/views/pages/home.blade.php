@extends('theme::layouts.app', ['title' => 'Главная страница'])

@section('content')

<section class="home-hero">

    <div class="home-hero__image">
        <img src="{{ asset('images/home-court.png') }}" role="img" aria-label="Баскетбольная площадка">
    </div>

    <div class="home-hero__content">
        <div class="home-hero__copy">

            <h1>
                Играй в баскетбол<br>
                где и когда удобно
            </h1>
            <p class="home-hero__subtitle">
                Площадки, игры и турниры —
                всё для баскетбола в Москве и области
            </p>

            <div class="home-hero__actions" aria-label="Основные действия">
                <a class="btn btn--primary btn--lg" href="#venues">
                    <span>Найти площадку</span>
                    <span class="btn__icon" aria-hidden="true">→</span>
                </a>
                <a class="btn btn--secondary btn--lg" href="#venues">
                    <span>Создать игру</span>
                    <span class="btn__icon btn__icon--plus" aria-hidden="true">+</span>
                </a>
            </div>

        </div>
    </div>


</section>

@endsection