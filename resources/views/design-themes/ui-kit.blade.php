<!doctype html>
<html lang="ru" data-appearance-mode="{{ $theme->mode }}" data-design-theme="{{ $theme->slug }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>UI Kit — {{ $theme->name }} · MSKBA</title>
    @vite(['resources/themes/mskba_app/css/app.css'])
    {{-- Uses precisely the same validated database-backed token compiler as the live theme. --}}
    <style>{!! $compiledCss !!}</style>
    <style>
        .ui-kit-body { min-height: 100dvh; background: var(--bg); color: var(--text); }
        .ui-kit-shell { width: min(1480px, calc(100% - 48px)); margin: 0 auto; padding: 36px 0 72px; }
        .ui-kit-back { color: var(--muted); text-decoration: none; font-size: 14px; }
        .ui-kit-back:hover { color: var(--accent); }
        .ui-kit-heading { margin: 16px 0 8px; font-size: clamp(28px, 4vw, 44px); line-height: 1.15; letter-spacing: -.035em; }
        .ui-kit-description { color: var(--muted); max-width: 76ch; margin-bottom: 28px; }
        .ui-kit-toolbar { display: flex; flex-wrap: wrap; align-items: end; gap: 16px; padding: 20px; border: 1px solid var(--line); border-radius: 20px; background: var(--surface); }
        .ui-kit-control { display: grid; gap: 8px; min-width: 180px; }
        .ui-kit-control > span { font-size: 13px; font-weight: 700; }
        .ui-kit-control select { min-height: 44px; width: 100%; background: var(--field); color: var(--text); border: 1px solid var(--control-line); border-radius: 10px; padding: 8px 12px; }
        .ui-kit-widths { display: flex; gap: 4px; flex-wrap: wrap; }
        .ui-kit-widths button { min-height: 44px; padding: 8px 12px; color: var(--muted); border: 1px solid var(--line); background: var(--field); border-radius: 9px; cursor: pointer; font-size: 13px; }
        .ui-kit-widths button[aria-pressed="true"] { border-color: var(--accent); color: var(--text); background: var(--surface-raised); }
        .ui-kit-shortcuts { margin-left: auto; display: flex; flex-wrap: wrap; gap: 8px; }
        .ui-kit-shortcuts .button { white-space: nowrap; }
        .ui-kit-frame-wrap { margin-top: 20px; overflow-x: auto; border: 1px solid var(--line); border-radius: 20px; background: var(--surface-raised); padding: 16px; text-align: center; }
        .ui-kit-frame { width: min(100%, 1440px); height: min(80dvh, 1050px); min-height: 480px; border: 1px solid var(--line); border-radius: 14px; background: var(--bg); display: block; margin: 0 auto; transition: width .2s ease; }
        .ui-kit-frame-label { display: block; text-align: left; margin: 0 0 10px; color: var(--muted); font-size: 12px; }
        .ui-kit-footer { margin-top: 20px; font-size: 13px; color: var(--muted); }
        .ui-kit-canvas { padding: 28px 0 80px; }
        .ui-kit-canvas > .container { max-width: 1280px; }
        .ui-kit-section { margin-top: 32px; scroll-margin-top: 24px; }
        .ui-kit-section > h2 { font-size: clamp(22px, 3vw, 30px); line-height: 1.2; margin: 8px 0; }
        .ui-kit-intro { color: var(--muted); margin-bottom: 18px; }
        .ui-kit-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(min(100%, 290px), 1fr)); gap: 16px; }
        .ui-kit-panel { padding: 24px; display: grid; align-content: start; gap: 16px; min-width: 0; }
        .ui-kit-panel h3 { font-size: 20px; line-height: 26px; }
        .ui-kit-panel .actions { align-items: center; }
        .ui-kit-panel form { max-width: 100%; }
        .ui-kit-swatch-grid { display: grid; grid-template-columns: repeat(auto-fill,minmax(145px,1fr)); gap: 12px; }
        .ui-kit-swatch { padding: 8px; border: 1px solid var(--line); border-radius: 12px; background: var(--surface); min-width: 0; }
        .ui-kit-swatch > i { display: block; height: 48px; border: 1px solid var(--control-line); border-radius: 8px; margin-bottom: 10px; }
        .ui-kit-swatch strong { display: block; font-size: 12px; line-height: 18px; overflow-wrap: anywhere; }
        .ui-kit-swatch code { font-size: 12px; color: var(--muted); }
        .ui-kit-large { font-weight: 900; font-size: clamp(32px,5vw,52px); line-height: 1.05; letter-spacing: -.04em; }
        .ui-kit-example-nav { display: flex; flex-wrap: wrap; gap: 12px; padding: 14px; background: var(--surface-raised); border-radius: 14px; }
        .ui-kit-example-nav a { min-height: 44px; display: inline-flex; align-items: center; padding: 8px 12px; color: var(--muted); text-decoration: none; border-radius: 8px; }
        .ui-kit-example-nav a[aria-current="page"] { background: var(--surface); color: var(--accent); }
        .ui-kit-sample-card { border-radius: 16px; border: 1px solid var(--line); padding: 20px; background: var(--surface-raised); display: grid; gap: 12px; }
        .ui-kit-table-scroll { overflow-x: auto; }
        .ui-kit-hint { font-size: 12px; line-height: 1.5; color: var(--muted); }
        .ui-kit-field-row { display: grid; grid-template-columns: repeat(2,minmax(0,1fr)); gap: 12px; }
        .ui-kit-demo-toast[hidden] { display: none !important; }
        .ui-kit-sample-dialog .mskba-modal__header { justify-content: space-between; }
        @media(max-width:639px) {
            .ui-kit-shell { width: calc(100% - 32px); padding: 22px 0 50px; }
            .ui-kit-toolbar { padding: 14px; }
            .ui-kit-control, .ui-kit-shortcuts { width: 100%; margin: 0; }
            .ui-kit-frame-wrap { padding: 8px; border-radius: 14px; }
            .ui-kit-frame { min-height: 540px; }
            .ui-kit-panel { padding: 20px; }
            .ui-kit-field-row { grid-template-columns: 1fr; }
        }
        @media(prefers-reduced-motion:reduce) { .ui-kit-frame { transition: none; } }
    </style>
