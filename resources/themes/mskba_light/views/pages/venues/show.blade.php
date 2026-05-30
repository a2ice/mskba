@php
    if(isset($venue)) {
        $title = "Площадка: {$venue->name}";
    } else {
        $title = 'Ошибка';
        $error_message = isset($error['message']) ? $error['message'] : 'Неизвестная ошибка';
        $title .= " - $error_message";
    }
@endphp

@extends('theme::layouts.app', [
    'title' => $title
])

@section('content')
    <section id="venue" class="venue-section py-5">
        <div class="container">

            @if(!empty($venue))

            <div class="section-heading">
                <h1 class="mb-4">{{ $venue->name }}</h1>
            </div>

            <div class="section-content">
                <div class="card">
                    <div class="card-body">
                        <ul class="list-unstyled mb-4">
                            <li class="mb-3">
                                Alias:
                                <span class="fw-bold">{{ $venue->alias }}</span>
                            </li>
                            <li class="mb-3">
                                Тип:
                                <span class="fw-bold">{{ $venue->type }}</span>
                            </li>
                            <li class="mb-3">
                                Статус:
                                <span class="fw-bold">{{ $venue->status }}</span>
                            </li>
                            <li class="mb-3">
                                Описание:
                                <span class="fw-bold">{{ $venue->description ?? '—' }}</span>
                            </li>
                        </ul>

                        <div class="d-flex gap-2">
                            <a href="{{ route('venues') }}" class="btn btn-outline-secondary">К списку</a>

                            @if ($venue->canEdit)
                                <a href="{{ route('venues.edit', $venue->alias) }}" class="btn btn-primary">Редактировать</a>
                            @endif

                            @if ($venue->canEditSchedule)
                                <a href="#" class="btn btn-outline-primary">Расписание</a>
                            @endif
                        </div>
                    </div>
                </div>
            </div>

            @else
                <div class="alert alert-warning" role="alert">
                    {{ $error_message }}
                </div>
            @endif

        </div>
    </section>
@endsection
