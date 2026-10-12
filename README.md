# فرة | Farah

منصة لحجز أماكن الترفيه والألعاب بالساعة (بلياردو، بولينج، قيمنق، كارتنج، ملاعب...) للعملاء وأصحاب الأماكن.

Model: **Provider** (business) → **Venue** (place) → **Activity** (billiards, bowling..., priced per hour) → **Resource** (table 3, lane 1; each has its own availability) → time slots → **Booking** → **Order** (payment container) → **Payment**.

**Scope decision:** only **customers** use the mobile app (Flutter, in the `farah-app` repository, talks to `/api/v1`).
The **super admin** (`/admin`) and **providers** (`/provider`) each use a **web dashboard**.
The public landing page at `/` markets the platform to venue owners (sign in / list your venue) and announces the customer app.
The provider REST endpoints (`/api/v1/provider/*`) are kept, but the web dashboard is the provider's primary tool.

| Layer | Tech |
|---|---|
| Backend / API | Laravel 13, PHP 8.4, Sanctum tokens |
| Database | MySQL 8 / MariaDB (`farah`, tests use `farah_test`) |
| Admin dashboard | Filament 5 (Livewire 4), Arabic RTL + English, `/admin` |
| Provider dashboard | Filament 5 panel with tenancy (one tenant = one provider), `/provider` |
| Landing page | Blade + inline CSS, `/` (`?lang=en` / `?lang=ar`) |
| RBAC | spatie/laravel-permission |
| Exports | maatwebsite/excel (Excel/CSV) + Arabic-capable PDF |

## Quality

```bash
composer check       # everything below, in order
composer lint        # code style (Pint, rules in pint.json); `composer format` fixes it
composer analyse     # static analysis (Larastan level 1, no ignore list; see phpstan.neon)
composer test        # the test suite, on its own database (farah_testing)
```

The same three run on every push (`.github/workflows/ci.yml`). Model fields are documented by
generated `@property` blocks; refresh them after a migration with
`php artisan ide-helper:models --write --reset`.

## Operations

| Command | When | What it does |
|---|---|---|
| `php artisan farah:doctor` | after every deployment | Fails on anything unsafe or missing: debug mode, plain HTTP, placeholder secrets, stand-in SMS / payment, scheduler not running |
| `php artisan schedule:run` | every minute (cron) | Expires unpaid orders, completes bookings, asks for ratings, sends campaigns, prunes old data, writes the heartbeat |
| `php artisan farah:prune` | daily (scheduled) | Deletes expired sign-in codes and tokens, old technical logs, read notifications |

Every response carries `X-Request-Id`; the same id is on that request's log lines. Queries of
500 ms and over are logged as "Slow query". Security measures and the go-live checklist are in
[SECURITY.md](SECURITY.md); changes are in [CHANGELOG.md](CHANGELOG.md).

## Notifications

