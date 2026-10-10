@extends('theme::layouts.account', ['title' => 'Кошелёк'])

@section('account-heading')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => 'Кошелёк',
        'subtitle' => 'Баланс, операции и история начислений.',
    ])
@endsection

@section('account-content')

@endsection
