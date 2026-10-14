@forelse ($dashboardWidgets as $widget)
    <article class="panel app-dashboard-widget app-dashboard-widget--{{ $widget->size === 'full' ? 'full' : 'half' }}"
             data-dashboard-widget="{{ $widget->id }}" data-widget-can-hide="{{ $widget->can_hide ? '1' : '0' }}">
        <div class="app-dashboard-widget__header">
            <h2>{{ $widget->title }}</h2>
            @if ($widget->kind === 'advertisement')<span class="app-dashboard-widget__ad-label">Реклама</span>@endif
        </div>
        @switch($widget->kind)
            @case('account_status')
                <p>Статус: <strong>{{ $dashboardUser->status->label() }}</strong>.</p>
                @if (! $dashboardUser->isConfirmed() && ! $dashboardUser->isBlocked())
                    <p>Подтверди аккаунт, чтобы получить доступ к дополнительным возможностям портала.</p>
                    <a class="button secondary" href="{{ route('account.confirmation') }}">Подтвердить аккаунт</a>
                @elseif ($dashboardUser->isConfirmed())
                    <p>Аккаунт подтверждён. Возможности портала доступны согласно твоим ролям.</p>
                @else
                    <p>Аккаунт заблокирован. По вопросам обратись в техподдержку.</p>
                @endif
                @break
            @case('getting_started')
                <p>Добавь роли участия, следи за играми и площадками, общайся с другими участниками MSKBA.</p>
                <a class="button secondary" href="{{ route('account.roles') }}">Мои роли</a>
                @break
            @case('message_summary')
                <p>Непрочитанных сообщений и уведомлений: <strong>{{ $dashboardUnread }}</strong></p>
                <a class="button secondary" href="{{ route('account.notifications') }}">Открыть сообщения</a>
                @break
            @case('custom_html')
                @php
                    // The iframe intentionally has an opaque origin: never allow-same-origin.
                    $csp = "default-src 'none'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src https: data:; font-src https: data:; connect-src 'none'; form-action 'none'; object-src 'none'; base-uri 'none'; frame-src 'none'";
                    $document = '<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta http-equiv="Content-Security-Policy" content="'.e($csp).'"><style>html,body{margin:0;min-height:100%;font:14px system-ui,sans-serif;color:#f5f5f5;background:transparent;box-sizing:border-box}*,*::before,*::after{box-sizing:inherit}</style><style>'.($widget->css_code ?? '').'</style></head><body>'.($widget->html_code ?? '').'<script>'.($widget->js_code ?? '').'</script></body></html>';
                @endphp
                <iframe title="{{ $widget->title }}" class="app-dashboard-widget__iframe" sandbox="allow-scripts"
                        referrerpolicy="no-referrer" loading="lazy"
                        style="height:{{ min(900, max(120, $widget->iframe_height)) }}px"
                        srcdoc="{{ $document }}"></iframe>
                @break
            @default
                @if ($widget->kind === 'advertisement' && $widget->image_url)
                    <img class="app-dashboard-widget__ad-image" src="{{ $widget->image_url }}" alt="" loading="lazy" referrerpolicy="no-referrer">
                @endif
                @if ($widget->body)<p class="app-dashboard-widget__body">{{ $widget->body }}</p>@endif
                @if ($widget->link_url)
                    <a class="button {{ $widget->kind === 'advertisement' ? 'primary' : 'secondary' }}"
                       href="{{ $widget->link_url }}" target="_blank"
                       rel="{{ $widget->kind === 'advertisement' ? 'sponsored noopener noreferrer' : 'noopener noreferrer' }}">
                        {{ $widget->link_label ?: 'Подробнее' }}
                    </a>
                @endif
        @endswitch
    </article>
@empty
    <section class="panel app-dashboard-widget app-dashboard-widget--full">
        <h2>Виджетов пока нет</h2><p>Информация появится здесь после настройки администратором.</p>
    </section>
@endforelse
