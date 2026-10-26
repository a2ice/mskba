@php
    $telegramCommunityUrl = trim((string) config('services.social.telegram_url'));
    $vkCommunityUrl = trim((string) config('services.social.vk_url'));
@endphp

<footer class="app-site-footer" role="contentinfo">
    <div class="container">
        <div class="app-footer__grid">
            <div class="app-footer__identity">
                <a class="app-footer__logo" href="{{ route('welcome') }}" aria-label="MSKBA — на главную">
                    <span class="app-footer__mark" aria-hidden="true"></span>
                    <span>MSK<span class="accent">BA</span></span>
                </a>
                <p class="app-footer__description">Баскетбольные площадки, игры, тренировки и сообщество Москвы и области.</p>

                @if ($telegramCommunityUrl !== '' || $vkCommunityUrl !== '')
                    <nav class="app-footer__socials" aria-label="MSKBA в социальных сетях">
                        @if ($telegramCommunityUrl !== '')
                            <a href="{{ $telegramCommunityUrl }}" target="_blank" rel="noopener noreferrer"
                               aria-label="MSKBA в Telegram" title="MSKBA в Telegram">
                                <svg aria-hidden="true"><use href="#contact-telegram"/></svg>
                            </a>
                        @endif
                        @if ($vkCommunityUrl !== '')
                            <a href="{{ $vkCommunityUrl }}" target="_blank" rel="noopener noreferrer"
                               aria-label="MSKBA во ВКонтакте" title="MSKBA во ВКонтакте">
                                <svg aria-hidden="true"><use href="#contact-vk"/></svg>
                            </a>
                        @endif
                    </nav>
                @endif
            </div>

            <nav class="app-footer__nav" aria-label="Разделы портала">
                <h2>Портал</h2>
                <a href="{{ route('venues') }}">Площадки</a>
                <a href="{{ route('events.index') }}">Мероприятия</a>
                <a href="{{ route('coordination.index') }}">Опросы</a>
                <a href="{{ route('faq.index') }}">FAQ</a>
            </nav>

            <nav class="app-footer__nav" aria-label="Информация и документы">
                <h2>Информация</h2>
                <a href="{{ route('welcome') }}#about">О проекте</a>
                <a href="{{ route('welcome') }}#partners">Партнёрам</a>
                <a href="{{ route('privacy.policy') }}">Персональные данные</a>
                <a href="mailto:{{ config('legal.privacy_email') }}">Связаться с нами</a>
            </nav>
        </div>

        <div class="app-footer__legal">
            <p>Материалы портала носят информационный характер и не являются публичной офертой.
                Пользователи самостоятельно отвечают за размещаемые сведения и договорённости с другими участниками.</p>
            <p class="app-footer__copyright">&copy; {{ date('Y') }} MSKBA. Все права защищены.</p>
        </div>
    </div>
</footer>
