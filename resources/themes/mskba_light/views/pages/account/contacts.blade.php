@extends('theme::layouts.account', [
    'title' => 'Контакты',
])

@section('account-content')

    @if(isset($error))
        <div class="alert alert-danger">
            {{ $error['message'] }}
        </div>
    @endif

    <p>Здесь будут контакты аккаунта.</p>

@endsection