@extends('theme::layouts.app')

@section('content')
    <section class="section" data-auth-open-on-load="login" aria-label="Вход в MSKBA">
        <p class="eyebrow accent">MSKBA / АККАУНТ</p>
        <h1>Войти в игру.</h1>
        <p class="section-description">Авторизация открывается в диалоговом окне.</p>
        <p class="actions"><a class="button primary" href="{{ route('login') }}" data-auth-trigger>Открыть вход</a></p>
    </section>
@endsection
