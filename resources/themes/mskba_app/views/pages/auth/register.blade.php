@extends('theme::layouts.app')

@section('content')
    <section class="section" data-auth-open-on-load="register" aria-label="Регистрация в MSKBA">
        <p class="eyebrow accent">MSKBA / СОЗДАТЬ ПРОФИЛЬ</p>
        <h1>Присоединяйся к игре.</h1>
        <p class="section-description">Регистрация открывается в диалоговом окне.</p>
        <p class="actions"><a class="button primary" href="{{ route('register') }}" data-auth-trigger data-auth-mode="register">Зарегистрироваться</a></p>
    </section>
@endsection
