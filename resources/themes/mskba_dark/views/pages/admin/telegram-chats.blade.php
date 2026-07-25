@php $title = 'Telegram-чаты'; @endphp

@extends('theme::partials.admin.list-shell', [
    'title' => $title,
    'subtitle' => 'Чаты, в которые можно публиковать опросы и согласования.',
])

@section('section-content')
    @if(session('status')) <div class="alert alert-success mb-3">{{ session('status') }}</div> @endif

    <div class="admin-settings-grid">
        <section class="admin-settings-group">
            <h2 class="admin-settings-group__title">Подключить чат</h2>
            <p class="admin-settings-group__description">Добавьте числовой ID чата, в котором бот уже состоит и может публиковать сообщения.</p>
            <form method="POST" action="{{ route('admin.telegram-chats.store') }}" class="admin-settings-fields">
                @csrf
                <div class="admin-readonly-field">
                    <label for="telegramChatId">ID чата</label>
                    <input id="telegramChatId" class="form-control" type="number" name="telegram_chat_id" value="{{ old('telegram_chat_id') }}" required>
                </div>
                <div class="admin-readonly-field">
                    <label for="telegramChatTitle">Название</label>
                    <input id="telegramChatTitle" class="form-control" name="title" value="{{ old('title') }}" maxlength="255" required>
                </div>
                <button class="btn btn--primary btn--sm" type="submit">Подключить</button>
            </form>
        </section>

        @foreach($chats as $chat)
            <section class="admin-settings-group">
                <h2 class="admin-settings-group__title">{{ $chat->title ?: 'Чат '.$chat->telegram_chat_id }}</h2>
                <p class="admin-settings-group__description">ID: {{ $chat->telegram_chat_id }} · {{ $chat->type ?: 'тип не указан' }}</p>
                <form method="POST" action="{{ route('admin.telegram-chats.update', $chat) }}" class="admin-settings-fields">
                    @csrf
                    @method('PUT')
                    <div class="admin-readonly-field">
                        <label for="telegramChatTitle{{ $chat->id }}">Название</label>
                        <input id="telegramChatTitle{{ $chat->id }}" class="form-control" name="title" value="{{ $chat->title }}" maxlength="255" required>
                    </div>
                    <input type="hidden" name="is_active" value="0">
                    <label class="form-check">
                        <input class="form-check-input" type="checkbox" name="is_active" value="1" @checked($chat->is_active)>
                        <span class="form-check-label">Чат активен</span>
                    </label>
                    <input type="hidden" name="publishes_coordination" value="0">
                    <label class="form-check">
                        <input class="form-check-input" type="checkbox" name="publishes_coordination" value="1" @checked($chat->publishes_coordination)>
                        <span class="form-check-label">Публиковать опросы</span>
                    </label>
                    <button class="btn btn--secondary btn--sm" type="submit">Сохранить</button>
                </form>
            </section>
        @endforeach
    </div>
@endsection
