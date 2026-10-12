<style>
    /*
     * Farah panel skin. Deliberately quiet: neutral surfaces, hairline borders, one brand
     * colour (primary, see App\Filament\Support\Brand) for actions and the current page.
     * No gradients outside the logo itself.
     */
    :root {
        --farah-canvas: #F7F6F9;
        --farah-surface: #FFFFFF;
        --farah-line: #EAE8EF;
        --farah-ink: #16141C;
        --farah-ink-soft: #5E5A6B;
        --farah-ink-muted: #9A96A6;
        --farah-active-bg: #F6ECF9;
    }
    .dark {
        --farah-canvas: #0E0D12;
        --farah-surface: #16141B;
        --farah-line: #26232D;
        --farah-ink: #F3F1F7;
        --farah-ink-soft: #B9B5C4;
        --farah-ink-muted: #7D7988;
        --farah-active-bg: rgba(208, 139, 227, .12);
    }

    /* Brand lock-up */
    .farah-brand { display: flex; align-items: center; gap: .55rem; line-height: 1; color: var(--farah-ink); }
    .farah-brand-mark { height: 1.9rem; width: auto; }
    .farah-brand-text { display: flex; flex-direction: column; gap: .2rem; }
    .farah-brand-ar { font-size: 1.25rem; font-weight: 800; }
    .farah-brand-en { font-size: .55rem; letter-spacing: .4em; color: var(--farah-ink-muted); font-weight: 700; }
    .fi-simple-main .farah-brand { flex-direction: column; gap: .6rem; }
    .fi-simple-main .farah-brand-mark { height: 3.5rem; }
    .fi-simple-main .farah-brand-text { align-items: center; }
    .fi-simple-header .fi-logo { height: auto !important; margin-bottom: .75rem; }

    /* Canvas */
    .fi-body, .fi-main-ctn { background: var(--farah-canvas); }

    /* Top bar: flat, hairline divider */
    .fi-topbar, .fi-topbar-ctn > nav { background: var(--farah-surface) !important; box-shadow: none !important; border-bottom: 1px solid var(--farah-line); }

    /* Sidebar: light surface, quiet labels, the current page marked with a tint + a short bar */
    .fi-sidebar { background: var(--farah-surface) !important; border-inline-end: 1px solid var(--farah-line); }
    .fi-sidebar-header { background: var(--farah-surface) !important; box-shadow: none !important; border-bottom: 1px solid var(--farah-line) !important; }
    .fi-sidebar-group-label { color: var(--farah-ink-muted) !important; font-size: .72rem !important; font-weight: 700 !important; letter-spacing: .02em; }
    .fi-sidebar-item-label { color: var(--farah-ink-soft) !important; font-weight: 600; }
    .fi-sidebar-item-icon { color: var(--farah-ink-muted) !important; }
    .fi-sidebar-item-btn { position: relative; border-radius: .6rem; transition: background .12s, color .12s; }
    .fi-sidebar-item-btn:hover { background: var(--farah-canvas) !important; }
    .fi-sidebar-item-btn:hover .fi-sidebar-item-label { color: var(--farah-ink) !important; }
    .fi-sidebar-item.fi-active > .fi-sidebar-item-btn { background: var(--farah-active-bg) !important; box-shadow: none !important; }
    .fi-sidebar-item.fi-active > .fi-sidebar-item-btn::before {
        content: ""; position: absolute; inset-inline-start: -.75rem; top: 22%; bottom: 22%; width: 3px;
        border-radius: 0 3px 3px 0; background: var(--primary-600);
    }
    [dir="rtl"] .fi-sidebar-item.fi-active > .fi-sidebar-item-btn::before { border-radius: 3px 0 0 3px; }
    .fi-sidebar-item.fi-active .fi-sidebar-item-label { color: var(--primary-600) !important; font-weight: 700; }
    .fi-sidebar-item.fi-active .fi-sidebar-item-icon { color: var(--primary-600) !important; }
    .dark .fi-sidebar-item.fi-active .fi-sidebar-item-label,
    .dark .fi-sidebar-item.fi-active .fi-sidebar-item-icon { color: var(--primary-300) !important; }
    .dark .fi-sidebar-item.fi-active > .fi-sidebar-item-btn::before { background: var(--primary-400); }

    /* Sidebar still scrolls (mouse wheel / trackpad / touch), but without a visible scrollbar. */
    .fi-sidebar-nav, .fi-sidebar nav { scrollbar-width: none; -ms-overflow-style: none; }
    .fi-sidebar-nav::-webkit-scrollbar, .fi-sidebar nav::-webkit-scrollbar { display: none; width: 0; height: 0; }

    /* Tenant (business) switcher at the top of the provider sidebar */
    .fi-sidebar .fi-tenant-menu-trigger { border: 1px solid var(--farah-line); border-radius: .75rem; background: var(--farah-canvas); }
    .fi-sidebar .fi-tenant-menu-trigger-tenant-name { color: var(--farah-ink) !important; font-weight: 700; }

    /* Content surfaces: hairline cards instead of shadows */
    .fi-section, .fi-ta-ctn, .fi-wi-stats-overview-stat, .fi-fo-tabs, .fi-sc-tabs, .fi-in-tabs {
        box-shadow: none !important; border: 1px solid var(--farah-line) !important; --tw-ring-shadow: 0 0 #0000 !important;
    }
    .fi-header-heading { font-weight: 800; letter-spacing: -.01em; }
    .fi-ta-header-cell-label { font-weight: 700; color: var(--farah-ink-soft); }
    .fi-ta-text-item { font-variant-numeric: tabular-nums; }
    .fi-badge { font-weight: 700; }

    /* Sign-in / register: calm neutral canvas, the card carries the brand */
    .fi-simple-layout { background: var(--farah-canvas) !important; }
    .fi-simple-main { box-shadow: 0 1px 2px rgba(16, 12, 24, .04), 0 12px 32px -12px rgba(16, 12, 24, .12) !important; border: 1px solid var(--farah-line); }

    .farah-json { font-size: .75rem; white-space: pre-wrap; word-break: break-word; direction: ltr; text-align: left; }
    .farah-diff td { vertical-align: top; padding: .25rem .5rem; border-bottom: 1px solid var(--farah-line); }

    /* Big numbers stay on one line ("5,440.00 SAR" used to break in two). */
    .fi-wi-stats-overview-stat-value { font-size: clamp(1.15rem, 1.6vw, 1.6rem) !important; white-space: nowrap; line-height: 1.3 !important; }

    /* The row under the pointer is easy to follow across a wide table. */
    .fi-ta-table tbody tr:hover > td { background: var(--farah-active-bg); }
    .dark .fi-ta-table tbody tr:hover > td { background: rgba(178, 31, 209, .10); }

    /* Keyboard users always see where they are. */
    :focus-visible { outline: 2px solid var(--primary-500, #B21FD1); outline-offset: 2px; border-radius: 6px; }

    /* Thin, quiet scrollbars instead of the heavy default ones. */
    * { scrollbar-width: thin; scrollbar-color: rgba(127, 127, 127, .35) transparent; }
    ::-webkit-scrollbar { width: 8px; height: 8px; }
    ::-webkit-scrollbar-thumb { background: rgba(127, 127, 127, .35); border-radius: 999px; }
    ::-webkit-scrollbar-track { background: transparent; }

    /* Selected text in the brand colour; no animation for people who asked their device for less motion. */
    ::selection { background: rgba(178, 31, 209, .22); }
    @media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation-duration: .01ms !important; transition-duration: .01ms !important; scroll-behavior: auto !important; } }
</style>
