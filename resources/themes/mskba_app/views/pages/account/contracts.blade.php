@extends('theme::layouts.account', ['title' => 'Контракты'])

@section('account-content')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => 'Контракты',
        'subtitle' => 'Ваши договоры и документы.',
    ])
@endsection
