@extends('theme::layouts.app', ['title' => 'Вход в аккаунт'])

@section('content')
<section class="account-hero" style="margin-top:100px">
    <div class="inner">
        <h1>Регистрация</h1>
        @if (auth()->check())
            <p>Вы уже авторизованы как {{ auth()->user()->login }}.</p>
            <br/>
            <a href="{{ route('account') }}" class="btn btn--primary btn--sm">Перейти в личный кабинет</a>
            <a href="{{ route('auth.logout') }}" class="btn btn--secondary btn--sm">Выйти из аккаунта</a>
        @else
            Форма "Полная регистрация"
        @endif
    </div>
</section>
@endsection