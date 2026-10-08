<?php

namespace App\Filament\Resources\Cities;

use App\Filament\Concerns\ModuleResource;
use App\Filament\NavGroup;
use App\Filament\Resources\Cities\Pages\ManageCities;
use App\Models\City;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class CityResource extends Resource
{
    use ModuleResource;

    protected static ?string $model = City::class;

    protected static string $permissionModule = 'sectors';

    protected static string $langKey = 'cities';

    protected static string|\UnitEnum|null $navigationGroup = NavGroup::Catalog;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    protected static ?int $navigationSort = 40;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name_ar')->label(__('admin.fields.name_ar'))->required()->maxLength(255),
            TextInput::make('name_en')->label(__('admin.fields.name_en'))->required()->maxLength(255),
            TextInput::make('region')->label(__('admin.fields.region'))->maxLength(255),
            TextInput::make('sort_order')->label(__('admin.fields.sort_order'))->numeric()->default(0),
            TextInput::make('lat')->label(__('admin.fields.lat'))->numeric(),
            TextInput::make('lng')->label(__('admin.fields.lng'))->numeric(),
            Toggle::make('is_active')->label(__('admin.fields.is_active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('name_ar')->label(__('admin.fields.name_ar'))->searchable(),
                TextColumn::make('name_en')->label(__('admin.fields.name_en'))->searchable(),
                TextColumn::make('region')->label(__('admin.fields.region'))->placeholder('-'),
                TextColumn::make('venues_count')->label(__('admin.resources.venues.plural'))->counts('venues')->badge(),
                ToggleColumn::make('is_active')->label(__('admin.fields.is_active'))->disabled(fn () => ! static::canManage()),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCities::route('/')];
    }
}
