# Verification checklist

- [ ] Individual game Telegram `Пойду` creates pending game admission, not confirmed event participant.
- [ ] Repeated `Пойду` is idempotent.
- [ ] Individual game Telegram `Не пойду` revokes pending application.
- [ ] Accepted player `Не пойду` becomes left/excluded while prior statistics stay stored.
- [ ] Preformed-team game publication has no personal participation buttons.
- [ ] Training/game-training callbacks retain ordinary event participation.
- [ ] Cancelled public game opens through Telegram Mini App deep link without 404.
- [ ] Telegram card shows format/recruitment/pool/teams-score context.
- [ ] Existing QR/recruitment regressions pass.
- [ ] Existing event-page regressions pass.
- [ ] Frontend production build passes.
