@extends('theme::layouts.app', ['title' => 'Аккаунт'])

@section('content')
<section class="first-screen">
    <div class="inner">
        <div class="section-header">
            <h1 class="section-title">Панель администратора</h1>
            @include('theme::partials.breadcrumbs')
        </div>
        <div class="section-content">
            <div class="row">
                <aside class="col-3">
                    <nav class="admin-nav">
                        <ul>
                            <li><a href="{{ route('admin.index') }}">Главная</a></li>
                            <li><a href="{{ route('admin.index') }}">Пользователи</a></li>
                            <li><a href="#">Настройки</a></li>
                            <li><a href="#">Логи</a></li>
                        </ul>
                    </nav>
                </aside>
            </div>
        </div>
    </div>
</section>
@endsection