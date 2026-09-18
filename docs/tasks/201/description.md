# Task 201 — Поджать композицию A4 acquisition flyer

## Цель

По production-preview скорректировать композицию встроенного A4 flyer без изменения template field schema и campaign overrides.

## Правки

- верхний padding flyer уменьшить до ~25 px (`6.6mm`);
- hero-заголовок уменьшить примерно в 1.5 раза: `19mm → 12.7mm`;
- hero image вынести за верхний и правый край примерно на 25% её размера: `top/right = -28mm` при размере `112mm`;
- CTA-заголовок уменьшить примерно в 1.5 раза: `10mm → 6.7mm`;
- текст шагов уменьшить примерно в 1.5 раза: `4.7mm → 3.15mm`;
- подпись под QR уменьшить примерно в 1.5 раза: `3.5mm → 2.35mm`.

Изменения касаются только CSS/layout встроенного шаблона и совместимы с будущим template editor: content schema и logical media slots остаются без изменений.

## Статус

- [x] layout;
- [ ] CI;
- [ ] merge/deploy.
