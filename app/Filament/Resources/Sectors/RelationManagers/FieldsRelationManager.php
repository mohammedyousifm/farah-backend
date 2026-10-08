<?php

namespace App\Filament\Resources\Sectors\RelationManagers;

use App\Filament\Concerns\ModuleRelationManager;
use App\Models\SectorField;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Unique;

/** Dynamic form fields of a sector. Venues and activities do not use them; kept for the sector catalogue. */
class FieldsRelationManager extends RelationManager
{
    use ModuleRelationManager;

    protected static string $relationship = 'fields';

    protected static string $permissionModule = 'sectors';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.fields.dynamic_fields');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('key')->label(__('admin.fields.key'))->required()->maxLength(60)
                ->regex('/^[a-z][a-z0-9_]*$/')->helperText(__('admin.hints.field_key'))
                ->unique('sector_fields', 'key', ignoreRecord: true, modifyRuleUsing: fn (Unique $rule) => $rule->where('sector_id', $this->getOwnerRecord()->id)),
            Select::make('type')->label(__('admin.fields.field_type'))->required()->live()
                ->options(collect(SectorField::TYPES)->mapWithKeys(fn ($t) => [$t => __("admin.field_types.{$t}")])),
            TextInput::make('label_ar')->label(__('admin.fields.label_ar'))->required(),
            TextInput::make('label_en')->label(__('admin.fields.label_en'))->required(),
            TextInput::make('placeholder_ar')->label(__('admin.fields.placeholder_ar')),
            TextInput::make('placeholder_en')->label(__('admin.fields.placeholder_en')),
            Repeater::make('options')->label(__('admin.fields.options'))->columnSpanFull()->columns(3)
                ->visible(fn (Get $get) => in_array($get('type'), ['select', 'multiselect'], true))
                ->required(fn (Get $get) => in_array($get('type'), ['select', 'multiselect'], true))
                ->schema([
                    TextInput::make('value')->label(__('admin.fields.value'))->required(),
                    TextInput::make('label_ar')->label(__('admin.fields.label_ar'))->required(),
                    TextInput::make('label_en')->label(__('admin.fields.label_en'))->required(),
                ])
                ->reorderable()
                ->addActionLabel(__('admin.actions.add_option')),
            TextInput::make('sort_order')->label(__('admin.fields.sort_order'))->numeric()->default(0),
            Toggle::make('is_required')->label(__('admin.fields.is_required')),
            Toggle::make('is_filterable')->label(__('admin.fields.is_filterable'))->helperText(__('admin.hints.filterable')),
            Toggle::make('is_active')->label(__('admin.fields.is_active'))->default(true),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make('key')->label(__('admin.fields.key'))->fontFamily('mono'),
                TextColumn::make('label_ar')->label(__('admin.fields.label_ar')),
                TextColumn::make('type')->label(__('admin.fields.field_type'))->badge()
                    ->formatStateUsing(fn ($state) => __("admin.field_types.{$state}")),
                IconColumn::make('is_required')->label(__('admin.fields.is_required'))->boolean(),
                IconColumn::make('is_filterable')->label(__('admin.fields.is_filterable'))->boolean(),
                ToggleColumn::make('is_active')->label(__('admin.fields.is_active')),
            ])
            ->headerActions([CreateAction::make()])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }
}
