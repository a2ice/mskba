const root = document.querySelector('[data-venue-reviews]');
if (root?.querySelector('[data-review-list]')) {
    const list = root.querySelector('[data-review-list]');
    const scroll = root.querySelector('[data-review-scroll]');
    const sentinel = root.querySelector('[data-review-sentinel]');
    const empty = root.querySelector('[data-review-empty]');
    const loading = root.querySelector('[data-review-loading]');
    const retry = root.querySelector('[data-review-retry]');
    const mineButton = root.querySelector('[data-review-mine]');
    const dialog = document.querySelector('[data-review-dialog]');
    const thanks = document.querySelector('[data-review-thanks]');
    const form = dialog?.querySelector('[data-review-form]');
    const range = form?.elements.rating;
    const body = form?.elements.body;
    const value = form?.querySelector('[data-review-value]');
    const error = form?.querySelector('[data-review-error]');
    const submit = form?.querySelector('[data-review-submit]');
    const heading = document.querySelector('[data-venue-heading-rating]');
    const title = dialog?.querySelector('[data-review-title]');
    let mine = false, cursor = null, done = false, busy = false, failed = false, generation = 0, pending, editing = null;

    function updateRating(rating) {
        if (!heading || !rating) return;
        const number = heading.querySelector('[data-review-heading-value]');
        const present = rating.value != null;
        number.hidden = !present;
        number.textContent = present ? Number(rating.value).toFixed(1) : '';
        heading.classList.toggle('is-unrated', !present);
        const label = present ? 'Рейтинг площадки: ' + number.textContent + ' из 5 · оценок: ' + rating.count : 'Пока нет оценок игроков';
        heading.title = label;
        heading.setAttribute('aria-label', 'Оценить площадку. ' + label);
        heading.querySelectorAll('[data-review-fill]').forEach(stop =>
            stop.setAttribute('offset', (present ? Math.min(100, Math.max(0, rating.value * 20)) : 0) + '%'));
    }

    function node(tag, className, text) {
        const element = document.createElement(tag);
        if (className) element.className = className;
        if (text != null) element.textContent = text;
        return element;
    }

    function renderReview(review) {
        const article = node('article', 'app-venue-review');
        const author = node('div', 'app-venue-review__author');
        const avatar = node('span', 'app-venue-review__avatar');
        if (review.avatar) {
            const img = node('img');
            img.alt = '';
            img.loading = 'lazy';
            img.src = review.avatar;
            img.addEventListener('error', () => { img.remove(); avatar.textContent = review.author.slice(0, 1).toUpperCase(); });
            avatar.append(img);
        } else {
            avatar.textContent = review.author.slice(0, 1).toUpperCase();
        }
        author.append(avatar, node('strong', '', review.author));
        const detail = node('div', 'app-venue-review__detail');
        const stars = node('span', 'app-venue-review__stars', '★'.repeat(review.rating) + '☆'.repeat(5 - review.rating));
        stars.setAttribute('role', 'img');
        stars.setAttribute('aria-label', review.rating + ' из 5');
        detail.append(stars);
        if (review.body) detail.append(node('p', 'app-venue-review__body', review.body));
        if (review.own && !review.published) detail.append(node('span', 'muted', 'На модерации'));
        if (review.own) {
            const edit = node('button', 'button secondary app-venue-review__edit', 'Редактировать');
            edit.type = 'button';
            edit.addEventListener('click', () => open(review));
            detail.append(edit);
        }
        article.append(author, detail);
        list.append(article);
    }

    async function load() {
        if (busy || done || failed) return;
        const current = generation;
        pending = new AbortController();
        busy = true;
        loading.hidden = false;
        retry.hidden = true;
        try {
            const url = new URL(root.dataset.reviewsUrl, location.href);
            if (mine) url.searchParams.set('mine', '1');
            if (cursor != null) url.searchParams.set('before', cursor);
            const response = await fetch(url, {headers: {'Accept': 'application/json'}, signal: pending.signal, credentials: 'same-origin'});
            if (!response.ok) throw new Error('Не удалось загрузить отзывы');
            const data = await response.json();
            if (current !== generation) return;
            data.reviews.forEach(renderReview);
            cursor = data.next;
            done = cursor == null;
            empty.textContent = mine ? 'У тебя пока нет отзывов' : 'Пока отзывов нет';
            empty.hidden = list.childElementCount > 0;
            updateRating(data.rating);
        } catch (e) {
            if (current !== generation || e.name === 'AbortError') return;
            failed = true;
            retry.hidden = false;
        } finally {
            if (current === generation) {
                busy = false;
                loading.hidden = true;
                // Fill an underfilled scroll area too, then wait for user scrolling.
                if (!done && !failed && sentinel.getBoundingClientRect().top <= scroll.getBoundingClientRect().bottom + 30) {
                    requestAnimationFrame(load);
                }
            }
        }
    }

    function reset() {
        ++generation;
        pending?.abort();
        busy = done = failed = false;
        cursor = null;
        list.replaceChildren();
        empty.hidden = true;
        retry.hidden = true;
        scroll.scrollTop = 0;
        load();
    }

    function setMine(next) {
        mine = next;
        mineButton?.setAttribute('aria-pressed', String(mine));
        if (mineButton) mineButton.textContent = mine ? 'Показывать все отзывы' : 'Показывать мои отзывы';
        reset();
    }

    function open(review = null) {
        if (!dialog || dialog.open) return;
        editing = review?.id ?? null;
        if (form) {
            form.reset();
            range.value = review?.rating ?? 5;
            body.value = review?.body ?? '';
            value.textContent = range.value;
            title.textContent = editing ? 'Редактировать отзыв' : 'Оценить площадку';
            submit.textContent = editing ? 'Сохранить отзыв' : 'Оставить оценку';
            error.hidden = true;
        }
        dialog.showModal();
    }

    document.querySelectorAll('[data-venue-review-open]').forEach(button => button.addEventListener('click', () => open()));
    for (const modal of [dialog, thanks]) {
        modal?.querySelectorAll('[data-review-close]').forEach(button => button.addEventListener('click', () => modal.close()));
    }
    range?.addEventListener('input', () => { value.textContent = range.value; });
    form?.addEventListener('submit', async event => {
        event.preventDefault();
        if (submit.disabled) return;
        submit.disabled = true;
        error.hidden = true;
        try {
            const endpoint = editing ? root.dataset.reviewsUrl + '/' + editing : root.dataset.reviewsUrl;
            const response = await fetch(endpoint, {method: editing ? 'PATCH' : 'POST', credentials: 'same-origin',
                headers: {'Accept': 'application/json', 'Content-Type': 'application/json', 'X-CSRF-TOKEN': root.dataset.csrf},
                body: JSON.stringify({rating: Number(range.value), body: body.value})});
            const data = await response.json();
            if (!response.ok) {
                const message = Object.values(data.errors || {}).flat()[0] || data.message;
                throw new Error(message || 'Не удалось сохранить оценку. Попробуй ещё раз.');
            }
            updateRating(data.rating);
            dialog.close();
            thanks.querySelector('[data-review-thanks-note]').hidden = data.published;
            thanks.querySelector('h2').textContent = editing ? 'Спасибо! Отзыв сохранён.' : 'Спасибо за оценку!';
            thanks.showModal();
            reset();
        } catch (e) {
            error.textContent = e.message || 'Не удалось сохранить оценку.';
            error.hidden = false;
        } finally {
            submit.disabled = false;
        }
    });
    mineButton?.addEventListener('click', () => setMine(!mine));
    retry.addEventListener('click', () => { failed = false; load(); });
    scroll.addEventListener('scroll', () => {
        if (scroll.scrollTop + scroll.clientHeight >= scroll.scrollHeight - 40) load();
    }, {passive: true});
    load();
}
