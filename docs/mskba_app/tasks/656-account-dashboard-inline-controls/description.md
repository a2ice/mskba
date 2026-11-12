# Task 656 — Inline controls for account dashboard widgets

## Original request (12 November 2026)

On `/account` remove the external buttons «Изменить порядок» and
«Выбрать виджеты». Instead, show the same dotted grip used by draggable
modal headers to the left of a widget title, and let users drag by holding
the whole widget header. The grip and dragging are enabled only for
`can_move=true`. Show a close icon for `can_hide=true`, allowing the
current user to remove that widget from their dashboard. In the free grid
cell place a panel with a centered circled plus; clicking it opens a modal
with widgets the current user may add. Commit, pass green CI, merge to dev.

## Design and implementation

- Use the existing light `mskba_app` panels and CSS tokens. Grip glyph and
  non-interactive header dragging reuse the established modal pattern
  (`resources/themes/mskba_app/css/modal.css` and `js/modal-dragging.js`).
  Modal uses `dialog.mskba-modal`, `mskba-modal__header` and scrollable body
  as defined in [the shared UI design system](../../../specification/ui-design-system.md).
- No global edit mode. Native desktop HTML5 dragging by header; pointer
  fallback on touch/pen; keyboard Alt+arrow up/down for focused movable
  headers. The existing reorder algorithm keeps `can_move=false` slots
  anchored; the server validates the same contract independently.
- Hide button is generated only for `can_hide=true`; the plus panel is a
  half-width grid item, displayed after dashboard widgets. The picker lists
  only hidden, currently audience-eligible widgets. If all are present, the
  dialog explains there are no more widgets to add.
- Visibility persists for the canonical user using the existing POST
  endpoint and records. AJAX receives JSON; the existing HTML settings
  page keeps its normal redirect. Reverb refresh is deferred during a
  drag/write, but audience/publication changes are always reconciled.
  Failed writes show a status and refresh / restore state.
- Dynamic data is never trusted as authorization. The picker loads an
  authenticated no-store catalog; server checks `can_hide`, audience,
  visible IDs and locked slots again on every save.

## Checks and acceptance

- Desktop light theme: no toolbar buttons; grip before each movable title,
  entire header draggable, no accidental drag on the close button.
- Touch and keyboard: reorder, fixed cards keep original slot, order persists
  after reload; failed save reverts the layout.
- Close icon hides only hideable widgets, without changing other accounts;
  plus dialog restores the widget and stays within the shared modal style.
- Locked `can_move=false` widget cannot move. Required `can_hide=false`
  widget has no close icon and remains visible on forged POST.
- Empty/full catalogs, realtime refresh, custom HTML/advertising cards and
  narrow-width single-column layout work without replacing editable content.
- Feature regression test and GitHub CI must pass. Merge only to dev; after
  automatic deployment perform visual/user acceptance before closing task.
  Production/main is out of scope.

## Status

Implementation in PR #693 for dev; user visual acceptance pending.
