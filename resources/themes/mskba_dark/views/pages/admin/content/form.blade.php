@php
    $editing = $contentItem->exists;
    $title = $editing ? 'Редактирование материала' : 'Новый материал';
    $selectedType = old('type', $contentItem->type?->value ?? 'material');
    $selectedRelatedId = old('related_id', $contentItem->related_id);
    $publishInFeed = (bool) old('publish_in_feed', $contentItem->publish_in_feed ?? true);
    $publishInTelegram = (bool) old('publish_in_telegram', $contentItem->publish_in_telegram ?? false);
    $selectedChats = collect(old('telegram_chat_ids', $selectedChatIds))->map(fn ($id) => (int) $id);
    $cover = $contentItem->cover?->first();
@endphp

@extends('theme::partials.admin.list-shell', [
    'title' => $title,
    'subtitle' => 'Лента сайта и Telegram настраиваются независимо.',
])

@section('section-heading-action')
    <a class="btn btn--secondary btn--sm" href="{{ route('admin.content') }}">К списку</a>
@endsection

@section('section-content')
    @if(session('status')) <div class="alert alert-success mb-3">{{ session('status') }}</div> @endif
    @if($errors->has('content')) <div class="alert alert-danger mb-3">{{ $errors->first('content') }}</div> @endif

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
                <textarea id="contentFullDescription" class="form-control @error('full_description') is-invalid @enderror" name="full_description" rows="12" maxlength="50000" required>{{ old('full_description', $contentItem->full_description) }}</textarea>
                <small class="form-text">
                    Поддерживается Markdown, например:
                    <code>[Заполнить профиль игрока](/account/participation)</code>.
                    Произвольный HTML не выполняется.
                </small>
                @error('full_description') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
            <div class="form-group field">
                <label class="form-label" for="contentLinkUrl">Ссылка действия в материале</label>
                <input id="contentLinkUrl" class="form-control @error('link_url') is-invalid @enderror" name="link_url" value="{{ old('link_url', $contentItem->link_url) }}" maxlength="2048" placeholder="/account/participation или https://example.com">
                <small class="form-text">Необязательная отдельная кнопка в конце статьи. Telegram-сниппет всегда открывает сам материал.</small>
                @error('link_url') <div class="invalid-feedback">{{ $message }}</div> @enderror
            </div>
        </section>

        <section class="content-admin-section">
            <h2 class="content-admin-section__title">Тип и связь</h2>
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
                <div class="col-md-6 form-group field" data-content-related-field @if($selectedType === 'material') hidden @endif>
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

        <section class="content-admin-section">
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

        <button class="btn btn--primary" type="submit">{{ $editing ? 'Сохранить' : 'Создать материал' }}</button>
    </form>

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
                field.hidden = type.value === 'material';
                select.disabled = field.hidden;
                select.required = type.value === 'event' || type.value === 'venue';
                help.textContent = type.value === 'user'
                    ? 'Необязательно: без выбора материал адресован всем пользователям.'
                    : '';
            };

            type.addEventListener('change', () => renderRelated(false));
            renderRelated();

            const telegramToggle = form.querySelector('[data-content-telegram-toggle]');
            const telegramChats = form.querySelector('[data-content-telegram-chats]');
            const syncTelegram = () => { telegramChats.hidden = !telegramToggle.checked; };
            telegramToggle?.addEventListener('change', syncTelegram);
            syncTelegram();
        })();
    </script>
@endsection
