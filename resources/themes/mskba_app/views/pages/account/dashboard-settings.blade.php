@extends('theme::layouts.account', ['title' => 'Дашборд'])

@section('account-heading')
    @include('theme::pages.account.partials.section-heading', [
        'heading' => 'Дашборд',
        'intro' => 'Выбери виджеты, которые хочешь видеть в обзоре аккаунта.',
    ])
@endsection

@section('account-content')
    <section class="panel app-dashboard-settings" aria-labelledby="dashboard-settings-title">
        <div class="app-dashboard-settings__head">
            <div>
                <h2 id="dashboard-settings-title">Мои виджеты</h2>
                <p class="muted">Отмеченные виджеты появятся в обзоре. Порядок карточек меняется перетаскиванием на странице «Обзор».</p>
            </div>
            <a class="button secondary" href="{{ route('account') }}">К обзору</a>
        </div>
        @if (session('status'))
            <p class="app-dashboard-settings__notice" role="status">{{ session('status') }}</p>
        @endif
        <form method="POST" action="{{ route('account.settings.dashboard.update') }}" class="app-dashboard-settings__form">
            @csrf
            <div class="app-dashboard-settings__list">
                @forelse ($widgets as $widget)
                    @php
                        $description = trim(strip_tags((string) ($widget->body ?? '')));
                        $description = html_entity_decode($description, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                        $fallbackDescription = match ($widget->kind) {
                            'account_status' => 'Статус подтверждения и возможности аккаунта.',
                            'getting_started' => 'Подсказки по возможностям портала и твоим ролям.',
                            'message_summary' => 'Новые сообщения и уведомления.',
                            'advertisement' => 'Предложения и объявления партнёров.',
                            'custom_html' => 'Дополнительный интерактивный блок.',
                            default => 'Информационная карточка аккаунта.',
                        };
                        $description = \Illuminate\Support\Str::limit(
                            preg_replace('/\s+/u', ' ', $description ?: $fallbackDescription), 185
                        );
                        $visible = ! $widget->can_hide || ($widgetPreferences->get($widget->id)?->is_visible ?? true);
                    @endphp
                    <label class="app-dashboard-settings__item" for="dashboard-widget-{{ $widget->id }}">
                        <span class="app-dashboard-settings__description">
                            <strong>{{ $widget->title }}</strong>
                            <span>{{ $description }}</span>
                            @if (! $widget->can_hide)
                                <span class="app-dashboard-settings__required">Обязательный виджет</span>
                            @endif
                        </span>
                        <span class="app-dashboard-settings__check">
                            <input id="dashboard-widget-{{ $widget->id }}" type="checkbox"
                                   name="visible_widget_ids[]" value="{{ $widget->id }}"
                                   @checked($visible) @disabled(! $widget->can_hide)>
                            <span>{{ $widget->can_hide ? 'Показывать' : 'Всегда виден' }}</span>
                        </span>
                    </label>
                @empty
                    <p class="muted">Сейчас для твоего аккаунта нет доступных виджетов.</p>
                @endforelse
            </div>
            @if ($widgets->isNotEmpty())
                <div class="app-dashboard-settings__actions">
                    <button type="submit" class="button primary">Сохранить настройки</button>
                </div>
            @endif
        </form>
    </section>
@endsection
