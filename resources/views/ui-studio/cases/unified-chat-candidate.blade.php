{{-- Local UIKit candidate, revision 2. Do not promote to common styles before acceptance. --}}
<style>
/* One visible focus boundary: the composer; no nested textarea outline. */
.venue-play-dialog__wall [data-chat-composer] {
    gap:5px;
}
.venue-play-dialog__wall [data-chat-composer] textarea {
    border-radius:5px;
    border:0;
    padding:0;
    outline:none !important;
    box-shadow:none !important;
}
.venue-play-dialog__wall [data-chat-composer] textarea:focus,
.venue-play-dialog__wall [data-chat-composer] textarea:focus-visible {
    border:0;
    outline:none !important;
    box-shadow:none !important;
}
.venue-play-dialog__wall [data-chat-composer]:has(textarea:focus-visible) {
    outline:1px solid var(--accent);
    outline-offset:2px;
}
/* 80% of 44 px vertically; preserve control width and icon scale. */
.venue-play-dialog__wall [data-chat-composer] :is(.venue-play-dialog__send,.chat-actions__toggle,.venue-play-dialog__edit-cancel) {
    height:35px;
    min-height:35px;
    padding-block:0;
}
@media (pointer:coarse) {
    .venue-play-dialog__wall [data-chat-composer] :is(.venue-play-dialog__send,.chat-actions__toggle,.venue-play-dialog__edit-cancel) {
        position:relative;
    }
    .venue-play-dialog__wall [data-chat-composer] :is(.venue-play-dialog__send,.chat-actions__toggle,.venue-play-dialog__edit-cancel)::after {
        content:"";
        position:absolute;
        inset:0 0 -9px;
    }
}
</style>
