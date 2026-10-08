<?php

namespace App\Providers\Filament;

use App\Filament\NavGroup;
use App\Filament\Pages\Dashboard;
use App\Filament\Support\Brand;
use App\Http\Middleware\SetAdminLocale;
use Filament\Actions\Action;
use Filament\FontProviders\GoogleFontProvider;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Tables\Table;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    /** Table defaults shared by both panels (resources override them where they need to). */
    public function boot(): void
    {
        Table::configureUsing(fn (Table $table) => $table
            ->emptyStateIcon('heroicon-o-inbox')
            ->emptyStateDescription(fn () => __('admin.empty.description'))
            ->paginationPageOptions([10, 25, 50, 100])
            ->defaultPaginationPageOption(25)
            ->striped());
    }

    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            // SPA navigation: pages swap in place (wire:navigate) instead of full reloads.
            ->spa()
            // The language switch changes <html dir/lang>, so it needs a full page load.
            ->spaUrlExceptions(['*/admin-locale/*'])
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
            ->navigationGroups(collect(NavGroup::cases())
                ->mapWithKeys(fn (NavGroup $group) => [$group->name => NavigationGroup::make()->label(fn () => $group->getLabel())->collapsible(false)])
                ->all())
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\Filament\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\Filament\Pages')
            ->pages([Dashboard::class])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\Filament\Widgets')
            ->widgets([])
            ->userMenuItems([
                Action::make('switch_locale')
                    ->label(fn () => app()->getLocale() === 'ar' ? 'English' : 'العربية')
                    ->icon('heroicon-o-language')
                    ->url(fn () => route('admin.locale', app()->getLocale() === 'ar' ? 'en' : 'ar')),
            ])
            ->renderHook(PanelsRenderHook::HEAD_END, fn () => view('filament.head'))
            ->renderHook(PanelsRenderHook::SIMPLE_PAGE_END, fn () => view('filament.provider.auth-footer', ['showHome' => false]))
            ->globalSearchKeyBindings(['command+k', 'ctrl+k'])
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
            // Only the locale needs to re-run on Livewire updates. Making the whole
            // session stack persistent restarts the session and logs the admin out.
            ->persistentMiddleware([SetAdminLocale::class])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
