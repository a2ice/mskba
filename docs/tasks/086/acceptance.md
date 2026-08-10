# Финальная локальная приёмка Event / Game / Tournament

## Подготовка

```bash
make up
make migrate
make acceptance-seed
npm run build
```

`acceptance-seed` разрешён только в `local/testing`, повторяем и не входит в production
`DatabaseSeeder`. Учётная запись: `demo-organizer`, пароль: `demo-password`.

## Основной браузерный сценарий

1. Войти как `demo-organizer` и открыть `/tournaments`.
2. Открыть `[DEMO] Круговой турнир 3×3` и проверить разделы Overview, Teams, Games, Table.
   Турнир уже идёт: eyebrow показывает «Идёт», а подача новых заявок и приглашений закрыта.
3. В таблице должна учитываться только завершённая игра `12:8`; неподтверждённая/запланированная игра не меняет standings.
4. Открыть управление турниром. Проверить четыре команды по три игрока, шесть матчей и ссылки на две назначенные игры.
5. Для неназначенного матча раскрыть форму назначения, изменить дату/длительность, найти площадку предикативным вводом и создать Event + Game + Booking.
6. Для назначенной неначатой игры проверить перенос на другой свободный слот.
7. Открыть запланированную игру: ссылка назад ведёт в Tournament, public screen не показывает
   mutation controls, management screen показывает состав, lifecycle, счёт и статистику.
8. Назначить точный стартовый состав, запустить игру, провести игровые действия, завершить и
   подтвердить итог. После возврата в Tournament standings должен измениться ровно один раз.
9. Отдельно открыть `[DEMO] Игра до начала`, игровую тренировку с двумя mini-games и завершённую standalone-игру — это регресс общего runtime.
10. Повторить ключевые public/manage экраны в ширине около 375 px: CTA, таблица и формы не должны выходить за viewport.

## Проверки перед production deployment

```bash
php artisan migrate:status
php artisan test
vendor/bin/pint --test
npm run build
```

До production deployment отдельно выполняется аудит VDS-конфигурации, PostgreSQL backup/restore,
очередей и Redis. Локальная реализация не требует чтения или изменения VDS.

## Deployment и восстановление

1. Зафиксировать текущий release SHA и снять проверенный PostgreSQL backup (`pg_dump` custom format).
2. Проверить backup через `pg_restore --list`; сохранить его вне каталога текущего release.
3. Остановить новые записи/включить maintenance, развернуть новый код и выполнить
   `php artisan migrate --force`.
4. Очистить кэши, перезапустить queue workers, выполнить smoke checks Event/Game/Tournament и только
   затем снять maintenance.
5. При ошибке до появления новых production-данных допустим rollback кода и миграционного batch.
6. После появления новых записей не выполнять слепой `migrate:rollback`: остановить запись,
   восстановить проверенный backup в отдельную БД, проверить orphan/cross-aggregate queries,
   переключить приложение на восстановленную БД и вернуть предыдущий release SHA.

Миграции текущего среза в основном additive, но TournamentMatch уже может ссылаться на созданные
Game/Event/Booking. Поэтому после начала эксплуатации backup/restore безопаснее обратных миграций.
