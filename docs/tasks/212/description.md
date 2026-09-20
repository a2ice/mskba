# 212 - Доработать Telegram hero spacing и плавность параллакса на мобильных

## Оригинальное описание

После исправления Telegram safe-area внешний отступ между шапкой и hero оказался визуально слишком большим: отступ нужен внутри hero для текста, а не между header и фоном. На узком мобильном экране foreground-слой с баскетбольным щитом слишком уходит вправо. Кроме того, многослойный parallax при прокрутке в Telegram WebView двигается рывками.

## Подробное описание

### Hero spacing

- убрать внешний `padding-top` у `.site-content` в Telegram Mini App;
- сохранить тот же визуальный воздух перед текстом, перенеся 18 px внутрь `.home-welcome__content`;
- фон hero должен начинаться сразу после in-flow header.

### Parallax

Текущий код читает `getBoundingClientRect()` в каждом scroll-render и напрямую привязывает transform к дискретным scroll events. В Telegram/iOS WebView события могут приходить не на каждый кадр, из-за чего слои визуально перескакивают.

Нужно:
- убрать layout-read из горячего scroll path;
- кэшировать document top/height hero и пересчитывать геометрию только при init/resize;
- использовать requestAnimationFrame loop с коротким time-based smoothing между текущим и целевым scroll offset;
- не держать бесконечный animation loop, завершать его после сходимости;
- сохранить GPU `translate3d` transforms.

### Узкий мобильный экран

Для foreground `court` layer на ширине до 520 px сместить framing влево примерно на 5–10% без изменения остальных слоёв.

## Проверка

- production frontend build;
- полный backend CI;
- в Telegram hero background начинается сразу под header, а текст сохраняет нужный внутренний отступ;
- parallax визуально плавный при медленной и быстрой прокрутке;
- щит на узком mobile виден заметнее, но смещение не превышает 10%.

## Результат

Внешний 18 px gap у `.site-content` удалён. Те же 18 px перенесены внутрь `.home-welcome__content`, поэтому фон hero начинается сразу под Telegram header, а текст сохраняет нужный визуальный воздух.

Parallax больше не читает `getBoundingClientRect()` на каждом scroll-render. Позиция/высота hero кэшируются и обновляются на init, resize и ResizeObserver; scroll меняет только target offset. Между target и фактическим transform добавлено короткое time-based RAF smoothing (45 ms), которое сглаживает пакетную доставку scroll events в Telegram/iOS WebView и останавливает loop после сходимости.

Foreground `court` layer получил отдельный `--home-parallax-x`; на ширине до 520 px он сдвигается влево на 6vw. Масштаб 1.18 оставляет достаточный запас по краям и не меняет остальные слои.
