<?php

namespace App\Filament\Resources\Providers\RelationManagers;

use App\Filament\Concerns\ModuleRelationManager;
use App\Filament\Resources\Settlements\SettlementResource;
use App\Filament\Support\Fmt;
use App\Models\Settlement;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class SettlementsRelationManager extends RelationManager
{
    use ModuleRelationManager;

    protected static string $relationship = 'settlements';

    protected static string $permissionModule = 'settlements';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.resources.settlements.plural');
    }

    public function table(Table $table): Table
    {
        $money = fn ($state) => Fmt::sar($state);

        return $table
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (Settlement $record) => SettlementResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('number')->label(__('admin.fields.number'))->weight('bold'),
                TextColumn::make('period_from')->label(__('admin.fields.period'))->date()
                    ->description(fn (Settlement $record) => $record->period_to?->toDateString()),
                TextColumn::make('gross_amount')->label(__('admin.fields.gross_amount'))->formatStateUsing($money),
                TextColumn::make('commission_amount')->label(__('admin.fields.commission'))->formatStateUsing($money),
                TextColumn::make('refunds_amount')->label(__('admin.fields.refunds_amount'))->formatStateUsing($money),
                TextColumn::make('net_amount')->label(__('admin.fields.net_amount'))->formatStateUsing($money)->weight('bold'),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge(),
                TextColumn::make('paid_at')->label(__('admin.fields.paid_at'))->dateTime()->placeholder('-'),
            ]);
    }
}
