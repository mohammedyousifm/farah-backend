# Farah API v1 (فرة)

REST API for the Farah customer and provider mobile app (Flutter).
Machine-readable spec: [`openapi.yaml`](openapi.yaml). Source: `routes/api.php`, `app/Http/**`.

Farah is an app for **booking entertainment / game venues by the hour** ("billiards, table 3, 8-9 PM"):

```
Provider (business) → Venue (place) → Activity (billiards, bowling, ... priced per hour)
        → Resource (Table 3, Lane 1; each has its own availability) → time slots
        → Booking (one resource, a run of consecutive slots) → Order (payment container) → Payment
```

- **Base URL:** `{APP_URL}/api/v1` (local: `http://localhost:8020/api/v1`)
- **Format:** JSON in, JSON out. Always send `Accept: application/json`.
- **Auth:** Sanctum personal access token, `Authorization: Bearer <token>`.
- **Language:** `Accept-Language: ar` (default) or `en`. Response header `Content-Language` echoes it.
- **Money:** SAR, VAT-inclusive, decimals as JSON numbers (`100` or `99.5`). Prices are **per hour**.
- **Dates:** ISO-8601 with offset, in UTC (`2026-10-09T17:00:00+00:00`). See *Time* below.
- **Images/files:** always absolute URLs (`cover_url`, `logo_url`, `file_url`, ...).

---

## 1. Conventions

### Headers

| Header | When | Notes |
|---|---|---|
| `Accept: application/json` | always | |
| `Accept-Language: ar\|en` | always | anything else falls back to the user's saved locale, then `ar` |
| `Authorization: Bearer <token>` | signed-in endpoints | optional on catalog endpoints (adds `is_favorite`, personalised home) |
| `X-Provider-Id: <id>` | provider endpoints | optional; picks which provider account to act for when the user belongs to several. Default: the user's first active membership |
| `X-Signature` | webhook only | HMAC-SHA256 of the raw body |

### Response envelope

Single resource:
```json
{ "data": { "id": 1, "...": "..." } }
```
Actions usually add a human message (already translated):
```json
{ "message": "تم الحفظ.", "data": { } }
```
Paginated list (`?page=2&per_page=20`):
```json
{
  "data": [ { } ],
  "links": { "first": "...?page=1", "last": "...?page=5", "prev": null, "next": "...?page=2" },
  "meta": { "current_page": 1, "from": 1, "last_page": 5, "per_page": 20, "to": 20, "total": 93, "path": "..." }
}
```

### Localised fields
Translatable models return the current-locale value plus both raw values:
`"name": "الرياض", "name_ar": "الرياض", "name_en": "Riyadh"` (same for `summary`, `description`, `title`, `label`).

### Enums
Statuses are objects so the app can render badges without its own mapping:
```json
"status": { "value": "confirmed", "label": "مؤكد", "color": "success" }
```
`color` is one of `gray | info | success | warning | danger | primary`.

### Time

- Every timestamp stored and returned (`starts_at`, `ends_at`, `expires_at`, ...) is **UTC**, ISO-8601.
- Working hours (`opens_at`, `closes_at`) and the slot labels `time` / `end_time` are **wall-clock times in the business
  timezone** (`timezone` in `/app/config`, `Asia/Riyadh`). Show those as they are; do not convert them to the device timezone.
- `weekday` is `0` = Sunday ... `6` = Saturday.
- `closes_at` at or before `opens_at` means the venue is open past midnight (`16:00` → `02:00`). A slot at 01:00 then
  belongs to the previous day's window; ask for availability with the date the business day **started** on.
- An activity is priced per hour and cut into slots of `slot_minutes`: `slot_price = price_per_hour × slot_minutes / 60`.
  A booking is `min_slots`..`max_slots` consecutive slots of one resource.
- When sending `starts_at`, use ISO-8601 **with an offset** (take the slot's `starts_at` from the availability response
  as is). A value without an offset is read as wall-clock time in the business timezone.
- Booking holds the slots for `order_payment_timeout_minutes` (`/app/config`, default 15) while the customer pays.

### Errors

| HTTP | Body | Meaning |
|---|---|---|
| 401 | `{"message": "...", "code": "unauthenticated"}` | missing / invalid / revoked token |
| 403 | `{"message": "...", "code": "account_suspended"}` | user suspended |
| 403 | `{"message": "...", "code": "not_provider_member"}` | not a member of (that) provider |
| 403 | `{"message": "...", "code": "missing_permission", "permission": "orders.view"}` | staff lacks a sub-permission |
| 404 | `{"message": "...", "code": "not_found"}` | unknown id **or** belongs to someone else |
| 422 | `{"message": "...", "errors": {"field": ["..."]}}` | validation failed |
| 4xx | `{"message": "...", "code": "<business_code>"}` | business rule (see list) |
| 429 | `{"message": "...", "code": "too_many_attempts", "retry_after": 42}` | rate limited (`api`: 120/min per user/IP; `otp`: 5/min per IP, 10/h per identifier) |

