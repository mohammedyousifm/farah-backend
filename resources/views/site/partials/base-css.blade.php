{{-- Shared styles for the public website and error pages (inline, no build step). --}}
:root {
    --bg: #0A0A0D; --bg-2: #131317; --card: rgba(255,255,255,.04); --line: rgba(255,255,255,.1);
    --text: #F5F5F7; --muted: #B4B4BC;
    --accent: #D391E8; --orange: var(--accent); --magenta: var(--accent); --purple: var(--accent); --blue: var(--accent);
    --grad: var(--accent);
    --radius: 1.25rem;
    color-scheme: dark;
}
* { box-sizing: border-box; }
html { scroll-behavior: smooth; scroll-padding-top: 5rem; -webkit-text-size-adjust: 100%; }
body { margin: 0; min-width: 320px; overflow-x: hidden; background: var(--bg); color: var(--text); font-family: 'IBM Plex Sans Arabic', system-ui, -apple-system, 'Segoe UI', Tahoma, sans-serif; line-height: 1.7; -webkit-font-smoothing: antialiased; }
a { color: inherit; text-decoration: none; }
img, svg { max-width: 100%; }
img { display: block; height: auto; }
h1, h2, h3 { overflow-wrap: anywhere; }
.wrap { width: min(1180px, 100% - 2.5rem); margin-inline: auto; }
.ico { width: 1.5rem; height: 1.5rem; flex-shrink: 0; }
.ltr { direction: ltr; unicode-bidi: isolate; }
.grad-text { background: var(--grad); -webkit-background-clip: text; background-clip: text; color: transparent; }
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }

/* Focus + skip link */
:focus-visible { outline: 2px solid var(--orange); outline-offset: 3px; border-radius: .4rem; }
.skip { position: absolute; inset-inline-start: 1rem; top: -4rem; z-index: 50; padding: .6rem 1rem; border-radius: .6rem; background: var(--text); color: var(--bg); font-weight: 600; }
.skip:focus { top: 1rem; }
main:focus { outline: none; }

/* Buttons */
.btn { display: inline-flex; align-items: center; justify-content: center; gap: .5rem; padding: .8rem 1.4rem; border-radius: 999px; font: inherit; font-weight: 600; font-size: .98rem; line-height: 1.3; border: 0; cursor: pointer; transition: transform .15s, box-shadow .15s, background .15s; text-align: center; }
.btn:hover { transform: translateY(-1px); }
.btn-primary { background: var(--accent); color: #17101b; }
.btn-ghost { border: 1px solid var(--line); background: rgba(255,255,255,.04); color: var(--text); }
.btn-ghost:hover { background: rgba(255,255,255,.09); }
.btn-sm { padding: .5rem 1rem; font-size: .9rem; }

/* Header */
header.top { position: sticky; top: 0; z-index: 20; backdrop-filter: blur(14px); -webkit-backdrop-filter: blur(14px); background: rgba(11,11,16,.82); border-bottom: 1px solid var(--line); }
.top-inner { display: flex; align-items: center; justify-content: space-between; gap: 1rem; height: 4.25rem; }
.brand { display: flex; align-items: center; gap: .6rem; flex-shrink: 0; }
.brand img { height: 2.25rem; width: auto; }
.brand-text { display: flex; flex-direction: column; line-height: 1; }
.brand-ar { font-weight: 700; font-size: 1.3rem; }
.brand-en { font-size: .58rem; letter-spacing: .45em; color: var(--muted); font-weight: 600; margin-top: .25rem; }
nav.links { display: flex; gap: 1.4rem; font-size: .93rem; color: var(--muted); }
nav.links a { padding: .25rem 0; }
nav.links a:hover, nav.links a[aria-current="page"] { color: var(--text); }
.top-actions { display: flex; align-items: center; gap: .5rem; }
.lang { font-size: .88rem; color: var(--muted); padding: .45rem .7rem; border-radius: .6rem; white-space: nowrap; }
.lang:hover { color: var(--text); background: rgba(255,255,255,.07); }
.menu-btn { display: none; width: 2.6rem; height: 2.6rem; border-radius: .8rem; border: 1px solid var(--line); background: rgba(255,255,255,.04); color: var(--text); cursor: pointer; align-items: center; justify-content: center; padding: 0; }
.menu-btn svg { width: 1.35rem; height: 1.35rem; }
.menu-btn .i-close, .menu-btn[aria-expanded="true"] .i-open { display: none; }
.menu-btn[aria-expanded="true"] .i-close { display: block; }
.mnav { border-top: 1px solid var(--line); background: rgba(11,11,16,.97); max-height: calc(100vh - 4.25rem); overflow-y: auto; }
.mnav[hidden] { display: none; }
.mnav ul { list-style: none; margin: 0; padding: .75rem 0 .5rem; display: grid; }
.mnav li a { display: block; padding: .8rem .25rem; border-bottom: 1px solid var(--line); font-weight: 600; }
.mnav li a:hover { color: var(--orange); }
.mnav .mnav-actions { display: grid; gap: .6rem; padding: .75rem 0 1.25rem; }

/* Page heads (inner pages + errors) */
.page-hero { position: relative; overflow: hidden; padding: 4rem 0 3rem; text-align: center; }
.page-hero > * { position: relative; }
.page-hero h1 { font-size: clamp(1.9rem, 4.5vw, 3rem); line-height: 1.25; margin: .8rem 0 .6rem; }
.page-hero p { color: var(--muted); margin: 0 auto; max-width: 40rem; font-size: 1.05rem; }
.badge { display: inline-flex; align-items: center; gap: .5rem; padding: .35rem .9rem; border-radius: 999px; border: 1px solid var(--line); background: rgba(255,255,255,.04); font-size: .85rem; color: var(--muted); }
.badge-dot { width: .5rem; height: .5rem; border-radius: 999px; background: var(--grad); }

/* Footer */
footer.site { border-top: 1px solid var(--line); padding: 3rem 0 2rem; margin-top: 5rem; color: var(--muted); font-size: .92rem; }
.foot-grid { display: grid; grid-template-columns: 1.5fr 1fr 1fr 1fr; gap: 2rem; }
footer.site h2 { color: var(--text); margin: 0 0 .8rem; font-size: .95rem; }
footer.site ul { list-style: none; padding: 0; margin: 0; display: grid; gap: .45rem; }
footer.site a:hover { color: var(--text); }
footer.site p { margin: .9rem 0 0; max-width: 22rem; }
.copy { margin-top: 2.5rem; padding-top: 1.5rem; border-top: 1px solid var(--line); display: flex; justify-content: space-between; flex-wrap: wrap; gap: 1rem; font-size: .82rem; }

@media (max-width: 1024px) {
    nav.links { display: none; }
    .menu-btn { display: inline-flex; }
    .foot-grid { grid-template-columns: 1fr 1fr; }
}
@media (max-width: 600px) {
    .top-actions .btn { display: none; }
    .brand-en { display: none; }
    .foot-grid { grid-template-columns: 1fr; }
    .wrap { width: calc(100% - 2rem); }
}
@media (prefers-reduced-motion: reduce) {
    html { scroll-behavior: auto; }
    *, *::before, *::after { transition: none !important; animation: none !important; }
    .btn:hover { transform: none; }
}
