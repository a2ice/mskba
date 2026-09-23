# Billing and cross-tab generation state

Follow-up to Task 242 after the first production generation test.

- `avatar_generation` is charged through Finance as `internal_service_payment` with the default `bonus_then_real` policy before GitHub Actions dispatch.
- charge and refund operations are idempotent and reference the player-character generation UUID.
- a dispatch failure, provider failure callback, or generation timeout refunds the original real/bonus split.
- a second generation request is rejected while an older non-expired generation is pending or processing.
- browser tabs synchronize the active generation through same-origin local storage; another already-open tab resumes status polling, and a newly opened tab can resume the same generation while the stored status record exists.
