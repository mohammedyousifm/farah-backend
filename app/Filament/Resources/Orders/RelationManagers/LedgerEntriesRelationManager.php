<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Filament\Concerns\ModuleRelationManager;
use App\Filament\Support\Fmt;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Ledger lines produced by this order (sale, commission, refunds, reversals). Immutable. */
class LedgerEntriesRelationManager extends RelationManager
{
    use ModuleRelationManager;

    protected static string $relationship = 'ledgerEntries';

    protected static string $permissionModule = 'orders';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.orders.ledger_entries');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('settlement'))
            ->defaultSort('id')
            ->paginated(false)
            ->columns([
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime('Y-m-d H:i'),
                TextColumn::make('account')->label(__('admin.fields.account'))->badge()
                    ->formatStateUsing(fn ($state) => __("admin.ledger_accounts.{$state}"))
                    ->color(fn ($state) => $state === 'platform' ? 'primary' : 'gray'),
                TextColumn::make('type')->label(__('admin.fields.type'))->badge(),
                TextColumn::make('amount')->label(__('admin.fields.amount'))->formatStateUsing(fn ($state) => Fmt::sar($state))
                    ->color(fn ($state) => $state < 0 ? 'danger' : 'success'),
                TextColumn::make('description')->label(__('admin.fields.description'))->limit(50)->placeholder('-'),
                TextColumn::make('available_at')->label(__('admin.fields.available_at'))->dateTime('Y-m-d')->placeholder('-'),
                TextColumn::make('settlement.number')->label(__('admin.fields.settlement'))->placeholder('-'),
            ]);
    }
}
