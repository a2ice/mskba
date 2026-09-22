@php
    $status = (int) ($status ?? 500);
    $content = match ($status) {
        401 => ['Нужна авторизация', 'Войдите в аккаунт, чтобы продолжить.', 'Войти', route('login')],
        403 => ['Доступ закрыт', 'У вас нет прав для просмотра этой страницы или выполнения действия.', 'На главную', url('/')],
        404 => ['Страница не найдена', 'Возможно, адрес указан неверно или страница была перемещена.', 'На главную', url('/')],
        405 => ['Действие недоступно', 'Эта страница не поддерживает выбранный способ обращения.', 'На главную', url('/')],
        419 => ['Сессия завершилась', 'Обновите страницу и повторите действие. Введённые ранее данные могли не сохраниться.', 'Обновить страницу', request()->fullUrl()],
        429 => ['Слишком много попыток', 'Немного передохните и попробуйте ещё раз через минуту.', 'Попробовать снова', request()->fullUrl()],
        500 => ['Что-то пошло не так', 'Мы уже можем увидеть эту ошибку в журнале. Попробуйте обновить страницу чуть позже.', 'На главную', url('/')],
        503 => ['Технический перерыв', 'Сервис временно недоступен. Скоро всё снова заработает.', 'Обновить страницу', request()->fullUrl()],
        default => $status >= 500
            ? ['Временная ошибка', 'Сервис не смог обработать запрос. Попробуйте ещё раз немного позже.', 'На главную', url('/')]
            : ['Запрос не выполнен', 'Не удалось открыть страницу или выполнить действие.', 'На главную', url('/')],
    };
    [$title, $message, $actionLabel, $actionUrl] = $content;
@endphp
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#10120f">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $status }} · {{ $title }} · {{ config('app.name', 'MSKBA') }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="any">
    <style>
        :root { color-scheme: dark; font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; }
        * { box-sizing: border-box; }
        body { margin: 0; min-width: 320px; min-height: 100vh; color: #f7f3ea; background: #10120f url('{{ asset('images/bg-indoor.png') }}') center bottom / 100% 34vh no-repeat; }
        body::before { content: ""; position: fixed; inset: 0; pointer-events: none; background: radial-gradient(circle at 50% 28%, rgba(246,95,42,.16), transparent 34rem), linear-gradient(180deg, rgba(16,18,15,.22), #10120f 78%); }
        .error-shell { position: relative; min-height: 100vh; display: grid; grid-template-rows: auto 1fr auto; }
        .error-header, .error-footer { width: min(1120px, calc(100% - 40px)); margin: 0 auto; }
        .error-header { display: flex; align-items: center; min-height: 92px; border-bottom: 1px solid rgba(255,255,255,.1); }
        .error-logo { display: inline-flex; align-items: center; }
        .error-logo img { display: block; width: 142px; max-height: 54px; object-fit: contain; object-position: left center; }
        .error-main { width: min(760px, calc(100% - 40px)); margin: auto; padding: 64px 0; text-align: center; }
        .error-code { margin: 0 0 12px; color: #f65f2a; font-size: clamp(76px, 17vw, 164px); font-weight: 950; line-height: .82; letter-spacing: -.065em; text-shadow: 0 0 38px rgba(246,95,42,.24); }
        h1 { margin: 0; font-size: clamp(30px, 6vw, 54px); line-height: 1.04; letter-spacing: -.035em; }
        p { max-width: 590px; margin: 22px auto 0; color: rgba(247,243,234,.7); font-size: clamp(16px, 2.5vw, 19px); line-height: 1.6; }
        .error-actions { display: flex; flex-wrap: wrap; justify-content: center; gap: 12px; margin-top: 34px; }
        .error-button { display: inline-flex; min-height: 48px; align-items: center; justify-content: center; padding: 12px 22px; border: 1px solid #f65f2a; border-radius: 8px; color: #17120f; background: #f65f2a; font-weight: 850; text-decoration: none; transition: transform .15s ease, background .15s ease; }
        .error-button:hover { transform: translateY(-1px); background: #ff7a45; }
        .error-button--secondary { color: #f7f3ea; border-color: rgba(255,255,255,.2); background: rgba(255,255,255,.05); }
        .error-button--secondary:hover { background: rgba(255,255,255,.1); }
        .error-footer { padding: 22px 0 30px; color: rgba(247,243,234,.38); font-size: 13px; text-align: center; border-top: 1px solid rgba(255,255,255,.08); }
        @media (max-width: 520px) { .error-header { min-height: 76px; } .error-main { padding: 42px 0; } .error-actions { flex-direction: column; } .error-button { width: 100%; } }
    </style>
</head>
<body>
<div class="error-shell">
    <header class="error-header">
        <a class="error-logo" href="{{ url('/') }}" aria-label="MSKBA — на главную">
            <img src="{{ asset('images/logo-header.png') }}" alt="MSKBA">
        </a>
    </header>
    <main class="error-main">
        <div class="error-code" aria-hidden="true">{{ $status }}</div>
        <h1>{{ $title }}</h1>
        <p>{{ $message }}</p>
        <div class="error-actions">
            <a class="error-button" href="{{ $actionUrl }}">{{ $actionLabel }}</a>
            @if($actionUrl !== url('/'))
                <a class="error-button error-button--secondary" href="{{ url('/') }}">На главную</a>
            @endif
        </div>
    </main>
    <footer class="error-footer">Московская Баскетбольная Ассоциация</footer>
</div>
</body>
</html>
