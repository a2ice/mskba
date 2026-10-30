@extends('theme::layouts.account', ['title' => 'Добавить площадку'])
@section('account-heading')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => 'Добавить площадку',
        'intro' => 'Расскажите сообществу о месте для игры в баскетбол.',
    ])
@endsection
@section('account-content')
    <section class="panel app-venue-create__page">
        @auth
            @include('theme::pages.venues.partials.create-form', ['instance' => 'venue-page', 'types' => $types, 'metros' => $metros])
        @else
            <p>Чтобы добавить площадку, войдите в аккаунт. После входа вернитесь на эту страницу.</p>
            <a class="button primary" href="{{ route('account') }}">Перейти в личный кабинет</a>
        @endauth
    </section>
@endsection
