@php $venue = isset($venue) ? $venue : null; @endphp

@php $title = $venue ? $venue->name : 'Площадка'; @endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'account',
    'sectionClass' => 'account-section',
    'contentTitle' => $title,
    'sidebarLabel' => 'Навигация аккаунта',
    'wrapSidebarPanel' => false,
    'sidebarPartial' => 'theme::partials.account.sidebar',
])

@if($venue !== null)
    @section('section-heading-action')
        @include('theme::partials.venues.account-status', ['venue' => $venue])
    @endsection
@endif

@section('section-content')
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
        <section class="account-venue-detail" aria-label="Информация о площадке">
            <dl class="account-venue-detail__facts">
                <div class="account-venue-detail__fact">
                    <dt>Тип площадки</dt>
                    <dd>{{ $venue->type }}</dd>
                </div>
                <div class="account-venue-detail__fact">
                    <dt>Адрес</dt>
                    <dd>{{ $venue->rawAddress ?? 'Не указан' }}</dd>
                </div>
                <div class="account-venue-detail__fact account-venue-detail__fact--wide">
                    <dt>Краткое описание</dt>
                    <dd>{{ $venue->shortDescription ?? 'Не указано' }}</dd>
                </div>
            </dl>

            <div class="account-venue-detail__actions">
                <a
                    href="{{ route('venues.show', $venue->routeIdentifier()) }}"
                    class="btn btn--secondary btn--sm"
                    target="_blank"
                    rel="noopener noreferrer"
                >Просмотр</a>

                @if ($venue->canEdit)
                    <a href="{{ route('account.venues.edit', $venue->routeIdentifier()) }}" class="btn btn--secondary btn--sm">Редактировать</a>
                @endif

                <a href="{{ route('account.venues.status', $venue->routeIdentifier()) }}" class="btn btn--secondary btn--sm">Модерация</a>

                @if ($venue->canEditSchedule)
                    <a href="{{ route('account.venues.schedule.edit', $venue->routeIdentifier()) }}" class="btn btn--secondary btn--sm">Расписание</a>
                @endif
            </div>
        </section>
    @endif
@endsection
