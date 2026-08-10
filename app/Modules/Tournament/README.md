# Tournament module

Tournament — самостоятельный агрегат соревнования, а не Event. Foundation хранит паспорт,
модерационный статус, период, рекомендуемый Game preset, обложку и creator Actor. Публичная
идентичность строится как `id-alias`; alias намеренно не уникален.

Создание разрешено только confirmed User. Mutation выполняют application handlers внутри
транзакций с блокировкой Tournament. Ownership сравнивается по user identity создавшего Actor,
поскольку один User может иметь разные Actor из разных browser fingerprint.

Ответственные подключаются через общий Contract aggregate с membership scope `tournament`.
`TournamentAccess` требует одновременно accepted membership, active непросроченный Contract и
конкретный `TournamentPermissionEnum`. Выдающий может делегировать только свои права.
Все мутации проверяют ACL в application-слое; UI лишь отражает его решение.

Admission отделён от игровой стороны: `TournamentAdmission` хранит двусторонний flow
application/invitation, а `TournamentEntry` — утверждённую сторону. Для постоянной Team Entry хранит
ссылку на команду, а допустимый состав вычисляется из её текущих accepted player memberships.
`TournamentEntryMember` хранит участников только для 1×1 и команд, собранных самим турниром.
Individual 3×3/5×5 accepted admissions образуют пул для balanced formation.

Приём заявок и приглашений открыт только у подтверждённого предстоящего Tournament. Фактический
старт любой Game закрывает набор и переводит вычисляемую фазу Tournament в ongoing независимо от
плановой даты; когда завершены все матчи, вычисляемая фаза становится completed. Проверка действует
в domain service, а UI только отражает её результат.

`TournamentMatch` ссылается на две Entry и имеет уникальный в Tournament `sequence`. Reorder блокирует
Tournament и Match и переносит sequence в два шага, чтобы не нарушать unique constraint.

Круговая схема строится чистым deterministic generator по circle method. Preview не пишет БД;
apply повторно генерирует пары под блокировкой Tournament и защищён fingerprint активных Entry.
Нечётный состав получает bye, второй круг меняет стороны местами. Regeneration запрещён после связи
хотя бы одного матча с Game.

Назначение матча атомарно создаёт Event, VenueBooking, primary Game, GameSide и GameRosterEntry:
для постоянной Team берётся её актуальный подтверждённый состав, для assembled/individual Entry —
TournamentEntryMember. Tournament Game использует общий Event/Game lifecycle и live UI. Ответственный
с `manage_tournament_games` получает игровые права только для Game своего Tournament. Он
редактирует состав постоянной команды в границах её актуальных accepted player memberships, а
assembled/individual стороны — в границах TournamentEntryMember. GameRosterEntry является изменяемым
до начала и затем исторически стабильным снимком состава конкретной игры.

Standings не хранится отдельной изменяемой таблицей: read-model пересчитывается только из Game с
подтверждённой статистикой. Формула v1: победа 2, ничья 1, поражение 0; tie-break — очки, победы,
разница, забитые, название. Такой подход исключает двойной учёт результата.

Локальные end-to-end fixtures создаются явно командой `make acceptance-seed`; production
`DatabaseSeeder` demo-данные не запускает.
