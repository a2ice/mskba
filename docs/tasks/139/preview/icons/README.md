# Tabler Icons — локальный набор стенда

Источник: [Tabler Icons](https://tabler.io/icons), Outline **3.46.0**, [MIT](LICENSE).
Оригиналы: `https://github.com/tabler/tabler-icons/tree/v3.46.0/icons/outline`.

В `../index.html` SVG помещены в inline symbols. Path-данные не изменены;
оболочка `svg` заменена на `symbol`, общие атрибуты вынесены в CSS:
viewBox 0 0 24 24, fill none, stroke currentColor, stroke-width 2,
stroke-linecap/linejoin round. Служебные метаданные оригиналов опущены.
Сами иконки не рисуются и не корректируются вручную. CDN во время просмотра не нужен.

| Symbol ID | Исходный файл .svg |
| --- | --- |
| ball | ball-basketball |
| arrow | arrow-right |
| pin | map-pin |
| people | users |
| cup | trophy |
| clock | clock |
| search | search |
| sliders | adjustments-horizontal |
| bell | bell |
| home | home |
| user | user |
| check | check |
| close | x |
| calendar | calendar |
| chevron-down | chevron-down |
| arrow-up-right | arrow-up-right |
| arrow-up | arrow-up |

Зависимости production-приложения не меняются ради статического стенда.
Для Vue-компонентов в этапе 004 использовать официальный пакет `@tabler/icons-vue`
с явными импортами нужных иконок, не переносить ручное редактирование sprite в production.