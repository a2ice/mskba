@php
    $title = $event->title;
    $timezone = $event->venue->schedule?->timezone ?: config('app.timezone');
    $confirmedParticipants = $event->participants->where('status', \App\Modules\Event\Domain\Enums\EventParticipantStatusEnum::CONFIRMED);
    $isOrganizer = $currentParticipant?->role === \App\Modules\Event\Domain\Enums\EventParticipantRoleEnum::ORGANIZER;
    $isFuture = $event->starts_at->isFuture();
    $isCompleted = $event->status === \App\Modules\Event\Domain\Enums\EventStatusEnum::COMPLETED;
@endphp

@extends('theme::layouts.section-sidebar', [
    'title' => $title,
    'sectionId' => 'events',
    'sectionClass' => 'events-section',
    'contentTitle' => $title,
    'contentSubtitle' => $event->type->label().' · '.$event->status->label(),
    'sidebarLabel' => 'Навигация мероприятий',
])

@section('section-sidebar')
    <div class="section-sidebar-block"><h2 class="section-sidebar-block__title">Мероприятия</h2><ul class="sidebar-nav nav flex-column"><li class="nav-item"><a class="nav-link" href="{{ route('events.index') }}">Все мероприятия</a></li><li class="nav-item"><a class="nav-link" href="{{ route('events.create') }}">Создать</a></li>@if($canManage && $event->ends_at->isFuture() && ! in_array($event->status->value, ['cancelled', 'completed'], true))<li class="nav-item"><a class="nav-link" href="{{ route('events.edit', $event->routeIdentifier()) }}">Редактировать</a></li>@endif</ul></div>
@endsection

