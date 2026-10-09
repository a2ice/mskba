@extends('theme::layouts.account', ['title' => 'Кошелёк'])

@section('account-content')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => 'Кошелёк',
        'subtitle' => 'Баланс, операции и история начислений.',
    ])
@endsection
