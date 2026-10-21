{{-- Scoped to the CSS/JS actions in Page Builder and Design Themes.
     CodeMirror owns source scrolling; the modal title and actions remain visible.
     Kept outside Page Builder / user theme CSS, so it works in the Filament panel. --}}
<style>
    .fi-modal-window.mskba-code-modal {
        max-height: 95vh;
        max-height: 95dvh;
        overflow: hidden;
    }

    .fi-modal-window.mskba-code-modal > .fi-modal-header,
    .fi-modal-window.mskba-code-modal > .fi-modal-footer {
        flex-shrink: 0;
    }

    /* Fallback scroll for exceptionally small screens / wrapped action buttons. */
    .fi-modal-window.mskba-code-modal > .fi-modal-content {
        min-height: 0;
        overflow-y: auto;
        overscroll-behavior: contain;
    }

    /* Filament 5 CodeEditor uses CodeMirror 6, which grows with the file by
       default. Give the editor a bounded viewport; keep all code editable. */
    .mskba-code-modal .mskba-code-editor .cm-editor {
        height: clamp(12rem, calc(95dvh - 19rem), 42rem);
        max-width: 100%;
    }

    .mskba-code-modal .mskba-code-editor .cm-scroller {
        overflow: auto;
        overscroll-behavior: contain;
    }
</style>
