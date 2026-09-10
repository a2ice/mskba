@php
    $photos = collect($photos ?? []);
    $photoStatus = session('court_photo_status');
    $photoError = session('court_photo_error');
    $showStatus = is_array($photoStatus) && (int) ($photoStatus['court_id'] ?? 0) === (int) $court->id;
    $showError = is_array($photoError) && (int) ($photoError['court_id'] ?? 0) === (int) $court->id;
@endphp

<section class="venue-gallery-editor mt-4" data-tooltip-skip data-image-upload-surface>
    @include('theme::partials.image-upload-loading', ['text' => 'Загружаем фотографию зала…'])

    <div class="venue-gallery-editor__heading">
        <div>
            <h3 class="h5 mb-1">Фотографии зала</h3>
            <p>До трёх изображений · JPEG, PNG или WebP · до 5 МБ</p>
        </div>
        <span>{{ $photos->count() }}/3</span>
    </div>

    <p class="text-muted small mb-3">
        Если фотографии для этого зала не добавлены, на публичной странице используются фотографии основной площадки.
    </p>

    @if($showStatus)
        <div class="alert alert-success">{{ $photoStatus['message'] }}</div>
    @endif
    @if($showError)
        <div class="alert alert-danger">{{ $photoError['message'] }}</div>
    @endif

    <form
        action="{{ route('account.venues.courts.photos.store', [$venue->routeIdentifier(), $court->routeIdentifier()]) }}"
        method="post"
        enctype="multipart/form-data"
        class="venue-gallery-editor__upload"
        data-image-upload
        data-image-upload-auto-submit
    >
        @csrf
        <label for="venue-court-photo-input-{{ $court->id }}" class="btn btn--secondary btn--sm">Добавить фотографию</label>
        <input
            id="venue-court-photo-input-{{ $court->id }}"
            type="file"
            name="photo"
            accept="image/jpeg,image/png,image/webp"
            hidden
        >
    </form>

    @if($photos->isNotEmpty())
        <div class="venue-gallery-editor__items" aria-label="Фотографии зала {{ $court->name }}">
            @foreach($photos as $photo)
                <article @class(['venue-gallery-editor__item', 'is-active' => $photo['is_featured']])>
                    <form action="{{ route('account.venues.courts.photos.activate', [$venue->routeIdentifier(), $court->routeIdentifier(), $photo['id']]) }}" method="post">
                        @csrf
                        @method('PATCH')
                        <button
                            type="submit"
                            class="venue-gallery-editor__preview"
                            @disabled($photo['is_featured'])
                            aria-label="Сделать фотографию основной"
                        >
                            <img src="{{ $photo['url'] }}" alt="">
                            @if($photo['is_featured'])<span>Основная</span>@endif
                        </button>
                    </form>
                    <form action="{{ route('account.venues.courts.photos.destroy', [$venue->routeIdentifier(), $court->routeIdentifier(), $photo['id']]) }}" method="post">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="venue-gallery-editor__delete" aria-label="Удалить фотографию" onclick="return confirm('Удалить фотографию этого зала?')">×</button>
                    </form>
                </article>
            @endforeach
        </div>
    @else
        <p class="venue-gallery-editor__empty">Отдельные фотографии зала ещё не добавлены.</p>
    @endif
</section>
