# 213 - Перевести parallax hero в Safari на native scroll-driven animation

## Оригинальное описание

На одном и том же iPhone 16 Pro Max parallax hero работает плавно внутри Telegram Mini App, но в обычном Safari слои заметно двигаются рывками. Значит проблема не в производительности устройства или самих изображениях, а в способе синхронизации JS-transform с нативным Safari scroll.

## Причина

Текущий fallback обновляет transform из JavaScript. Даже после RAF smoothing Safari может прокручивать документ асинхронно на compositor thread, а main-thread JS получает/видит scroll position с другой частотой. Поэтому контент страницы движется плавно, а JS-driven слои визуально догоняют его.

Современный Safari поддерживает CSS Scroll-driven Animations, а в актуальных версиях WebKit такие animations выполняются на compositor thread и синхронизированы с нативным scroll.

## Реализация

- для обычного браузера с поддержкой `animation-timeline: scroll(root block)` использовать native CSS scroll timeline;
- Telegram Mini App оставить на текущем JS-path, который уже работает плавно;
- animation range должен совпадать с текущей логикой: от document-top hero до heroTop + heroHeight;
- индивидуальная скорость каждого слоя остаётся прежней через рассчитанный конечный Y offset;
- мобильный scale и отдельный horizontal shift court layer сохраняются;
- для браузеров без Scroll-driven Animations оставить существующий JS fallback;
- geometry обновлять только при init/load/resize/ResizeObserver, без scroll-time layout reads.

## Проверка

- полный CI и production frontend build;
- Safari на iPhone: параллакс должен быть синхронизирован с нативным scroll без рывков;
- Telegram Mini App не должен регрессировать;
- browsers без native timeline продолжают использовать JS fallback.

## Результат

Для обычного браузера с поддержкой CSS Scroll-driven Animations parallax переведён с main-thread JS transforms на native `animation-timeline: scroll(root block)`. JS теперь только измеряет document-top/height hero при init/load/resize и задаёт каждому слою конечный Y offset; сам scroll-progress ведёт браузер.

Telegram Mini App намеренно остаётся на существующем JS path, поскольку на реальном iPhone он уже работает плавно. Браузеры без `animation-timeline` также используют этот fallback.

Native range повторяет прежнюю математику: старт = document top hero, конец = heroTop + heroHeight. Мобильный scale и отдельный сдвиг court layer через `--home-parallax-x` сохраняются.