@section('section-content')
    @if(session('status')) <div class="alert alert-success mb-3">{{ session('status') }}</div> @endif
    @if(session('error')) <div class="alert alert-danger mb-3">{{ session('error') }}</div> @endif
    @if(session('photo_status')) <div class="alert alert-success mb-3">{{ session('photo_status') }}</div> @endif
    @if(session('photo_error') || $errors->has('photo')) <div class="alert alert-danger mb-3">{{ session('photo_error') ?: $errors->first('photo') }}</div> @endif

    <div class="section-list mb-4">
        <article class="section-list-item">
            <p class="mb-2"><strong>Когда:</strong> {{ $event->starts_at->setTimezone($timezone)->format('d.m.Y H:i') }}–{{ $event->ends_at->setTimezone($timezone)->format('H:i') }}</p>
            <p class="mb-2"><strong>Где:</strong> <a href="{{ route('venues.show', $event->venue->routeIdentifier()) }}">{{ $event->venue->name }}</a>{{ $event->venue->raw_address ? ' · '.$event->venue->raw_address : '' }}</p>
            <p class="mb-2"><strong>Бронирование:</strong> {{ $event->booking->status->label() }}</p>
            <p class="mb-3"><strong>Участники:</strong> {{ $confirmedParticipants->count() }}{{ $event->max_participants ? ' / '.$event->max_participants : '' }}</p>
            @if($event->description)<p class="mb-4">{{ $event->description }}</p>@endif
            @if($event->status->value === 'cancelled' && $event->cancellation_reason)
                <p class="mb-4"><strong>Причина отмены:</strong> {{ $event->cancellation_reason }}</p>
            @endif

            @auth
                @if($event->status->value === 'published' && $event->visibility->value === 'public' && $isFuture && ! $isParticipating)
                    <form method="POST" action="{{ route('events.join', $event->routeIdentifier()) }}">@csrf<button class="btn btn--primary" type="submit">Присоединиться</button></form>
                @elseif($event->status->value === 'published' && $isFuture && $isParticipating && ! $isOrganizer)
                    <form method="POST" action="{{ route('events.leave', $event->routeIdentifier()) }}">@csrf @method('DELETE')<button class="btn btn--secondary" type="submit">Выйти из участников</button></form>
                @elseif($isOrganizer)
                    <span class="badge badge--success">Вы организатор</span>
                @endif
            @else
                @if($event->status->value === 'published' && $isFuture)
                    <button type="button" class="btn btn--primary js-handler" data-handler="modal" data-modal-action="open" data-modal-target="auth-entry-classic">Войти и присоединиться</button>
                @endif
            @endauth
        </article>
    </div>

    @if($canManage && $event->starts_at->isFuture() && ! in_array($event->status->value, ['cancelled', 'completed'], true))
        <section class="section-list mb-4">
            <article class="section-list-item">
                <h2 class="h3 mb-3">Управление</h2>
                <form method="POST" action="{{ route('events.cancel', $event->routeIdentifier()) }}" onsubmit="return confirm('Вы уверены, что хотите отменить мероприятие и освободить бронь?')">
                    @csrf
                    <div class="form-group field mb-3"><label class="form-label" for="eventCancellationReason">Причина отмены</label><textarea id="eventCancellationReason" class="form-control" name="reason" rows="3" maxlength="1000"></textarea></div>
                    <button class="btn btn--danger btn--sm" type="submit">Отменить мероприятие</button>
                </form>
            </article>
        </section>
    @endif

    @if($canManage && $event->ends_at->isPast() && ! in_array($event->status->value, ['cancelled', 'draft'], true))
        <section class="section-list mb-4">
            <article class="section-list-item">
                <h2 class="h3 mb-3">Итоги мероприятия</h2>
                <form method="POST" action="{{ route('events.result.update', $event->routeIdentifier()) }}">
                    @csrf @method('PUT')
                    <div class="form-group field mb-3">
                        <label class="form-label" for="eventResultDescription">Как прошло мероприятие</label>
                        <textarea id="eventResultDescription" class="form-control @error('result_description') is-invalid @enderror" name="result_description" rows="6" maxlength="10000">{{ old('result_description', $event->result_description) }}</textarea>
                        @error('result_description') <div class="invalid-feedback">{{ $message }}</div> @enderror
                    </div>
                    <button class="btn btn--primary btn--sm" type="submit">{{ $isCompleted ? 'Сохранить итоги' : 'Отметить состоявшимся' }}</button>
                </form>
            </article>
        </section>
    @endif

    @if($isCompleted)
        <section class="section-list mb-4">
            <article class="section-list-item">
                <h2 class="h3 mb-3">Как это было</h2>
                <p>{{ $event->result_description ?: 'Описание пока не добавлено.' }}</p>
            </article>
        </section>

        @if($canManage)
            <section class="venue-gallery-editor mb-4" data-tooltip-skip data-image-upload-surface>
                @include('theme::partials.image-upload-loading', ['text' => 'Загружаем фотографию…'])
                <div class="venue-gallery-editor__heading"><div><h2>Фотографии</h2><p>До 12 изображений · JPEG, PNG или WebP · до 5 МБ</p></div><span>{{ $event->media->count() }}/12</span></div>
                @if($event->media->count() < 12)
                    <form action="{{ route('events.result.photos.store', $event->routeIdentifier()) }}" method="POST" enctype="multipart/form-data" class="venue-gallery-editor__upload" data-image-upload data-image-upload-auto-submit>
                        @csrf
                        <label for="event-result-photo-input" class="btn btn--secondary btn--sm">Добавить фотографию</label>
                        <input id="event-result-photo-input" type="file" name="photo" accept="image/jpeg,image/png,image/webp" hidden>
                    </form>
                @endif
                @if($event->media->isNotEmpty())
                    <div class="venue-gallery-editor__items" aria-label="Фотографии мероприятия">
                        @foreach($event->media as $photo)
                            <article class="venue-gallery-editor__item">
                                <span class="venue-gallery-editor__preview"><img src="{{ $photo->publicUrl() }}" alt=""></span>
                                <form action="{{ route('events.result.photos.destroy', [$event->routeIdentifier(), $photo->id]) }}" method="POST">@csrf @method('DELETE')<button type="submit" class="venue-gallery-editor__delete" aria-label="Удалить фотографию" onclick="return confirm('Вы уверены, что хотите удалить фотографию?')">×</button></form>
                            </article>
                        @endforeach
                    </div>
                @endif
            </section>
        @endif

        @if($event->media->isNotEmpty())
            <section class="venue-show-section mb-4">
                <div class="venue-show-section__heading"><h2>Галерея</h2><span class="venue-section-state">{{ $event->media->count() }} фото</span></div>
                <div class="venue-gallery">
                    @foreach($event->media as $index => $photo)
                        <figure class="venue-gallery__item"><button type="button" class="venue-gallery__button" data-venue-gallery-item data-index="{{ $index }}" data-url="{{ $photo->publicUrl() }}" data-title="{{ $event->title }}" data-description=""><img src="{{ $photo->publicUrl() }}" alt="{{ $event->title }}"></button></figure>
                    @endforeach
                </div>
                <div class="venue-gallery-modal" data-venue-gallery-modal hidden>
                    <div class="venue-gallery-modal__backdrop" data-venue-gallery-close></div>
                    <section class="venue-gallery-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="event-gallery-modal-title">
                        <button type="button" class="venue-gallery-modal__close" data-venue-gallery-close aria-label="Закрыть"><i class="ti ti-x"></i></button>
                        <button type="button" class="venue-gallery-modal__nav venue-gallery-modal__nav--prev" data-venue-gallery-prev aria-label="Предыдущее фото"><i class="ti ti-chevron-left"></i></button>
                        <img src="" alt="" data-venue-gallery-image>
                        <button type="button" class="venue-gallery-modal__nav venue-gallery-modal__nav--next" data-venue-gallery-next aria-label="Следующее фото"><i class="ti ti-chevron-right"></i></button>
                        <div class="venue-gallery-modal__caption"><h3 id="event-gallery-modal-title" data-venue-gallery-title></h3><p data-venue-gallery-description></p></div>
                    </section>
                </div>
            </section>
        @endif
    @endif

    <h2 class="h3 mb-3">Участники</h2>
    @if($confirmedParticipants->isEmpty())
        <p>Участников пока нет.</p>
    @else
        <div class="section-list">
            @foreach($confirmedParticipants as $participant)
                <div class="section-list-item">{{ $participant->user->username }}{{ $participant->role->value === 'organizer' ? ' · организатор' : '' }}</div>
            @endforeach
        </div>
    @endif
@endsection
