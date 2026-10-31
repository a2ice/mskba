@php
    /** @var \Illuminate\Database\Eloquent\Model $model */
    $targets = app(\App\Modules\Media\Application\Services\EntityBannerTargets::class);
    $type = $targets->type($model);
    $id = $targets->id($model);
    $context = $context ?? 'account';
    $banner = array_key_exists('bannerOverride', get_defined_vars()) ? $bannerOverride : $model->banner;
    $updateUrl = route('entity-banners.update', compact('context', 'type', 'id'));
    $sourceUrl = $banner?->original_path
        ? route('entity-banners.source', compact('context', 'type', 'id'))
        : null;
    $deleteUrl = route('entity-banners.destroy', compact('context', 'type', 'id'));
    $controlId = 'banner-zoom-'.$context.'-'.$type.'-'.$id;
@endphp
<section class="entity-banner-editor panel"
    data-entity-banner-editor
    data-upload-url="{{ $updateUrl }}"
    data-delete-url="{{ $deleteUrl }}"
    data-original-url="{{ $sourceUrl }}"
    data-initial-crop='@json($banner?->crop_applied)'
    aria-label="Оформление страницы">
    <header class="entity-banner-editor__heading">
        <h2>Баннер</h2>
        <p>Добавьте широкое изображение в верхнюю часть страницы.</p>
    </header>

    <div class="entity-banner-editor__selection">
        <label class="entity-banner-editor__choose" data-banner-choose>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"
                stroke-linecap="round" stroke-linejoin="round" width="19" height="19" aria-hidden="true">
                <path d="M12 16V4m-4 4 4-4 4 4M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3"/>
            </svg>
            <span>Выбрать изображение</span>
            <input class="entity-banner-editor__file" type="file" data-banner-file
                accept="image/jpeg,image/png,image/webp"
                aria-label="Выбрать изображение для баннера">
        </label>
        <span class="entity-banner-editor__file-name" data-banner-file-name>JPG, PNG или WebP, до 10 МБ</span>
    </div>

    <div class="entity-banner-editor__preview">
        <canvas width="1200" height="400" data-banner-canvas role="img"
            aria-label="Предпросмотр обложки"></canvas>
        <p class="entity-banner-editor__empty" data-banner-empty>
            Здесь появится выбранное изображение
        </p>
    </div>

    <div class="entity-banner-editor__controls">
        <label for="{{ $controlId }}">Масштаб</label>
        <div class="entity-banner-editor__zoom">
            <button type="button" data-banner-zoom-out aria-label="Уменьшить масштаб" disabled>−</button>
            <input id="{{ $controlId }}" type="range" min="1" max="3" step="0.01" value="1"
                data-banner-zoom aria-label="Масштаб изображения" disabled>
            <button type="button" data-banner-zoom-in aria-label="Увеличить масштаб" disabled>+</button>
        </div>
        <button class="entity-banner-editor__reset" type="button" data-banner-reset disabled>Сбросить</button>
    </div>
    <p class="entity-banner-editor__hint">Перетащите изображение, чтобы выбрать нужный фрагмент.</p>

    <div class="entity-banner-editor__footer">
        <div class="entity-banner-editor__actions">
            <button class="entity-banner-editor__save" type="button" data-banner-save disabled>
                Сохранить
            </button>
            <button class="entity-banner-editor__delete" type="button"
                data-banner-delete @if(!$banner) hidden @endif>
                Удалить баннер
            </button>
        </div>
        <p class="entity-banner-editor__status" data-banner-status role="status" aria-live="polite"></p>
    </div>

    <div class="entity-banner-editor__confirmation" data-banner-delete-confirm hidden>
        <p>Удалить баннер?</p>
        <div class="entity-banner-editor__confirmation-actions">
            <button type="button" data-banner-delete-yes>Удалить</button>
            <button type="button" data-banner-delete-cancel>Отмена</button>
        </div>
    </div>

    <input type="hidden" data-banner-csrf value="{{ csrf_token() }}">
</section>
@once
    <link rel="stylesheet" href="{{ asset('css/entity-banner-editor.css') }}">
    <script src="{{ asset('js/entity-banner-editor.js') }}" defer></script>
@endonce
