@extends('theme::layouts.app', ['title' => 'Авторизация'])

@section('content')

    <section id="login" class="login-section first-screen px-1">
        <div class="inner">
            <div class="section-heading">
                <h1 class="mb-4">Авторизация</h1>
            </div>

            <div class="section-content">
                @auth
                    <div class="alert alert-success d-flex justify-content-between align-items-center">
                        <span>Вы вошли как {{ auth()->user()->username }}.</span>
                        <form method="POST" action="{{ route('auth.logout') }}">
                            @csrf
                            <button type="submit" class="btn btn--secondary-bordered btn--sm">Выйти</button>
                        </form>
                    </div>
                @else
                    @include('theme::partials.auth.inline-login')
                @endauth
            </div>
        </div>
    </section>
@endsection
