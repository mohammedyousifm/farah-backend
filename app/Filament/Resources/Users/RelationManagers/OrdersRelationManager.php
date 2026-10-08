<?php

namespace App\Filament\Resources\Users\RelationManagers;

use App\Enums\OrderStatus;
use App\Filament\Concerns\ModuleRelationManager;
use App\Filament\Support\Fmt;
use App\Models\Order;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** The customer's orders (read-only; open the order for actions). */
class OrdersRelationManager extends RelationManager
{
    use ModuleRelationManager;

    protected static string $relationship = 'orders';

    protected static string $permissionModule = 'users';

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
        $orderResource = 'App\Filament\Resources\Orders\OrderResource';

        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['venue', 'provider']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('admin.fields.number'))->weight('bold')->searchable(),
                TextColumn::make('venue.name_ar')->formatStateUsing(Fmt::localized('venue.name'))->label(__('admin.fields.venue'))->limit(30),
                TextColumn::make('provider.name_ar')->formatStateUsing(Fmt::localized('provider.name'))->label(__('admin.fields.provider'))->limit(25),
                TextColumn::make('total')->label(__('admin.fields.total'))->formatStateUsing(fn ($state) => Fmt::sar($state)),
                TextColumn::make('refunded_amount')->label(__('admin.fields.refunded_amount'))->formatStateUsing(fn ($state) => Fmt::sar($state))->toggleable(),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge(),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))->options(OrderStatus::class),
            ])
            ->recordUrl(fn (Order $record) => class_exists($orderResource) && auth()->user()?->can('orders.view')
                ? $orderResource::getUrl('view', ['record' => $record])
                : null);
    }
}
