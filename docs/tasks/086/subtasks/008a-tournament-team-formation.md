# 008a - Формирование составов из пула игроков

## Цель

Собирать balanced TournamentEntry из пула подтверждённых individual admissions и давать
организатору безопасно скорректировать preview вручную.

## Статус

Реализовано и локально проверено 2026-08-08. Следующий срез — 009, генератор «каждый с каждым».

## Работы

- единый balanced strategy; отдельные manual/random modes не создаются;
- preview без persistence и многократное balanced-переформирование;
- deterministic seed только для tie-break и равномерного распределения low-coverage players;
- обязательный `assessment_source`: `self_assessment` либо `objective_assessment`;
- balanced cost function по выбранному assessment и physical characteristics: рост, вес, опыт,
  позиции и телосложение;
- normalization каждой numeric feature в общую шкалу до умножения на коэффициент 1–10;
- versioned coefficient profile с отдельными weights для assessment skills и characteristics;
- data coverage для каждого игрока и явное отображение missing features в preview;
- neutral imputation для missing values без приравнивания unknown к нулю;
- равномерное seeded-random распределение low-coverage players между entries, а не добавление
  в последний остаток;
- атомарная фиксация выбранного preview в entries/members;
- drag-and-drop между draft entries с теми же keyboard/touch fallbacks, что и в Team lineup UI;
- пересчёт team balance indicators сразу после ручного перемещения;
- confirmation перед rerun, если preview уже вручную изменён;
- pool fingerprint/version в preview; apply отклоняет stale draft, если за время ручной правки изменился
  состав accepted admissions;
- запрет regeneration после создания матчей.

## Приёмка

- ни один игрок не дублируется и не теряется;
- все entries удовлетворяют размеру стороны или UI явно показывает неполный остаток;
- один seed и один input дают одинаковые tie-break/low-coverage placements;
- balanced preview минимизирует задокументированную cost function;
- выбранный assessment source не подменяется вторым источником неявно;
- missing value не уменьшает score как будто это нулевой skill;
- разница между entries минимизируется не только по total score, но и по position/body profile;
- preview не меняет базу до команды «Применить».
- drag-and-drop не теряет и не дублирует игрока, соблюдает лимиты состава и не пишет draft в базу;
- backend повторно проверяет membership в accepted pool, уникальность игроков, размеры entries и ACL;

## Черновая формула v1

Numeric feature сначала нормализуется в `0..1`. Сырые сантиметры, килограммы и skill points
не складываются. Базовый player score:

```text
weighted_score = sum(normalized_feature * coefficient) / sum(applicable_coefficients)
```

Для `objective_assessment` confidence определяет доверие к накопленной оценке:

```text
effective_feature = confidence * measured_feature + (1 - confidence) * neutral_feature
```

Стартовые, обязательно версионируемые coefficients 1–10:

- stamina 6, passing 7, ball handling 7, basketball IQ 8;
- close/mid/long shooting по 5;
- defense 8, rebounding 7, speed 5;
- experience 6, height 5, weight 2, body type 2;
- position coverage — не player score, а composition penalty с весом 10.

Это начальные гипотезы, а не вечные константы. Preview хранит `formula_version`, а коэффициенты
калибруются по реальным результатам и экспертной оценке.

Balanced optimizer минимизирует совместно:

- разброс team weighted score;
- разброс по offense/defense/rebounding profiles;
- position/body composition penalties;
- разницу в числе low-coverage players.

## Реализация и проверка

- preview детерминирован по seed и не пишет данные до явного применения;
- missing values нейтрально заполняются медианой пула, а UI показывает coverage и список
  отсутствующих характеристик;
- low-coverage игроки распределяются между командами равномерно;
- после preview состав можно корректировать drag-and-drop либо кнопкой переноса для touch/keyboard;
- apply выполняется транзакционно, блокирует Tournament и повторно проверяет ACL, fingerprint,
  полный accepted pool, уникальность игроков и минимальные размеры команд;
- после создания первого TournamentMatch повторное формирование запрещено;
- профильный и смежный регресс: `63 tests / 614 assertions`; production Vite build выполнен.
