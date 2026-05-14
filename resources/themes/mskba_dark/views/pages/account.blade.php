@extends('theme::layouts.app', ['title' => 'Аккаунт'])

@section('content')
<section class="first-screen">
    <div class="inner">

        @include('theme::partials.breadcrumbs', ['title' => 'Личный кабинет'])

        <h1>Личный кабинет</h1>

        <p>Добро пожаловать, {{ auth()->user()->login }}!</p> <br/>
        <a href="{{ route('auth.logout') }}" class="btn btn--primary btn--sm">← Выйти</a>

    </div>
</section>
@endsection