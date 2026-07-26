<div class="mobile-primary-bar" data-mobile-primary-bar>
    @php
        $today = now((string) config('app.timezone', 'Europe/Moscow'))->toDateString();
        $todayGamesUrl = route('events.index', ['type' => 'games', 'date_from' => $today, 'date_to' => $today]);
    @endphp
    <div @class(['mobile-primary-bar__stats', 'has-online' => $siteSummary->onlineUsers > 0]) aria-label="Статистика сайта" data-mobile-summary-stats>
        <p class="mobile-primary-bar__stat">
            <span class="mobile-primary-bar__dot" aria-hidden="true"></span>
            <a
                href="{{ $todayGamesUrl }}"
                data-today-events-link
                @if($siteSummary->todayEvents === 0) hidden @endif
            >{{ $siteSummary->todayEventsText() }}</a>
            <span data-today-events-empty @if($siteSummary->todayEvents > 0) hidden @endif>На сегодня игр нет</span>
            @auth
                <a
                    class="site-summary-create"
                    href="{{ route('events.create', ['type' => 'game']) }}"
                    aria-label="Создать игру"
                    title="Создать игру"
                    data-tooltip-variant="title"
                    data-tooltip-icon
                ></a>
            @else
                <button
                    class="site-summary-create js-handler"
                    type="button"
                    aria-label="Создать игру"
                    title="Создать игру"
                    data-tooltip-variant="title"
                    data-tooltip-icon
                    data-handler="modal"
                    data-modal-action="open"
                    data-modal-target="auth-entry-classic"
                    data-auth-redirect-url="{{ route('events.create', ['type' => 'game'], false) }}"
                ></button>
            @endauth
        </p>

        <p class="mobile-primary-bar__stat" data-online-summary @if($siteSummary->onlineUsers === 0) hidden @endif>
            <span class="mobile-primary-bar__dot mobile-primary-bar__dot--online" aria-hidden="true"></span>
            <span><span data-online-users-count>{{ $siteSummary->onlineUsers }}</span> онлайн</span>
        </p>
    </div>

    <nav class="mobile-primary-bar__actions" aria-label="Основные действия">
        <a class="mobile-primary-bar__action" href="{{ route('events.index', ['type' => 'game']) }}">
            <i class="ti ti-ball-basketball" aria-hidden="true"></i>
            <span>Играть</span>
        </a>

        <a class="mobile-primary-bar__action" href="{{ route('venues') }}">
            <i class="ti ti-map-pin" aria-hidden="true"></i>
            <span>Площадки</span>
        </a>

        <a class="mobile-primary-bar__action" href="{{ route('events.index', ['type' => 'training']) }}">
            <i class="ti ti-barbell" aria-hidden="true"></i>
            <span>Тренировки</span>
        </a>

        <a
            class="mobile-primary-bar__action mobile-primary-bar__menu js-handler"
            href="#menu"
            data-handler="toggleClass"
            data-params="nav-shown;body"
            data-nav-toggle
            aria-expanded="false"
            aria-label="Открыть основное меню"
        >
            <span class="mobile-primary-bar__menu-icon" aria-hidden="true">
                <span></span>
                <span></span>
                <span></span>
            </span>
            <span class="mobile-primary-bar__menu-open">Меню</span>
            <span class="mobile-primary-bar__menu-close">Закрыть</span>
        </a>
    </nav>
</div>