**Business error codes** (HTTP 422 unless noted):
`otp_throttled` (429), `otp_expired`, `otp_invalid`, `otp_locked` (429),
`slot_taken` (**409**), `outside_hours`, `slots_range`, `slot_in_past`, `too_far_ahead`, `resource_unavailable`,
`activity_unavailable`, `not_bookable`, `one_venue_per_order`, `max_per_order`, `booking_empty`,
`order_not_payable`, `order_paid`, `order_already_cancelled`,
`refund_pending`, `refund_not_eligible`, `order_not_refundable`, `invalid_items`, `nothing_left`, `review_not_allowed` (403),
`already_reviewed`, `forbidden` (403, counter booking for a venue of another provider), `complaint_closed`,
`already_registered`, `document_locked`, `sector_exists`, `sector_request_pending`, `sector_not_allowed` (403),
`wrong_status`, `provider_not_approved`, `no_activities`, `no_hours`, `venue_locked`, `venue_has_orders`,
`activity_has_bookings`, `resource_has_bookings`, `member_exists`,
`cannot_edit_owner` (403), `cannot_edit_self` (403), `booking_not_found` (404), `booking_used` (409),
`booking_finished` (409), `booking_cancelled` (409), `invalid_signature` (401), `invalid_webhook` (400), `amount_mismatch` (webhook),
`provider_suspended` (403, any write while the provider is suspended), `cannot_grant` (403, staff granting more than they hold),
`admin_account` (403), `refunds_failed`, `invalid_transition`.

---

## 2. Authentication flow (passwordless OTP)

1. `POST /auth/otp/request` `{"identifier": "0501234567"}` (Saudi mobile in any common form, or an email).
2. User receives a 4-digit code (SMS / email). **`APP_ENV=local` / `testing` only: code `1234` always works**
   (disabled in every other environment, including staging). Only the latest code is valid; it allows 5 attempts,
   then `otp_locked` until a new code is requested. Requests are throttled per IP (5/min) and per normalised number (10/h).
3. `POST /auth/otp/verify` `{"identifier": "0501234567", "code": "1234", "device_name": "iPhone 15"}`
   → `token`, `user`, `is_new`. New phone numbers are registered as customers automatically.
   If `is_new` is true, show the "complete profile" screen and call `PUT /me`.
4. Store the token securely; send it as `Authorization: Bearer ...`. Tokens expire after 90 days (`API_TOKEN_TTL_DAYS`),
   after which the API answers 401 `unauthenticated` and the app signs in again; `POST /auth/logout` revokes the current one.
5. Register the push token: `POST /me/device-tokens`.

Phone numbers are normalised to `+9665XXXXXXXX`.

---

## 3. Endpoints

Legend: 🔓 public (token optional) · 🔑 token required · 🏢 provider member (+ permission)

### 3.1 Auth & profile

| Method | Path | Auth | Description |
|---|---|---|---|
| POST | `/auth/otp/request` | 🔓 | Send OTP. Body `identifier`. |
| POST | `/auth/otp/verify` | 🔓 | Verify OTP → token. Body `identifier`, `code`, `device_name?`, `locale?` |
| GET | `/me` | 🔑 | Current user (with `providers` memberships) |
| PUT | `/me` | 🔑 | Update `name, email, city_id, birth_date, gender(male\|female), locale(ar\|en), marketing_consent, tracking_consent`. For `avatar` upload use **POST `/me`** multipart |
| DELETE | `/me` | 🔑 | Delete account (soft delete, tokens revoked) |
| POST | `/auth/logout` | 🔑 | Revoke current token |
| POST | `/me/device-tokens` | 🔑 | `{token, platform: ios\|android\|web}` |
| DELETE | `/me/device-tokens` | 🔑 | `{token}` |

```http
POST /api/v1/auth/otp/request
{"identifier": "0501234567"}

200 {"message": "تم إرسال رمز التحقق.",
     "data": {"identifier": "+966501234567", "channel": "sms", "expires_in": 300, "resend_in": 60}}
```
```http
POST /api/v1/auth/otp/verify
{"identifier": "0501234567", "code": "1234", "device_name": "Pixel 8"}

200 {"data": {"token": "12|x7Yc...", "token_type": "Bearer", "is_new": true,
     "user": {"id": 15, "name": "", "phone": "+966501234567", "email": null,
              "type": {"value": "customer", "label": "عميل", "color": "info"}, "locale": "ar",
              "avatar_url": null, "city_id": null, "tracking_consent": false, "providers": [], "...": "..."}}}
```
`user.providers[]` = `{id, name, status, role: owner|manager|staff, permissions: ["*"] | [...]}` — use it to decide whether to show the provider app.

### 3.2 Catalog (public)

