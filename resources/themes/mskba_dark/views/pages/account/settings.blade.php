@extends('theme::layouts.account', [
    'title' => 'Настройки аккаунта',
])

@section('account-content')

    @if(isset($error))
        <div class="alert alert-danger">
            {{ $error['message'] }}
        </div>
    @endif

    @if ($user)

        <p>Здесь будут настройки аккаунта.</p>

    @endif

@endsection