@extends('theme::layouts.account', ['title' => 'Мои площадки'])

@section('account-content')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => 'Мои площадки',
        'subtitle' => 'Площадки и связанные с ними действия.',
    ])
@endsection
