<?php

namespace App\Filament\Provider\Widgets;

use App\Filament\Provider\Resources\Orders\OrderResource;
use App\Filament\Provider\Support\ProviderAccess;
use App\Filament\Support\Fmt;
use App\Models\Order;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class LatestOrdersWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static ?int $sort = 3;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('provider.dashboard.latest_orders'))
            ->query(Order::query()->where('provider_id', ProviderAccess::provider()?->id)->with(['venue', 'user'])->latest('id'))
            ->paginated([8])
            ->defaultPaginationPageOption(8)
            ->columns([
                TextColumn::make('number')->label(__('provider.fields.number'))->weight('bold'),
                TextColumn::make('venue.name_ar')->formatStateUsing(Fmt::localized('venue.name'))->label(__('provider.fields.venue'))->limit(30),
                TextColumn::make('customer')->label(__('provider.fields.customer'))->state(fn (Order $r) => $r->customerDisplayName()),
                TextColumn::make('channel')->label(__('provider.fields.channel'))->badge()->color('gray')
                    ->formatStateUsing(fn ($state) => __("provider.orders.channels.{$state}")),
                TextColumn::make('total')->label(__('provider.fields.total'))->money('SAR'),
                TextColumn::make('status')->label(__('provider.fields.status'))->badge(),
                TextColumn::make('created_at')->label(__('provider.fields.created_at'))->since(),
            ])
            ->recordUrl(fn (Order $record) => OrderResource::getUrl('view', ['record' => $record]));
    }

    public static function canView(): bool
    {
        return ProviderAccess::canView('orders', 'orders.view');
    }
}
