@php
    $dashboardHtml = app(\App\Modules\Dashboard\Application\Services\DashboardWidgetHtmlRenderer::class);
    $dashboardActions = app(\App\Modules\Dashboard\Application\Services\DashboardWidgetActions::class);
    $dashboardButtonStyles = \App\Modules\Dashboard\Domain\Models\DashboardButtonStyle::query()
        ->where('enabled', true)->pluck('class_name')->all();
    $dashboardButtonCss = app(\App\Modules\Dashboard\Application\Services\DashboardButtonStyleCompiler::class)
        ->cssForWidgets($dashboardWidgets);
@endphp
@if ($dashboardButtonCss !== '')
    <style data-dashboard-button-styles>{!! $dashboardButtonCss !!}</style>
@endif
@forelse ($dashboardWidgets as $widget)
    @php
        // Explicit HEX validation before writing a CSS variable prevents CSS injection.
        $color = $widget->gradient_color;
        $gradientGlow = is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/D', $color)
            ? $color.'1e'
            : null;
        $imagePosition = in_array($widget->image_position, ['left', 'right', 'top', 'bottom'], true)
            ? $widget->image_position : 'top';
        $imageWidth = in_array((int) $widget->image_width_percent, [25, 50, 75, 100], true)
            ? (int) $widget->image_width_percent : 100;
        $imageGap = min(64, max(0, (int) ($widget->image_gap_px ?? 10)));
        $showImage = filled($widget->image_url) && filter_var($widget->image_url, FILTER_VALIDATE_URL)
            && in_array(parse_url($widget->image_url, PHP_URL_SCHEME), ['http', 'https'], true);
        // With a full-width image no grid column remains for text; stack it.
        if ($imageWidth === 100 && in_array($imagePosition, ['left', 'right'], true)) {
            $imagePosition = 'top';
        }
        $imageCss = "--widget-image-gap: {$imageGap}px; --widget-image-fr: {$imageWidth}fr; --widget-text-fr: ".(100 - $imageWidth)."fr; --widget-image-width: {$imageWidth}%";
        $widgetVersion = hash('sha256', serialize($widget->getAttributes()));
        $refreshSeconds = min(60, max(0, (int) ($widget->refresh_delay_seconds ?? 5)));
    @endphp
    <article class="panel app-dashboard-widget app-dashboard-widget--{{ $widget->size === 'full' ? 'full' : 'half' }}"
             data-dashboard-widget="{{ $widget->id }}" data-widget-can-hide="{{ $widget->can_hide ? '1' : '0' }}"
             data-widget-version="{{ $widgetVersion }}" data-widget-refresh-delay="{{ $refreshSeconds }}"
             @if ($gradientGlow) style="--surface-glow: {{ $gradientGlow }}" @endif>
        <div class="app-dashboard-widget__header">
            <h2>{{ $widget->title }}</h2>
            @if ($widget->kind === 'advertisement')<span class="app-dashboard-widget__ad-label">Реклама</span>@endif
        </div>
        <div class="app-dashboard-widget__composition{{ $showImage ? ' app-dashboard-widget__composition--'.$imagePosition : '' }}"
             @if ($showImage) style="{{ $imageCss }}" @endif>
            @if ($showImage)
                <div class="app-dashboard-widget__media">
                    <img class="app-dashboard-widget__image" src="{{ $widget->image_url }}"
                         alt="" loading="lazy" referrerpolicy="no-referrer">
                </div>
            @endif
            <div class="app-dashboard-widget__content">
        @switch($widget->kind)
            @case('account_status')
                <div class="app-account-overview__status-top">
                    <span class="app-account-overview__status-label {{ $dashboardUser->isConfirmed() ? 'app-account-overview__status-label--confirmed' : '' }}">{{ $dashboardUser->status->label() }}</span>
                </div>
                @php
                    $statusField = $dashboardUser->isConfirmed() ? 'status_confirmed_html' : ($dashboardUser->isBlocked() ? 'status_blocked_html' : 'status_unconfirmed_html');
                    $statusDefault = match ($statusField) {
                        'status_confirmed_html' => '<p>Аккаунт подтверждён. Теперь доступны возможности портала, для которых требуется подтверждение аккаунта.</p>',
                        'status_blocked_html' => '<p>Аккаунт заблокирован. Если возникли вопросы, обратись в поддержку.</p>',
                        default => '<p>Подтверди аккаунт, чтобы получить доступ ко всем возможностям портала.</p>',
                    };
                @endphp
                <div class="app-dashboard-widget__richtext">{!! $dashboardHtml->render($widget->{$statusField} ?: $statusDefault) !!}</div>

                @break
            @case('getting_started')
                <div class="app-dashboard-widget__richtext">{!! $dashboardHtml->render($widget->body ?: '<p>Добавь роли участия, следи за играми и площадками, общайся с другими участниками MSKBA.</p>') !!}</div>

                @break
            @case('message_summary')
                @php
                    $messagesDefault = '<p>Непрочитанных сообщений и уведомлений: <strong>'.e($dashboardUnread).'</strong></p>';
                @endphp
                <div class="app-dashboard-widget__richtext">{!! $dashboardHtml->render($widget->body ?: $messagesDefault, ['unread_count' => $dashboardUnread]) !!}</div>

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
                @if ($widget->body)
                    <div class="app-dashboard-widget__richtext">{!! $dashboardHtml->render($widget->body) !!}</div>
                @endif

        @endswitch
        @php
            $widgetActions = $dashboardActions->forUser($widget, $dashboardUser, $dashboardButtonStyles);
        @endphp
        @if ($widgetActions !== [])
            <div class="app-dashboard-widget__actions">
                @foreach ($widgetActions as $action)
                    <a class="button {{ $action['appearance'] }}{{ $action['confirmation'] ? ' app-account-overview__confirmation-action' : '' }}{{ $action['classes'] !== [] ? ' '.implode(' ', $action['classes']) : '' }}"
                       href="{{ $action['url'] }}"
                       @if ($action['external']) target="_blank" @endif
                       rel="{{ $widget->kind === 'advertisement' ? 'sponsored noopener noreferrer' : 'noopener noreferrer' }}">
                        @if ($action['confirmation'])<span class="app-account-overview__confirmation-dot" aria-hidden="true"></span>@endif
                        {{ $action['label'] }}
                    </a>
                @endforeach
            </div>
        @endif
            </div>
        </div>
    </article>
@empty
    <section class="panel app-dashboard-widget app-dashboard-widget--full">
        <h2>Виджетов пока нет</h2><p>Информация появится здесь после настройки администратором.</p>
    </section>
@endforelse
