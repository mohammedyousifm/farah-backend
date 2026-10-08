<?php

namespace App\Filament\Resources\Providers\RelationManagers;

use App\Enums\VenueStatus;
use App\Filament\Concerns\ModuleRelationManager;
use App\Filament\Resources\Venues\VenueResource;
use App\Filament\Support\Fmt;
use App\Models\Venue;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class VenuesRelationManager extends RelationManager
{
    use ModuleRelationManager;

    protected static string $relationship = 'venues';

    protected static string $permissionModule = 'venues';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.resources.venues.plural');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['city', 'activities.sector'])->withCount('resources'))
            ->defaultSort('created_at', 'desc')
            ->recordUrl(fn (Venue $record) => VenueResource::getUrl('view', ['record' => $record]))
            ->columns([
                TextColumn::make('name_ar')->formatStateUsing(Fmt::localized('name'))->label(__('admin.fields.name'))->searchable(['name_ar', 'name_en'])->weight('bold')
                    ->description(fn (Venue $record) => $record->name_en),
                TextColumn::make('sectors')->label(__('admin.fields.sectors'))->badge()->color('gray')
                    ->state(fn (Venue $record) => $record->activities->map(fn ($a) => $a->sector?->tr('name'))->filter()->unique()->values()->all()),
                TextColumn::make('city.name_ar')->formatStateUsing(Fmt::localized('city.name'))->label(__('admin.fields.city'))->placeholder('-'),
                TextColumn::make('resources_count')->label(__('admin.fields.resources'))->badge()->color('info'),
                TextColumn::make('min_price')->label(__('admin.fields.min_price'))->formatStateUsing(fn ($state) => Fmt::sar($state)),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge(),
                IconColumn::make('is_featured')->label(__('admin.fields.is_featured'))->boolean(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))->options(VenueStatus::class),
            ]);
    }
}
