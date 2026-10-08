<?php

namespace App\Filament\Resources\Venues\RelationManagers;

use App\Filament\Concerns\ModuleRelationManager;
use App\Filament\Support\Fmt;
use App\Models\Activity;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/** Activities and their resources (tables, lanes, ...) are managed by the provider; admins see them read-only. */
class ActivitiesRelationManager extends RelationManager
{
    use ModuleRelationManager;

    protected static string $relationship = 'activities';

    protected static string $permissionModule = 'venues';

    public function isReadOnly(): bool
    {
        return true;
    }

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.fields.activities');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['sector', 'category', 'resources']))
            ->paginated(false)
            ->columns([
                TextColumn::make('name_ar')->formatStateUsing(Fmt::localized('name'))->label(__('admin.fields.name'))->weight('bold')
                    ->description(fn (Activity $r) => $r->name_en),
                TextColumn::make('sector.name_ar')->formatStateUsing(Fmt::localized('sector.name'))->label(__('admin.fields.sector'))
                    ->description(fn (Activity $r) => $r->category?->tr('name')),
                TextColumn::make('price_per_hour')->label(__('admin.fields.price_per_hour'))->formatStateUsing(fn ($state) => Fmt::sar($state)),
                TextColumn::make('slot_minutes')->label(__('admin.fields.slot_minutes'))
                    ->formatStateUsing(fn ($state) => __('admin.venues.minutes', ['count' => $state]))
                    ->description(fn (Activity $r) => __('admin.venues.slot_price', ['price' => Fmt::sar($r->slotPrice())])),
                TextColumn::make('slots_range')->label(__('admin.fields.slots_range'))
                    ->state(fn (Activity $r) => $r->min_slots.' – '.$r->max_slots),
                TextColumn::make('capacity')->label(__('admin.fields.capacity'))->placeholder('-'),
                TextColumn::make('resources_list')->label(__('admin.fields.resources'))->badge()->color('info')->placeholder('-')
                    ->state(fn (Activity $r) => $r->resources
                        ->map(fn ($res) => $res->tr('name').($res->is_active ? '' : ' ('.__('admin.venues.inactive').')'))->all()),
                IconColumn::make('is_active')->label(__('admin.fields.is_active'))->boolean(),
            ]);
    }
}
