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

Существующий feature-код может продолжать менять локальный `.modal_title`: общий header наблюдает за активным заголовком и синхронизирует его текст, не вырывая исходный узел из DOM-контекста feature. Видимый заголовок в `.modal__header` всегда использует базовую типографику общего shell (`20px`, `line-height: 1.1`, `font-weight: 800`); feature-стили не должны переопределять `.modal__header [data-modal-title]`. Для фиксированного footer Blade-компонент принимает named slot `footer`; динамический код может создать `[data-modal-footer-container]`, а существующий узел с `[data-modal-footer]` shell переносит в эту область при инициализации.

Dialog центрируется внутри `visualViewport`. Его CSS-переменные обновляются при resize/scroll visual viewport, поэтому zoom, экранная клавиатура и Telegram safe-area не возвращают расчёт к document viewport.

## Lifecycle и доступность

Единый lifecycle поддерживает `open`, `minimize`, `restore` и `close`:

- открытие блокирует прокрутку фона, переводит focus внутрь и запоминает инициатор;
- `Tab`/`Shift+Tab` остаются внутри верхнего развёрнутого окна;
- `Escape` и backdrop закрывают верхнее окно;
- сворачивание снимает modal-блокировку страницы, не удаляет DOM/state формы и переносит окно во вкладку общего tray;
- если сворачивание вызвано из активного окна, focus переходит на кнопку восстановления соответствующей вкладки tray;
- разворачивание возвращает modal semantics и focus внутрь окна;
- закрытие возвращает focus живому инициатору.

Runtime-атрибут `data-modal-action` читается непосредственно из DOM, поэтому повторные циклы minimize/restore не зависят от кэша jQuery. `Escape` закрывает только верхнее развёрнутое окно и не удаляет скрытые вкладки tray.

Сохраняются события `modal:opened` и `modal:closed`; дополнительно публикуются `modal:minimized` и `modal:restored`. Это UI lifecycle, он не меняет backend authorization и доменные правила.

## URL-контракт

Состояние кодируется query-параметрами:

```text
?modal=<expanded-id>
?modal_tray=<minimized-id-1>,<minimized-id-2>
?modal=<expanded-id>&modal_tray=<minimized-id-1>,<minimized-id-2>
```

Старый deep link `?modal=<id>&modal_state=minimized` поддерживается при чтении для обратной совместимости и восстанавливается как вкладка tray. При следующей синхронизации состояние записывается уже через `modal_tray`.

Изменения выполняются через `history.replaceState`: Back не засоряется техническими шагами popup. Close удаляет id закрытого окна из сериализованного состояния. Неизвестные id безопасно игнорируются. Вкладки tray восстанавливаются сразу после DOM ready, а развёрнутый deep link открывается через одну секунду после события `load`, чтобы страница успела стабилизироваться.

Tray допускает несколько свёрнутых окон. Вкладки сохраняют порядок, сжимаются до минимальной ширины 100 px, после чего область становится горизонтально прокручиваемой. На мобильном экране tray компактнее и через `--modal-tray-height` поднимает mobile primary bar и нижний отступ страницы, не перекрывая навигацию.

В URL хранится только id и UI-состояние. Значения полей, CSRF-токены, результаты запросов и другие чувствительные данные туда не переносятся. Для окна, которое принципиально нельзя восстанавливать из адреса, layout принимает `persistInUrl => false`.

Параметры `modal`, `modal_state` и `modal_tray` считаются временным UI-состоянием и удаляются из целевого URL после успешной авторизации. Это выполняется и в browser lifecycle (`MskbaModal.withoutState`), и в общем backend-resolver безопасных auth-редиректов, поэтому правило одинаково для пароля, регистрации, VK и Telegram. Остальные query-параметры и fragment сохраняются.

## Интеграция feature-кода

Обычные кнопки используют текущий декларативный контракт:

```html
<button data-handler="modal" data-modal-action="open" data-modal-target="example">Открыть</button>
```

Для программного управления доступен `window.MskbaModal` с методами `open`, `close`, `minimize`, `restore` и helper `withoutState(url)` для очистки popup-параметров перед навигацией. Feature-код не должен самостоятельно переключать `hidden`, `is-open` и `body.modal-open`, иначе URL, focus и события расходятся. Homepage wizard, вложенный location wizard, игровые формы и управление правами команды используют общий lifecycle.

## Границы

Контракт автоматически охватывает окна на `theme::partials.modal.layout` и динамический location mini-wizard, приведённый к тому же DOM shell. Media lightbox, старые venue day overlays, native `<dialog>` редактора цен и Telegram-specific overlays пока имеют отдельный media/platform lifecycle; механически подменять его без проверки нельзя.
