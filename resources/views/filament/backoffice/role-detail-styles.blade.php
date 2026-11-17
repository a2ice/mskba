{{-- Scoped styles: Filament does not generate arbitrary Tailwind utilities from
     dynamically registered resource views. Keep role editor spacing self-contained. --}}
<style>
    .mskba-role-editor {
        display: grid;
        gap: 22px;
        min-width: 0;
    }
    .mskba-role-editor .fi-section {
        min-width: 0;
    }
    .mskba-role-summary {
        display: flex;
        align-items: center;
        justify-content: space-between;
        flex-wrap: wrap;
        gap: 10px 18px;
        min-width: 0;
    }
    .mskba-role-summary-text {
        color: #64748b;
        font-size: .875rem;
        line-height: 1.5;
    }
    .mskba-role-summary-text a {
        color: #ea580c;
        font-weight: 600;
        text-decoration: none;
    }
    .mskba-role-summary-text a:hover {
        text-decoration: underline;
    }
    .mskba-role-summary-text > span {
        padding-inline: 8px;
    }
    .mskba-role-caption {
        color: #64748b;
        font-size: .8125rem;
        line-height: 1.5;
    }
    .mskba-role-form {
        display: grid;
        gap: 10px;
    }
    .mskba-role-label,
    .mskba-role-field > label {
        display: block;
        margin-bottom: 8px;
        color: #111827;
        font-size: .875rem;
        font-weight: 600;
        line-height: 1.4;
    }
    .mskba-role-form > .mskba-role-label {
        margin-bottom: 0;
    }
    .mskba-role-tabs {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 7px;
        min-width: 0;
        padding: 9px;
        border: 1px solid #e5e7eb;
        border-radius: 12px;
        background: #fff;
    }
    .mskba-role-tab {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 37px;
        padding: 9px 14px;
        border: 1px solid transparent;
        border-radius: 9px;
        background: transparent;
        color: #64748b;
        font-size: .875rem;
        font-weight: 600;
        line-height: 1.3;
        text-align: center;
        text-decoration: none;
        transition: background .16s ease, color .16s ease, border-color .16s ease;
    }
    .mskba-role-tab:hover {
        background: #f3f4f6;
        color: #c2410c;
    }
    .mskba-role-tab.is-active {
        border-color: #e5e7eb;
        background: #f3f4f6;
        color: #ea580c;
    }
    .mskba-role-tab:focus-visible {
        outline: 2px solid #f97316;
        outline-offset: 2px;
    }
    .mskba-role-fields {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        gap: 20px 22px;
        min-width: 0;
    }
    .mskba-role-field {
        min-width: 0;
    }
    .mskba-role-field--wide {
        grid-column: 1 / -1;
    }
    .mskba-role-actions {
        display: flex;
        align-items: center;
        justify-content: flex-end;
        flex-wrap: wrap;
        gap: 10px;
        margin-top: 20px;
    }
    .mskba-role-form .mskba-role-actions {
        margin-top: 10px;
    }
    .mskba-role-checklist {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: 10px 14px;
    }
    .mskba-role-check {
        display: inline-flex;
        align-items: center;
        gap: 8px;
        min-height: 38px;
        padding: 8px 12px;
        border: 1px solid #e5e7eb;
        border-radius: 9px;
        font-size: .875rem;
        cursor: pointer;
    }
    .mskba-role-check input {
        accent-color: #f97316;
    }
    .mskba-role-objective-grid {
        display: grid;
        grid-template-columns: repeat(4, minmax(0, 1fr));
        gap: 14px;
    }
    .mskba-role-objective-grid > div {
        min-width: 0;
        padding: 14px;
        border: 1px solid #e5e7eb;
        border-radius: 9px;
    }
    .mskba-role-objective-grid > div > div + div {
        margin-top: 8px;
    }
    .mskba-role-search-results {
        display: grid;
        gap: 10px;
        margin-top: 14px;
    }
    .mskba-role-search-result {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        justify-content: space-between;
        gap: 12px;
        padding: 12px 14px;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
    }
    .mskba-role-table-scroll {
        max-width: 100%;
        overflow-x: auto;
    }
    .mskba-role-table-scroll table {
        width: 100%;
        border-collapse: collapse;
        text-align: left;
        font-size: .875rem;
    }
    .mskba-role-table-scroll th,
    .mskba-role-table-scroll td {
        padding: 12px;
        border-bottom: 1px solid #e5e7eb;
        vertical-align: middle;
    }
    .mskba-role-table-scroll th {
        color: #6b7280;
        font-weight: 600;
    }
    .dark .mskba-role-summary-text,
    .dark .mskba-role-caption {
        color: #94a3b8;
    }
    .dark .mskba-role-label,
    .dark .mskba-role-field > label {
        color: #f3f4f6;
    }
    .dark .mskba-role-tabs {
        border-color: #374151;
        background: #111827;
    }
    .dark .mskba-role-tab {
        color: #cbd5e1;
    }
    .dark .mskba-role-tab:hover,
    .dark .mskba-role-tab.is-active {
        border-color: #374151;
        background: #1f2937;
        color: #fb923c;
    }
    .dark .mskba-role-check,
    .dark .mskba-role-objective-grid > div,
    .dark .mskba-role-search-result,
    .dark .mskba-role-table-scroll th,
    .dark .mskba-role-table-scroll td {
        border-color: #374151;
    }
    @media (max-width: 767px) {
        .mskba-role-fields { grid-template-columns: minmax(0, 1fr); }
        .mskba-role-objective-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .mskba-role-tabs { gap: 6px; }
        .mskba-role-tab { flex: 1 1 auto; padding-inline: 10px; }
        .mskba-role-actions { justify-content: flex-start; }
    }
</style>
