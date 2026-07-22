@php
    $title = $venue?->name ?? 'Редактирование площадки';
    $breadcrumbs = $venue === null ? null : [
        ['label' => 'Аккаунт', 'url' => route('account')],
        ['label' => 'Мои площадки', 'url' => route('account.venues')],
        ['label' => $venue->name, 'url' => route('account.venues.show', $venue->routeIdentifier())],
        ['label' => 'Редактирование'],
    ];
    $venueSidebarActive = 'edit';
    $hasPendingModeration = $venue?->moderationRequests
        ?->contains(fn ($request) => $request->status === \App\Modules\Moderation\Domain\Enums\ModerationRequestStatusEnum::PENDING) ?? false;
    $hasDraftRevision = ($venueRevision ?? null) !== null;
    $canSubmitModeration = $venue !== null && ! $hasPendingModeration && (
        $venue->status === \App\Modules\Venue\Domain\Enums\VenueStatusEnum::UNCONFIRMED
        || ($venue->status === \App\Modules\Venue\Domain\Enums\VenueStatusEnum::CONFIRMED && $hasDraftRevision)
    );
@endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'account',
    'sectionClass' => 'account-section',
    'contentTitle' => $title,
    'sidebarLabel' => 'Управление площадкой',
])

@section('section-sidebar')
    @if($venue !== null)
        @include('theme::partials.venues.internal-sidebar')
    @endif
@endsection

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
        @if($hasPendingModeration)
            <div class="alert alert-warning">
                Площадка находится на модерации. Данные ниже доступны для просмотра, но изменить их можно будет после решения модератора.
            </div>
        @elseif($canSubmitModeration)
            <section class="venue-edit-moderation-callout">
                <div>
                    <strong>{{ $venue->status === \App\Modules\Venue\Domain\Enums\VenueStatusEnum::CONFIRMED ? 'Изменения готовы к отправке' : 'Площадка готова к проверке' }}</strong>
                </div>
                <form method="POST" action="{{ route('account.venues.moderation.submit', $venue->routeIdentifier()) }}">
                    @csrf
                    <button type="submit" class="btn btn--primary btn--sm">
                        {{ $venue->status === \App\Modules\Venue\Domain\Enums\VenueStatusEnum::CONFIRMED ? 'Отправить изменения на модерацию' : 'Отправить на модерацию' }}
                    </button>
                </form>
            </section>
        @endif

        @include('theme::partials.venues.gallery-editor', [
            'venue' => $venue,
            'photos' => $venuePhotos ?? [],
            'readOnly' => $hasPendingModeration,
        ])

        @include('theme::partials.venues.form', [
            'venue' => $venue,
            'types' => $types,
            'metros' => $metros,
            'action' => route('account.venues.update', $venue->routeIdentifier()),
            'method' => 'PUT',
            'cancelUrl' => route('account.venues.show', $venue->routeIdentifier()),
            'submitLabel' => 'Сохранить',
            'venueRevision' => $venueRevision ?? null,
            'readOnly' => $hasPendingModeration,
            'readOnlyMessage' => 'Дождитесь результата модерации — после этого редактирование снова станет доступно.',
        ])

    @endif
@endsection
