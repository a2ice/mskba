@php
    /** @var \Illuminate\Database\Eloquent\Model $model */
    $targets = app(\App\Modules\Media\Application\Services\EntityBannerTargets::class);
    $type = $targets->type($model);
    $id = $targets->id($model);
    $context = $context ?? 'account';
    $banner = $model->banner;
    $updateUrl = route('entity-banners.update', compact('context', 'type', 'id'));
    $sourceUrl = $banner?->original_path
        ? route('entity-banners.source', compact('context', 'type', 'id'))
        : null;
    $deleteUrl = route('entity-banners.destroy', compact('context', 'type', 'id'));
@endphp
<section class="entity-banner-editor"
    data-entity-banner-editor
    data-upload-url="{{ $updateUrl }}"
    data-delete-url="{{ $deleteUrl }}"
    data-original-url="{{ $sourceUrl }}"
    aria-label="Редактор баннера">
    <div class="entity-banner-editor__heading">
        <div>
            <h3>Баннер</h3>
            <p>Дополнительная широкая обложка для публичной страницы. Логотип, аватар и галерея не изменяются.</p>
        </div>
    </div>
    <div class="entity-banner-editor__saved" data-banner-saved @if(!$banner) hidden @endif>
        <img data-banner-published src="{{ $banner?->publicUrl() ?? '' }}" alt="Текущий баннер" loading="lazy">
    </div>
    <div class="entity-banner-editor__editor">
        <label class="entity-banner-editor__choose">
            <input type="file" data-banner-file accept="image/jpeg,image/png,image/webp">
            <span>Выбрать изображение</span>
            <small>JPEG, PNG, WebP · до 10 МБ</small>
        </label>
        <div class="entity-banner-editor__canvas-wrap">
            <canvas width="1200" height="400" data-banner-canvas
                role="img" aria-label="Предпросмотр кадрирования баннера в формате 3 к 1"></canvas>
            <p class="entity-banner-editor__empty" data-banner-empty>
                Выберите изображение, чтобы настроить кадр и масштаб.
            </p>
        </div>
        <div class="entity-banner-editor__controls">
            <label for="banner-zoom-{{ $context }}-{{ $type }}-{{ $id }}">Масштаб</label>
            <input id="banner-zoom-{{ $context }}-{{ $type }}-{{ $id }}" type="range"
                min="1" max="3" step="0.01" value="1" data-banner-zoom aria-label="Увеличить или уменьшить изображение">
            <button type="button" data-banner-reset>Сбросить</button>
        </div>
        <p class="entity-banner-editor__hint">Перетаскивайте изображение в рамке; изменяйте масштаб ползунком. Оригинал сохраняется отдельно.</p>
    </div>
    <div class="entity-banner-editor__actions">
        <button type="button" data-banner-save disabled>Сохранить баннер</button>
        <button type="button" data-banner-delete @if(!$banner) hidden @endif>Удалить баннер</button>
        <span data-banner-status role="status" aria-live="polite"></span>
    </div>
    <input type="hidden" data-banner-csrf value="{{ csrf_token() }}">
</section>
@once
    <link rel="stylesheet" href="{{ asset('css/entity-banner-editor.css') }}">
    <script src="{{ asset('js/entity-banner-editor.js') }}" defer></script>
@endonce
