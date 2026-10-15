<!doctype html>
<html lang="ru" data-appearance-mode="{{ $theme->mode }}" data-design-theme="{{ $theme->slug }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Предпросмотр: {{ $theme->name }} · MSKBA</title>
    @vite(['resources/themes/mskba_app/css/app.css'])
    {{-- Compiled CSS is strictly validated: no HTML, selectors beyond :root or external URLs. --}}
    <style>{!! $compiledCss !!}</style>
    {{-- Only this preview nests input inside .search-control: preserve the
         wrapper focus indicator and remove the redundant inner outline. --}}
    <style>
        .theme-preview .search-control input:focus-visible {
            outline: none !important;
            box-shadow: none !important;
        }
    </style>
</head>
<body>
<main class="container theme-preview" data-theme-typography-preview data-preview-gloss="on" data-preview-font="inter" style="padding:36px 0 90px">
    <p class="eyebrow accent">MSKBA / ТЕМА ОФОРМЛЕНИЯ</p>
    <h1 class="theme-preview__display-title" style="font-size:clamp(32px,4vw,48px);margin:16px 0 12px">{{ $theme->name }}</h1>
    <p class="muted" style="margin-bottom:26px">{{ $theme->description }}</p>
    @if ($theme->mode === 'light')
        <aside class="theme-preview__controls" aria-label="Сравнение типографики">
            <label for="premium-typography-choice">
                Вариант шрифта
                <select id="premium-typography-choice" data-premium-font>
                    <option value="inter">Inter — текущий</option>
                    <option value="onest">Onest — вариант A</option>
                    <option value="manrope">Manrope — вариант B</option>
                </select>
            </label>
            <label class="theme-preview__check" for="premium-heading-gloss">
                <input id="premium-heading-gloss" type="checkbox" checked data-premium-gloss>
                Лёгкий градиент заголовков
            </label>
            <p class="theme-preview__hint" role="status" aria-live="polite" data-premium-help>
                Сравнение изменяет только этот предпросмотр. Выбранный вариант не сохраняется в настройках темы.
            </p>
        </aside>
    @endif
    <div class="event-grid">
        <section class="panel" style="padding:24px">
            <p class="eyebrow accent">МОЯ АРЕНА</p>
            <h2 class="theme-preview__display-title" style="margin:14px 0">Баскетбол сегодня</h2>
            <p class="muted" style="margin-bottom:20px">Игры, тренировки и команды рядом с тобой.</p>
            <div class="actions">
                <button class="button primary">Найти игру</button>
                <button class="button secondary">Подробнее</button>
            </div>
        </section>
        <section class="panel theme-preview__cool-panel" style="padding:24px">
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
@if ($theme->mode === 'light')
<script>
(() => {
    const preview = document.querySelector('[data-theme-typography-preview]');
    const font = preview?.querySelector('[data-premium-font]');
    const gloss = preview?.querySelector('[data-premium-gloss]');
    const description = preview?.querySelector('[data-premium-help]');
    if (!font || !gloss || !description) return;

    const fonts = {
        inter: 'Inter, Arial, sans-serif',
        onest: '"Onest MSKBA", Inter, Arial, sans-serif',
        manrope: '"Manrope MSKBA", Inter, Arial, sans-serif',
    };
    const update = () => {
        const family = fonts[font.value] ?? fonts.inter;
        preview.style.setProperty('--preview-font-family', family);
        preview.dataset.previewFont = font.value in fonts ? font.value : 'inter';
        preview.dataset.previewGloss = gloss.checked ? 'on' : 'off';
        description.textContent =
            'Показан ' + font.options[font.selectedIndex].text
            + (gloss.checked ? ' с мягким градиентом заголовков.' : ' без градиента.')
            + ' Изменения действуют только в предпросмотре, без сохранения.';
    };
    font.addEventListener('change', update);
    gloss.addEventListener('change', update);
    update();
})();
</script>
@endif
</body>
</html>
