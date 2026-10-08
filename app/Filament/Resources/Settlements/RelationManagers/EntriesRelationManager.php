<?php

namespace App\Filament\Resources\Settlements\RelationManagers;

use App\Filament\Concerns\ModuleRelationManager;
use App\Filament\Resources\Ledger\LedgerEntryResource;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Ledger entries grouped into this settlement (read-only). */
class EntriesRelationManager extends RelationManager
{
    use ModuleRelationManager;

    protected static string $relationship = 'entries';

    protected static string $permissionModule = 'settlements';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.resources.ledger.plural');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['order', 'settlement']))
            ->defaultSort('id')
            ->columns(LedgerEntryResource::columns(withProvider: false));
    }
}
