<section class="panel app-venue-block" id="app-venue-reviews" data-venue-reviews
    data-reviews-url="{{ route('venues.reviews.index', $venue->id) }}"
    data-authenticated="{{ auth()->check() ? '1' : '0' }}"
    data-csrf="{{ csrf_token() }}">
    <header class="app-venue-block__header"><h2>Отзывы</h2></header>
    @if($venue->statusSlug === 'confirmed')
        <div class="app-venue-reviews__scroll mskba-scroll" data-review-scroll tabindex="0" aria-label="Отзывы игроков">
            <div class="app-venue-reviews__list" data-review-list></div>
            <p class="muted" data-review-empty hidden>Пока отзывов нет</p>
            <div class="app-venue-reviews__loading" data-review-loading role="status" hidden>
                <span class="image-upload-loading__spinner" aria-hidden="true"></span><span>Загружаем отзывы…</span>
            </div>
            <button type="button" class="button secondary" data-review-retry hidden>Повторить загрузку</button>
            <div data-review-sentinel aria-hidden="true"></div>
        </div>
        <div class="app-venue-reviews__actions">
            <button type="button" class="button primary" data-venue-review-open>Оценить площадку</button>
            @auth
                <button type="button" class="button secondary" data-review-mine aria-pressed="false">Показывать мои отзывы</button>
            @endauth
        </div>
    @else
        <p class="muted">Пока отзывов нет</p>
    @endif
</section>
