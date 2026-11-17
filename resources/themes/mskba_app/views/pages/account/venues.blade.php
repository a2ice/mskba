@extends('theme::layouts.account', ['title' => 'Мои площадки'])
@section('account-heading')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => 'Мои площадки',
        'intro' => 'Управляйте площадками и смотрите свои договорные права.',
    ])
@endsection
@section('account-content')
    @include('account.partials.venues-index')
@endsection
