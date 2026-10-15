<!doctype html>
<html lang="ru" data-appearance-mode="{{ $theme->mode }}" data-design-theme="{{ $theme->slug }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Предпросмотр: {{ $theme->name }} · MSKBA</title>
    @vite(['resources/themes/mskba_app/css/app.css'])
    {{-- Compiled CSS is strictly validated: no HTML, selectors beyond :root or external URLs. --}}
    <style>{!! $compiledCss !!}</style>
</head>
<body>
<main class="container" style="padding:36px 0 90px">
    <p class="eyebrow accent">MSKBA / ТЕМА ОФОРМЛЕНИЯ</p>
    <h1 style="font-size:clamp(32px,4vw,48px);margin:16px 0 12px">{{ $theme->name }}</h1>
    <p class="muted" style="margin-bottom:26px">{{ $theme->description }}</p>
    <div class="event-grid">
        <section class="panel" style="padding:24px">
            <p class="eyebrow accent">МОЯ АРЕНА</p>
            <h2 style="margin:14px 0">Баскетбол сегодня</h2>
            <p class="muted" style="margin-bottom:20px">Игры, тренировки и команды рядом с тобой.</p>
            <div class="actions">
                <button class="button primary">Найти игру</button>
                <button class="button secondary">Подробнее</button>
            </div>
        </section>
        <section class="panel" style="padding:24px">
            <h2>Состояния</h2>
            <div class="actions" style="margin-top:20px">
                <span class="badge success">Подтверждено</span>
                <span class="badge warning">Ожидает</span>
                <span class="badge danger-badge">Ошибка</span>
            </div>
            <p class="muted" style="margin:20px 0">Вспомогательный текст и границы карточек должны быть читаемыми.</p>
        </section>
        <section class="panel" style="padding:24px">
            <h2>Поля и действия</h2>
            <label for="design-theme-preview-input" style="display:block;margin:12px 0 8px">Название команды</label>
            <div class="search-control">
                <input id="design-theme-preview-input" placeholder="Введите название">
            </div>
            <div class="actions" style="margin-top:20px">
                <button class="button secondary" disabled>Недоступно</button>
                <button class="button ghost">Отмена</button>
            </div>
        </section>
    </div>
</main>
</body>
</html>
