# Task 199 — Hero asset для встроенной acquisition-листовки

## Цель

Заменить декоративный оранжевый круг в A4 acquisition flyer на загруженный пользователем баскетбольный мяч с огнём, не связывая Blade-шаблон с конкретным путём к файлу.

## Архитектурное решение

Текущая листовка остаётся trusted built-in template, но её медиа оформляются как именованные asset slots в template definition:

- `logo`;
- `hero_image`.

Blade получает только подготовленные data URI через template context:

- `logoDataUri`;
- `heroImageDataUri`.

Физический путь к файлу хранится в `config/document-templates.php`, а не в Blade. Это сохраняет границу будущего шаблонизатора: DB/template editor сможет назначать свои media assets тем же слотам без изменения PDF renderer или разметки consumer-кода.

Для literal template keys с точками используется общий `TrustedTemplateRegistry`, чтобы lookup definition/assets не зависел от Laravel dot-notation.

## UI

- убрать декоративное кольцо справа;
- использовать прозрачный PNG мяча как hero-art справа;
- сохранить читаемую левую колонку текста;
- asset не должен ломать HTML preview и Chromium PDF;
- при отсутствии hero asset шаблон остаётся валидным.

## Статус

- [x] asset path normalized;
- [x] template asset registry;
- [x] flyer layout;
- [x] smoke/tests;
- [x] CI / merge.

Merged to `main` as `d65dc72a4e9a594a94896a9e97cfe29da3144bf1`; production document smoke прошёл с hero asset.
