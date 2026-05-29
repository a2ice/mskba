@extends('theme::layouts.app', ['title' => 'Аккаунт'])

@section('content')
    <section id="account" class="account-section py-5">
        <div class="container">
            <div class="section-heading">
                <h1 class="mb-4">Аккаунт</h1>
            </div>

            <div class="section-content">
                @auth

                        <div class="row">
                            <div class="col-md-3">
                                <div class="card mb-4">
                                    <div class="card-body text-center">
                                        <div class="avatar-wrapper mb-3">
                                            <img src="{{ $user->profile->avatar_url }}" alt="Аватар" class="rounded-circle avatar-lg">
                                        </div>
                                        <h5 class="card-title">{{ $user->profile->first_name }} {{ $user->profile->last_name }}</h5>
                                        <p class="card-text">{{ $user->email }}</p>
                                    </div>
                                </div>
                                <div class="card mb-4">
                                    <div class="card-body">
                                        <ul class="sidebar-nav nav flex-column">
                                            <li class="nav-item">
                                                <a href="{{ route('account') }}" class="nav-link active">Профиль</a>
                                            </li>
                                            <li class="nav-item">
                                                <a href="{{ route('account.contracts') }}" class="nav-link">Контракты</a>
                                            </li>
                                            <hr>
                                            <li class="nav-item">
                                                <a href="{{ route('auth.logout') }}" class="nav-link">Выйти</a>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-9">
                                <div class="card mb-4">
                                    <div class="card-body">
                                        <ul class="list-unstyled mb-3">
                                            <li class="list-unstyled mb-3">
                                                Логин: 
                                                <span class="fw-bold">{{ $user->username }}</span>
                                            </li>
                                            <li class="list-unstyled mb-3">
                                                Статус: 
                                                <span class="fw-bold">{{ $user->status->label() }}</span>
                                            </li>
                                            <li class="list-unstyled mb-3">
                                                Имя: 
                                                <span class="fw-bold">{{ $user->profile->first_name }}</span>
                                            </li>
                                            <li class="list-unstyled mb-3">
                                                Фамилия: 
                                                <span class="fw-bold">{{ $user->profile->last_name }}</span>
                                            </li>
                                            </li>
                                            <li class="list-unstyled mb-3">
                                                Отчество: 
                                                <span class="fw-bold">{{ $user->profile->middle_name }}</span>
                                            </li>
                                            </li>
                                            <li class="list-unstyled mb-3">
                                                Пол: 
                                                <span class="fw-bold">{{ $user->profile->gender->label() }}</span>
                                            </li>
                                            </li>
                                            <li class="list-unstyled mb-3">
                                                Возраст: 
                                                <span class="fw-bold">{{ $user->profile->age }}</span>
                                            </li>
                                            <li class="list-unstyled mb-3">
                                                Роль: 
                                                <span class="fw-bold">{{ $user->system_role->label() }}</span>
                                            </li>
                                            </li>
                                            <li class="list-unstyled mb-3">
                                                Роли в проекте: 
                                                <span class="fw-bold">{{ $user->participation_role_labels }}</span>
                                            </li>
                                            <li class="list-unstyled mb-3">
                                                Email: 
                                                <span class="fw-bold">{{ $user->email }}</span>
                                            </li>
                                            <li class="list-unstyled mb-3">
                                                Дата регистрации: 
                                                <span class="fw-bold">{{ $user->created_at->format('d.m.Y H:i') }}</span>
                                            </li>
                                        </ul>
                                    </div>
                                </div>
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
        </div>
    </section>
@endsection
