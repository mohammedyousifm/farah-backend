<?php

namespace App\Filament\Provider\Widgets;

use App\Enums\BookingStatus;
use App\Enums\VenueStatus;
use App\Filament\Provider\Support\ProviderAccess;
use App\Filament\Support\Fmt;
use App\Models\Booking;
use App\Services\AvailabilityService;
use App\Services\LedgerService;
use Carbon\CarbonImmutable;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;

class ProviderStatsWidget extends StatsOverviewWidget
{
    protected static bool $isDiscovered = false;

    protected static ?int $sort = 1;

    protected int|string|array $columnSpan = 'full';

    private const PAID = ['confirmed', 'completed', 'partially_refunded'];

    protected function getColumns(): int
    {
        return 4;
    }

    protected function getStats(): array
    {
        $provider = ProviderAccess::provider();
        if (! $provider) {
            return [];
        }

        $byStatus = $provider->venues()->toBase()->selectRaw('status, COUNT(*) as total')->groupBy('status')->pluck('total', 'status');
        $statusLine = collect([VenueStatus::Published, VenueStatus::PendingReview, VenueStatus::Draft, VenueStatus::Rejected])
            ->map(fn (VenueStatus $s) => $s->getLabel().': '.(int) ($byStatus[$s->value] ?? 0))
            ->implode(' · ');

        $stats = [
            Stat::make(__('provider.dashboard.venues'), number_format((int) $byStatus->sum()))
                ->description($statusLine)->icon('heroicon-o-building-storefront')->color('primary'),
        ];

        $monthStart = now()->startOfMonth();
        $paid = fn () => $provider->orders()->whereIn('status', self::PAID);

        if (ProviderAccess::allows('orders.view')) {
            // "Today" is the business day (config farah.timezone), not the UTC date.
            $today = CarbonImmutable::now(AvailabilityService::timezone())->startOfDay();
            $bookings = fn () => Booking::where('provider_id', $provider->id)
                ->whereIn('status', [BookingStatus::Confirmed, BookingStatus::CheckedIn, BookingStatus::Completed]);

            $stats[] = Stat::make(__('provider.dashboard.bookings_today'), number_format($bookings()->whereBetween('starts_at', [$today->utc(), $today->endOfDay()->utc()])->count()))
                ->description(__('provider.dashboard.bookings_upcoming', ['count' => $bookings()->where('starts_at', '>', now())->count()]))
                ->icon('heroicon-o-clipboard-document-check')->color('info');
            $stats[] = Stat::make(__('provider.dashboard.orders_month'), number_format($paid()->where('confirmed_at', '>=', $monthStart)->count()))
                ->icon('heroicon-o-calendar-days');
        }

        if (ProviderAccess::allows('finance.view')) {
            $balances = app(LedgerService::class)->balances($provider);
            $stats[] = Stat::make(__('provider.dashboard.revenue_month'), Fmt::sar($paid()->where('confirmed_at', '>=', $monthStart)->sum('total')))
                ->description(__('provider.dashboard.net').': '.Fmt::sar($paid()->where('confirmed_at', '>=', $monthStart)->sum('provider_net')))
                ->icon('heroicon-o-banknotes')->color('success');
            $stats[] = Stat::make(__('provider.balances.available'), Fmt::sar($balances['available']))
                ->description(__('provider.balances.available_hint'))->icon('heroicon-o-wallet')->color('success');
            $stats[] = Stat::make(__('provider.balances.pending'), Fmt::sar($balances['pending']))
                ->description(__('provider.balances.pending_hint'))->icon('heroicon-o-clock')->color('warning');
        }

        $stats[] = Stat::make(__('provider.dashboard.rating'), number_format((float) $provider->rating_avg, 1).' ★')
            ->description(__('provider.dashboard.reviews_count', ['count' => (int) $provider->rating_count]))
            ->icon('heroicon-o-star')->color('warning');

        return $stats;
    }
}
