@extends('theme::layouts.app', ['title' => 'Аккаунт'])

@section('content')
<section class="account-hero" style="margin-top:100px">
    <div class="inner">

        <h1>Личный кабинет</h1>

        <p>Добро пожаловать, {{ auth()->user()->login }}!</p> <br/>
        <a href="{{ route('auth.logout') }}" class="btn btn--primary btn--sm">← Выйти</a>

    </div>
</section>
@endsection