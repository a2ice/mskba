@php
    $cookieConsentState = \App\Support\Privacy\CookieConsent::state();
@endphp

<section class="mskba-cookie-consent" data-cookie-consent-banner
         aria-labelledby="mskba-cookie-consent-title"
         @if($cookieConsentState !== null) hidden @endif>
    <div class="container mskba-cookie-consent__inner">
        <div class="mskba-cookie-consent__copy">
            <strong id="mskba-cookie-consent-title">Файлы cookie на MSKBA</strong>
            <p>
                Необходимые cookie обеспечивают работу портала и безопасность аккаунта.
                С вашего разрешения MSKBA сохраняет внутреннюю статистику посещений и просмотров.
                Вы можете отказаться от внутренней аналитики без ограничения основных функций сайта.
                Яндекс Метрика — отдельный внешний сервис, не управляемый этим выбором.
                Подробнее — в <a href="{{ route('privacy.policy') }}">Политике обработки персональных данных</a>.
            </p>
            <p class="mskba-cookie-consent__error" data-cookie-consent-error role="alert" hidden>
                Не удалось сохранить выбор. Проверьте подключение и повторите попытку.
            </p>
        </div>
        <form class="mskba-cookie-consent__actions" data-cookie-consent-form
              method="POST" action="{{ route('cookie-consent.store') }}">
            @csrf
            <button class="mskba-cookie-consent__button mskba-cookie-consent__button--secondary"
                    type="submit" name="analytics" value="rejected">
                Только необходимые
            </button>
            <button class="mskba-cookie-consent__button mskba-cookie-consent__button--primary"
                    type="submit" name="analytics" value="accepted">
                Принять все
            </button>
        </form>
    </div>
</section>
