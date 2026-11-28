<section class="panel app-news-comments" id="news-comments" data-news-comments
    data-content-id="{{ $contentItem->id }}" data-comments-url="{{ route('news.comments.index', $contentItem->alias) }}" data-authenticated="{{ auth()->check() ? '1' : '0' }}">
    <header><h2>Комментарии (<span data-news-comments-total>{{ $engagementSummary['comments'] }}</span>)</h2></header>
    <div class="app-venue-reviews__scroll mskba-scroll" data-news-comments-scroll tabindex="0" aria-label="Комментарии к материалу">
        <div class="app-venue-reviews__list" data-news-comments-list></div>
        <p class="muted" data-news-comments-empty hidden>Пока комментариев нет</p>
        <div class="app-venue-reviews__loading" data-news-comments-loading role="status" hidden><span class="image-upload-loading__spinner" aria-hidden="true"></span>Загружаем комментарии…</div>
        <button type="button" class="button secondary" data-news-comments-more hidden>Показать ещё</button>
    </div>
    <p class="muted" role="status" data-news-comments-status hidden></p>
    <div class="app-venue-reviews__actions">
        <button type="button" class="button primary" data-news-comment-open>Оставить комментарий</button>
        @auth
            <button type="button" class="button secondary" data-news-comments-mine aria-pressed="false">Показывать мои комментарии</button>
        @endauth
    </div>
</section>
<dialog class="app-venue-overlay app-venue-review-dialog app-news-comment-dialog" data-news-comment-dialog aria-labelledby="news-comment-dialog-title">
    <header class="app-venue-overlay__header">
        <h2 id="news-comment-dialog-title" data-news-comment-title>Оставить комментарий</h2>
        <button type="button" class="icon-button" data-news-comment-close aria-label="Закрыть"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
    </header>
    <form data-news-comment-form>
        <div class="field-group">
            <label for="news-comment-body">Комментарий появится после модерации.</label>
            <textarea id="news-comment-body" name="body" required maxlength="3000" rows="4"></textarea>
        </div>
        <p class="error" data-news-comment-error role="alert" hidden></p>
        <button type="submit" class="button primary full" data-news-comment-submit>Отправить</button>
    </form>
</dialog>
