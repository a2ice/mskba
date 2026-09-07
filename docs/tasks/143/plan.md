# 143 — План

1. [x] Зафиксировать conditional nested-flow и radius contract.
2. [x] Обновить task registry: закрыть 142, добавить 143.
3. [x] Расширить `home/location-options` каноническими станциями метро и координатами.
4. [x] Переработать frontend location mini-wizard: динамические шаги, footer takeover, active border, Москва/не-Москва ветки.
5. [x] Заменить metro dropdown на predictive input.
6. [x] Добавить reusable radius step для метро и текущей геолокации.
7. [x] Передавать anchor/radius в homepage discovery и venue predictive/map lookup.
8. [x] Добавить backend radius filtering до limit.
9. [x] Добавить regression tests; дополнительно стабилизировать legacy half-court tests, которые падали после 23:00 из-за перехода часового интервала на следующие сутки.
10. [ ] Дождаться зелёного CI в PR #138 и, по заранее данному пользователем разрешению, merge в `main` для production smoke-check.
