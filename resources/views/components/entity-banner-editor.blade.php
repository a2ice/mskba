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
@endphp
<section class="entity-banner-editor panel"
    data-entity-banner-editor
    data-banner-context="{{ $context }}"
    data-upload-url="{{ $updateUrl }}"
    data-delete-url="{{ $deleteUrl }}"
    data-original-url="{{ $sourceUrl }}"
    data-initial-crop="{{ json_encode($banner?->crop_applied ?? []) }}"
    aria-label="Редактирование баннера">
    <header class="entity-banner-editor__heading">
        <h2>Баннер</h2>
        <p>Широкое изображение в верхней части публичной страницы.</p>
    </header>

    <div class="entity-banner-editor__saved" data-banner-saved @if(!$banner) hidden @endif>
        <span class="entity-banner-editor__caption">Сохранённый баннер</span>
        <div class="entity-banner-editor__saved-image">
            <img data-banner-published @if($banner) src="{{ $banner->publicUrl() }}" @endif alt="Текущий баннер" loading="lazy">
        </div>
    </div>

    <div class="entity-banner-editor__workspace">
        <label class="entity-banner-editor__choose" data-banner-dropzone>
            <input type="file" data-banner-file accept="image/jpeg,image/png,image/webp" aria-label="Выбрать изображение для баннера">
            <span class="entity-banner-editor__choose-text" data-banner-choose-label>{{ $banner ? 'Заменить изображение' : 'Выбрать изображение' }}</span>
            <span class="entity-banner-editor__file-hint">JPG, PNG или WebP · до 10 МБ</span>
        </label>

        <div class="entity-banner-editor__canvas-wrap">
            <canvas width="1200" height="400" data-banner-canvas
                tabindex="0"
                role="img"
                aria-label="Предпросмотр баннера. Перетаскивайте изображение или используйте стрелки на клавиатуре."></canvas>
            <p class="entity-banner-editor__empty" data-banner-empty>
                Выберите изображение, чтобы настроить его положение и масштаб.
            </p>
        </div>

        <div class="entity-banner-editor__controls">
            <label for="banner-zoom-{{ $context }}-{{ $type }}-{{ $id }}">Масштаб</label>
            <input id="banner-zoom-{{ $context }}-{{ $type }}-{{ $id }}" type="range"
                min="1" max="3" step="0.01" value="1" data-banner-zoom aria-label="Масштаб изображения">
            <button class="button secondary btn btn--secondary" type="button" data-banner-reset>Сбросить</button>
        </div>
        <p class="entity-banner-editor__hint">Перетаскивайте изображение, чтобы выбрать нужную часть.</p>
    </div>

    <footer class="entity-banner-editor__footer">
        <div class="entity-banner-editor__actions">
            <button class="button primary btn btn--primary" type="button" data-banner-save disabled>Сохранить баннер</button>
            <button class="button danger btn btn--danger" type="button" data-banner-delete @if(!$banner) hidden @endif>Удалить баннер</button>
        </div>
        <p class="entity-banner-editor__status" data-banner-status role="status" aria-live="polite"></p>
    </footer>
    <dialog class="mskba-modal entity-banner-delete-dialog" data-banner-confirm
        aria-labelledby="banner-delete-title-{{ $context }}-{{ $type }}-{{ $id }}">
        <header class="mskba-modal__header entity-banner-delete-dialog__header">
            <h2 id="banner-delete-title-{{ $context }}-{{ $type }}-{{ $id }}">Удалить баннер?</h2>
            <button type="button" class="entity-banner-delete-dialog__close"
                data-banner-cancel aria-label="Закрыть">×</button>
        </header>
        <div class="mskba-modal__body entity-banner-delete-dialog__body">
            <p>Баннер исчезнет со страницы. Остальные изображения сохранятся.</p>
        </div>
        <footer class="mskba-modal__footer entity-banner-delete-dialog__footer">
            <button class="button secondary btn btn--secondary" type="button" data-banner-cancel>Отмена</button>
            <button class="button danger btn btn--danger" type="button" data-banner-confirm-delete>Удалить</button>
        </footer>
    </dialog>
    <input type="hidden" data-banner-csrf value="{{ csrf_token() }}">
</section>
@once
    <link rel="stylesheet" href="{{ asset('css/entity-banner-editor.css') }}">
    <script src="{{ asset('js/entity-banner-editor.js') }}" defer></script>
@endonce
