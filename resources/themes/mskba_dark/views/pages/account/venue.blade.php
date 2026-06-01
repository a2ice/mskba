@php $venue = isset($venue) ? $venue : null; @endphp

@extends('theme::layouts.account', [
    'title' => $venue ? $venue->name : 'Площадка',
])

@section('account-content')
    @if(isset($error))
        <div class="alert alert-danger">
            {{ $error['message'] }}
        </div>
    @endif

    @if(session('status'))
        <div class="alert alert-success">
            {{ session('status') }}
        </div>
    @endif

    @if($venue !== null)
        <ul class="list-unstyled mb-4">
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
            <a href="{{ route('account.venues') }}" class="btn btn-outline-secondary">К списку</a>

            @if ($venue->canEdit)
                <a href="{{ route('account.venues.edit', $venue->alias) }}" class="btn btn-primary">Редактировать</a>
            @endif

            @if ($venue->canEditSchedule)
                <a href="#" class="btn btn-outline-primary">Расписание</a>
            @endif
        </div>
    @endif
@endsection
