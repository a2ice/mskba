<dialog class="app-venue-overlay app-venue-review-dialog" data-review-dialog aria-labelledby="app-venue-review-title">
    <header class="app-venue-overlay__header">
        <h2 id="app-venue-review-title" data-review-title>Оценить площадку</h2>
        <button type="button" class="icon-button" data-review-close aria-label="Закрыть"><svg aria-hidden="true" viewBox="0 0 24 24"><path d="M18 6L6 18M6 6l12 12"/></svg></button>
    </header>
    @auth
        <form data-review-form>
            <output class="app-venue-review-dialog__value" for="venue-review-rating" data-review-value>5</output>
            <div class="app-player-workspace__field app-venue-review-dialog__range">
                <label class="sr-only" for="venue-review-rating">Оценка от 1 до 5</label>
                <input id="venue-review-rating" type="range" name="rating" min="1" max="5" step="1" value="5">
                <div class="app-venue-review-dialog__range-labels" aria-hidden="true"><span>1</span><span>5</span></div>
            </div>
            <div class="field-group">
                <label for="venue-review-body">Будем признательны за отзыв, он появится на этой странице в блоке «Отзывы» после модерации.</label>
                <textarea id="venue-review-body" name="body" maxlength="3000" rows="4"></textarea>
            </div>
            <p class="error" data-review-error role="alert" hidden></p>
            <div class="app-venue-overlay__actions">
                <button type="submit" class="button primary full" data-review-submit>Оставить оценку</button>
            </div>
        </form>
    @else
        <p>Для того чтобы оценить площадку и оставить отзыв, необходимо авторизоваться.</p>
        <button type="button" class="button primary full" data-review-auth-start>Войти</button>
        <p class="error" data-review-auth-error role="alert" hidden></p>
    @endauth
</dialog>
<dialog class="app-venue-overlay app-venue-review-dialog app-venue-review-thanks" data-review-thanks aria-labelledby="app-venue-review-thanks-title">
    <h2 id="app-venue-review-thanks-title">Спасибо за оценку!</h2>
    <p class="muted" data-review-thanks-note hidden>Отзыв появится после модерации.</p>
    <button type="button" class="button primary full" data-review-close>Закрыть</button>
</dialog>
