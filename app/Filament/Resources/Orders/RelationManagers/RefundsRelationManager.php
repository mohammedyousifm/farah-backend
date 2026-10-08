<?php

namespace App\Filament\Resources\Orders\RelationManagers;

use App\Filament\Concerns\ModuleRelationManager;
use App\Filament\Resources\Refunds\RefundResource;
use App\Filament\Support\Fmt;
use App\Models\Refund;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class RefundsRelationManager extends RelationManager
{
    use ModuleRelationManager;

    protected static string $relationship = 'refunds';

    protected static string $permissionModule = 'refunds';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.resources.refunds.plural');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('reviewer'))
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (Refund $record) => RefundResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('number')->label(__('admin.fields.number'))->weight('bold'),
                TextColumn::make('type')->label(__('admin.fields.type'))->formatStateUsing(fn ($state) => __("admin.refunds.types.{$state}")),
                TextColumn::make('amount')->label(__('admin.fields.amount'))->formatStateUsing(fn ($state) => Fmt::sar($state)),
                TextColumn::make('commission_reversed')->label(__('admin.fields.commission_reversed'))->formatStateUsing(fn ($state) => Fmt::sar($state))->toggleable(),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge(),
                TextColumn::make('reason')->label(__('admin.fields.reason'))->limit(40)->placeholder('-'),
                TextColumn::make('reviewer.name')->label(__('admin.fields.reviewed_by'))->placeholder('-'),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime('Y-m-d H:i'),
            ]);
    }
}
