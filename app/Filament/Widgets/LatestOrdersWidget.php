<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\Fmt;
use App\Models\Order;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;

class LatestOrdersWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected static ?int $sort = 5;

    protected int|string|array $columnSpan = 'full';

    public function table(Table $table): Table
    {
        return $table
            ->heading(__('admin.kpi.latest_orders'))
            ->query(Order::query()->with(['venue', 'provider', 'user'])->latest('id'))
            ->paginated([8])
            ->defaultPaginationPageOption(8)
            ->columns([
                TextColumn::make('number')->label(__('admin.fields.number'))->weight('bold'),
                TextColumn::make('venue.name_ar')->formatStateUsing(Fmt::localized('venue.name'))->label(__('admin.fields.venue'))->limit(30),
                TextColumn::make('provider.name_ar')->formatStateUsing(Fmt::localized('provider.name'))->label(__('admin.fields.provider'))->limit(25),
                TextColumn::make('customer_name')->label(__('admin.fields.customer'))->placeholder('-'),
                TextColumn::make('channel')->label(__('admin.fields.channel'))->badge()->formatStateUsing(fn ($state) => __("admin.channels.{$state}")),
                TextColumn::make('total')->label(__('admin.fields.total'))->money('SAR'),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge(),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->since(),
            ])
            ->recordUrl(fn (Order $record) => OrderResource::getUrl('view', ['record' => $record]));
    }

    public static function canView(): bool
    {
        $user = auth()->user();

        return (bool) ($user?->can('orders.view') || $user?->can('orders.manage'));
    }
}
