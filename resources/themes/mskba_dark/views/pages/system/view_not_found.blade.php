@extends('theme::layouts.app', ['title' => 'Профиль'])

@section('content')
    <section id="profile" class="profile-section py-5">
        <div class="container">
            <div class="section-heading">
                <h1 class="mb-4">Шаблона страницы '{{ $page }}' не найдено</h1>
            </div>

            <div class="section-content">
                <p>К сожалению, запрошенная страница '{{ $page }}' не найдена. Возможно, она была удалена или перемещена.</p>
                <a href="javascript:history.back()" class="btn btn-primary">Назад</a>
            </div>
        </div>
    </section>
@endsection
