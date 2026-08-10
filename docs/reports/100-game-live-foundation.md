# 100 — Game Live foundation

## Цель

Подготовить отдельное полноэкранное представление игры для будущей realtime-трансляции без преждевременного подключения WebSocket/Reverb.

## Реализовано

- Публичный canonical URL трансляции: `/events/{event}/games/{game}/live`.
- Live-страница читает тот же `Game` aggregate, что и обычная страница игры:
  - `game_sides` — названия команд и счёт;
  - `game_roster_entries` — составы;
  - `game_player_statistics` — текущая статистика игроков.
- Полноэкранный fixed UI поверх стандартного layout:
  - логотип MSKBA;
  - кнопка выхода на обычную страницу игры;
  - крупный счёт;
  - логотипы и названия команд;
  - кнопка и bottom-sheet текущей статистики.
- На основной странице игры добавляется вход в Live:
  - для активной игры статус заменяется на пульсирующий зелёный `LIVE`;
  - для неактивной игры рядом со статусом появляется ссылка `Live`.
- Добавлен fullscreen overlay игрового события. Новое событие заменяет предыдущее и автоматически скрывается через 5 секунд.

## Клиентский контракт под Reverb

Live UI не знает о Laravel Echo/Reverb напрямую. Он принимает изменения через browser events:

```js
window.dispatchEvent(new CustomEvent('mskba:game-live-score', {
    detail: { A: 12, B: 10 },
}));

window.dispatchEvent(new CustomEvent('mskba:game-live-event', {
    detail: {
        teamName: 'БК Круассан',
        teamLogo: '/storage/...',
        label: 'ТРЁХОЧКОВЫЙ',
        playerName: 'Илья',
        duration: 5000,
    },
}));
```

Для ручной проверки из DevTools также доступен:

```js
window.MSKBAGameLive.updateScore({ A: 12, B: 10 });
window.MSKBAGameLive.showEvent({
    teamName: 'БК Круассан',
    label: 'ПОДБОР',
    playerName: 'Илья',
});
```

При подключении Reverb будущий adapter должен только подписаться на канал игры и транслировать server payload в эти интерфейсы. Разметка и логика показа события от транспорта не зависят.

## Следующий этап

- Реализован append-only журнал `game_actions` и восстановление последней активной стороны после reload.
- Laravel Reverb + Echo.
- Канал конкретной игры.
- Broadcast events для счёта и игровых действий.
- Синхронизация live-статистики без reload.
- Использование общей realtime-инфраструктуры для toast/notification событий проекта.

## Проверка

Добавлены feature-тесты:

- live route отображает игру в рамках правильного Event;
- live route возвращает 404 при попытке открыть Game через чужой Event.

Тесты и frontend build в рамках работы агента не запускались.
