# 160 — Добавить модель качества ведения площадки

> Originally planned as historical Task 155 before task numbering diverged.

## Контекст и проблема

Подтверждённое владение площадкой даёт пользователю договорные права управления,
но само по себе не подтверждает, что сведения о площадке регулярно поддерживаются
в актуальном состоянии. Для дальнейшей аналитики и публичной оценки достоверности
нужно отдельно хранить принятое обязательство, фактическую административную оценку
качества и внутренний комментарий.

## Бизнес-логика

Каждая запись `VenueOwnership` хранит:

- `maintenance_commitment_accepted` — принято ли обязательство поддерживать данные,
  boolean, по умолчанию `false`;
- `maintenance_score` — административная оценка только из множества
  `0, 10, 20, ..., 100`, по умолчанию `0`;
- `maintenance_comment` — необязательный внутренний текстовый комментарий.

Обязательство и оценка независимы: согласие владельца не доказывает качество, а
высокая оценка без принятого обязательства не считается гарантией актуальности.

## Scope

- безопасная переходная миграция для существующей production-базы;
- casts/fillable и единый инвариант допустимых оценок в `VenueOwnership`;
- backend validation и use case административного обновления;
- элементы управления в существующем admin-интерфейсе владений;
- подключение `VenueOwnership` к существующему audit whitelist;
- regression-тесты схемы, defaults, validation, permissions и audit.

## Out of scope

- самостоятельное редактирование этих полей владельцем площадки;
- автоматический расчёт score;
- публичное отображение внутреннего комментария;
- новая система аудита или отдельный журнал изменений.

## Data model

Новые колонки таблицы `venue_ownerships`:

| Поле | Тип | Default | Ограничение |
|---|---|---:|---|
| `maintenance_commitment_accepted` | boolean | `false` | not null |
| `maintenance_score` | small integer | `0` | `0 <= value <= 100 AND value % 10 = 0` |
| `maintenance_comment` | text | `null` | nullable |

Инвариант score защищается HTTP-validation, use case и поддерживаемым production-СУБД
CHECK constraint. Это не позволяет обойти правило прямым вызовом прикладного use case.

## Permissions

Обновление доступно только подтверждённому пользователю с системной ролью не ниже
`ADMIN`. Проверка выполняется на HTTP-границе и повторяется внутри use case, чтобы
правило сохранялось при будущем вызове не из web-controller.

## Audit

`VenueOwnership` использует существующий `Auditable`, но должен быть включён в
`config/audit.php`. Изменения commitment, score и comment сохраняются в стандартном
`audit_logs` с `auditable_type`, `auditable_id`, `actor_id`, old/new values и общей
metadata. Вторая система аудита не создаётся.

## API и UI

В admin-карточке владения появляется отдельная форма качества. Score выбирается
из фиксированного select с шагом 10; arbitrary numeric input не используется.
Комментарий обозначен как внутренний. Сохранение выполняется отдельным PATCH-маршрутом,
не смешанным с переходом статуса владения.

## Security и конкурентный доступ

Route model binding не заменяет authorization. Use case повторно загружает запись
под `lockForUpdate`, поэтому параллельные административные сохранения сериализуются,
а audit фиксирует фактические old/new values последовательно. Длинных транзакций и
межтабличной перестановки блокировок не добавляется.

## Acceptance criteria

- новые поля существуют и имеют defaults `false`, `0`, `null`;
- разрешены только оценки от 0 до 100 с шагом 10, включая границы 0 и 100;
- admin может изменить все три значения в существующем UI;
- обычный пользователь получает 403;
- каждое реальное изменение попадает в существующий audit с actor и old/new values;
- migration безопасно применяется к существующим строкам.

## Tests

- schema/defaults и DB constraint там, где он поддерживается;
- HTTP-validation диапазона и шага, успешные 0/100;
- admin update и запрет обычному пользователю;
- audit regression `50 -> 70` одновременно с commitment/comment.

## Implementation progress

- [x] восстановлено каноническое описание и выбран свободный номер;
- [x] migration/model/invariant;
- [x] admin use case, route и UI;
- [x] audit whitelist;
- [x] tests и документация архитектуры;
- [x] PR и CI;
- [x] merge и production smoke.

## PR и deployment

- Branch: `feature/160`
- PR: #155
- CI: успешно, 770 tests / 5391 assertions + frontend build
- Merge: `ed3312bb1458492fe28a3f3ef95fcc94c8608cd7`
- Production deploy: успешно, GitHub Actions run `34417896494`; главная и публичная страница площадки отвечают `200`
