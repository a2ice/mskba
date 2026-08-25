# 118 — Перевести Player Character на 3D-прототип

## Контекст

После SVG-прототипа стало понятно, что целевой Player Character должен иметь существенно более качественную анатомию, объём, свет, будущую анимацию и возможность примерки формы. Продолжать наращивать детализацию процедурного SVG нецелесообразно.

## Цель

Сохранить существующую метрическую сцену `200 × 250 см` и UI профиля игрока, но заменить основной renderer на lazy-loaded Three.js/GLB прототип. SVG остаётся локальным fallback, пока 3D-система не будет окончательно утверждена.

## Требования

- пол персонажа не редактируется в конфигураторе и берётся только из `Profile.gender`;
- пользователь не может подменить пол через POST payload;
- допустимые причёски и facial hair продолжают зависеть от пола профиля;
- высота 3D-модели и оранжевая линия роста используют одну метрическую систему координат;
- `0 см` — линия пола, `250 см` — верх метрической области;
- стопы модели стоят на `0`, макушка должна попадать в выбранный рост;
- сверху и снизу сохраняется внешний safe-area существующего stage;
- вес и `body_type` совместно влияют на ширину/массу 3D-фигуры;
- 3D-модель можно ограниченно поворачивать drag-жестом мышью/пальцем;
- использовать idle clip, если он есть в GLB;
- renderer останавливает тяжёлую работу вне viewport и при скрытой вкладке;
- при ошибке WebGL/CDN/model loading автоматически остаётся SVG fallback;
- существующий appearance state и persistence сохраняются.

## 3D asset для POC

Для технического прототипа используются готовые CC0 MakeHuman/MPFB2 GLB-модели из репозитория `kunalkushwaha/vsim`, pinned на commit:

`3f97faf85e46d2f9a122b0a8b8d3ccc0af598f91`

- male prototype: `packages/assets/library/man.glb`;
- female/neutral prototype: `packages/assets/library/human.glb`.

В upstream manifest модели описаны как MakeHuman/MPFB2 assets с CC0 skin data и rig/idle animation. Runtime URL pinned через jsDelivr к конкретному commit.

Это **временный технический asset**, а не утверждённый финальный арт MSKBA. После проверки 3D pipeline модель должна быть заменена на собственные male/female MSKBA meshes, не меняя frontend state contract.

## Three.js runtime

В этой итерации Three.js загружается лениво как pinned ESM runtime (`0.184.0`) только при наличии Player Character stage. Это позволяет проверить 3D на production без добавления тяжёлого engine bundle на каждую страницу сайта.

После утверждения 3D-подхода runtime и GLB assets следует vendor/bundle внутри MSKBA, чтобы production не зависел от внешнего CDN.

## Архитектура

- `player-character-stage.js` — UI/state orchestration;
- `player-character-svg-renderer.js` — локальный fallback;
- `player-character-three-renderer.js` — Three.js/GLB renderer;
- `player_profiles.extra.character` — appearance persistence;
- `Profile.gender` — единственный источник пола.

3D renderer не должен менять backend contract и не должен быть источником состояния.

## Важное ограничение POC

Причёски, facial hair и настоящая баскетбольная форма уже хранятся в state, но текущие временные MakeHuman GLB не имеют MSKBA-specific сменных mesh-слоёв. На этой итерации проверяются прежде всего:

1. качество и пригодность 3D-base;
2. метрическая привязка;
3. камера и свет;
4. height/weight/body type;
5. skin/material tint where supported;
6. drag rotation;
7. idle animation;
8. desktop/mobile performance.

После утверждения базы отдельным этапом подключаются собственные hair/facial-hair/uniform meshes.

## Acceptance criteria

- на `/account/participation/player` нет переключателя пола персонажа;
- в UI указано, что пол берётся из профиля;
- `character[gender]` отсутствует в форме и отклоняется сервером при попытке передачи;
- Three.js renderer загружается lazy;
- GLB загружается по полу профиля;
- при росте 150/185/220 см макушка 3D-модели совпадает с соответствующей линией роста;
- изменение веса и телосложения меняет массу фигуры без изменения её высоты;
- drag позволяет немного повернуть модель;
- есть graceful SVG fallback;
- Blade cache, JS syntax, player profile tests и frontend build проходят.