</head>
<body class="ui-kit-body">
@if (! $embedded)
    <main class="ui-kit-shell">
        <a class="ui-kit-back" href="{{ \App\Filament\Backoffice\Resources\DesignThemes\DesignThemeResource::getUrl('index') }}">← Темы оформления</a>
        <p class="eyebrow accent" style="margin-top:24px">MSKBA / DESIGN SYSTEM</p>
        <h1 class="ui-kit-heading">UI Kit: {{ $theme->name }}</h1>
        <p class="ui-kit-description">Интерактивная витрина элементов с реальными токенами выбранной темы. Предпросмотр не изменяет настройки портала и ничего не сохраняет.</p>
        <div class="ui-kit-toolbar">
            <label class="ui-kit-control" for="kit-theme">
                <span>Тема оформления</span>
                <select id="kit-theme" data-theme-picker>
                    @foreach ($themes as $option)
                        <option value="{{ route('design-themes.ui-kit', $option) }}" @selected($option->id === $theme->id)>{{ $option->name }} — {{ $option->mode === 'light' ? 'светлая' : 'тёмная' }}</option>
                    @endforeach
                </select>
            </label>
            <div class="ui-kit-control" role="group" aria-label="Ширина предпросмотра">
                <span>Размер экрана</span>
                <div class="ui-kit-widths" data-kit-widths>
                    <button type="button" data-width="1440" aria-pressed="true">Desktop</button>
                    <button type="button" data-width="768" aria-pressed="false">Tablet</button>
                    <button type="button" data-width="390" aria-pressed="false">Mobile</button>
                    <button type="button" data-width="360" aria-pressed="false">360</button>
                </div>
            </div>
            <div class="ui-kit-shortcuts">
                <a class="button secondary" href="{{ route('design-themes.preview', $theme) }}">Предпросмотр темы</a>
                <a class="button secondary" href="{{ route('design-themes.ui-kit', $theme) }}?embed=1" target="_blank" rel="noopener">Открыть отдельно ↗</a>
                @if (\App\Filament\Backoffice\Resources\DesignThemes\DesignThemeResource::canEdit($theme))
                    <a class="button primary" href="{{ \App\Filament\Backoffice\Resources\DesignThemes\DesignThemeResource::getUrl('edit', ['record' => $theme]) }}">Настроить тему</a>
                @endif
            </div>
        </div>
        <div class="ui-kit-frame-wrap">
            <span class="ui-kit-frame-label" data-kit-frame-label>Desktop · 1440 px</span>
            <iframe class="ui-kit-frame" data-kit-frame title="UI Kit темы {{ $theme->name }}" src="{{ route('design-themes.ui-kit', $theme) }}?embed=1"></iframe>
        </div>
        <p class="ui-kit-footer">Образцы доступны только пользователям с правом просмотра тем. Для реальных страниц и Hero Slider используем те же токены; уникальные компоненты добавляем в каталог по мере реализации.</p>
    </main>
