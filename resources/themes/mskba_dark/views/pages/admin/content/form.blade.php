@php
    $editing = $contentItem->exists;
    $selectedType = old('type', $contentItem->type?->value ?? 'material');
    $title = $editing
        ? ($selectedType === 'faq' ? 'Редактирование FAQ' : 'Редактирование материала')
        : 'Новый материал';
    $selectedFormat = old('content_format', $contentItem->content_format?->value ?? 'safe_html');
    $selectedRelatedId = old('related_id', $contentItem->related_id);
    $publishInFeed = (bool) old('publish_in_feed', $contentItem->publish_in_feed ?? true);
    $publishInTelegram = (bool) old('publish_in_telegram', $contentItem->publish_in_telegram ?? false);
    $selectedChats = collect(old('telegram_chat_ids', $selectedChatIds))->map(fn ($id) => (int) $id);
    $cover = $contentItem->cover?->first();
    $tagsValue = old('tags', $editing ? $contentItem->tags->pluck('name')->implode(', ') : '');
    $inlineImages = $editing ? $contentItem->inlineImages : collect();
@endphp

@extends('theme::partials.admin.list-shell', [
    'title' => $title,
    'subtitle' => 'Контент, FAQ, лента сайта и Telegram управляются из одного редактора.',
])

@section('section-heading-action')
    <a class="btn btn--secondary btn--sm" href="{{ route('admin.content') }}">К списку</a>
@endsection

