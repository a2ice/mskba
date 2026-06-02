@extends('theme::layouts.app', ['title' => 'Профиль'])

@section('content')
    <section id="profile" class="profile-section first-screen">
        <div class="container">
            <div class="section-heading">
                <h1 class="mb-4">Шаблона страницы '{{ $page }}' не найдено</h1>
            </div>

            <div class="section-content">
                <p>К сожалению, запрошенный шаблон '{{ $page }}' не найден. Возможно, он был удален или перемещен.</p>
                <hr>
                <a href="javascript:history.back()" class="btn btn--primary "><span class="fw-200 px-3">Назад</span></a>
            </div>
        </div>
    </section>
@endsection
