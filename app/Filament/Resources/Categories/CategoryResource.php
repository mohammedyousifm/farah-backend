<?php

namespace App\Filament\Resources\Categories;

use App\Filament\Concerns\ModuleResource;
use App\Filament\NavGroup;
use App\Filament\Resources\Categories\Pages\ManageCategories;
use App\Filament\Support\Fmt;
use App\Filament\Support\Upload;
use App\Models\Category;
use App\Models\Sector;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Str;

class CategoryResource extends Resource
{
    use ModuleResource;

    protected static ?string $model = Category::class;

    protected static string $permissionModule = 'sectors';

    protected static string $langKey = 'categories';

    protected static string|\UnitEnum|null $navigationGroup = NavGroup::Catalog;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-tag';

    protected static ?int $navigationSort = 30;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('sector_id')->label(__('admin.fields.sector'))->required()->live()
                ->options(fn () => Sector::orderBy('sort_order')->pluck('name_ar', 'id'))->searchable(),
            Select::make('parent_id')->label(__('admin.fields.parent'))->placeholder('-')
                ->options(fn (Get $get) => Category::where('sector_id', $get('sector_id'))->whereNull('parent_id')->pluck('name_ar', 'id')),
            TextInput::make('name_ar')->label(__('admin.fields.name_ar'))->required(),
            TextInput::make('name_en')->label(__('admin.fields.name_en'))->required()
                ->live(onBlur: true)
                ->afterStateUpdated(fn ($state, callable $set, $get) => blank($get('slug')) ? $set('slug', Str::slug((string) $state)) : null),
            TextInput::make('slug')->label(__('admin.fields.slug'))->required()->unique(ignoreRecord: true),
            TextInput::make('icon')->label(__('admin.fields.icon')),
            FileUpload::make('image')->label(__('admin.fields.image'))->acceptedFileTypes(Upload::IMAGES)->maxSize(4096)->disk('public')->directory('categories'),
            TextInput::make('sort_order')->label(__('admin.fields.sort_order'))->numeric()->default(0),
            Toggle::make('is_active')->label(__('admin.fields.is_active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['sector', 'parent']))
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('name_ar')->label(__('admin.fields.name_ar'))->searchable()->weight('bold'),
                TextColumn::make('name_en')->label(__('admin.fields.name_en'))->searchable(),
                TextColumn::make('sector.name_ar')->formatStateUsing(Fmt::localized('sector.name'))->label(__('admin.fields.sector'))->badge()->color('gray'),
                TextColumn::make('parent.name_ar')->formatStateUsing(Fmt::localized('parent.name'))->label(__('admin.fields.parent'))->placeholder('-'),
                TextColumn::make('activities_count')->label(__('admin.fields.activities'))->counts('activities'),
                ToggleColumn::make('is_active')->label(__('admin.fields.is_active'))->disabled(fn () => ! static::canManage()),
            ])
            ->filters([
                SelectFilter::make('sector_id')->label(__('admin.fields.sector'))->relationship('sector', 'name_ar')->getOptionLabelFromRecordUsing(fn ($record) => Fmt::tr($record, 'name')),
                TernaryFilter::make('is_active')->label(__('admin.fields.is_active')),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCategories::route('/')];
    }
}
