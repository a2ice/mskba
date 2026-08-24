# Task 111 implementation report

Telegram event participation now follows the same domain rules as web recruitment.

- Individual standalone games use `GameAdmission` for Telegram `Пойду`.
- `Не пойду` revokes the admission, leaves the parent event and excludes the active roster entry without deleting historical statistics.
- Preformed-team games do not expose personal participation buttons.
- Training and game-training keep ordinary event participation.
- Telegram cards contain richer game context and stay useful after the event starts.
- Public cancelled events remain viewable through the Telegram Mini App deep link instead of returning 404.
