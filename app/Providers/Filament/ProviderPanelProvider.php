<?php

namespace App\Providers\Filament;

use App\Filament\Provider\Pages\Auth\RegisterProvider;
use App\Filament\Provider\Pages\Dashboard;
use App\Filament\Provider\Pages\EditProviderProfile;
use App\Filament\Provider\ProviderNavGroup;
use App\Filament\Provider\Resources\Venues\VenueResource;
use App\Filament\Provider\Support\ProviderAccess;
use App\Filament\Support\Brand;
use App\Http\Middleware\SetAdminLocale;
use App\Models\Provider;
use Filament\Actions\Action;
use Filament\FontProviders\GoogleFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Navigation\NavigationItem;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

/**
 * Provider web dashboard (/provider). Tenancy: App\Models\Provider, a user acts for
 * any provider they are an active member of (/provider/{provider-slug}/...).
 * Customers use the mobile app; admins and providers use the web.
 */
class ProviderPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->id('provider')
            ->path('provider')
            ->login()
            ->registration(RegisterProvider::class)
            ->passwordReset()
            // "My account" in the user menu: the signed-in person's own name, email and password.
            ->profile(isSimple: false)
            ->tenant(Provider::class, slugAttribute: 'slug', ownershipRelationship: 'provider')
            ->tenantProfile(EditProviderProfile::class)
            ->tenantSwitcher()
            // The business box at the top of the sidebar is only useful for people who work for
            // several providers; hide it for everyone else (the profile has its own menu item).
            ->tenantMenu(fn () => (auth('provider')->user()?->providers()->wherePivot('is_active', true)->count() ?? 0) > 1)
            // SPA navigation: pages swap in place (wire:navigate) instead of full reloads.
            ->spa()
            // The language switch changes <html dir/lang>, so it needs a full page load.
            ->spaUrlExceptions(['*/admin-locale/*'])
            ->searchableTenantMenu(false)
            ->brandName('فرة | Farah')
            ->brandLogo(fn () => view('filament.brand'))
            ->brandLogoHeight('2.5rem')
            ->favicon(asset('images/favicon.png'))
            ->colors([
                'primary' => Brand::PRIMARY,
                'gray' => Color::Zinc,
                'info' => Color::hex('#3D8BFF'),
                'warning' => Color::hex('#F59E0B'),
                'success' => Color::Emerald,
                'danger' => Color::Rose,
            ])
            ->font('Almarai', provider: GoogleFontProvider::class)
            ->sidebarCollapsibleOnDesktop()
            ->maxContentWidth('full')
            // Built with lazy labels: NavigationGroup::fromEnum() would translate once at boot,
            // before SetAdminLocale runs, freezing the group names in Arabic.
            ->navigationGroups(collect(ProviderNavGroup::cases())
                ->mapWithKeys(fn (ProviderNavGroup $group) => [$group->name => NavigationGroup::make()->label(fn () => $group->getLabel())->collapsible(false)])
                ->all())
            ->discoverResources(in: app_path('Filament/Provider/Resources'), for: 'App\Filament\Provider\Resources')
            ->discoverPages(in: app_path('Filament/Provider/Pages'), for: 'App\Filament\Provider\Pages')
            ->pages([Dashboard::class])
            ->discoverWidgets(in: app_path('Filament/Provider/Widgets'), for: 'App\Filament\Provider\Widgets')
            ->widgets([])
            ->navigationItems([
                // The store's activities and games, straight from the menu (a tab of the store page).
                NavigationItem::make('activities')
                    ->label(fn () => __('provider.nav.activities'))
                    ->icon('heroicon-o-puzzle-piece')
                    ->group(ProviderNavGroup::Venues)
                    ->sort(2)
                    ->url(fn () => VenueResource::activitiesUrl() ?? '#')
                    ->isActiveWhen(fn () => request()->routeIs('filament.provider.resources.venues.view') && request()->query('relation') === '0')
                    ->visible(fn () => VenueResource::activitiesUrl() !== null && ProviderAccess::canView('venues')),
                NavigationItem::make('profile')
                    ->label(fn () => __('provider.profile.title'))
                    ->icon('heroicon-o-identification')
                    ->group(ProviderNavGroup::Account)
                    ->sort(1)
                    ->url(fn () => EditProviderProfile::getUrl())
                    ->isActiveWhen(fn () => request()->routeIs('filament.provider.tenant.profile'))
                    ->visible(fn () => ProviderAccess::canView('profile', 'profile.manage')),
            ])
            ->userMenuItems([
                Action::make('switch_locale')
                    ->label(fn () => app()->getLocale() === 'ar' ? 'English' : 'العربية')
                    ->icon('heroicon-o-language')
                    ->url(fn () => route('admin.locale', app()->getLocale() === 'ar' ? 'en' : 'ar')),
            ])
            // Separate from the admin panel's `web` guard (see config/auth.php).
            ->authGuard('provider')
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.head'))
            ->renderHook(PanelsRenderHook::CONTENT_START, fn () => view('filament.provider.banners'))
            ->renderHook(PanelsRenderHook::SIMPLE_PAGE_END, fn () => view('filament.provider.auth-footer'))
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                SetAdminLocale::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            // Same as the admin panel: only the locale re-runs on Livewire updates.
            // Never make the session stack persistent (it logs the user out).
            ->persistentMiddleware([SetAdminLocale::class])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
