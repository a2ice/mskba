# tournaments-scene — homepage full-width section

Filament: **Дизайн → Компоненты → Главная — Турниры** (slug `tournaments-scene`).

The scene is **full browser width**. Only the editorial content and real
upcoming-tournament panel are constrained to Bootstrap `.container`.
Homepage switches from the legacy `#tournaments .bordered-plate` to a
`<mskba-component name="tournaments-scene"></mskba-component>` placeholder.

## Artwork

In Filament **Медиа → Файлы сайта**, folder
`home-tournaments-section`, upload these three separate images:

| Name | Suggested format | Rendering |
|---|---|---|
| `background` | landscape WebP, 2560×1200 | full-bleed background (cover) |
| `rim` | PNG or transparent WebP, ~1400×1400 | independent basketball backboard + rim |
| `ball` | PNG or transparent WebP, ~600×600 | independent basketball, separate motion layer |

The public JSON `GET /api/v1/home/tournaments-scene` looks up **exact
lower-cased asset title** or **original filename stem** in that folder. It
returns stable UUID media routes `/media/site/{uuid}`. Images may be uploaded
**after** the component is published; no code or manual URLs need updating.
Use PNG/WebP with transparency for rim and ball, and disable image
optimization at upload if preserving exact alpha is important.

The CSS variables
`--tournaments-rim-x`, `--tournaments-rim-y`,
`--tournaments-rim-scale`, and equivalents for the ball are available for
responsive positioning. There is no scroll director yet: future animations
must not interfere with venues/hero scroll listeners.

## Real data

The feed displays up to three **confirmed** future tournaments, excluding
closed ones. Title, date, format and URL come from the real tournaments table.
No invented teams, ratings or dates. Until content exists it shows an empty
state with a catalogue link.

## Publication safety

A dedicated one-shot installer checks the exact previous homepage HTML and
creates the component before updating the homepage in a database transaction.
Use a full verified PostgreSQL backup before running it, and never overwrite
parallel manual Filament edits. The regular Page Builder history is preserved.
