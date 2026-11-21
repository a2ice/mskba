{{-- Local UIKit candidate, revision 6. Do not promote to common styles before acceptance. --}}
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
.venue-play-dialog__wall [data-chat-composer] :is(.venue-play-dialog__send,.chat-actions__toggle) {
    height:35px;
    min-height:35px;
    padding-block:0;
}
@media (pointer:coarse) {
    .venue-play-dialog__wall [data-chat-composer] :is(.venue-play-dialog__send,.chat-actions__toggle) {
        position:relative;
    }
    .venue-play-dialog__wall [data-chat-composer] :is(.venue-play-dialog__send,.chat-actions__toggle)::after {
        content:"";
        position:absolute;
        inset:0 0 -9px;
    }
}
/* Candidate: header shortcut, participation before roster, date grouping. */
[data-studio-chat] > .venue-play-dialog__header {
    grid-template-columns:16px minmax(0,1fr) 44px 44px;
}
[data-studio-chat] .venue-play-dialog__messages-jump {
    width:44px;height:44px;justify-self:end;
    display:grid;place-items:center;
}
.venue-play-dialog__messages li.venue-play-dialog__date {
    display:flex;justify-content:center;
    background:transparent;padding:2px 0;
}
.venue-play-dialog__messages .venue-play-dialog__date time {
    display:inline-block;
    margin:0;padding:3px 12px;border-radius:999px;
    background:var(--surface-raised);color:var(--muted);
    font-size:12px;font-weight:600;line-height:18px;opacity:1;
}
/* Compact discussion typography; retain date separator and timestamp hierarchy. */
[data-studio-chat] .venue-play-dialog__messages li:not(.venue-play-dialog__date) {
    font-size:14px;
    line-height:1.4;
}
[data-studio-chat] .venue-play-dialog__messages li:not(.venue-play-dialog__date) p {
    font-size:inherit;
    line-height:inherit;
}
/* Native participant button, same roster geometry; keyboard-accessible mention. */
[data-studio-chat] button.venue-play-dialog__participant {
    width:100%;
    padding:0;
    border:0;
    background:transparent;
    color:inherit;
    font-family:inherit;
    text-align:start;
    cursor:pointer;
}
[data-studio-chat] button.venue-play-dialog__participant:not(:disabled):hover .venue-play-dialog__participant-name {
    text-decoration:underline;
}
[data-studio-chat] button.venue-play-dialog__participant:focus-visible {
    outline:2px solid var(--accent);
    outline-offset:-2px;
    border-radius:5px;
}
[data-studio-chat] button.venue-play-dialog__participant:disabled {
    cursor:default;
}
/* Candidate participation CTA: inline themed link, not a button. */
[data-studio-chat] .venue-play-dialog__participation-link {
    color:var(--accent);
    text-decoration:none;
}
[data-studio-chat] .venue-play-dialog__participation-link:hover,
[data-studio-chat] .venue-play-dialog__participation-link:focus-visible {
    text-decoration:underline;
}
</style>