@else
<main class="ui-kit-canvas" id="main">
    <div class="container">
        <p class="eyebrow accent">MSKBA / UI KIT</p>
        <h1 style="font-size:clamp(32px,4vw,48px);line-height:1.08;margin:12px 0">{{ $theme->name }}</h1>
        <p class="ui-kit-intro">Актуальная палитра, типографика, компоненты и состояния. Все данные в примерах демонстрационные.</p>

        <nav class="ui-kit-example-nav" aria-label="Разделы UI Kit">
            <a href="#palette" aria-current="page">Токены</a>
            <a href="#actions">Кнопки и состояния</a>
            <a href="#forms">Формы</a>
            <a href="#components">Карточки и данные</a>
        </nav>

        <section class="ui-kit-section" id="palette">
            <p class="eyebrow accent">01 / DESIGN TOKENS</p>
            <h2>Палитра и типографика</h2>
            <p class="ui-kit-intro">Цвета считываются из сохранённой темы в Filament. В CSS применяются действующие переменные, не ручные копии.</p>
            <div class="ui-kit-swatch-grid">
                @foreach (\App\Presentation\Theming\DesignThemeCss::COLORS as $token => $label)
                    <div class="ui-kit-swatch">
                        <i style="background-color:var(--{{ $token }})" aria-hidden="true"></i>
                        <strong>{{ $label }}</strong>
                        <code>--{{ $token }}</code>
                    </div>
                @endforeach
            </div>
            <div class="ui-kit-grid" style="margin-top:16px">
                <div class="panel ui-kit-panel">
                    <p class="eyebrow accent">DISPLAY / H1</p>
                    <p class="ui-kit-large">БОЛЬШЕ,<br>ЧЕМ <span class="accent">ИГРА.</span></p>
                    <h2>Заголовок секции</h2>
                    <h3>Заголовок карточки</h3>
                    <p>Основной текст: площадки, турниры и команды в Москве.</p>
                    <p class="muted">Вторичный текст и пояснения к интерфейсу.</p>
                </div>
                <div class="panel ui-kit-panel">
                    <p class="eyebrow accent">НАСТРОЙКИ ШРИФТОВ</p>
                    <div class="details-list">
                        <div><span>Основной шрифт</span><strong>{{ \App\Presentation\Theming\DesignFontCatalog::OPTIONS[$theme->body_font ?? 'inter'] ?? 'Inter' }}</strong></div>
                        <div><span>Заголовки</span><strong>{{ \App\Presentation\Theming\DesignFontCatalog::OPTIONS[$theme->heading_font ?? 'inter'] ?? 'Inter' }}</strong></div>
                        <div><span>Схема</span><strong>{{ $theme->mode === 'light' ? 'Светлая' : 'Тёмная' }}</strong></div>
                        <div><span>Код</span><strong>{{ $theme->slug }}</strong></div>
                    </div>
                    <p class="ui-kit-hint">Шрифты, цвета и параметры CSS обновляются после сохранения темы в Filament.</p>
                </div>
            </div>
        </section>

        <section class="ui-kit-section" id="actions">
            <p class="eyebrow accent">02 / STATES</p>
            <h2>Кнопки, статусы и фильтры</h2>
            <div class="ui-kit-grid">
                <div class="panel ui-kit-panel">
                    <h3>Действия</h3>
                    <div class="actions">
                        <button type="button" class="button primary" data-kit-dialog-open>Primary · модальное окно</button>
                        <button type="button" class="button secondary" data-kit-toast-open>Secondary · уведомление</button>
                        <button type="button" class="button ghost" data-kit-toast-open>Ghost</button>
                        <button type="button" class="button danger" data-kit-dialog-open>Destructive</button>
                    </div>
                    <div class="actions">
                        <button type="button" class="button primary" disabled>Disabled</button>
                        <button type="button" class="button secondary" disabled aria-busy="true"><span class="spinner" aria-hidden="true"></span> Loading</button>
                    </div>
                    <p class="ui-kit-hint">Наведи курсор, используй Tab и попробуй открыть окно или уведомление.</p>
                </div>
                <div class="panel ui-kit-panel">
                    <h3>Статусы и фильтры</h3>
                    <div class="actions">
                        <span class="badge success">Подтверждено</span>
                        <span class="badge warning">Ожидает</span>
                        <span class="badge danger-badge">Ошибка</span>
                        <span class="badge">Черновик</span>
                    </div>
                    <div class="filter-group" role="group" aria-label="Демонстрационные фильтры" data-kit-chips>
                        <button type="button" class="chip" aria-pressed="true">Все</button>
                        <button type="button" class="chip" aria-pressed="false">5×5</button>
                        <button type="button" class="chip" aria-pressed="false">3×3</button>
                    </div>
                    <div class="notice inline"><div><strong>Информация</strong><p>Так выглядит системное уведомление в выбранной теме.</p></div></div>
                </div>
            </div>
        </section>

        <section class="ui-kit-section" id="forms">
            <p class="eyebrow accent">03 / FORM CONTROLS</p>
            <h2>Формы и состояния полей</h2>
            <div class="ui-kit-grid">
                <div class="panel ui-kit-panel">
                    <h3>Создать игру</h3>
                    <form data-kit-form novalidate>
                        <div class="field-group">
                            <label for="kit-name">Название игры</label>
                            <input id="kit-name" name="name" placeholder="Вечерний баскетбол" required aria-describedby="kit-help">
                            <p class="helper" id="kit-help">Пример обязательного поля.</p>
                        </div>
                        <div class="ui-kit-field-row">
                            <div class="field-group"><label for="kit-date">Дата</label><input id="kit-date" type="date" value="2026-10-19"></div>
                            <div class="field-group"><label for="kit-time">Время</label><input id="kit-time" type="time" value="19:00"></div>
                        </div>
                        <div class="field-group">
                            <label for="kit-select">Формат</label>
                            <select id="kit-select"><option>5×5</option><option>3×3</option></select>
                        </div>
                        <div class="field-group">
                            <label for="kit-error">Поле с ошибкой</label>
                            <input id="kit-error" aria-invalid="true" aria-describedby="kit-error-hint" value="Неверное значение">
                            <p class="error" id="kit-error-hint">Проверь введённые данные.</p>
                        </div>
                        <div class="field-group"><label for="kit-comment">Комментарий</label><textarea id="kit-comment" placeholder="Что нужно знать участникам"></textarea></div>
                        <label class="check-label"><input type="checkbox" checked><span>Принимать заявки</span></label>
                        <label class="check-label switch-label"><input type="checkbox" role="switch" checked><span>Напоминать об игре</span></label>
                        <fieldset><legend>Тип площадки</legend><div class="actions">
                            <label class="check-label"><input type="radio" name="court" checked> Открытая</label>
                            <label class="check-label"><input type="radio" name="court"> Крытая</label>
                        </div></fieldset>
                        <button type="submit" class="button primary full">Проверить образец</button>
                        <p class="helper" role="status" aria-live="polite" data-kit-form-status>Демо: данные не отправляются на сервер.</p>
                    </form>
                </div>
                <div class="panel ui-kit-panel">
                    <h3>Поиск и загрузка</h3>
                    <label class="search-control">
                        <input type="search" aria-label="Поиск в демонстрации" placeholder="Найти площадку…">
                    </label>
                    <p class="ui-kit-hint">Поиск не делает сетевых запросов. Поля сохраняют настоящие фокус-стили.</p>
                    <div class="skeleton" aria-label="Пример состояния загрузки" role="img"><span></span><span></span><span></span></div>
                    <div class="notice error-notice"><div><strong>Не удалось загрузить</strong><p>Ошибка показана текстом и цветом.</p></div></div>
                </div>
            </div>
        </section>

        <section class="ui-kit-section" id="components">
            <p class="eyebrow accent">04 / COMPONENTS</p>
            <h2>Карточки, навигация и таблицы</h2>
            <div class="ui-kit-grid">
                <article class="panel ui-kit-panel">
                    <p class="eyebrow accent">ПЛОЩАДКИ</p>
                    <div class="ui-kit-sample-card">
                        <span class="badge success">Открытая площадка</span>
                        <h3>Баскетбол в парке</h3>
                        <p class="muted">Москва · пример карточки площадки</p>
                        <div class="details-list">
                            <div><span>Формат</span><strong>5×5 / 3×3</strong></div>
                            <div><span>Покрытие</span><strong>Резиновое</strong></div>
                        </div>
                    </div>
                    <button type="button" class="button secondary full" data-kit-dialog-open>Посмотреть детали</button>
                </article>
                <article class="panel ui-kit-panel">
                    <p class="eyebrow accent">НАВИГАЦИЯ</p>
                    <nav class="ui-kit-example-nav" aria-label="Пример навигации">
                        <a href="#components" aria-current="page">Площадки</a>
                        <a href="#components">Мероприятия</a>
                        <a href="#components">Команды</a>
                    </nav>
                    <p class="ui-kit-hint">В каталоге показаны общие базовые состояния. Hero Slider и параллакс-сцену добавим как отдельные прикладные демонстрации после утверждения их общего контракта.</p>
                </article>
                <article class="panel ui-kit-panel">
                    <p class="eyebrow accent">ТАБЛИЦА</p>
                    <div class="ui-kit-table-scroll" role="region" tabindex="0" aria-label="Пример турнирной таблицы">
                        <table>
                            <caption>Пример результатов</caption>
                            <thead><tr><th scope="col">Команда</th><th scope="col">Игры</th><th scope="col">Очки</th></tr></thead>
                            <tbody><tr><th scope="row">Север</th><td>4</td><td>8</td></tr><tr><th scope="row">Центр</th><td>4</td><td>6</td></tr></tbody>
                        </table>
                    </div>
                </article>
            </div>
        </section>
        <p class="ui-kit-footer">Образцы MSKBA App · изолированный UI Kit · {{ $theme->slug }}</p>
    </div>
