# Changelog

Notable changes to the Farah back end and dashboards. Newest first.

## Unreleased

### Added
- Provider plans (packages), checked by the server. Admin → Finance → "Plans" (name, price, length,
  features; one plan can be the free plan) and "Plan subscriptions" (activate a request once paid and the
  provider is approved, give a plan, stop one); permission `plans`. Every provider is on the free plan
  while no paid plan runs. Features: top of search, featured on the home page, number of activities (the
  free plan sets the allowance, a paid plan only raises it); a paid feature can last fewer days than the
  subscription and stops on its own date. Activating a plan replaces the running one. Providers see
  "My plan" from sign-up on (also a step in the onboarding checklist) and can ask for a paid plan before
  approval. The plan can be chosen before the account exists: the website lists the plans (#plans, each
  with a link to `/provider/register?plan=ID`) and the sign-up form starts with the plan choice; a paid
  choice becomes a pending request; API `GET /provider/plans`, `GET/POST /provider/subscription`, `DELETE /provider/subscription/pending`.
- Farah wallet: a customer balance fed only by refunds, compensation and rewards. It cannot be topped
  up. It pays a pending order when it covers the whole amount (`POST /orders/{id}/pay-wallet`,
  `GET /wallet`); an order is never split between the wallet and a card. Admins add compensation or a
  reward from the customer's page, a complaint, or the new "Customer wallets" page (permission `wallet`).
- Complaints in the customer app: "Complaints and support" in the account, a complaint can be raised
  from an order, and the team's replies from the admin dashboard show as a conversation in the app.
- Provider sign-up asks for a Google Maps link to the place (required) and a website (optional).
- Invoices: every paid order gets an invoice number in sequence (`INV-2026-000001`). The customer app
  lists them under "My tax invoices" (`GET /invoices`, `GET /invoices/{order}`) with the ZATCA
  phase-one QR code and a PDF behind a signed 15-minute link. A provider without a VAT number gets
  a plain invoice with no VAT.
- Two-step login for both dashboards: each person switches on a code by SMS, by e-mail, or both in
  "My account", which also gained the mobile number field. Replaces the authenticator-app option.
- Rating flow: customers rate a visit once it has started; the provider is told about a new rating
  and the customer about the provider's reply. A "rate your visit" request goes out a few minutes
  after the booked time ends (`farah:complete-bookings`, every five minutes).
- Push notifications through Firebase Cloud Messaging (`PUSH_DRIVER=fcm`); `log` remains the default.
- Notification bell in the admin and provider dashboards. Admins are told about providers awaiting
  approval, refund requests and new complaints.
- Phone as the check-in scanner: the provider links a phone from the check-in page by QR.
- Activity type "Majlis games" (baloot, jakaroo, carrom, foosball, dominoes); Saudi demo data.
- `farah:doctor` (deployment readiness check) and `farah:prune` (daily housekeeping).
- `X-Request-Id` on every response and log line; slow queries (500 ms and over) are logged.
- ETag / `304 Not Modified` on the cached public lists (home feed, cities, activity types).
- Static analysis (Larastan level 1, no ignore list), Pint rules, CI workflow, generated
  `@property` documentation on every model.

### Changed
- Provider panel: the booking length of a game now offers 15, 20, 30, 45, 60, 90, 120, 180 and 240 minutes
  (was 30 to 120), with plain names and the customer price of the chosen length under the field.
- Approved refunds are added to the customer's Farah wallet at once instead of going back to the card.
  A payment taken by mistake (slot gone after payment, paid twice) still returns the way it was paid.
- One store per provider; a new activity on a published store is reviewed before it goes live.
- Provider documents and payout receipts moved to private storage behind signed links.
- Sign-in codes are six digits. Card photos are served as small WebP versions.
- A rating is accepted only after the booking has started or the customer was checked in.
- Dashboard tables remember their search, filters and sort; forms warn before unsaved changes are lost.
- Provider dashboard wording: "store" instead of "place"; one-store providers no longer see a store column.

### Security
- Duplicate-request protection on the API; approvals, rejections and suspensions act once.
- Search text is escaped before `LIKE`; uploads no longer accept SVG; media links must be HTTPS.
- Security headers, CORS allow-list, forced HTTPS and secure cookies in production, action rate limits.
- Dashboard sign-ins, failures and lockouts are written to the audit log.
- Dashboard uploads wait on the private disk (not `public/storage`) until saved, so a provider
  document is never reachable from the web on its way in.
- Production deployment guide (`docs/DEPLOY.md`); `.env.example` now lists every key with production
  defaults. Daily scheduled jobs are set in Saudi time (prune 03:30, settlements 05:00, document
  warnings 08:00).
- Unused Vite/Tailwind starter files and two unused logo images removed.

### Removed
- Cancelling an order: the app buttons, `POST /orders/{id}/cancel`, the admin "cancel unpaid order"
  action and the cancellation-window setting. An unpaid order is released when its payment window ends.
- The "individual" provider type: providers are establishments with a commercial registration.
- Wathq verification (code, configuration and database columns).
