@extends('theme::layouts.account', ['title' => 'Профиль'])

@section('account-content')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => 'Профиль',
        'subtitle' => 'Личные данные и оформление профиля. Редактирование появится здесь позже.',
    ])
@endsection
