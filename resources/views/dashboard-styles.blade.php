@once
    {{-- Packaged with the plugin so the Panel's compiled theme need not be rebuilt. --}}
    <style>
        .amm-dashboard .amm-grid { display: grid; gap: .875rem; grid-template-columns: repeat(auto-fit, minmax(min(100%, 12rem), 1fr)); }
        .amm-dashboard .amm-value { font-size: clamp(1.125rem, 2vw, 1.5rem); font-weight: 650; line-height: 1.3; overflow-wrap: anywhere; }
        .amm-dashboard .amm-label { font-size: .8125rem; color: var(--gray-600, #4b5563); }
        .dark .amm-dashboard .amm-label { color: var(--gray-300, #d1d5db); }
        .amm-dashboard .amm-alert { border-inline-start: .25rem solid var(--warning-500, #f59e0b); border-radius: .5rem; background: rgb(245 158 11 / .08); padding: .75rem 1rem; }
        .amm-dashboard .amm-status-card { min-width: 0; }
        .amm-dashboard .amm-review-table { width: 100%; }
        .amm-dashboard .amm-review-table td { vertical-align: top; overflow-wrap: anywhere; }
        .amm-dashboard .amm-review-table code { overflow-wrap: anywhere; }
        .amm-dashboard progress { display: block; width: 100%; height: .5rem; accent-color: var(--primary-500, #3b82f6); }
    </style>
@endonce
