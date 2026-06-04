@php
    $title = 'FAQ';
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
                <p class="lead">Ответы на частые вопросы и короткие инструкции по работе с MSKBA.</p>
            </div>

            <div class="section-content">
                <div class="card mb-4">
                    <div class="card-body">
                        <h2 class="h4 mb-3">Первые шаги</h2>
                        <p class="mb-3">
                            Что сделать после регистрации: подтвердить контакт, заполнить профиль и перейти к доступным возможностям личного кабинета.
                        </p>
                        <a href="{{ route('faq.welcome') }}" class="btn btn--primary btn--sm">Открыть</a>
                    </div>
                </div>
            </div>
        </div>
    </section>
@endsection
