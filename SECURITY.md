# Security

## Reporting a problem

Write to the support address published at `/.well-known/security.txt` on the live site (it is the
support e-mail from the admin Settings page). Please include the steps to reproduce and, if a
request failed, the `X-Request-Id` value from the response: it leads us to the exact log lines.
Do not open a public issue for a security problem.

## What the code already does

| Area | Measure | Where |
|---|---|---|
| Sign-in | One-time codes, 6 digits, hashed, 5 tries per code, rate limited per number and per IP | `OtpService`, `AppServiceProvider` |
| Dashboards | Optional two-step login (authenticator app); sign-ins, failures and lockouts in the audit log | panel providers, `AppServiceProvider::recordSignIns()` |
| Passwords | Dashboards: 8+ characters with upper- and lower-case letters, a digit and a symbol | `AppServiceProvider::harden()` |
| Tokens | 90-day API tokens with a recognisable prefix; revoked on sign-out, suspension and account deletion | `AuthController`, `UserResource` |
| Repeated requests | The same action sent twice within 5 seconds runs once; approvals notify once | `PreventDuplicateRequests`, `Support\Once` |
| Rate limits | 120 requests a minute per person; 30 a minute for booking, paying, cancelling, rating, complaining | `AppServiceProvider` |
| Files | Provider documents and payout receipts on the private disk, served by 15-minute signed links; uploads limited to PDF / JPEG / PNG / WebP | `ProviderDocument`, `Settlement` |
| Search | Text typed by people is escaped before it reaches a `LIKE` | `Support\Like` |
| Payments | Webhooks verified by HMAC against the raw body; the placeholder secret is refused outside local | `PaymentService`, `FakePaymentGateway` |
| Browser | No framing by other sites, no MIME sniffing, HTTPS pinned, `noindex` on dashboards and API, signed-in answers never cached | `SecurityHeaders` |
| Cross-site calls | The API answers browsers only from the origins in `CORS_ALLOWED_ORIGINS` (production) | `config/cors.php` |
| Production | Debug forced off, HTTPS forced, secure cookies | `AppServiceProvider::harden()` |
| Data kept | Expired codes and tokens, old technical logs and read notifications are deleted daily | `farah:prune` |
| Phone scanner | A long random link, stored hashed, 12 hours at most, dies on disconnect or sign-out | `Support\PhoneScanner` |

## Before going live

Run `php artisan farah:doctor` on the server. It fails on anything unsafe: debug mode, plain HTTP,
the placeholder webhook secret, the fixed development sign-in code, stand-in SMS or payment
providers, a scheduler that is not running.

Still to do outside the code: connect a real SMS provider and payment gateway, create the
Android signing key, and move cache and sessions to Redis. Server set-up: [docs/DEPLOY.md](docs/DEPLOY.md).