@section('section-content')
    @if(session('status')) <div class="alert alert-success mb-3">{{ session('status') }}</div> @endif
    @if($errors->has('content')) <div class="alert alert-danger mb-3">{{ $errors->first('content') }}</div> @endif

    <section class="content-admin-shortcodes mb-4">
        <h2 class="content-admin-shortcodes__title">Shortcodes редактора</h2>
        <p class="mb-3">Вставляйте shortcode прямо в поле «Полное описание». При выводе он безопасно заменяется нужным элементом.</p>
        <div class="content-admin-shortcodes__list">
            <div><code>[image id="17"]</code> — изображение из блока «Изображения материала»; <code>view="wide"</code> растягивает его по ширине контента.</div>
            <div><code>[popup type="venue" id="7" view="short"]Название площадки[/popup]</code> — кликабельная краткая карточка площадки.</div>
            <div><code>[creation_requirements topic="venues"]</code> — актуальные системные условия создания сущности. Темы: <code>venues, events, teams, tournaments, coordination, sections</code>.</div>
            <div><code>[anchor id="contact-confirmation"]</code> — якорь внутри статьи для прямой ссылки на конкретный раздел.</div>
        </div>
    </section>

    <form
        method="POST"
        action="{{ $editing ? route('admin.content.update', $contentItem->alias) : route('admin.content.store') }}"
        enctype="multipart/form-data"
        class="content-admin-form"
        data-content-form
    >
        @csrf
        @if($editing) @method('PUT') @endif

        <section class="content-admin-section">
            <h2 class="content-admin-section__title">Материал</h2>
            <div class="form-group field mb-3">
                <label class="form-label" for="contentTitle">Название</label>
                <input id="contentTitle" class="form-control @error('title') is-invalid @enderror" name="title" value="{{ old('title', $contentItem->title) }}" maxlength="255" required>
                @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="form-group field mb-3">
                <label class="form-label" for="contentShortDescription">Краткое описание</label>
                <textarea id="contentShortDescription" class="form-control @error('short_description') is-invalid @enderror" name="short_description" rows="3" maxlength="1000" required>{{ old('short_description', $contentItem->short_description) }}</textarea>
                @error('short_description') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="form-group field mb-3">
                <label class="form-label" for="contentFullDescription">Полное описание</label>
                <textarea id="contentFullDescription" class="form-control @error('full_description') is-invalid @enderror" name="full_description" rows="14" maxlength="50000" required>{{ old('full_description', $contentItem->full_description) }}</textarea>
                @error('full_description') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="form-group field mb-3">
                <label class="form-label" for="contentFormat">Формат содержимого</label>
                <select id="contentFormat" class="form-select @error('content_format') is-invalid @enderror" name="content_format" required>
                    @foreach($formats as $format)
                        <option value="{{ $format->value }}" @selected($selectedFormat === $format->value)>{{ $format->label() }}</option>
                    @endforeach
                </select>
                <small class="form-text">
                    Для новых материалов используйте безопасный HTML. Разрешены:
                    <code>p, div, span, br, strong, em, u, s, mark, h2–h4, ul, ol, li, blockquote, pre, code, a, hr, figure, figcaption, img, table, thead, tbody, tr, th, td</code>.
                    Допустимые классы:
                    <code>content-lead, content-note, content-accent, content-muted, content-columns, content-image, content-image--wide, content-table</code>.
                    Скрипты, inline-стили и прочие опасные конструкции будут удалены сервером.
                </small>
                @error('content_format') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="form-group field">
                <label class="form-label" for="contentLinkUrl">Ссылка действия в материале</label>
                <input id="contentLinkUrl" class="form-control @error('link_url') is-invalid @enderror" name="link_url" value="{{ old('link_url', $contentItem->link_url) }}" maxlength="2048" placeholder="/account/participation или https://example.com">
                <small class="form-text">Необязательная отдельная кнопка в конце статьи. Telegram-сниппет всегда открывает сам материал.</small>
                @error('link_url') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
        </section>

        <section class="content-admin-section">
            <h2 class="content-admin-section__title">Тип и теги</h2>
            <div class="row g-3">
                <div class="col-md-6 form-group field">
                    <label class="form-label" for="contentType">Тип контента</label>
                    <select id="contentType" class="form-select @error('type') is-invalid @enderror" name="type" data-content-type required>
                        @foreach($types as $type)
                            <option value="{{ $type->value }}" @selected($selectedType === $type->value)>{{ $type->label() }}</option>
                        @endforeach
                    </select>
                    @error('type') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
                <div class="col-md-6 form-group field" data-content-related-field @if(in_array($selectedType, ['material', 'faq'], true)) hidden @endif>
                    <label class="form-label" for="contentRelatedId">Связанная сущность</label>
                    <select id="contentRelatedId" class="form-select @error('related_id') is-invalid @enderror" name="related_id" data-content-related>
                        <option value="">Выберите</option>
                    </select>
                    <small class="form-text" data-content-related-help></small>
                    @error('related_id') <div class="invalid-feedback">{{ $message }}</div> @enderror
                </div>
            </div>
            <script type="application/json" data-content-related-options>@json($relatedEntities)</script>
            <input type="hidden" data-content-related-selected value="{{ $selectedRelatedId }}">

            <div class="form-group field mt-3">
                <label class="form-label" for="contentTags">Теги</label>
                <input id="contentTags" class="form-control @error('tags') is-invalid @enderror" name="tags" value="{{ $tagsValue }}" maxlength="3000" placeholder="площадка, добавление площадки, подтверждение контакта">
                <small class="form-text">Через запятую. Для FAQ predictive-поиск работает именно по этим тегам, а не по заголовку или тексту статьи.</small>
                @error('tags') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>

            @if($editing && $contentItem->system_key)
                <div class="form-text mt-3">Системный ключ: <code>{{ $contentItem->system_key }}</code>. Он закрепляет эту запись за системной FAQ-ссылкой и из редактора не меняется.</div>
            @endif
        </section>

        <section class="content-admin-section">
            <h2 class="content-admin-section__title">SEO материала</h2>
            <p class="form-text mb-3">Если оставить поля пустыми, будут использованы название и краткое описание материала.</p>
            <div class="form-group field mb-3">
                <label class="form-label" for="contentMetaTitle">Meta title</label>
                <input id="contentMetaTitle" class="form-control @error('meta_title') is-invalid @enderror" name="meta_title" value="{{ old('meta_title', $contentItem->meta_title) }}" maxlength="255">
                @error('meta_title') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="form-group field mb-3">
                <label class="form-label" for="contentMetaDescription">Meta description</label>
                <textarea id="contentMetaDescription" class="form-control @error('meta_description') is-invalid @enderror" name="meta_description" rows="3" maxlength="320">{{ old('meta_description', $contentItem->meta_description) }}</textarea>
                @error('meta_description') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="form-group field">
                <label class="form-label" for="contentMetaKeywords">Meta keywords</label>
                <input id="contentMetaKeywords" class="form-control @error('meta_keywords') is-invalid @enderror" name="meta_keywords" value="{{ old('meta_keywords', $contentItem->meta_keywords) }}" maxlength="1000" placeholder="баскетбол, Москва, игры">
                <small class="form-text">Через запятую. Это SEO keywords и они не участвуют в поиске FAQ; для поиска используйте поле «Теги» выше.</small>
                @error('meta_keywords') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
        </section>

        <section class="content-admin-section">
            <h2 class="content-admin-section__title">Обложка</h2>
            @if($cover)
                <img class="content-admin-cover" src="{{ $cover->publicUrl() }}" alt="" width="320" height="180">
            @endif
            <div class="form-group field">
                <label class="form-label" for="contentCover">{{ $cover ? 'Заменить обложку' : 'Добавить обложку' }}</label>
                <input id="contentCover" class="form-control @error('cover') is-invalid @enderror" type="file" name="cover" accept="image/jpeg,image/png,image/webp">
                <small class="form-text">JPEG, PNG или WebP · до 5 МБ. Изображение будет уменьшено до 1200 пикселей по большей стороне.</small>
                @error('cover') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
        </section>

        <section class="content-admin-section" data-content-publication-section @if($selectedType === 'faq') hidden @endif>
            <h2 class="content-admin-section__title">Публикация</h2>
            <div class="content-publication-options">
                @include('theme::partials.forms.toggle', [
                    'name' => 'publish_in_feed',
                    'id' => 'publishInFeed',
                    'title' => 'Публиковать в ленте',
                    'description' => 'Материал появится в разделе «Новости»',
                    'checked' => $publishInFeed,
                ])
                @include('theme::partials.forms.toggle', [
                    'name' => 'publish_in_telegram',
                    'id' => 'publishInTelegram',
                    'title' => 'Публиковать в Telegram',
                    'description' => 'Сниппет будет создан или обновлён в выбранных чатах',
                    'checked' => $publishInTelegram,
                    'inputAttributes' => ['data-content-telegram-toggle' => true],
                ])
            </div>
            <div class="content-chat-options" data-content-telegram-chats @if(! $publishInTelegram) hidden @endif>
                <h3 class="content-chat-options__title">Telegram-чаты</h3>
                @forelse($telegramChats as $chat)
                    <label class="content-chat-option">
                        <input type="checkbox" name="telegram_chat_ids[]" value="{{ $chat->id }}" @checked($selectedChats->contains($chat->id))>
                        <span>{{ $chat->title ?: 'Чат '.$chat->telegram_chat_id }}</span>
                    </label>
                @empty
                    <p class="form-text">Активных Telegram-чатов пока нет.</p>
                @endforelse
                @error('telegram_chat_ids') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
                @error('telegram_chat_ids.*') <div class="invalid-feedback d-block">{{ $message }}</div> @enderror
            </div>
        </section>

        <div class="content-admin-faq-note" data-content-faq-publication-note @if($selectedType !== 'faq') hidden @endif>
            FAQ публикуется в разделе справки и не отправляется в новостную ленту или Telegram.
        </div>

        <button class="btn btn--primary" type="submit">{{ $editing ? 'Сохранить' : 'Создать материал' }}</button>
    </form>

    @if($editing)
        <section class="content-admin-section content-inline-images mt-4">
            <h2 class="content-admin-section__title">Изображения материала</h2>
            <p class="form-text mb-3">Загрузите изображение, затем нажмите «Вставить в текст». Shortcode будет добавлен в позицию курсора поля «Полное описание».</p>

            @error('image') <div class="alert alert-danger mb-3">{{ $message }}</div> @enderror

            <form method="POST" action="{{ route('admin.content.images.store', $contentItem->alias) }}" enctype="multipart/form-data" class="content-inline-images__upload mb-4">
                @csrf
                <div class="row g-3">
                    <div class="col-md-5 form-group field">
                        <label class="form-label" for="contentInlineImage">Изображение</label>
                        <input id="contentInlineImage" class="form-control" type="file" name="image" accept="image/jpeg,image/png,image/webp" required>
                    </div>
                    <div class="col-md-3 form-group field">
                        <label class="form-label" for="contentInlineImageTitle">Alt / название</label>
                        <input id="contentInlineImageTitle" class="form-control" name="title" maxlength="255">
                    </div>
                    <div class="col-md-4 form-group field">
                        <label class="form-label" for="contentInlineImageDescription">Подпись</label>
                        <input id="contentInlineImageDescription" class="form-control" name="description" maxlength="1000">
                    </div>
                </div>
                <button class="btn btn--secondary mt-3" type="submit">Загрузить изображение</button>
            </form>

            @if($inlineImages->isEmpty())
                <p class="form-text mb-0">Внутренних изображений пока нет.</p>
            @else
                <div class="content-inline-images__grid">
                    @foreach($inlineImages as $media)
                        @php $shortcode = '[image id="'.$media->id.'"]'; @endphp
                        <article class="content-inline-image">
                            <img src="{{ $media->publicUrl() }}" alt="{{ $media->title }}">
                            <div class="content-inline-image__body">
                                <strong>#{{ $media->id }} {{ $media->title ?: 'Без названия' }}</strong>
                                @if($media->description)<span>{{ $media->description }}</span>@endif
                                <code>{{ $shortcode }}</code>
                                <div class="content-admin-actions">
                                    <button class="btn btn--secondary btn--sm" type="button" data-content-image-insert="{{ $shortcode }}">Вставить в текст</button>
                                    <button class="btn btn--secondary btn--sm" type="button" data-content-image-insert="[image id=&quot;{{ $media->id }}&quot; view=&quot;wide&quot;]">Вставить wide</button>
                                    <form method="POST" action="{{ route('admin.content.images.destroy', [$contentItem->alias, $media->id]) }}" onsubmit="return confirm('Удалить изображение? Shortcode в тексте нужно будет убрать вручную.');">
                                        @csrf
                                        @method('DELETE')
                                        <button class="btn btn--danger btn--sm" type="submit">Удалить</button>
                                    </form>
                                </div>
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
        </section>
    @else
        <p class="form-text mt-3">После первого сохранения появится блок загрузки изображений с готовыми shortcode для вставки в текст.</p>
    @endif

    <script>
        (() => {
            const form = document.querySelector('[data-content-form]');
            if (!form) return;

            const type = form.querySelector('[data-content-type]');
            const field = form.querySelector('[data-content-related-field]');
            const select = form.querySelector('[data-content-related]');
            const help = form.querySelector('[data-content-related-help]');
            const selected = form.querySelector('[data-content-related-selected]')?.value || '';
            const options = JSON.parse(form.querySelector('[data-content-related-options]')?.textContent || '{}');
            const publication = form.querySelector('[data-content-publication-section]');
            const faqPublicationNote = form.parentElement.querySelector('[data-content-faq-publication-note]');
            const feedToggle = form.querySelector('[name="publish_in_feed"]');
            const telegramToggle = form.querySelector('[data-content-telegram-toggle]');
            const telegramChats = form.querySelector('[data-content-telegram-chats]');

            const renderRelated = (preserveValue = true) => {
                const value = preserveValue ? (select.value || selected) : '';
                const entries = options[type.value] || [];
                select.innerHTML = '<option value="">Выберите</option>';
                entries.forEach((entry) => {
                    const option = document.createElement('option');
                    option.value = String(entry.id);
                    option.textContent = entry.label;
                    option.selected = String(entry.id) === String(value);
                    select.append(option);
                });
                field.hidden = type.value === 'material' || type.value === 'faq';
                select.disabled = field.hidden;
                select.required = type.value === 'event' || type.value === 'venue';
                help.textContent = type.value === 'user'
                    ? 'Необязательно: без выбора материал адресован всем пользователям.'
                    : '';
            };

            const syncPublication = () => {
                const isFaq = type.value === 'faq';
                publication.hidden = isFaq;
                faqPublicationNote.hidden = !isFaq;
                if (isFaq) {
                    if (feedToggle) feedToggle.checked = false;
                    if (telegramToggle) telegramToggle.checked = false;
                }
                telegramChats.hidden = isFaq || !telegramToggle?.checked;
            };

            type.addEventListener('change', () => {
                renderRelated(false);
                syncPublication();
            });
            telegramToggle?.addEventListener('change', syncPublication);
            renderRelated();
            syncPublication();

            const textarea = document.getElementById('contentFullDescription');
            document.querySelectorAll('[data-content-image-insert]').forEach((button) => {
                button.addEventListener('click', () => {
                    if (!textarea) return;
                    const shortcode = button.dataset.contentImageInsert || '';
                    const start = textarea.selectionStart ?? textarea.value.length;
                    const end = textarea.selectionEnd ?? start;
                    const prefix = start > 0 && textarea.value[start - 1] !== '\n' ? '\n' : '';
                    const suffix = end < textarea.value.length && textarea.value[end] !== '\n' ? '\n' : '';
                    textarea.setRangeText(prefix + shortcode + suffix, start, end, 'end');
                    textarea.focus();
                    textarea.dispatchEvent(new Event('input', { bubbles: true }));
                });
            });
        })();
    </script>
@endsection