`SystemNotification::send($user, $key, $params, $data)` stores a row (shown in the app's inbox and
in the dashboards' bell) and pushes it to the person's devices. Texts live in
`lang/{ar,en}/notifications.php`. `PUSH_DRIVER=log` writes pushes to the log; `PUSH_DRIVER=fcm`
with `FIREBASE_CREDENTIALS=<service-account.json>` sends them through Firebase.

## Run locally

```bash
composer install && npm install
cp .env.example .env && php artisan key:generate
php artisan migrate:fresh --seed     # reference data + demo data (APP_ENV=local)
php artisan storage:link
php artisan serve --port=8020        # landing /, admin /admin, providers /provider
php artisan schedule:work            # expiries, completions, settlements, campaigns
```

Admin logins (password `password`): `admin@farah.sa` (super admin), `ops@`, `finance@`, `content@`, `support@farah.sa`.

Provider dashboard logins at `/provider/login` (password `password` after `migrate:fresh --seed`):
`provider0@example.com` (Cue Entertainment: billiards lounge + table tennis & darts club, Riyadh), `provider1@` (Strike Bowling, Jeddah),
`provider2@` (Pixel Gaming: gaming lounge in Riyadh + escape rooms in Khobar), `provider3@` (Apex Sports: karting in Dammam + padel / football courts in Jeddah),
`provider4@example.com` (Hadaf Shooting Club, **pending**: only onboarding pages). New providers sign up at `/provider/register`.
Password reset mails go to the log (`MAIL_MAILER=log`, see `storage/logs/laravel.log`).

Mobile login in dev (customers): request OTP for any phone, then use code **1234** (`OTP_DEV_CODE`, disabled in production).
Demo customers: `+966501000000` … `+966501000011`.

### Provider dashboard (`/provider`)

- Filament panel `provider` (`app/Providers/Filament/ProviderPanelProvider.php`, code in `app/Filament/Provider/**`, labels in `lang/{ar,en}/provider.php`).
- **Tenancy:** `App\Models\Provider` is the tenant (`/provider/{provider-slug}/...`); a user can belong to several providers (tenant switcher). All resources are scoped to the current provider.
- **Access:** `User::canAccessPanel` is panel-aware: `admin` → active admins; `provider` → active non-admin users with an active `provider_members` row. Admins cannot log in to `/provider`.
- **Permissions:** every page checks `ProviderMember::allows()` with the same names as the provider API (`venues.manage`, `orders.view`, `orders.manage`, `orders.offline`, `bookings.checkin`, `finance.view`, `team.manage`, `reviews.reply`, `profile.manage`). Owner and manager have everything.
- **Onboarding:** pending / rejected providers see a status banner and only Dashboard (checklist), Profile, Documents, Sectors / sector requests and Team. Suspended = read-only + banner.
- **Pages:** dashboard (stats, upcoming bookings, latest orders), profile, documents (upload / re-upload), sectors (activity types) + sector requests, venues (details, working hours, cover, gallery, activities and their tables / lanes / rooms, submit for review), schedule (slot grid per resource), bookings, orders (filters, detail, counter booking), check-in (type / scan a booking code), team (invite with temporary password or reset link, roles / permissions, deactivate), finance (balances + ledger statement with sum footer, settlements), reviews (reply).
- State changes go through the services (`VenueService`, `OrderService::createOffline`, `CheckInService`, `LedgerService::balances`, ...). Refunds stay an admin decision (read-only for providers).

## Domain map

```
app/
  Enums/            statuses with Arabic/English labels + badge colours
  Models/           38 Eloquent models (Concerns/Auditable writes audit_logs, Concerns/Localized → tr('name'))
  Services/
    OtpService            phone/email OTP (rate limited, hashed, attempts)
    ProviderService       onboarding, Wathq check, approve/reject/suspend, documents + expiry, sector requests
    VenueService          venue + weekly hours, submit → review → publish/reject/suspend
    AvailabilityService   slots per resource for a date (hours may run past midnight), provider blocks
    OrderService          direct booking: hold slots → pending order → confirm; counter bookings, expiry, completion
    CheckInService        booking code scanned on arrival
    PaymentService        hosted checkout, idempotent webhooks (unique gateway+event_id)
    CommissionService     versioned rules: provider > category > sector > global, effective dates
    LedgerService         append-only ledger, held vs available balances
    RefundService         policy quotes, full/partial (per booking) refunds, commission reversal, slot release
    SettlementService     cycle → statement → approve → paid (payout entry)
    RecommendationService rule-based, weighted, consent-aware
    HomeFeedService       schedulable home sections (banners image/video/GIF, rows)
    Integrations/         contracts + sandbox drivers: payment, SMS, Wathq, accounting, push
  Filament/         admin dashboard (Filament/Provider/: provider dashboard)
  Http/             API v1 (see docs/API.md, docs/openapi.yaml)
```

### Booking rules

- A booking reserves **one resource** for a run of consecutive slots; every held slot is a row in `booking_slots` with a unique `(resource_id, starts_at)` key, so double booking is impossible (`slot_taken`, HTTP 409).
- Working hours and slots are wall-clock times in `config('farah.timezone')` (Asia/Riyadh); the database stores UTC. `closes_at <= opens_at` means open past midnight.
- No cart: picking slots creates a `pending_payment` order that holds them for `order_payment_timeout_minutes`. A payment arriving after the hold was released takes the same slots again if they are still free, otherwise it is refunded.
- Each booking has a `code` (QR in the app) that the venue scans on arrival.

### Money flow

Prices are VAT-inclusive (15%, configurable). On payment the order snapshots its commission rule, then the ledger records:

| Entry | Provider account | Platform account |
|---|---|---|
| Online sale | `sale +total`, `commission −fee` | `commission +fee` |
| Offline sale (cash to provider) | `commission −fee` (deducted from next payout) | `commission +fee` |
| Refund | `refund −amount`, `commission_reversal +share` | `commission_reversal −share` |
| Settlement paid | `payout −net` | |

Funds stay **pending** until the last booked slot of the order ends + `settlement_hold_days`, then become **available**. Settlements group available entries, go through finance approval and are marked paid with the bank reference. Ledger rows cannot be edited or deleted (model guard).

### Integrations

All external services are behind interfaces in `app/Services/Integrations/Contracts` and chosen in `config/farah.php` / `.env`:

| Service | Env | Current driver | To add |
|---|---|---|---|
| Payment | `PAYMENT_DRIVER` | `fake` (local page with Pay/Fail at `/payments/fake/{ref}`) | Moyasar / HyperPay / Tap |
| SMS | `SMS_DRIVER` | `log` | Unifonic / Taqnyat / Msegat |
| Wathq | `WATHQ_DRIVER` | `fake` | Wathq CR API |
| Accounting | `ACCOUNTING_DRIVER` | `null` | Qoyod / Daftra (pending decision) |
| Push | `PUSH_DRIVER` | `log` | FCM |

Every call is logged in `integration_logs` (visible in the admin panel).

### Scheduler

| Command | When |
|---|---|
| `farah:expire-orders` | every minute: release the slots held by unpaid orders |
| `farah:send-campaigns` | every minute: scheduled push campaigns |
| `farah:complete-bookings` | every 15 minutes: bookings whose time passed (and their orders) become completed |
| `farah:document-expiry` | daily 08:00: warn owners, expire documents |
| `farah:generate-settlements` | daily 02:00, acts on the configured cycle day (`--force` to run now) |

## Pending business decisions (from the SRS)

Settlement cycle, commission base, check-in model (QR scanning is optional), accounting system. All are settings or swappable drivers, so they can be decided later without schema changes.
