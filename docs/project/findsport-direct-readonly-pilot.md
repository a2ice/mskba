# Task 306 — первый реальный read-only пилот FindSport / Basketpoint

Дата исследования: 2026-11-12. Окружение: отдельная feature branch от dev.
**Не означает готовность Task 306–309 или разрешение автоматического polling.**

## Подтверждённый формат источника

Обычный HTML GET:

`https://findsport.ru/playground/4805`

возвращает `pagesPlaygroundView({...})` с `playground.id=4805`,
`timeStep=1800`, `minBookingInterval=3600`, `timezone=Europe/Moscow`,
`schedules[0].id=9068`, `priceManagerData.periods` и `priceManagerData.prices`.
В этом пилоте используем только JSON-данные, не выполняем сторонний JavaScript.

В JS `/web/dist/pages.playground.view.bundle.js` обнаружен GET:

`https://findsport.ru/playground/schedule/4805?date=YYYY-MM-DD`

Возвращает JSON на неделю: `{date: {HH:mm: {schedule_id: booking_mask}}}`.
Проверено на 13.11.2026: HTTP 200, ключи 13–19 ноября, schedule 9068.
Число получасовых записей с маской 12 за неделю: 18, 7, 3, 24, 16, 22, 13.
Это не число отдельных бронирований.

Прямо в коде FindSport подтверждена проверка полной зоны:
`12 - booking_mask < 12` — при `booking_mask > 0` слот заблокирован.
Для полного ответа по дню отсутствие маски трактуется **интерфейсом поставщика**
как незанято, но не как подтверждённая бронь. Если ключа даты нет, весь
день становится `unknown`.

Почасовая цена `outer` нормализуется за шаг:
`ceil(outer * step_seconds / 3600)` рублей, затем ×100 копеек.
Пример: 3250 ₽/час => 1625 ₽/30 минут. Учитываются:
период начала/конца, битовая маска дня недели (пн=2, сб=64, вс=1),
`part=12` и пустой список factor options. Перекрытия/неподдержанные скидки
не превращаются в вымышленную цену.

## Доступный функционал пилота

Код: `FindSportReadOnlyAdapter`, Filament Venue → Интеграции →
Источники и залы → действие «Получить данные».

- Прямой read-only GET страницы + недельного JSON, максимум 2 запроса за запуск.
- Только `findsport.ru`, HTTPS, без редиректов, с проверкой публичного IPv4
  и закреплением разрешённого адреса через cURL `CURLOPT_RESOLVE`.
- Только один связанный зал MSKBA и один внешний schedule — иначе явная ошибка.
- Настройка тестовая, API source, reviewed permission_status с основанием;
  отдельное backoffice permission `integrations.execute`.
- Короткий cache и ограничение повторов; без ключей/авторизации.
- Просмотр по дням и 30-минутным слотам в Filament, read-only audit event.
- **Не** изменяет `VenueSchedule`, `VenueCourtSchedule`, BookingPolicy,
  цены, брони и Wallet. **Не** сохраняет ExternalAvailabilitySnapshot
  (это следующий Task 307), не запускает периодический polling (Task 308).

## Конфигурация на dev

Для закрытого теста:

1. Создать выключенную конфигурацию `mode=test` и API-источник
   `https://findsport.ru/playground/4805`, `external_id=4805`.
2. Привязать ровно один соответствующий VenueCourt; отметить
   `availability`, `pricing`, сохранить `enabled=false`.
3. Внести проверенный источник/основание тестового доступа через
   `permission_status=testing_only` и `permission_reference`;
   внутренний статус не заменяет условия доступа третьей стороны.
4. На **dev** явно установить
   `VENUE_INTEGRATIONS_FINDSPORT_PREVIEW_ENABLED=true`
   и обновить Laravel config cache.
5. С учётной записью с правом
   `backoffice.venue.integrations.execute` нажать «Получить данные».
6. Сверить даты, маски занятости, цены в рублях и метку времени с FindSport.
   Повторить после истечения короткого кэша; убедиться, что аудит есть,
   а расписание MSKBA и бронирования не изменились.

`venue_integrations.live_source_execution_enabled` остаётся **false**.
Настройка нового preview не включает обычные интеграции.

## Незакрытые вопросы

- Конечная актуальность и полнота ответа внешнего сервиса, периоды скидок,
  выбор альтернативных весов/факторов, неоднозначные маски.
- Точный контроль ограничений бронирования (минимум 60 минут, cutoff,
  cancellation) и резервирование.
- Immutable snapshots/provenance, Reverb refresh, TTL/circuit breaker,
  conflict policy — Task 307/308.
- Правомерность дальнейшего регулярного обращения/договорные ограничения.
  Нахождение данных в открытом HTML и закрытый dev не заменяют согласование.
