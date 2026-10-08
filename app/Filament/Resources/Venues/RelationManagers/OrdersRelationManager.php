<?php

namespace App\Filament\Resources\Venues\RelationManagers;

use App\Enums\OrderStatus;
use App\Filament\Concerns\ModuleRelationManager;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\Fmt;
use App\Models\Order;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class OrdersRelationManager extends RelationManager
{
    use ModuleRelationManager;

    protected static string $relationship = 'orders';

    protected static string $permissionModule = 'orders';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.resources.orders.plural');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('user')->withCount('bookings'))
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (Order $record) => OrderResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('number')->label(__('admin.fields.number'))->searchable()->weight('bold'),
                TextColumn::make('customer')->label(__('admin.fields.customer'))->state(fn (Order $record) => $record->customerDisplayName()),
                TextColumn::make('bookings_count')->label(__('admin.resources.bookings.plural'))->badge()->color('gray'),
                TextColumn::make('total')->label(__('admin.fields.total'))->formatStateUsing(fn ($state) => Fmt::sar($state))->sortable(),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge(),
                TextColumn::make('channel')->label(__('admin.fields.channel'))->badge()->color('gray')
                    ->formatStateUsing(fn ($state) => __("admin.orders.channels.{$state}")),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime('Y-m-d H:i')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))->options(OrderStatus::class),
            ]);
    }
}
