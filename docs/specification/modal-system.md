# Modal System

## Оглавление

- [Общий shell](#общий-shell)
- [Lifecycle и доступность](#lifecycle-и-доступность)
- [URL-контракт](#url-контракт)
- [Интеграция feature-кода](#интеграция-feature-кода)
- [Границы](#границы)

## Общий shell

Попапы основной темы создаются через `theme::partials.modal.layout`. Shell состоит из трёх областей:

- `.modal__header` — синхронизируемый заголовок, сворачивание и закрытие;
- `.modal__body` — единственная прокручиваемая область с `min-height: 0` и `overflow-y: auto`;
- необязательный `.modal__footer` — неподвижные действия формы или wizard.

Существующий feature-код может продолжать менять локальный `.modal_title`: общий header наблюдает за активным заголовком и синхронизирует его текст, не вырывая исходный узел из DOM-контекста feature. Для фиксированного footer Blade-компонент принимает named slot `footer`; динамический код может создать `[data-modal-footer-container]`, а существующий узел с `[data-modal-footer]` shell переносит в эту область при инициализации.

Dialog центрируется внутри `visualViewport`. Его CSS-переменные обновляются при resize/scroll visual viewport, поэтому zoom, экранная клавиатура и Telegram safe-area не возвращают расчёт к document viewport.

## Lifecycle и доступность

Единый lifecycle поддерживает `open`, `minimize`, `restore` и `close`:

- открытие блокирует прокрутку фона, переводит focus внутрь и запоминает инициатор;
- `Tab`/`Shift+Tab` остаются внутри верхнего развёрнутого окна;
- `Escape` и backdrop закрывают верхнее окно;
- сворачивание снимает modal-блокировку страницы, оставляет компактный header в правом нижнем углу и не удаляет DOM/state формы;
- разворачивание возвращает modal semantics и focus;
- закрытие возвращает focus живому инициатору.

Сворачивание, в отличие от закрытия, не возвращает focus инициатору: активной остаётся та же кнопка, которая после смены состояния становится кнопкой «Развернуть». Runtime-атрибут `data-modal-action` читается непосредственно из DOM, поэтому повторные циклы minimize/restore не зависят от кэша jQuery.

Сохраняются события `modal:opened` и `modal:closed`; дополнительно публикуются `modal:minimized` и `modal:restored`. Это UI lifecycle, он не меняет backend authorization и доменные правила.

## URL-контракт

Состояние кодируется query-параметрами:

```text
?modal=<data-modal id>
?modal=<data-modal id>&modal_state=minimized
```

Изменения выполняются через `history.replaceState`: Back не засоряется техническими шагами popup. Close удаляет оба параметра. Неизвестный id игнорируется. Обычный deep link открывается через одну секунду после события `load`, чтобы страница успела стабилизироваться; minimized deep link восстанавливается сразу после DOM ready.

В URL хранится только id и UI-состояние. Значения полей, CSRF-токены, результаты запросов и другие чувствительные данные туда не переносятся. Для окна, которое принципиально нельзя восстанавливать из адреса, layout принимает `persistInUrl => false`.

Параметры `modal` и `modal_state` считаются временным UI-состоянием и удаляются из целевого URL после успешной авторизации. Это выполняется и в browser lifecycle (`MskbaModal.withoutState`), и в общем backend-resolver безопасных auth-редиректов, поэтому правило одинаково для пароля, регистрации, VK и Telegram. Остальные query-параметры и fragment сохраняются.

## Интеграция feature-кода

Обычные кнопки используют текущий декларативный контракт:

```html
<button data-handler="modal" data-modal-action="open" data-modal-target="example">Открыть</button>
```

Для программного управления доступен `window.MskbaModal` с методами `open`, `close`, `minimize`, `restore` и helper `withoutState(url)` для очистки popup-параметров перед навигацией. Feature-код не должен самостоятельно переключать `hidden`, `is-open` и `body.modal-open`, иначе URL, focus и события расходятся. Homepage wizard, вложенный location wizard, игровые формы и управление правами команды используют общий lifecycle.

## Границы

Контракт автоматически охватывает окна на `theme::partials.modal.layout` и динамический location mini-wizard, приведённый к тому же DOM shell. Media lightbox, старые venue day overlays, native `<dialog>` редактора цен и Telegram-specific overlays пока имеют отдельный media/platform lifecycle; механически подменять его без проверки нельзя.
