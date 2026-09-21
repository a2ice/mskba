# 006 — Проверки, rollout и полевой smoke

## Цель

Проверить сценарий не только тестами, но и как реальную работу агента с планшета.

## Проверки

- agent/applicant/reviewer audit;
- permission allow/deny;
- self-approve denied;
- IDOR venue/claim/document;
- upload camera/photo и существующие квоты private documents;
- existing venue и новая venue;
- existing user и новая регистрация;
- agent review и remote admin review;
- race двух заявок / уже существующий active owner;
- повторный submit/approve;
- после approval представитель реально открывает management cabinet;
- после завершения у представителя нет обязательного pending action.

## Rollout

- включать через feature setting;
- сначала выполнить внутренний smoke на тестовой площадке;
- затем провести минимум один реальный полевой pilot с планшета;
- после пилота зафиксировать UX-проблемы и обновить эту документацию до массового
  обхода площадок.
