@extends('theme::layouts.app', ['title' => 'Профиль'])

@section('content')
    <section id="profile" class="profile-section py-5">
        <div class="container">
            <h1 class="mb-4">Профиль пользователя</h1>

            @auth
                <div class="card mb-4">
                    <div class="card-body">
                        <h5 class="card-title">Имя пользователя: {{ auth()->user()->username }}</h5>
                        <p class="card-text">Email: {{ auth()->user()->email }}</p>
                        <p class="card-text">Дата регистрации: {{ auth()->user()->created_at->format('d.m.Y H:i') }}</p>
                    </div>
                </div>
                <hr>
                <form method="POST" action="{{ route('auth.logout') }}">
                    @csrf
                    <button type="submit" class="btn btn-outline-secondary">Выйти из аккаунта</button>
                </form>
            @else
                <div class="alert alert-warning">
                    Вы не авторизованы. Пожалуйста, <a href="{{ route('welcome') }}">войдите</a>, чтобы увидеть свой профиль.
                </div>
            @endauth
        </div>
    </section>
@endsection
