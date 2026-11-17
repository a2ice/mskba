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
            <div class="theme-preview__font-select" data-premium-picker>
                <span id="premium-typography-label">Вариант шрифта</span>
                <button id="premium-typography-choice" type="button"
                    class="theme-preview__font-trigger" data-premium-trigger
                    aria-labelledby="premium-typography-label premium-typography-choice"
                    aria-haspopup="listbox" aria-expanded="false"
                    aria-controls="premium-typography-options">
                    <span data-premium-selected>Inter — универсальный</span>
                    <svg class="theme-preview__chevron" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <path d="m6 9 6 6 6-6" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>
                    </svg>
                </button>
                <div id="premium-typography-options"
                    class="theme-preview__font-options" role="listbox"
                    aria-labelledby="premium-typography-label" data-premium-options hidden>
                    @foreach (\App\Presentation\Theming\DesignFontCatalog::OPTIONS as $key => $name)
                        <button type="button" role="option" class="theme-preview__font-option"
                            data-premium-font="{{ $key }}"
                            aria-selected="{{ $key === ($theme->body_font ?? 'inter') ? 'true' : 'false' }}"
                            tabindex="-1">{{ $name }}</button>
                    @endforeach
                </div>
            </div>
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
    const picker = preview?.querySelector('[data-premium-picker]');
    const trigger = picker?.querySelector('[data-premium-trigger]');
    const menu = picker?.querySelector('[data-premium-options]');
    const options = [...(menu?.querySelectorAll('[data-premium-font]') || [])];
    const selectedText = trigger?.querySelector('[data-premium-selected]');
    const gloss = preview?.querySelector('[data-premium-gloss]');
    const description = preview?.querySelector('[data-premium-help]');
    if (!preview || !picker || !trigger || !menu || !selectedText || !gloss || !description) return;

    const fonts = {
        inter: 'Inter, Arial, sans-serif',
        onest: '"Onest MSKBA", Inter, Arial, sans-serif',
        manrope: '"Manrope MSKBA", Inter, Arial, sans-serif',
        'golos-text': '"Golos Text MSKBA", Inter, Arial, sans-serif',
        commissioner: '"Commissioner MSKBA", Inter, Arial, sans-serif',
        rubik: '"Rubik MSKBA", Inter, Arial, sans-serif',
    };
    let current = @json($theme->body_font ?? 'inter');
    if (!(current in fonts)) current = 'inter';

    function update() {
        const selected = options.find(option => option.dataset.premiumFont === current) || options[0];
        if (!selected) return;
        preview.style.setProperty('--preview-font-family', fonts[current]);
        preview.dataset.previewFont = current;
        preview.dataset.previewGloss = gloss.checked ? 'on' : 'off';
        selectedText.textContent = selected.textContent.trim();
        options.forEach(option => option.setAttribute('aria-selected',
            String(option.dataset.premiumFont === current)));
        description.textContent = 'Показан ' + selected.textContent.trim()
            + (gloss.checked ? ' с мягким градиентом заголовков.' : ' без градиента.')
            + ' Изменения действуют только в предпросмотре, без сохранения.';
    }

    function close(focusTrigger = false) {
        menu.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
        if (focusTrigger) trigger.focus();
    }
    function open() {
        menu.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');
        (options.find(option => option.dataset.premiumFont === current) || options[0])?.focus();
    }
    function selectOption(option) {
        if (!(option.dataset.premiumFont in fonts)) return;
        current = option.dataset.premiumFont;
        update();
        close(true);
    }
    trigger.addEventListener('click', () => menu.hidden ? open() : close(true));
    trigger.addEventListener('keydown', (event) => {
        if (['ArrowDown', 'ArrowUp', 'Enter', ' '].includes(event.key)) {
            event.preventDefault();
            if (menu.hidden) open();
        }
    });
    options.forEach(option => option.addEventListener('click', () => selectOption(option)));
    menu.addEventListener('keydown', event => {
        const index = options.indexOf(document.activeElement);
        if (event.key === 'Escape') {
            event.preventDefault();
            close(true);
            return;
        }
        if (['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) {
            event.preventDefault();
            const next = event.key === 'Home' ? 0
                : event.key === 'End' ? options.length - 1
                    : (index + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length;
            options[next]?.focus();
        }
        if (event.key === 'Tab') close();
    });
    document.addEventListener('pointerdown', event => {
        if (!menu.hidden && !picker.contains(event.target)) close();
    });
    gloss.addEventListener('change', update);
    update();
})();
</script>
@endif
</body>
</html>
