@php
    $title = $venue?->name ?? 'Редактирование площадки';
    $latestModerationRequest = $venue?->moderationRequests?->first();
    $latestOutgoingMessage = $latestModerationRequest?->messages
        ?->where('direction', \App\Modules\Venue\Domain\Enums\VenueModerationMessageDirectionEnum::OUTGOING)
        ->sortByDesc('id')
        ->first();
    $hasPendingModeration = $venue?->moderationRequests
        ?->contains(fn ($request) => $request->status === \App\Modules\Venue\Domain\Enums\VenueModerationRequestStatusEnum::PENDING) ?? false;
@endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'venues',
    'sectionClass' => 'venues-section',
    'contentTitle' => $title,
    'sidebarLabel' => 'Навигация площадок',
    'wrapSidebarPanel' => false,
])

@section('section-content')
    @if(isset($error))
        <div class="alert alert-danger">
            {{ $error['message'] }}
        </div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger">
            {{ session('error') }}
        </div>
    @endif

    @if(session('status'))
        <div class="alert alert-success">
            {{ session('status') }}
        </div>
    @endif

    @if($venue !== null)
        <div class="alert alert-info">
            Текущий статус: <strong>{{ $venue->status->label() }}</strong>
            @if($venue->canonicalVenue)
                <br>Эта запись связана с главной площадкой: #{{ $venue->canonicalVenue->id }} {{ $venue->canonicalVenue->name }}
            @endif
            @if($venue->status_info)
                <br>{{ $venue->status_info }}
            @endif
        </div>

        @if($latestModerationRequest)
            <div class="alert {{ $latestModerationRequest->status === \App\Modules\Venue\Domain\Enums\VenueModerationRequestStatusEnum::REJECTED ? 'alert-warning' : 'alert-secondary' }}">
                Последняя заявка: <strong>{{ $latestModerationRequest->status->label() }}</strong>
                @if($latestOutgoingMessage)
                    <br>{{ $latestOutgoingMessage->message }}
                @endif
            </div>
        @endif

        @include('theme::partials.venues.form', [
            'venue' => $venue,
            'types' => $types,
            'metros' => $metros,
            'action' => route('venues.update', $venue->alias),
            'method' => 'PUT',
            'cancelUrl' => route('venues.show', $venue->alias),
            'submitLabel' => 'Сохранить',
        ])

        <hr class="my-4">

        @if($venue->status === \App\Modules\Venue\Domain\Enums\VenueStatusEnum::BLOCKED)
            <div class="alert alert-danger">
                Площадка заблокирована. Повторная отправка на модерацию недоступна.
            </div>
        @elseif($hasPendingModeration)
            <div class="alert alert-secondary">
                Заявка уже находится на модерации.
            </div>
        @else
            <form method="POST" action="{{ route('venues.moderation.submit', $venue->alias) }}" class="mt-4">
                @csrf

                <div class="mb-3">
                    <label for="moderationMessage" class="form-label">Комментарий для модератора</label>
                    <textarea id="moderationMessage" name="message" class="form-control" rows="3">{{ old('message') }}</textarea>
                </div>

                <button type="submit" class="btn btn--primary btn--sm">Отправить на модерацию</button>
            </form>
        @endif
    @endif
@endsection