| Method | Path | Description |
|---|---|---|
| GET | `/app/config` | Platform settings: currency, VAT, `timezone`, support contacts, min app versions, maintenance flag, `order_payment_timeout_minutes`, `max_bookings_per_order`, refund policies, audiences, published page slugs |
| GET | `/cities` | Active cities `{id, name, name_ar, name_en, region, lat, lng}` |
| GET | `/sectors` | Activity types (billiards, bowling, gaming, ...) `{id, slug, name, icon, image_url, venues_count, categories[]}` — `venues_count` = bookable venues offering it |
| GET | `/categories` | `?sector_id=&parent_id=` — sub-types of an activity type (pool / snooker, ...) |
| GET | `/home` | Home feed sections (`?city_id=`; defaults to user's city) — below |
| GET | `/venues` | Search & filter (below) — paginated `VenueCard` |
| GET | `/venues/{id\|slug}` | Venue details (media, weekly hours, activities with their resources, refund policy, provider). Increments views |
| GET | `/venues/{id}/reviews` | Published reviews + `meta.rating_avg, rating_count, distribution` |
| GET | `/venues/{id}/similar` | Similar venues (`?limit=10`, max 30) — `VenueCard[]` |
| GET | `/recommendations` | Personalised picks (`?limit=10&city_id=`, max 30) — `VenueCard[]` |
| GET | `/activities/{id}/availability` | Every slot of the activity on one day, per resource (`?date=YYYY-MM-DD`, today when omitted) — below |
| GET | `/providers/{id}` | Public provider profile with its `venues` (`VenueCard[]`, up to 50) |
| GET | `/pages/{slug}` | Static page (terms, privacy, about, ...) |
| POST | `/track` | Behaviour events (stored only with consent, see 3.8) |

Only **published** venues of **approved** providers are visible; anything else answers 404.

**`GET /venues` query parameters**

| Param | Example | Notes |
|---|---|---|
| `q` | `بلياردو` | venue name (ar/en), summary, address, or the name of one of its activities |
| `sector_id`, `category_id` | `3` | venues with an active activity of that type (a venue has no sector of its own); category includes its sub-categories |
| `city_id`, `provider_id` | `1` | |
| `price_min`, `price_max` | `50`, `200` | on `min_price` = the cheapest hourly price of the venue |
| `audience` | `families` | `all, families, men, women, kids` (venues for `all` always match) |
| `featured` | `1` | |
| `lat`, `lng`, `radius` | `24.71`, `46.67`, `25` | both required together; km (default 50); adds `distance_km` and drops venues without coordinates |
| `sort` | `recommended` | `recommended` (default; featured first, then best rated), `price` (cheapest first), `rating`, `nearest` (default when lat/lng given; needs them), `latest` |
| `per_page`, `page` | `20` | max 50 |

`VenueCard`:
```json
{"id": 12, "slug": "cue-club-ab12cd", "name": "كيو كلوب", "summary": "...",
 "cover_url": "http://localhost:8020/storage/venues/3/cover.jpg",
 "min_price": 60, "currency": "SAR", "is_featured": true,
 "rating_avg": 4.6, "rating_count": 18, "address": "...", "lat": 24.7, "lng": 46.6, "distance_km": null,
 "status": {"value": "published", "label": "منشور", "color": "success"}, "is_favorite": false,
 "is_open_now": true, "today_hours": {"opens_at": "16:00", "closes_at": "02:00"},
 "city": {"id": 1, "name": "الرياض", "...": ""},
 "sectors": [{"id": 1, "slug": "billiards", "name": "بلياردو", "icon": "billiards"}],
 "provider": {"id": 3, "name": "...", "logo_url": null, "...": ""}}
```
`min_price` is per hour. `today_hours` is `null` when the venue is closed today. `sectors` = the types of its active activities.

Venue details (`GET /venues/{id|slug}`) = the card plus:
```json
{"name_ar": "...", "name_en": "...", "summary_ar": "...", "summary_en": "...",
 "description": "...", "description_ar": "...", "description_en": "...",
 "city_id": 1, "phone": "0112345678", "audience": "all", "min_age": null,
 "advance_booking_days": 14, "is_bookable": true, "views_count": 120, "published_at": "2026-10-01T09:00:00+00:00",
 "refund_policy": {"key": "flexible", "label": "مرنة", "description": "...", "note": null,
                   "rules": [{"min_hours_before_start": 24, "refund_percent": 100}, {"min_hours_before_start": 0, "refund_percent": 50}]},
 "media": [{"id": 4, "type": "image", "url": "...", "is_external": false, "sort_order": 0}],
 "hours": [{"weekday": 0, "day": "الأحد", "opens_at": "16:00", "closes_at": "02:00", "is_closed": false},
           {"weekday": 1, "day": "الاثنين", "opens_at": null, "closes_at": null, "is_closed": true}, "... always 7 rows, Sunday first"],
 "activities": [{"id": 7, "venue_id": 12, "name": "بلياردو", "name_ar": "بلياردو", "name_en": "Billiards",
                 "description": null, "sector_id": 1, "category_id": null,
                 "sector": {"id": 1, "slug": "billiards", "name": "بلياردو", "icon": "billiards"},
                 "price_per_hour": 60, "currency": "SAR", "slot_minutes": 60, "slot_price": 60,
                 "min_slots": 1, "max_slots": 4, "capacity": 4, "is_active": true, "sort_order": 1,
                 "resources_count": 3,
                 "resources": [{"id": 21, "activity_id": 7, "name": "طاولة 1", "name_ar": "طاولة 1", "name_en": "Table 1", "is_active": true, "sort_order": 1}]}]}
```
Customers only get active activities and active resources. `advance_booking_days` = how far ahead bookings open.
`media[].type` is `image | video | gif`.

**`GET /activities/{id}/availability?date=2026-10-09`**
```json
{"data": {"activity_id": 7, "venue_id": 12, "date": "2026-10-09", "timezone": "Asia/Riyadh",
  "is_open": true, "opens_at": "16:00", "closes_at": "02:00",
  "slot_minutes": 60, "slot_price": 60, "currency": "SAR", "min_slots": 1, "max_slots": 4,
  "resources": [{"id": 21, "name": "طاولة 1",
     "slots": [{"starts_at": "2026-10-09T17:00:00+00:00", "ends_at": "2026-10-09T18:00:00+00:00",
                "time": "20:00", "end_time": "21:00", "status": "available"}]}]}}
```
Slot `status`: `available` · `booked` (held by a booking or blocked by the venue) · `past` · `closed` (beyond the venue's
`advance_booking_days`). On a closed day `is_open` is false, `opens_at` / `closes_at` are null and every resource has `slots: []`.
404 for an inactive activity or a venue that is not visible. Refresh right before booking: another customer may take a slot at any moment.

**`GET /home`** → `data[]` of sections `{id, title, type, layout, source, items[]}`; empty sections are left out.
`type`: `banner | venues | categories | sectors | providers` · `layout`: `hero | carousel | grid | list`.
Each item has a `kind` telling which card to render: `venue` (a `VenueCard`), `category`, `sector`, `provider`, or `banner`:
```json
{"kind": "banner", "id": 3, "title": "...", "media_type": "image", "media_url": "...",
 "link": {"type": "venue", "value": "12"}}
```
`link.type`: `none | venue | category | sector | provider | url`.

### 3.3 Favorites 🔑

| Method | Path | |
|---|---|---|
| GET | `/favorites` | paginated `VenueCard` |
| POST | `/favorites/{venueId}` | add → `201 {"data": {"venue_id": 12, "is_favorite": true}}` (404 if the venue is not visible) |
| DELETE | `/favorites/{venueId}` | remove → `{"data": {"venue_id": 12, "is_favorite": false}}` |

### 3.4 Booking & payment 🔑

There is no cart: the customer picks the slots and books directly. One request creates **one pending order** (the slots
are held for the payment window) and **one payment** for it.

| Method | Path | Body | |
|---|---|---|---|
| POST | `/bookings` | `{activity_id, resource_id?, starts_at, slots}` · or `{lines: [{activity_id, resource_id?, starts_at, slots}]}` (several resources / activities of the **same venue**) | 201 `{order, payment}` |
| GET | `/bookings` | | my bookings (`?scope=upcoming\|past\|all&status=&per_page=`) |
| GET | `/bookings/{id}` | | one booking |
| GET | `/payments/{reference}` | | poll payment status |
| POST | `/orders/{id}/pay` | | new payment session for a still-pending order → 201 `{payment}` |

- `starts_at`: start of the first slot (ISO-8601 with offset; see *Time*). `slots`: how many consecutive slots (`min_slots`..`max_slots` of the activity).
- `resource_id` is optional: without it the first free resource (table / lane / room) is assigned.
- At most `max_bookings_per_order` (`/app/config`) lines per request.

```http
POST /api/v1/bookings
{"activity_id": 7, "resource_id": 21, "starts_at": "2026-10-09T17:00:00+00:00", "slots": 2}
```
```json
// 201
{"data": {
  "order": {"id": 31, "number": "ORD-261009-AB12C", "channel": "online",
            "status": {"value": "pending_payment", "label": "بانتظار الدفع", "color": "warning"},
            "subtotal": 120, "discount": 0, "vat_amount": 15.65, "total": 120, "refunded_amount": 0, "currency": "SAR",
            "expires_at": "2026-10-06T15:15:00+00:00", "confirmed_at": null, "cancelled_at": null, "cancel_reason": null,
            "created_at": "2026-10-06T15:00:00+00:00",
            "venue": {"id": 12, "slug": "cue-club-ab12cd", "name": "كيو كلوب", "cover_url": "...", "address": "...",
                      "lat": 24.7, "lng": 46.6, "phone": "0112345678", "city": {"id": 1, "name": "الرياض"}},
            "provider": {"id": 3, "name": "...", "logo_url": null},
            "bookings": [{"...": "Booking, see below"}]},
  "payment": {"reference": "7c1d...uuid", "amount": 120, "refunded_amount": 0, "currency": "SAR", "method": null,
              "status": {"value": "initiated", "label": "...", "color": "..."}, "failure_reason": null,
              "checkout_url": "http://localhost:8020/payments/fake/7c1d...", "paid_at": null}}}
```

**Errors of `POST /bookings`**

| HTTP | `code` | Meaning |
|---|---|---|
| 409 | `slot_taken` | someone else holds one of the slots (or no resource is free at that time). Reload availability |
| 422 | `outside_hours` | not inside the working hours, or not on the slot grid |
| 422 | `slots_range` | `slots` outside `min_slots`..`max_slots` |
| 422 | `slot_in_past` | the start time already passed |
| 422 | `too_far_ahead` | later than the venue's `advance_booking_days` |
| 422 | `resource_unavailable` | `resource_id` is not an active resource of the activity |
| 422 | `activity_unavailable` | unknown or inactive activity |
| 422 | `not_bookable` | venue not published / provider not approved |
| 422 | `one_venue_per_order` | lines of different venues |
| 422 | `max_per_order` | too many lines |
| 422 | `booking_empty` | no lines |
| 422 | *(validation)* | `errors{}` for missing / malformed fields |

A failed request holds nothing.

**Double submit:** the same booking sent again within 15 seconds (double tap, retry on a slow network) answers 201 with
the **same** order and payment instead of holding a second resource.

**Payment flow in the app**
1. Open `payment.checkout_url` in a WebView / in-app browser.
2. The slots are held for `order_payment_timeout_minutes` (see `/app/config`, default 15; `order.expires_at`); after that
   the order becomes `expired` and the slots go back on sale.
3. Poll `GET /payments/{reference}` every 2–3 s until `status.value` is `paid` or `failed` (or intercept the `farah://payments/{reference}` deep link the sandbox result page links to).
   The payment carries `orders: [{id, number, status}]`.
4. On `paid` show the order (`GET /orders/{id}`) — its bookings are `confirmed` and each has a `code`.
   On `failed` the order is still `pending_payment` until it expires; retry with `POST /orders/{id}/pay`.

**Sandbox gateway (dev):** `checkout_url` opens `/payments/fake/{reference}`, an Arabic page with *Pay* and *Fail* buttons that fire a signed webhook.

**Booking**
```json
{"id": 44, "code": "K7M2XQ9PAB",
 "status": {"value": "confirmed", "label": "مؤكد", "color": "success"},
 "name": "بلياردو - طاولة 1",
 "starts_at": "2026-10-09T17:00:00+00:00", "ends_at": "2026-10-09T19:00:00+00:00",
 "slots": 2, "slot_minutes": 60, "unit_price": 60, "total": 120, "checked_in_at": null,
 "activity": {"id": 7, "name": "بلياردو"}, "resource": {"id": 21, "name": "طاولة 1"},
 "venue": {"id": 12, "slug": "cue-club-ab12cd", "name": "كيو كلوب", "cover_url": "...", "address": "...",
           "lat": 24.7, "lng": 46.6, "phone": "0112345678", "city": {"id": 1, "name": "الرياض"}},
 "order": {"id": 31, "number": "ORD-261009-AB12C", "status": {"value": "confirmed", "label": "مؤكد", "color": "success"}}}
```
`unit_price` is per slot. Render `code` as a QR code; the venue scans it on arrival (provider check-in).
Booking statuses: `pending` (held, waiting for payment), `confirmed`, `checked_in`, `completed`, `cancelled`, `expired`.
Bookings nested in an order carry this same shape, including `order` and `venue`.

`GET /bookings` — `scope`: `upcoming` (default: not finished and not ended yet, soonest first; includes `pending` ones
still waiting for payment), `past` (completed / cancelled / expired or already ended, newest first), `all`.
`status` = comma-separated booking statuses.

### 3.5 Orders & refunds 🔑

| Method | Path | |
|---|---|---|
| GET | `/orders` | `?status=confirmed,completed&scope=upcoming\|past&per_page=` — paginated, includes `venue`, `provider`, `bookings`, `has_review` |
| GET | `/orders/{id}` | + `payment`, `refunds` |
| POST | `/orders/{id}/cancel` | `{reason?}` — only `pending_payment` (releases the slots at once); paid orders → `order_paid`; twice → `order_already_cancelled` |
| POST | `/orders/{id}/pay` | new payment session (see 3.4); `order_not_payable` unless `pending_payment` |
| GET | `/orders/{id}/refund-quote` | `{policy, hours_before_start, percent, amount, refundable_amount, eligible}` — the clock runs to the earliest booking of the order |
| POST | `/orders/{id}/refund` | `{reason?, items?: [{booking_id}]}` — omit `items` for a full refund, or list the bookings to give up. Creates a refund request reviewed by the admin team (`refund_pending` while one is open) |

`scope=upcoming` = orders with a booking that has not ended yet; `past` = the rest.
Order statuses: `pending_payment, confirmed, completed, cancelled, expired, partially_refunded, refunded`.
A processed refund cancels the refunded bookings and puts their slots back on sale.

Refund: `{id, number, order_id, type: full|partial, amount, items: [{booking_id}] | null, reason, status, admin_notes, processed_at, created_at}`.

### 3.6 Reviews & complaints 🔑

| Method | Path | Body |
|---|---|---|
| POST | `/reviews` | `{order_id, rating 1-5, comment?}` — only for your confirmed/completed order, once; the review is attached to the order's venue |
| GET | `/complaints` | paginated |
| POST | `/complaints` | `{type: complaint\|feedback\|suggestion, subject, body, order_id?, venue_id?}` |
| GET | `/complaints/{id}` | with public replies (`from_support` true for staff replies) |
| POST | `/complaints/{id}/replies` | `{body}` |

Review: `{id, rating, comment, user: {id, name}, venue: {id, name}, order_id, provider_reply, replied_at, status, created_at}` (`venue` only on provider lists).

### 3.7 Notifications 🔑

| Method | Path | |
|---|---|---|
| GET | `/notifications` | `?unread=1` — paginated, `meta.unread_count` |
| GET | `/notifications/unread-count` | `{unread_count}` |
| POST | `/notifications/{id}/read` | |
| POST | `/notifications/read-all` | |

Notification: `{id (uuid), key: "order_confirmed", title, body, data: {"order_id": 31}, read, read_at, created_at}`.
Use `key` + `data` for navigation (`order_id`, `venue_id`, `payment_reference`, `provider_id`, ...).
Keys include `order_confirmed`, `new_order`, `payment_failed`, `refund_processed`, `refund_rejected`, `venue_approved`,
`venue_rejected`, `venue_suspended`, `provider_approved`, `team_invited`, `complaint_replied`, `campaign`.

### 3.8 Tracking 🔓

`POST /track`
```json
{"device_id": "a1b2", "consent": true,
 "events": [{"name": "view_venue", "subject_type": "venue", "subject_id": 12, "properties": {"source": "home"}, "occurred_at": "2026-10-01T10:00:00Z"}]}
```
`name`: `view_venue, view_category, search, favorite, start_booking, purchase, share`.
`subject_type`: `venue, category, sector, provider`. Up to 50 entries per request.
Signed-in users: stored only if `tracking_consent` is true on their profile. Guests: need `consent: true` and a `device_id`.
Always answers `202 {"data": {"stored": N}}`. (`favorite` and `start_booking` are also recorded by the server itself for consenting users.)

### 3.9 Payment webhook (gateway → server)

`POST /payments/webhook`, raw JSON body + `X-Signature: hex(HMAC-SHA256(body, PAYMENT_WEBHOOK_SECRET))`.
```json
{"id": "evt_123", "type": "payment.paid", "data": {"reference": "<payment reference>", "id": "pay_abc", "method": "mada"}}
```
`200 {"received": true, "duplicate": false}`; a repeated `id` returns `duplicate: true` without re-applying. Bad signature → 401
(the forged request is logged but never reserves the webhook `id`, so the genuine one is still processed).
Optional `data.amount` / `data.currency`: when present they must equal the payment, otherwise `422 amount_mismatch` and nothing is confirmed.

Late / duplicate payments are handled automatically:
- paid after the hold expired or the order was cancelled → the same slots are taken again if still free,
  otherwise (slot taken by someone else / venue no longer bookable / time passed) the payment is refunded in full (`cancel_reason: sold_out_after_payment`);
- a second payment for an order already paid by another checkout session is refunded (`reason: duplicate_payment`).

---

## 4. Provider app 🏢

All paths below are prefixed with `/provider` and need a token. The acting provider = `X-Provider-Id` header or the user's first active membership.
A **suspended** provider is read-only: every non-GET request answers `403 provider_suspended`.
Staff holding `team.manage` can only add / edit `staff` members with permissions they hold themselves (`403 cannot_grant`); new members get a `team_invited` notification.
**Roles:** `owner` and `manager` have every permission. `staff` only has the permissions granted:
`venues.manage, orders.view, orders.manage, orders.offline, bookings.checkin, finance.view, team.manage, reviews.reply, profile.manage`.

| Method | Path | Permission | Description |
|---|---|---|---|
| POST | `/provider/register` | token only | Apply as provider (below) — status `pending` until approved by admins |
| GET | `/provider/dashboard` | member | KPIs (below) |
| GET | `/provider/profile` | member | full profile, `status`; `national_id`, `iban`, `bank_name`, `account_holder` only for owner / manager / `profile.manage` / `finance.view` |
| PUT / POST | `/provider/profile` | profile.manage | update; `logo`, `cover` images via **POST multipart** |
| GET | `/provider/documents` | profile.manage | verification documents |
| POST | `/provider/documents` | profile.manage | multipart `type, file (pdf/jpg/png ≤10MB), title?, number?, sector_id?, issued_at?, expires_at?, replaces_id?` |
| GET | `/provider/sectors` | member | activity types of the provider with `approval_status` (pending/approved/rejected) |
| GET | `/provider/sector-requests` | member | |
| POST | `/provider/sector-requests` | profile.manage | `{sector_id}` to join an existing activity type, or `{name_ar, name_en?, description?}` to propose a new one |
| GET | `/provider/venues` | member | own venues, any status — `?status=draft,published&q=&per_page=`, paginated full `Venue` |
| POST | `/provider/venues` | venues.manage | create a draft (below) |
| GET | `/provider/venues/{id}` | member | full venue incl. `rejection_reason`, `submitted_at`, inactive activities / resources |
| PUT / POST | `/provider/venues/{id}` | venues.manage | update (POST for multipart `cover`). Editing a published / rejected venue sends it back to `pending_review`. Suspended venue → `422 venue_locked` |
| DELETE | `/provider/venues/{id}` | venues.manage | only without orders (`venue_has_orders`) |
| POST | `/provider/venues/{id}/submit` | venues.manage | draft / rejected → `pending_review`. Needs an approved provider (`provider_not_approved`), ≥1 active activity with an active resource (`no_activities`) and ≥1 open day (`no_hours`); other statuses → `wrong_status` |
| GET | `/provider/venues/{id}/hours` | member | the 7 weekly rows `{weekday, day, opens_at, closes_at, is_closed}` |
| PUT | `/provider/venues/{id}/hours` | venues.manage | `{hours: [{weekday, opens_at, closes_at, is_closed?}]}` — applies at once, no re-review; days left out keep their hours |
| GET | `/provider/venues/{id}/media` | member | |
| POST | `/provider/venues/{id}/media` | venues.manage | multipart `file` (jpg/png/webp/gif/mp4/mov ≤50MB) **or** `url` (external video/image), `sort_order?` |
| PUT | `/provider/venues/{id}/media/order` | venues.manage | `{ids: [5,3,9]}` |
| DELETE | `/provider/venues/{id}/media/{mediaId}` | venues.manage | |
| GET | `/provider/venues/{id}/activities` | member | activities with `sector` and all `resources` |
| POST | `/provider/venues/{id}/activities` | venues.manage | create (below) |
| GET | `/provider/venues/{id}/activities/{activityId}` | member | |
| PUT | `/provider/venues/{id}/activities/{activityId}` | venues.manage | any of the create fields (not `resources_count`). New prices apply to new bookings only. `slot_minutes` cannot change while the activity has upcoming bookings (`activity_has_bookings`) |
| DELETE | `/provider/venues/{id}/activities/{activityId}` | venues.manage | only if never booked (`activity_has_bookings`; deactivate instead) |
| GET | `/provider/venues/{id}/activities/{activityId}/resources` | member | |
| POST | `/provider/venues/{id}/activities/{activityId}/resources` | venues.manage | `{name_ar, name_en?, is_active?, sort_order?}` |
| PUT | `/provider/venues/{id}/activities/{activityId}/resources/{resourceId}` | venues.manage | deactivating stops new bookings; existing ones stay valid |
| DELETE | `/provider/venues/{id}/activities/{activityId}/resources/{resourceId}` | venues.manage | only if never booked (`resource_has_bookings`) |
| GET | `/provider/venues/{id}/schedule` | member | the day at the counter (`?date=YYYY-MM-DD&activity_id=`) — below |
| POST | `/provider/venues/{id}/blocks` | venues.manage | `{resource_id, starts_at, slots, reason?}` — take slots out of sale; `409 slot_taken` if any is booked or already blocked |
| DELETE | `/provider/venues/{id}/blocks/{slotId}` | venues.manage | put one blocked slot back on sale (`slot_id` from the schedule); a slot held by a booking → `409 slot_taken` |
| GET | `/provider/orders` | orders.view | `?venue_id=&status=&channel=online\|offline&q=&date_from=&date_to=&per_page=` — `q` matches order number, customer name / phone or a booking code; includes `customer`, `offline_payment_method`, `notes`, `commission_amount`, `provider_net` |
| GET | `/provider/orders/{id}` | orders.view | + `refunds` |
| POST | `/provider/orders/offline` | orders.offline | counter booking (below) → confirmed at once |
| POST | `/provider/bookings/check-in` | bookings.checkin | `{code, venue_id?}` (below) |
| GET | `/provider/members` | team.manage | |
| POST | `/provider/members` | team.manage | `{phone, name?, role: manager\|staff, permissions?: [...]}` — creates the user if needed; they log in with OTP |
| PUT | `/provider/members/{id}` | team.manage | `{role?, permissions?, is_active?}` (owner / yourself can't be edited) |
| DELETE | `/provider/members/{id}` | team.manage | deactivate |
| GET | `/provider/finance/balances` | finance.view | `{pending, available, in_settlement, paid_out, lifetime_sales, lifetime_commission, currency, bank}` |
| GET | `/provider/finance/ledger` | finance.view | `?type=sale,commission&order_id=&date_from=&date_to=&per_page=` — entries with `state: pending\|available\|in_settlement\|paid_out` |
| GET | `/provider/finance/settlements` | finance.view | `?status=` |
| GET | `/provider/finance/settlements/{id}` | finance.view | with ledger `entries` |
| GET | `/provider/reviews` | member | `?venue_id=&rating=&replied=0\|1` |
| POST | `/provider/reviews/{id}/reply` | reviews.reply | `{reply}` |

Everything is scoped to the acting provider: a venue, activity, resource, order or booking of another provider answers 404.

**Register**
```http
POST /api/v1/provider/register
{"type": "establishment", "name_ar": "مؤسسة الفرح", "name_en": "Farah Co", "city_id": 1,
 "cr_number": "1010123456", "vat_number": "300000000000003", "iban": "SA0380000000608010167519",
 "bank_name": "Al Rajhi", "account_holder": "Farah Co", "sector_ids": [1, 3]}
```
`type=individual` requires `national_id` (10 digits); `establishment` requires `cr_number`. Then upload documents.

**Dashboard** `GET /provider/dashboard`
```json
{"data": {
  "provider": {"...": "own profile"},
  "membership": {"role": "owner", "permissions": ["*"]},
  "venues": {"by_status": {"published": 2, "draft": 1}, "total": 3, "list": ["VenueCard (latest 5)"]},
  "bookings": {"today": 14, "checked_in_today": 6, "this_month": 210, "next": ["Booking (next 5 confirmed)"]},
  "orders": {"today": 9, "this_month": 180, "recent": ["Order (latest 5)"]},
  "sales": {"this_month": 21500, "net_this_month": 19350, "balances": {}, "currency": "SAR"},
  "reviews": {"rating_avg": 4.6, "rating_count": 18, "unreplied": 2},
  "documents": {"pending": 0, "rejected": 0, "expired": 0}}}
```
`orders` is `null` without `orders.view`, `sales` is `null` without `finance.view`, `bookings.next` is `null` without
`orders.view` or `bookings.checkin`. "Today" is the calendar day in the business timezone.

**Create venue**
```http
POST /api/v1/provider/venues   (JSON, or multipart with "cover" image)
{"city_id": 1, "name_ar": "كيو كلوب", "name_en": "Cue Club",
 "summary_ar": "...", "description_ar": "...", "address": "...", "lat": 24.71, "lng": 46.67, "phone": "0112345678",
 "audience": "all", "min_age": 12, "refund_policy": "flexible", "refund_policy_note": null, "advance_booking_days": 14,
 "hours": [{"weekday": 0, "opens_at": "16:00", "closes_at": "02:00"}, {"weekday": 5, "is_closed": true}]}
→ 201 {"message": "تم الحفظ.", "data": {"...": "Venue, status draft"}}
```
Required on create: `city_id`, `name_ar`. `audience`: `all|families|men|women|kids`; `refund_policy`: `none|flexible|moderate|strict`;
`advance_booking_days`: 1-365. `hours` is optional (a new venue without it opens daily 10:00 – 00:00); times are `HH:MM`;
a day with `is_closed: true` or without both times is closed.
Venue statuses: `draft → pending_review → published | rejected`, and `suspended` (by the platform: hidden, no new bookings, locked for editing).
Then add activities and resources, and `POST /provider/venues/{id}/submit`.

**Create activity**
```http
POST /api/v1/provider/venues/12/activities
{"sector_id": 1, "category_id": null, "name_ar": "بلياردو", "name_en": "Billiards", "description_ar": null,
 "price_per_hour": 60, "slot_minutes": 60, "min_slots": 1, "max_slots": 4, "capacity": 4,
 "is_active": true, "resources_count": 3}
→ 201 {"message": "تم الحفظ.", "data": {"...": "Activity with sector + resources"}}
```
Required: `sector_id`, `name_ar`, `price_per_hour` (≥ 1). `sector_id` is the activity type and must be approved for the
provider (`403 sector_not_allowed`); `category_id` must belong to that sector. `slot_minutes`: 15-480 in steps of 5
(default 60); `min_slots` / `max_slots`: 1-48 (defaults 1 / 4), `max_slots ≥ min_slots`.
`resources_count` (create only, 1-100) adds that many resources named `1`, `2`, `3`, ... to rename later.

**Schedule** `GET /provider/venues/12/schedule?date=2026-10-09`
```json
{"data": {"venue_id": 12, "date": "2026-10-09", "timezone": "Asia/Riyadh",
  "is_open": true, "opens_at": "16:00", "closes_at": "02:00",
  "activities": [{"id": 7, "name": "بلياردو", "is_active": true, "slot_minutes": 60, "slot_price": 60, "min_slots": 1, "max_slots": 4,
    "resources": [{"id": 21, "name": "طاولة 1",
      "slots": [{"starts_at": "2026-10-09T17:00:00+00:00", "ends_at": "2026-10-09T18:00:00+00:00",
                 "time": "20:00", "end_time": "21:00", "status": "booked", "slot_id": null, "block_reason": null,
                 "booking": {"id": 44, "code": "K7M2XQ9PAB", "status": {"value": "confirmed"},
                             "starts_at": "...", "ends_at": "...", "slots": 2, "total": 120, "checked_in_at": null,
                             "order": {"id": 31, "number": "ORD-261009-AB12C", "channel": "online", "status": {"value": "confirmed"}},
                             "customer": {"name": "...", "phone": "+9665..."}}}]}]}]}}
```
Slot `status`: `available` · `booked` (with `booking`) · `blocked` (with `slot_id` and `block_reason`) · `past` (ended).
A slot that already started stays `available` until it ends, because it can still be sold at the counter.
`booking.customer` is `null` unless the member has `orders.view` or `bookings.checkin`. Only active resources are listed.
`date` defaults to today; `activity_id` narrows the result to one activity.

**Block slots**
```http
POST /api/v1/provider/venues/12/blocks
{"resource_id": 21, "starts_at": "2026-10-09T17:00:00+00:00", "slots": 2, "reason": "صيانة"}
→ 201 {"message": "...", "data": {"blocked": 2, "slots": [{"slot_id": 91, "resource_id": 21, "starts_at": "...", "ends_at": "...", "block_reason": "صيانة"}]}}
```
The slots must be inside the working hours and on the slot grid (`outside_hours`), not already over (`slot_in_past`).
Blocked slots show as `booked` to customers.

**Counter booking**
```http
POST /api/v1/provider/orders/offline
{"activity_id": 7, "resource_id": 21, "starts_at": "2026-10-09T17:00:00+00:00", "slots": 1,
 "payment_method": "cash",
 "customer": {"name": "عميل حضوري", "phone": "0501231234", "email": null, "notes": null}}
→ 201 {"message": "...", "data": {"channel": "offline", "status": {"value": "confirmed"}, "bookings": [...], "customer": {}, "...": ""}}
```
Same booking lines as `POST /bookings` (flat, or `lines: [...]`) plus `payment_method` (`cash | transfer | pos`) and
`customer` (`name` required). Confirmed immediately, each booking gets its `code`. Unlike the customer app, a slot that
already started can still be sold until it ends, and the `advance_booking_days` limit does not apply. Same error codes as
`POST /bookings`, plus `403 forbidden` when the activity belongs to a venue of another provider and `not_bookable` for a suspended venue.
Counter sales are paid to the provider directly; the platform commission is recorded as owed and deducted from the next settlement.

**Check-in**
```http
POST /api/v1/provider/bookings/check-in
{"code": "K7M2XQ9PAB", "venue_id": 12}
200 {"message": "تم تسجيل الحضور بنجاح.", "data": {"code": "K7M2XQ9PAB", "status": {"value": "checked_in"}, "checked_in_at": "...", "...": "Booking"}}
404 {"code": "booking_not_found"} · 409 {"code": "booking_used"} · 409 {"code": "booking_finished"} · 409 {"code": "booking_cancelled"}
```
The code is case-insensitive. `venue_id` is optional and guards against a code of another branch (→ `booking_not_found`).
`booking_used` = already checked in; `booking_finished` = its time has passed; `booking_cancelled` = cancelled, expired or not paid.

---

## 5. Testing locally

```bash
php artisan serve --port=8020
curl -s -X POST localhost:8020/api/v1/auth/otp/request -H 'Accept: application/json' -d identifier=0501000000
curl -s -X POST localhost:8020/api/v1/auth/otp/verify  -H 'Accept: application/json' -d identifier=0501000000 -d code=1234
```
Demo customers `+966501000000…011`, demo provider owners `+966552000000…003` (after `migrate:fresh --seed`).
Feature tests: `php artisan test tests/Feature/Api` (database `farah_test`).