</main>

<dialog id="kit-modal" class="mskba-modal ui-kit-sample-dialog" aria-labelledby="kit-modal-title" aria-describedby="kit-modal-desc">
    <header class="dialog-header mskba-modal__header"><strong>MSKBA / UI Kit</strong><button type="button" class="button ghost" data-kit-dialog-close aria-label="Закрыть окно">×</button></header>
    <div class="dialog-body mskba-modal__body">
        <p class="eyebrow accent">ПРИМЕР МОДАЛЬНОГО ОКНА</p>
        <h2 id="kit-modal-title">Готов к игре?</h2>
        <p class="muted" id="kit-modal-desc">Модальная оболочка, контраст, кнопки и фокус используют активную тему. Ничего не сохраняется.</p>
    </div>
    <footer class="dialog-footer actions mskba-modal__footer">
        <button type="button" class="button secondary" data-kit-dialog-close>Закрыть</button>
        <button type="button" class="button primary" data-kit-dialog-confirm>Понятно</button>
    </footer>
</dialog>
<div class="toast ui-kit-demo-toast" role="status" data-kit-toast hidden>
    <div><strong>Готово!</strong><p>Это демонстрационное уведомление UI Kit.</p></div>
    <button type="button" class="button ghost" data-kit-toast-close aria-label="Закрыть уведомление">×</button>
