@php
    $title = 'Первые шаги';
@endphp

@extends('theme::layouts.app', ['title' => $title])

@section('content')
    <section class="faq-section first-screen">
        <div class="inner">
            <div class="mb-3">
                @include('theme::partials.breadcrumbs')
            </div>

            <div class="section-heading mb-4">
                <h1 class="section-title">{{ $title }}</h1>
                <p class="lead">Короткий маршрут после создания аккаунта.</p>
            </div>

            <div class="section-content">
                <div class="card mb-4">
                    <div class="card-body">
                        <h3 class="h4 mb-3">1. Подтвердите контакт</h3>
                        <p>
                            Добавьте контакт в личном кабинете и подтвердите его соответствующим способом: кодом из письма, смс или мессенджера. 
                            Подтвержденный контакт нужен для восстановления доступа и дальнейшего подтверждения профиля.
                        </p>
                        <br>
                        <a href="{{ route('account.contacts') }}" class="btn btn--secondary btn--sm">Перейти к контактам</a>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-body">
                        <h3 class="h4 mb-3">2. Заполните профиль</h3>
                        <p>
                            Необходимо минимально заполнить профиль, а именно выбрать и установить себе роль на проекте. Это позволит получать релевантные уведомления и рекомендации по следующим шагам.
                        </p>
                        <br>
                        <a href="{{ route('account') }}" class="btn btn--secondary btn--sm">Открыть профиль</a>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-body">
                        <h3 class="h4 mb-3">3. Следите за уведомлениями</h3>
                        <p>
                            После установки роли в центре уведомлений могут появиться системные сообщения и подсказки по следующим действиям.
                        </p>
                        <br>
                        <a href="{{ route('account.notifications') }}" class="btn btn--secondary btn--sm">Открыть уведомления</a>
                    </div>
                </div>

                <div class="card mb-4">
                    <div class="card-body">
                        <h3 class="h4 mb-3">4. Подтвердите аккаунт</h3>
                        <p>
                            Если все необходимые условия выполнены Вы сможете подтвердить аккаунт. Это даст вам доступ к необходимому функционалу и возможностям платформы согласно выбранно     роли.
                        </p>
                        <br>
                        <a href="{{ route('account') }}" class="btn btn--secondary btn--sm">Открыть профиль</a>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
