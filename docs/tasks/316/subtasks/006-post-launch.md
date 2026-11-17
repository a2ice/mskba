# 316/006 — Post-release search monitoring

Статус: **запланирована**.

- В день релиза: public crawl sitemap + top pages; HTTP 200/301, canonical, Title/Description/H1/OG/schema; media, `lang=ru`, `robots.txt`.
- Подключить прежний `YANDEX_METRIKA_ID`, проверить hits/goals, дубли, SPA history transitions и старые метрики.
- Сохранить верификацию домена Яндекс Вебмастера и GSC; отправить sitemap и проверить отчёты индексирования.
- D+1 / D+7 / D+30: 404/5xx, excluded/soft404, impressions, clicks, traffic and referrers; зарегистрировать отклонения, исправить по приоритету.
- Для крупных потерь — определить решение об откате вместе с владельцем проекта, не менять сервер самовольно.

Приёмка: отчёт наблюдения с подтверждением преемственности и закрытыми критическими ошибками.
