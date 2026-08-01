@php
    $telegramCommunityUrl = trim((string) config('services.social.telegram_url'));
    $vkCommunityUrl = trim((string) config('services.social.vk_url'));
@endphp

<div class="site-footer__inner partial-wrapper partial-footer">
    <div class="inner">
        <div class="site-footer__grid">
            <div class="site-footer__brand">
                @include('theme::partials.logo')
                <p>Баскетбольные площадки, игры, тренировки и сообщество Москвы и области.</p>

                @if($telegramCommunityUrl !== '' || $vkCommunityUrl !== '')
                    <nav class="site-footer__socials" aria-label="MSKBA в социальных сетях" style="display:flex;align-items:center;gap:10px;margin-top:18px;">
                        @if($telegramCommunityUrl !== '')
                            <a
                                href="{{ $telegramCommunityUrl }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                aria-label="Официальное сообщество MSKBA в Telegram"
                                title="MSKBA в Telegram"
                                data-tooltip-variant="title"
                                data-tooltip-icon
                                style="display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;border:1px solid rgba(255,255,255,.16);border-radius:50%;font-size:22px;transition:color 160ms ease,border-color 160ms ease,background-color 160ms ease;"
                            >
                                <i class="ti ti-brand-telegram" aria-hidden="true"></i>
                            </a>
                        @endif

                        @if($vkCommunityUrl !== '')
                            <a
                                href="{{ $vkCommunityUrl }}"
                                target="_blank"
                                rel="noopener noreferrer"
                                aria-label="Официальное сообщество MSKBA во ВКонтакте"
                                title="MSKBA во ВКонтакте"
                                data-tooltip-variant="title"
                                data-tooltip-icon
                                style="display:inline-flex;align-items:center;justify-content:center;width:42px;height:42px;border:1px solid rgba(255,255,255,.16);border-radius:50%;font-size:22px;transition:color 160ms ease,border-color 160ms ease,background-color 160ms ease;"
                            >
                                <i class="ti ti-brand-vk" aria-hidden="true"></i>
                            </a>
                        @endif
                    </nav>
                @endif
            </div>

            <nav class="site-footer__nav" aria-label="Разделы портала">
                <h2>Портал</h2>
                <a href="{{ route('venues') }}">Площадки</a>
                <a href="{{ route('events.index') }}">Мероприятия</a>
                <a href="{{ route('coordination.index') }}">Опросы</a>
                <a href="{{ route('faq.index') }}">FAQ</a>
            </nav>

            <nav class="site-footer__nav" aria-label="Информация и документы">
                <h2>Информация</h2>
                <a href="{{ route('welcome') }}#about">О проекте</a>
                <a href="{{ route('welcome') }}#partners">Партнёрам</a>
                <a href="{{ route('privacy.policy') }}">Персональные данные</a>
                <a href="mailto:{{ config('legal.privacy_email') }}">Связаться с нами</a>
            </nav>
        </div>

        <div class="site-footer__disclaimer">
            <p>
                Материалы портала носят информационный характер и не являются публичной офертой.
                Пользователи самостоятельно отвечают за размещаемые сведения и договорённости с другими участниками.
            </p>
            <p>&copy; {{ date('Y') }} MSKBA. Все права защищены.</p>
        </div>
    </div>
</div>
