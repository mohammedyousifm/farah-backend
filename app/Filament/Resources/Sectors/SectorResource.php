<?php

namespace App\Filament\Resources\Sectors;

use App\Filament\Concerns\ModuleResource;
use App\Filament\NavGroup;
use App\Filament\Resources\Sectors\Pages\CreateSector;
use App\Filament\Resources\Sectors\Pages\EditSector;
use App\Filament\Resources\Sectors\Pages\ListSectors;
use App\Filament\Resources\Sectors\RelationManagers\CategoriesRelationManager;
use App\Filament\Resources\Sectors\RelationManagers\FieldsRelationManager;
use App\Models\Sector;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class SectorResource extends Resource
{
    use ModuleResource;

    protected static ?string $model = Sector::class;

    protected static string $permissionModule = 'sectors';

    protected static string $langKey = 'sectors';

    protected static string|\UnitEnum|null $navigationGroup = NavGroup::Catalog;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?int $navigationSort = 20;

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columns(2)->columnSpanFull()->schema([
                TextInput::make('name_ar')->label(__('admin.fields.name_ar'))->required()->maxLength(255),
                TextInput::make('name_en')->label(__('admin.fields.name_en'))->required()->maxLength(255)
                    ->live(onBlur: true)
                    ->afterStateUpdated(fn ($state, callable $set, $get) => blank($get('slug')) ? $set('slug', Str::slug((string) $state)) : null),
                TextInput::make('slug')->label(__('admin.fields.slug'))->required()->unique(ignoreRecord: true)->maxLength(255),
                TextInput::make('icon')->label(__('admin.fields.icon'))->helperText(__('admin.hints.icon'))->maxLength(255),
                Textarea::make('description_ar')->label(__('admin.fields.description_ar'))->rows(3),
                Textarea::make('description_en')->label(__('admin.fields.description_en'))->rows(3),
                FileUpload::make('image')->label(__('admin.fields.image'))->image()->disk('public')->directory('sectors'),
                TextInput::make('sort_order')->label(__('admin.fields.sort_order'))->numeric()->default(0),
                Toggle::make('requires_license')->label(__('admin.fields.requires_license')),
                Toggle::make('is_active')->label(__('admin.fields.is_active'))->default(true),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                ImageColumn::make('image')->label('')->disk('public')->circular()->size(36),
                TextColumn::make('name_ar')->label(__('admin.fields.name_ar'))->searchable()->weight('bold'),
                TextColumn::make('name_en')->label(__('admin.fields.name_en'))->searchable(),
                TextColumn::make('categories_count')->label(__('admin.resources.categories.plural'))->counts('categories')->badge(),
                TextColumn::make('fields_count')->label(__('admin.fields.dynamic_fields'))->counts('fields')->badge()->color('info'),
                TextColumn::make('activities_count')->label(__('admin.fields.activities'))->counts('activities')->badge()->color('gray'),
                IconColumn::make('requires_license')->label(__('admin.fields.requires_license'))->boolean(),
                ToggleColumn::make('is_active')->label(__('admin.fields.is_active'))->disabled(fn () => ! static::canManage()),
            ])
            ->recordActions([EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [CategoriesRelationManager::class, FieldsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSectors::route('/'),
            'create' => CreateSector::route('/create'),
            'edit' => EditSector::route('/{record}/edit'),
        ];
    }
}
