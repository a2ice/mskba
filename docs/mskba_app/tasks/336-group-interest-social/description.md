# MSKBA App · 336 — группы игровых интересов, выборы и общая история

Основная задачу: [GitHub #849](https://github.com/a2ice/mskba/issues/849).
Полная концепция: [docs/tasks/336](../../../tasks/336/description.md), [287 — группы](../../../tasks/287/group-interest-workspace-20261118.md), [управление](../../../tasks/287/group-governance-20261118.md).
Эталон: [UI Kit темы / Дизайн-система](../../../specification/ui-design-system.md), существующие shell, context modal, account и sidebars.

## Что изменяем
- Добавляем страницу `/play/opportunities/{uuid}` через `theme::layouts.app`, не заменяя site header/footer/mobile nav, breadcrumbs и общую контекстную панель.
- Внутри страницы создаём собственную смысловую навигацию (обзор, площадки, участники, обсуждение, история), на desktop боковые sticky-вкладки, на mobile горизонтально прокручиваемые вкладки без второго бар-меню.
- Над основным содержанием показываем единый banner заявок кандидатов и голосов.
- Используем существующие `.button`, `.panel`, поля и CSS токены темы; отдельный `play-opportunity.css` отвечает только за композицию.
- Стена мероприятий добавляется в существующую страницу `events.show` как отдельный partial и две вкладки, сохраняя весь старый layout, management controls и roster. Для неё `event-social-wall.css` следует legacy dark-theme токенам.
- Обычные сообщения max 200 Unicode chars, визуальное зачёркивание удаления. Системные события RSVP и выборов immutable, в тех же потоках. Счётчики из фактических данных, а не ленты.
- Ссылки FAQ открывают опубликованную статью штатным `data-mskba-faq-section=other`/`data-mskba-faq-article=play-interest-group-howto` без самодельного `dialog`.
- Не менять личные/финансовые разрешения Event и Booking при внедрении group-admin.

## Приёмка UI и регрессии
- desktop 1440/1280, tablet 768, mobile 390/360 и TMA: header, breadcrumbs, contextual sidebar, sticky group tabs, bottom mobile nav.
- Клавиатура: фокус, tabindex, устойчивый порядок, Enter в формах; нет потери формы и скачков при переключении вкладок.
- Стена: scroll, визуальная и серверная валидация, правильные состояния 429/422/403, совместимость AJAX с native fallback.
- Проверить редактирование group custom title, кандидатские голоса, изменение голосов и историю, чужие private группы и сообщения.
- Перед merge `main`: PHPUnit/CI/JS build, сценарии групп, событий, venue, Filament, UX. После деплоя production smoke.
