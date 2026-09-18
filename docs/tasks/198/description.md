# Task 198 — Исправить acquisition flyer preview/PDF на production

## Симптом

После Task 196/197 в production:

- `/admin/acquisition/{id}/flyer` → HTTP 500;
- `/admin/acquisition/{id}/flyer.pdf` → HTTP 500.

QR/flyer pipeline состоит из:

1. `AcquisitionQrCodeRenderer` → `qrencode`;
2. `AcquisitionFlyerContextFactory`;
3. trusted Blade template;
4. для PDF — `GotenbergDocumentRenderer`.

Так как HTML preview падает до вызова Gotenberg, первичная диагностика должна изолировать QR/context/template runtime.

## План

- выполнить production-side smoke через отдельный diagnostic workflow;
- проверить наличие и фактический запуск `qrencode` внутри текущего phpfpm;
- отдельно проверить flyer context;
- отдельно проверить Blade render;
- отдельно проверить Gotenberg PDF;
- исправить корневую причину;
- добавить автоматический smoke, чтобы deploy больше не считался успешным при сломанном document pipeline;
- удалить временную диагностику после фикса.

## Результат диагностики

Production diagnostic подтвердил:

- `qrencode 4.1.1` установлен и успешно генерирует SVG;
- `AcquisitionQrCodeRenderer` работает;
- `AcquisitionFlyerContextFactory` работает;
- Gotenberg/Chromium успешно возвращает PDF;
- ошибка возникает в `BladeTemplateRenderer`: literal key `acquisition.flyer.a4` читался через Laravel dot-notation и превращался в несуществующий nested path.

Исправление читает registry целиком и затем обращается к exact array key.

## Статус

- [x] воспроизведено в production UI;
- [x] production diagnostics;
- [x] fix;
- [x] tests;
- [x] deploy smoke;
- [x] merge/deploy.

Merged to `main` as `e0c0421c1c86db8fa8a42ff782a7d2f5a60c89af`. Production deploy `35351522779` completed successfully; runtime smoke reported `QR 26103 B, HTML 40213 B, PDF 92616 B`.
