<!doctype html>
<html lang="ar" dir="rtl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex">
    <title>@yield('title') | فرة</title>
    <style>
        :root { --brand: #7c3aed; --brand-dark: #5b21b6; --ok: #059669; --bad: #dc2626; --ink: #1f2937; --muted: #6b7280; --line: #e5e7eb; --bg: #f5f3ff; }
        * { box-sizing: border-box; }
        body { margin: 0; font-family: "Tajawal", "Segoe UI", Tahoma, system-ui, sans-serif; background: var(--bg); color: var(--ink); min-height: 100vh; display: flex; flex-direction: column; align-items: center; padding: 24px 16px; }
        .brand { font-size: 28px; font-weight: 800; color: var(--brand); margin: 8px 0 20px; letter-spacing: .5px; }
        .card { width: 100%; max-width: 420px; background: #fff; border-radius: 20px; box-shadow: 0 10px 30px rgba(91, 33, 182, .08); padding: 24px; }
        .sandbox { display: inline-block; background: #fef3c7; color: #92400e; border-radius: 999px; padding: 4px 12px; font-size: 12px; font-weight: 700; margin-bottom: 12px; }
        h1 { font-size: 20px; margin: 0 0 16px; }
        .amount { font-size: 34px; font-weight: 800; margin: 8px 0 4px; }
        .amount small { font-size: 16px; color: var(--muted); font-weight: 600; }
        .muted { color: var(--muted); font-size: 14px; }
        ul.lines { list-style: none; padding: 0; margin: 16px 0; border-top: 1px solid var(--line); }
        ul.lines li { display: flex; justify-content: space-between; gap: 12px; padding: 10px 0; border-bottom: 1px solid var(--line); font-size: 14px; }
        .btn { display: block; width: 100%; border: 0; border-radius: 14px; padding: 15px; font-size: 17px; font-weight: 700; cursor: pointer; font-family: inherit; text-align: center; text-decoration: none; margin-top: 10px; }
        .btn-pay { background: var(--brand); color: #fff; }
        .btn-pay:active { background: var(--brand-dark); }
        .btn-fail { background: #fff; color: var(--bad); border: 1.5px solid #fecaca; }
        .btn-app { background: var(--ink); color: #fff; }
        .icon { width: 72px; height: 72px; border-radius: 50%; display: flex; align-items: center; justify-content: center; font-size: 38px; margin: 4px auto 16px; color: #fff; }
        .icon.ok { background: var(--ok); } .icon.bad { background: var(--bad); }
        .center { text-align: center; }
        code { direction: ltr; unicode-bidi: embed; font-size: 12px; color: var(--muted); word-break: break-all; }
        footer { margin-top: 20px; font-size: 12px; color: var(--muted); }
    </style>
</head>
<body>
    <div class="brand">فرة</div>
    <main class="card">
        @yield('content')
    </main>
    <footer>بوابة دفع تجريبية لبيئة التطوير فقط &middot; Sandbox gateway</footer>
</body>
</html>
