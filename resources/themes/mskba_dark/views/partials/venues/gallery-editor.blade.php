@php
    $photos = collect($photos ?? []);
    $adminMode = $adminMode ?? false;
    $readOnly = $readOnly ?? false;
    $routeKey = $adminMode ? $venue : $venue->routeIdentifier();
    $storeRoute = $adminMode ? route('admin.venues.photos.store', $routeKey) : route('venues.photos.store', $routeKey);
    $canManagePhotos = ! $readOnly && ($adminMode || $venue->status !== \App\Modules\Venue\Domain\Enums\VenueStatusEnum::BLOCKED);
@endphp

<section class="venue-gallery-editor mb-4" data-tooltip-skip data-image-upload-surface>
    @include('theme::partials.image-upload-loading', ['text' => 'Загружаем фотографию…'])
    <div class="venue-gallery-editor__heading">
        <div>
            <h2>Фотографии</h2>
            <p>До трёх изображений · JPEG, PNG или WebP · до 5 МБ</p>
        </div>
        <span>{{ $photos->count() }}/3</span>
    </div>

    @if(session('photo_status'))
        <div class="alert alert-success">{{ session('photo_status') }}</div>
    @endif
    @if(session('photo_error') || $errors->has('photo'))
        <div class="alert alert-danger">{{ session('photo_error') ?: $errors->first('photo') }}</div>
    @endif

    @if($canManagePhotos)
    <form action="{{ $storeRoute }}" method="post" enctype="multipart/form-data" class="venue-gallery-editor__upload" data-image-upload data-image-upload-auto-submit>
        @csrf
        <label for="venue-photo-input-{{ $adminMode ? 'admin' : 'owner' }}" class="btn btn--secondary btn--sm">Добавить фотографию</label>
        <input id="venue-photo-input-{{ $adminMode ? 'admin' : 'owner' }}" type="file" name="photo" accept="image/jpeg,image/png,image/webp" hidden>
    </form>
    @else
        <p class="venue-gallery-editor__notice">{{ $readOnly ? 'Фотографии доступны для просмотра до решения модератора.' : 'Фотографии заблокированной площадки нельзя изменять.' }}</p>
    @endif

    @if($photos->isNotEmpty())
        <div class="venue-gallery-editor__items" aria-label="Фотографии площадки">
            @foreach($photos as $photo)
                @php
                    $activateRoute = $adminMode
                        ? route('admin.venues.photos.activate', [$venue, $photo['id']])
                        : route('venues.photos.activate', [$venue->routeIdentifier(), $photo['id']]);
                    $deleteRoute = $adminMode
                        ? route('admin.venues.photos.destroy', [$venue, $photo['id']])
                        : route('venues.photos.destroy', [$venue->routeIdentifier(), $photo['id']]);
                @endphp
                <article @class(['venue-gallery-editor__item', 'is-active' => $photo['is_featured']])>
                    <form action="{{ $activateRoute }}" method="post">
                        @csrf @method('PATCH')
                        <button type="submit" class="venue-gallery-editor__preview" @disabled($photo['is_featured'] || ! $canManagePhotos) aria-label="Сделать фотографию основной">
                            <img src="{{ $photo['url'] }}" alt="">
                            @if($photo['is_featured'])<span>Основная</span>@endif
                            @if($photo['is_draft'])<small>Черновик</small>@endif
                        </button>
                    </form>
                    @if($canManagePhotos)<form action="{{ $deleteRoute }}" method="post">
                        @csrf @method('DELETE')
                        <button type="submit" class="venue-gallery-editor__delete" aria-label="Удалить фотографию" onclick="return confirm('Вы уверены, что хотите удалить фотографию?')">×</button>
                    </form>@endif
                </article>
            @endforeach
        </div>
    @else
        <p class="venue-gallery-editor__empty">Фотографии ещё не добавлены.</p>
    @endif

    @if($venue->status === \App\Modules\Venue\Domain\Enums\VenueStatusEnum::CONFIRMED && ! $adminMode)
        <p class="venue-gallery-editor__notice">Изменения фотографий появятся на странице после модерации.</p>
    @endif
</section>
