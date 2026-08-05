@php
    $photoTagDisplayName = static function ($tag): string {
        return trim(implode(' ', array_filter([
            $tag->user?->profile?->first_name,
            $tag->user?->profile?->last_name,
        ]))) ?: 'Участник #'.$tag->user_id;
    };
    $photoTagCandidates = $event->participants
        ->filter(fn ($participant) => $participant->user !== null)
        ->unique('user_id')
        ->map(function ($participant): array {
            $profile = $participant->user->profile;

            return [
                'id' => $participant->user_id,
                'name' => trim(implode(' ', array_filter([$profile?->first_name, $profile?->last_name])))
                    ?: 'Пользователь #'.$participant->user_id,
                'username' => $participant->user->username,
            ];
        })
        ->values();
@endphp

<section class="section-card mb-4 venue-gallery-editor event-result-gallery" data-tooltip-skip data-image-upload-surface>
    @include('theme::partials.image-upload-loading', ['text' => 'Загружаем фотографию…'])
    <div class="venue-gallery-editor__heading">
        <div>
            <h2>Фотографии результата</h2>
            <p>До 12 изображений · JPEG, PNG или WebP · до 10 МБ</p>
        </div>
        <span>{{ $event->media->count() }}/12</span>
    </div>

    @if($event->media->count() < 12)
        <form action="{{ route('events.result.photos.store', $event->routeIdentifier()) }}" method="POST" enctype="multipart/form-data" class="venue-gallery-editor__upload" data-image-upload data-image-upload-auto-submit>
            @csrf
            <label for="event-management-result-photo-input" class="btn btn--secondary btn--sm">Добавить фотографию</label>
            <input id="event-management-result-photo-input" type="file" name="photo" accept="image/jpeg,image/png,image/webp" hidden>
        </form>
    @endif

    @if($event->media->isNotEmpty())
        <div class="venue-gallery-editor__items" aria-label="Фотографии мероприятия">
            @foreach($event->media as $photo)
                @php
                    $editorTags = $photo->eventResultPhotoTags->map(fn ($tag) => [
                        'user_id' => $tag->user_id,
                        'name' => $photoTagDisplayName($tag),
                        'username' => $tag->user?->username,
                        'x' => $tag->position_x,
                        'y' => $tag->position_y,
                    ])->values();
                @endphp
                <article class="venue-gallery-editor__item event-result-photo-editor" data-event-photo-editor data-candidates='@json($photoTagCandidates)' data-tags='@json($editorTags)'>
                    <form action="{{ route('events.result.photos.update', [$event->routeIdentifier(), $photo->id]) }}" method="POST" data-event-photo-metadata-form data-image-upload-surface>
                        @csrf @method('PUT')
                        @include('theme::partials.image-upload-loading', ['text' => 'Сохраняем описание и отметки…'])
                        <div class="event-result-photo-editor__image" data-event-photo-tag-surface>
                            <img src="{{ $photo->publicUrl() }}" alt="">
                            <div data-event-photo-tags></div>
                        </div>
                        <label class="form-label" for="event-management-result-description-{{ $photo->id }}">Описание фотографии</label>
                        <textarea id="event-management-result-description-{{ $photo->id }}" class="form-control" name="description" rows="2" maxlength="2000" placeholder="Что происходит на фотографии?">{{ $photo->description }}</textarea>
                        <div class="event-photo-tag-editor">
                            <label class="form-label" for="event-management-result-tag-{{ $photo->id }}">Отметить участника</label>
                            <div class="event-photo-tag-editor__control">
                                <input id="event-management-result-tag-{{ $photo->id }}" class="form-control" type="text" data-event-photo-tag-search placeholder="Введите @имя или @логин" autocomplete="off">
                                <div class="event-photo-tag-editor__suggestions" data-event-photo-tag-suggestions hidden></div>
                            </div>
                            <p data-event-photo-tag-hint>Выберите участника, затем нажмите на нужное место фотографии.</p>
                        </div>
                        <button class="btn btn--primary btn--sm" type="submit">Сохранить</button>
                        <p class="event-result-photo-editor__status" data-event-photo-status aria-live="polite"></p>
                    </form>
                    <form action="{{ route('events.result.photos.destroy', [$event->routeIdentifier(), $photo->id]) }}" method="POST">
                        @csrf @method('DELETE')
                        <button type="submit" class="venue-gallery-editor__delete" aria-label="Удалить фотографию" onclick="return confirm('Вы уверены, что хотите удалить фотографию?')">×</button>
                    </form>
                </article>
            @endforeach
        </div>
    @endif
</section>
