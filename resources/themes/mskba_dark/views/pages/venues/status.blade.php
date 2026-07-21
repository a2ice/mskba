@php
    $title = $venue?->name ?? 'Статус площадки';
    $latestModerationRequest = $venue?->moderationRequests?->first();
    $hasPendingModeration = $venue?->moderationRequests
        ?->contains(fn ($request) => $request->status === \App\Modules\Moderation\Domain\Enums\ModerationRequestStatusEnum::PENDING) ?? false;
    $hasDraftRevision = $venue?->draftRevision !== null;
    $breadcrumbs = $venue === null ? null : [
        ['label' => 'Площадки', 'url' => route('venues')],
        ['label' => $venue->name, 'url' => route('venues.show', $venue->routeIdentifier())],
        ['label' => 'Статус'],
    ];
    $venueSidebarActive = 'status';
@endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'venues',
    'sectionClass' => 'venues-section',
    'contentTitle' => 'Статус площадки',
    'sidebarLabel' => 'Навигация площадок',
])

@section('section-sidebar')
    @if($venue !== null)
        @include('theme::partials.venues.internal-sidebar')
    @endif
@endsection

@section('section-content')
    @if(isset($error))
        <div class="alert alert-danger">{{ $error['message'] }}</div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger">{{ session('error') }}</div>
    @endif

    @if(session('status'))
        <div class="alert alert-success">{{ session('status') }}</div>
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
            <div class="alert {{ $latestModerationRequest->status === \App\Modules\Moderation\Domain\Enums\ModerationRequestStatusEnum::REJECTED ? 'alert-warning' : 'alert-secondary' }}">
                Последняя заявка модерации: <strong>{{ $latestModerationRequest->status->label() }}</strong>
            </div>
        @endif

        @if($venue->status === \App\Modules\Venue\Domain\Enums\VenueStatusEnum::BLOCKED)
            <div class="alert alert-danger">Площадка заблокирована. Повторная отправка на модерацию недоступна.</div>
        @elseif($hasPendingModeration)
            <div class="alert alert-secondary">Заявка модерации уже находится на рассмотрении.</div>
        @elseif($venue->status === \App\Modules\Venue\Domain\Enums\VenueStatusEnum::CONFIRMED && ! $hasDraftRevision)
            <div class="alert alert-success">Площадка подтверждена. Сохраните изменения, чтобы сформировать новую заявку.</div>
        @else
            <form method="POST" action="{{ route('venues.moderation.submit', $venue->routeIdentifier()) }}" class="mt-4">
                @csrf
                <div class="mb-3">
                    <label for="moderationMessage" class="form-label">Комментарий для модера</label>
                    <textarea id="moderationMessage" name="message" class="form-control" rows="3">{{ old('message') }}</textarea>
                </div>
                <button type="submit" class="btn btn--primary btn--sm">{{ $venue->status === \App\Modules\Venue\Domain\Enums\VenueStatusEnum::CONFIRMED ? 'Отправить изменения на модерацию' : 'Отправить на модерацию' }}</button>
            </form>
        @endif

        @if($venue->moderationRequests->isNotEmpty())
            <section class="venue-moderation-history" aria-labelledby="venue-moderation-history-title">
                <h2 id="venue-moderation-history-title">История запросов</h2>

                <div class="venue-moderation-history__list">
                    @foreach($venue->moderationRequests as $moderationRequest)
                        <article class="venue-moderation-request">
                            <header class="venue-moderation-request__header">
                                <div>
                                    <strong>Запрос №{{ $moderationRequest->id }}</strong>
                                    <time datetime="{{ $moderationRequest->submitted_at?->toIso8601String() }}">
                                        {{ $moderationRequest->submitted_at?->format('d.m.Y H:i') ?? 'Дата не указана' }}
                                    </time>
                                </div>
                                <span class="venue-moderation-request__status venue-moderation-request__status--{{ $moderationRequest->status->value }}">
                                    {{ $moderationRequest->status->label() }}
                                </span>
                            </header>

                            @if($moderationRequest->messages->isEmpty())
                                <p class="venue-moderation-request__empty">Запрос отправлен без комментария.</p>
                            @else
                                <div class="venue-moderation-request__messages">
                                    @foreach($moderationRequest->messages->sortByDesc('id') as $message)
                                        @php
                                            $isOwnerMessage = $message->sender_id === $moderationRequest->submitted_by_actor_id;
                                            $senderUsername = $message->sender?->user?->username
                                                ?? $message->sender?->user?->email
                                                ?? 'гость';
                                        @endphp
                                        <div @class([
                                            'venue-moderation-message',
                                            'venue-moderation-message--owner' => $isOwnerMessage,
                                            'venue-moderation-message--moderator' => ! $isOwnerMessage,
                                        ])>
                                            <div class="venue-moderation-message__meta">
                                                {{ $isOwnerMessage ? 'Вы' : 'Модератор' }} ({{ $senderUsername }}) · {{ $message->created_at?->format('d.m.Y H:i') ?? '—' }}
                                            </div>
                                            <p>{{ $message->message }}</p>
                                        </div>
                                    @endforeach
                                </div>
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>
        @endif
    @endif
@endsection
