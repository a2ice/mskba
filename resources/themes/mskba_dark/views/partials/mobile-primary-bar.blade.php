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
                >+</a>
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
                >+</button>
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

        <a class="mobile-primary-bar__action mobile-primary-bar__action--home" href="{{ route('welcome') }}" aria-label="На главную">
            <span class="mobile-primary-bar__home-mark" aria-hidden="true">
                <img src="{{ asset('apple-touch-icon.png') }}" alt="">
            </span>
            <span>Домой</span>
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

<style>
@media screen and (max-width: 768px) {
    :root {
        --mobile-primary-actions-height: 68px;
        --mobile-primary-stats-height: 38px;
        --mobile-primary-bar-inset: 4px;
        --mobile-primary-bar-max-width: 500px;
    }

    .site-frame {
        padding-bottom: calc(
            var(--mobile-primary-actions-height)
            + var(--mobile-primary-stats-height)
            + var(--mobile-primary-bar-inset)
            + env(safe-area-inset-bottom)
        );
    }

    .mobile-primary-bar__stats {
        right: auto;
        bottom: calc(
            var(--mobile-primary-actions-height)
            + var(--mobile-primary-bar-inset)
            + env(safe-area-inset-bottom)
        );
        left: 50%;
        width: calc(100% - (var(--mobile-primary-bar-inset) * 2));
        max-width: var(--mobile-primary-bar-max-width);
        height: var(--mobile-primary-stats-height);
        border: 1px solid rgba(255, 255, 255, 0.1);
        border-bottom: 0;
        border-radius: 18px 18px 0 0;
        background: rgba(13, 15, 14, 0.93);
        box-shadow: 0 -8px 28px rgba(0, 0, 0, 0.28);
        transform: translateX(-50%);
    }

    .mobile-primary-bar__stat {
        gap: 5px;
        padding: 0 7px;
        font-size: clamp(9px, 2.55vw, 11px);
    }

    .mobile-primary-bar__dot {
        width: 7px;
        height: 7px;
    }

    .mobile-primary-bar__actions {
        right: auto;
        bottom: var(--mobile-primary-bar-inset);
        left: 50%;
        width: calc(100% - (var(--mobile-primary-bar-inset) * 2));
        max-width: var(--mobile-primary-bar-max-width);
        grid-template-columns: repeat(5, minmax(0, 1fr));
        height: calc(var(--mobile-primary-actions-height) + env(safe-area-inset-bottom));
        padding: 0 3px env(safe-area-inset-bottom);
        border: 1px solid rgba(255, 255, 255, 0.11);
        border-radius: 0 0 24px 24px;
        background: rgba(7, 8, 8, 0.96);
        box-shadow: 0 -8px 28px rgba(0, 0, 0, 0.38);
        overflow: visible;
        transform: translateX(-50%);
    }

    .mobile-primary-bar__action {
        min-height: var(--mobile-primary-actions-height);
        gap: 4px;
        padding: 10px 2px 7px;
        color: rgba(255, 255, 255, 0.78);
        font-size: clamp(8px, 2.45vw, 10px);
        font-weight: 700;
        letter-spacing: .015em;
        text-transform: none;
    }

    .mobile-primary-bar__action > i {
        font-size: 20px;
    }

    .mobile-primary-bar__action:hover,
    .mobile-primary-bar__action:focus-visible {
        color: #fff;
    }

    .mobile-primary-bar__action--home {
        position: relative;
        z-index: 2;
        justify-content: flex-end;
        padding-top: 0;
        color: #fff;
    }

    .mobile-primary-bar__home-mark {
        position: absolute;
        top: -30px;
        left: 50%;
        display: grid;
        width: 58px;
        height: 58px;
        place-items: center;
        border: 4px solid rgba(7, 8, 8, 0.98);
        border-radius: 50%;
        background:
            radial-gradient(circle at 35% 28%, rgba(255, 255, 255, .2), transparent 34%),
            linear-gradient(145deg, #ff8c2b, #df4b0b);
        box-shadow:
            0 8px 22px rgba(0, 0, 0, .46),
            0 0 24px rgba(236, 127, 18, .28);
        transform: translateX(-50%);
    }

    .mobile-primary-bar__home-mark img {
        display: block;
        width: 38px;
        height: 38px;
        object-fit: contain;
        filter: drop-shadow(0 2px 3px rgba(0, 0, 0, .25));
    }

    .mobile-primary-bar__action--home > span:last-child {
        margin-top: 26px;
        color: var(--accent-text);
        font-weight: 800;
    }

    .mobile-primary-bar__menu-icon {
        width: 18px;
        height: 20px;
        gap: 3px;
    }

    .mobile-primary-bar__menu-icon > span {
        height: 2px;
    }

    body.nav-shown .site-header .site-nav-wrapper {
        bottom: calc(
            var(--mobile-primary-actions-height)
            + var(--mobile-primary-stats-height)
            + var(--mobile-primary-bar-inset)
            + env(safe-area-inset-bottom)
        );
    }
}
</style>
