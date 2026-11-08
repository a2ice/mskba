# FAQ-модалки в Page Builder (MSKBA App)

## Единый механизм

Никаких копий попапа в редакторе: на всех страницах темы **mskba_app**
подключается один `theme::partials.context-action-dialogs` с нативным
`<dialog class="mskba-modal app-context-help-dialog">`.
Стили `mskba-modal`, backdrop, заголовок и drag-handle общие для сайта.
`context-dialogs.js` загружает актуальную опубликованную FAQ-статью из
`/faq/help-content` и управляет закрытием, возвратом фокуса, Escape,
отправкой вопроса и авторизацией. Никаких inline-обработчиков и
самодельных копий модалки.

## HTML кнопок внутри любого Page Builder компонента

```html
<button class="section-cta" type="button"
        data-mskba-faq-section="other"
        data-mskba-faq-article="faq-uniform-selection">Подробнее</button>

<button class="mskba-uniform-button mskba-uniform-button--primary" type="button"
        data-mskba-faq-section="other"
        data-mskba-faq-article="faq-uniform-partnership">Как стать партнёром</button>

<button type="button" data-mskba-faq-section="other"
        data-mskba-faq-article="faq-uniform-workshop">Как это работает</button>
```

Дополнительная статья о производстве формы:
`data-mskba-faq-article="faq-uniform-production"`.

Атрибуты разрешены строго для `<button>` редакторским санитайзером;
`type="button"` выставляется принудительно, `onclick`, скрипты и
произвольный HTML не допускаются. Текст, CSS и расположение кнопок
принадлежат компоненту Filament. Глобальный обработчик использует
делегирование `document.addEventListener('click')`, поэтому действует
и для вложенных компонентов, не требуя индивидуального JS.

Альтернативный программный вызов для будущих editor-owned JS компонентов:

```js
window.dispatchEvent(new CustomEvent('mskba:faq:open', {
  detail: {
    section: 'other',
    article: 'faq-uniform-selection',
    trigger: document.activeElement,
  },
}));
```

Статьи создаются и редактируются в разделе «Контент → Материалы и FAQ». В атрибуте `data-mskba-faq-article` указывается значение поля «Алиас» опубликованной статьи. После смены алиаса обновите атрибут в кнопках, которые открывают эту статью. По состоянию на
2026-11-05 эти четыре статьи опубликованы в разделе `other` на DEV.
Разделы создаются в «Контент → Разделы FAQ», назначаются в карточке материала. При переносе статьи в другой раздел обновите `data-mskba-faq-section` на код нового раздела. Раздел «Мастерская» пока только справочный;
кнопка запуска полноценного конструктора потребует отдельной реализации
самого конструктора.

## Проверка

1. На главной нет лишней контекстной панели, но присутствует один
   `data-context-help-dialog`.
2. Нажатие «Подробнее» открывает `faq-uniform-selection`,
   «Как стать партнёром» — `faq-uniform-partnership`.
3. Общие шапка, перетаскивание, крестик, Escape, фон и мобильная адаптация
   остаются штатными.
4. Другие страницы не получают двойных FAQ-окон.
5. `data-mskba-faq-*` сохраняются в публичном HTML Page Builder;
   `onclick` продолжает отбрасываться.
