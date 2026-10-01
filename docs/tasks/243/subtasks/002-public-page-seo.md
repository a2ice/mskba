# 002 - Public page SEO templates and structured data

## Цель

Завершить внутренний SEO-аудит публичных посадочных до подключения масштабной контентной дистрибуции.

## Статус

Запланировано после merge PR #282.

## Подтверждённые production-наблюдения

- каталоги `/venues`, `/events`, `/teams`, `/tournaments`, `/sections` не имеют собственных meta description;
- `/players` и `/coaches` используют одинаковый title «Участники · MSKBA»;
- detail страницы площадки и команды уже получают description через существующий SEO resolver;
- detail страница турнира не получает description;
- общий document language до PR #282 был `en`;
- structured data специализированных публичных сущностей пока отсутствует.

## Состав

- уникальные title/description для основных каталогов и role-specific participant catalogs;
- шаблоны title/description для detail страниц, где их ещё нет;
- JSON-LD по типам сущностей: SportsActivityLocation/Place, SportsTeam, SportsEvent/Event, ProfilePage/Person и ItemList/CollectionPage там, где это действительно соответствует странице;
- единая политика canonical/noindex для фильтров, поиска и пагинации каталогов;
- аудит H1/title и внутренних ссылок;
- аудит meaningful alt для публичных галерей, карточек и логотипов;
- проверка redirect/status/canonical для legacy и альтернативных URL;
- повторный live crawl после deployment.

## Результат

После этапа публичные страницы должны иметь не только общий fallback, но и предметно релевантные метаданные и schema.org-разметку.
