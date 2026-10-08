<?php

namespace App\Filament\Provider\Resources\Orders\RelationManagers;

use App\Filament\Provider\Concerns\ProviderRelationManager;
use App\Filament\Support\Fmt;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Refunds are decided by the platform (admin); the provider sees them read-only. */
class RefundsRelationManager extends RelationManager
{
    use ProviderRelationManager;

    protected static string $relationship = 'refunds';

    protected static string $area = 'orders';

    protected static ?string $managePermission = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('provider.orders.refunds');
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->emptyStateDescription(__('provider.orders.refunds_hint'))
            ->columns([
                TextColumn::make('number')->label(__('provider.fields.number'))->weight('bold'),
                TextColumn::make('type')->label(__('provider.fields.type'))->formatStateUsing(fn ($state) => __("provider.orders.refund_types.{$state}")),
                TextColumn::make('amount')->label(__('provider.fields.amount'))->formatStateUsing(fn ($state) => Fmt::sar($state)),
                TextColumn::make('provider_debit')->label(__('provider.fields.provider_debit'))->formatStateUsing(fn ($state) => Fmt::sar($state)),
                TextColumn::make('status')->label(__('provider.fields.status'))->badge(),
                TextColumn::make('reason')->label(__('provider.fields.reason'))->limit(40)->placeholder('-'),
                TextColumn::make('created_at')->label(__('provider.fields.created_at'))->dateTime('Y-m-d H:i'),
            ]);
    }
}
