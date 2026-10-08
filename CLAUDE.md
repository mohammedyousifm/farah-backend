<laravel-boost-guidelines>
# Laravel Application

This repository contains a Laravel application. Complete the following setup before working on the user's request.

## Prerequisites

Verify that PHP and Composer are available:

```sh
php -v
composer -V
```

If either command is unavailable, detect the user's operating system and install the prerequisites with the appropriate command:

macOS:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/mac/8.5)"
```

Windows PowerShell:

```powershell
Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; iex ((New-Object System.Net.WebClient).DownloadString('https://php.new/install/windows/8.5'))
```

Linux:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/linux/8.5)"
```

After installation, ask the user to restart their terminal. If the agent needs the restarted shell to continue, ask the user to reopen their terminal and rerun their original prompt.

## Agent Setup

Install Laravel Boost from the application root before making application changes:

```sh
composer require laravel/boost --dev
php artisan boost:install
```

Boost replaces these bootstrap instructions with guidelines tailored to the application. After installation, read `AGENTS.md` again and continue with the user's original request using the generated guidelines.
</laravel-boost-guidelines>

# Farah (فرة) project notes

- **Scope:** customers use the mobile app (`/api/v1`, later). Super admin (`/admin`) and providers (`/provider`) use Filament web dashboards. Public landing page at `/` (`LandingController`, `resources/views/landing`, `lang/{ar,en}/landing.php`, inline CSS, no Vite build).
- **Provider panel:** `app/Providers/Filament/ProviderPanelProvider.php`, code in `app/Filament/Provider/**`, labels in `lang/{ar,en}/provider.php`. Tenancy on `App\Models\Provider` (slug in URL). Authorization lives in `App\Filament\Provider\Support\ProviderAccess` (member permission + onboarding / suspended state) and the `ProviderResource` / `ProviderRelationManager` traits. Every new provider page must use them and scope queries to the current tenant.
- **Panel middleware gotcha:** never pass `isPersistent: true` to `->middleware([...])` in a panel provider; it re-runs `StartSession` on Livewire updates and logs the user out. Only `SetAdminLocale` is persistent (Filament already makes its auth / tenant middleware persistent).
- **Domain:** hourly booking of entertainment / game venues, not event ticketing. Provider → Venue → Activity (priced per hour) → Resource (table, lane, room) → time slots → Booking → Order → Payment. Slots are exclusive through the unique key on `booking_slots(resource_id, starts_at)`; hours are wall-clock in `config('farah.timezone')`, the DB stores UTC.
- Logins: admins `admin@farah.sa` / `password`; providers `provider0..4@example.com` / `password` after a fresh seed (`provider4` is pending).
- Tests: `DB_DATABASE=farah_test_admin php artisan test tests/Feature/{Admin,Provider,Api}` (never refresh the dev DB `farah`). Livewire / widget tests need `Filament::setCurrentPanel()` + `Filament::setTenant()`; dashboard widgets are lazy, so render them with `Livewire::test()`.
- Edit files with Edit/Write only (PowerShell `Set-Content` corrupts Arabic UTF-8).