</div>
@endif
<script>
(() => {
    const frame = document.querySelector('[data-kit-frame]');
    const picker = document.querySelector('[data-theme-picker]');
    if (frame && picker) {
        picker.addEventListener('change', () => { window.location.assign(picker.value); });
        document.querySelectorAll('[data-kit-widths] button').forEach(button => {
            button.addEventListener('click', () => {
                const value = Number(button.dataset.width);
                if (![1440, 768, 390, 360].includes(value)) return;
                frame.style.width = 'min(100%, ' + value + 'px)';
                document.querySelectorAll('[data-kit-widths] button').forEach(b =>
                    b.setAttribute('aria-pressed', String(b === button)));
                document.querySelector('[data-kit-frame-label]').textContent =
                    button.textContent + ' · ' + value + ' px';
            });
        });
    }

    const dialog = document.getElementById('kit-modal');
    const toast = document.querySelector('[data-kit-toast]');
    if (!dialog || !toast) return;
    document.querySelectorAll('[data-kit-dialog-open]').forEach(button =>
        button.addEventListener('click', () => dialog.showModal()));
    document.querySelectorAll('[data-kit-dialog-close]').forEach(button =>
        button.addEventListener('click', () => dialog.close()));
    document.querySelector('[data-kit-dialog-confirm]')?.addEventListener('click', () => {
        dialog.close();
        toast.hidden = false;
    });
    dialog.addEventListener('click', event => { if (event.target === dialog) dialog.close(); });
    document.querySelectorAll('[data-kit-toast-open]').forEach(button =>
        button.addEventListener('click', () => { toast.hidden = false; }));
    document.querySelector('[data-kit-toast-close]')?.addEventListener('click', () => {
        toast.hidden = true;
    });
    document.querySelectorAll('[data-kit-chips] button').forEach(button =>
        button.addEventListener('click', () => {
            document.querySelectorAll('[data-kit-chips] button').forEach(item =>
                item.setAttribute('aria-pressed', String(item === button)));
        }));
    document.querySelector('[data-kit-form]')?.addEventListener('submit', event => {
        event.preventDefault();
        const input = document.getElementById('kit-name');
        const status = document.querySelector('[data-kit-form-status]');
        status.textContent = input.value.trim()
            ? 'Пример пройден. Данные не сохранены.'
            : 'Введите название игры.';
        if (!input.value.trim()) input.focus();
    });
})();
</script>
</body>
</html>
