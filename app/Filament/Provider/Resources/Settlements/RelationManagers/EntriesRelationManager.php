<?php

namespace App\Filament\Provider\Resources\Settlements\RelationManagers;

use App\Filament\Provider\Concerns\ProviderRelationManager;
use App\Filament\Support\Fmt;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class EntriesRelationManager extends RelationManager
{
    use ProviderRelationManager;

    protected static string $relationship = 'entries';

    protected static string $area = 'finance';

    protected static ?string $managePermission = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('provider.settlements.entries');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->where('account', 'provider')->with('order:id,number'))
            ->defaultSort('id')
            ->columns([
                TextColumn::make('created_at')->label(__('provider.fields.date'))->dateTime('Y-m-d'),
                TextColumn::make('type')->label(__('provider.fields.type'))->badge(),
                TextColumn::make('description')->label(__('provider.fields.description'))->limit(50)->placeholder('-'),
                TextColumn::make('order.number')->label(__('provider.fields.order'))->placeholder('-'),
                TextColumn::make('amount')->label(__('provider.fields.amount'))->formatStateUsing(fn ($state) => Fmt::sar($state))
                    ->color(fn ($state) => $state < 0 ? 'danger' : 'success')
                    ->summarize(Sum::make()->label(__('provider.fields.total'))->formatStateUsing(fn ($state) => Fmt::sar($state))),
            ]);
    }
}
