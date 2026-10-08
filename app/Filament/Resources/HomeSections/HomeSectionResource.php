<?php

namespace App\Filament\Resources\HomeSections;

use App\Filament\Concerns\ModuleResource;
use App\Filament\NavGroup;
use App\Filament\Resources\HomeSections\Pages\CreateHomeSection;
use App\Filament\Resources\HomeSections\Pages\EditHomeSection;
use App\Filament\Resources\HomeSections\Pages\ListHomeSections;
use App\Filament\Resources\HomeSections\RelationManagers\ItemsRelationManager;
use App\Filament\Support\Fmt;
use App\Models\Category;
use App\Models\HomeSection;
use App\Models\Sector;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/** App home screen sections (banners, venue rows, ...), ordered and schedulable. */
class HomeSectionResource extends Resource
{
    use ModuleResource;

    protected static ?string $model = HomeSection::class;

    protected static string $permissionModule = 'content';

    protected static string $langKey = 'home_sections';

    protected static string|\UnitEnum|null $navigationGroup = NavGroup::Content;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-group';

    protected static ?int $navigationSort = 10;

    /** @return array<string, string> */
    public static function options(string $group, array $values): array
    {
        return collect($values)->mapWithKeys(fn ($v) => [$v => __("admin.home_sections.{$group}.{$v}")])->all();
    }

    public static function form(Schema $schema): Schema
    {
        $isSource = fn (string ...$sources) => fn (Get $get) => in_array($get('source'), $sources, true);

        return $schema->components([
            Section::make(__('admin.home_sections.content'))->columns(2)->columnSpanFull()->schema([
                TextInput::make('title_ar')->label(__('admin.fields.title_ar'))->maxLength(255),
                TextInput::make('title_en')->label(__('admin.fields.title_en'))->maxLength(255),
                Select::make('type')->label(__('admin.fields.section_type'))->options(static::options('types', HomeSection::TYPES))
                    ->required()->default('venues')->live(),
                Select::make('layout')->label(__('admin.fields.layout'))->options(static::options('layouts', HomeSection::LAYOUTS))
                    ->required()->default('carousel'),
                Select::make('source')->label(__('admin.fields.source'))->options(static::options('sources', array_values(array_diff(HomeSection::SOURCES, ['upcoming']))))
                    ->required()->default('manual')->live()
                    ->helperText(__('admin.hints.section_source')),
                TextInput::make('max_items')->label(__('admin.fields.max_items'))->numeric()->minValue(1)->maxValue(50)->default(10)->required(),
                Select::make('source_params.category_id')->label(__('admin.fields.category'))
                    ->options(fn () => Category::orderBy('sort_order')->pluck(Fmt::col('name'), 'id'))->searchable()
                    ->visible($isSource('category'))->required($isSource('category')),
                Select::make('source_params.sector_id')->label(__('admin.fields.sector'))
                    ->options(fn () => Sector::orderBy('sort_order')->pluck(Fmt::col('name'), 'id'))->searchable()
                    ->visible(fn (Get $get) => $get('source') === 'sector' || $get('type') === 'categories')
                    ->required($isSource('sector')),
            ]),
            Section::make(__('admin.home_sections.schedule'))->columns(3)->columnSpanFull()->schema([
                DateTimePicker::make('starts_at')->label(__('admin.fields.starts_at'))->seconds(false),
                DateTimePicker::make('ends_at')->label(__('admin.fields.ends_at'))->seconds(false)->after('starts_at'),
                Toggle::make('is_active')->label(__('admin.fields.is_active'))->default(true)->inline(false),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->withCount('items'))
            ->defaultSort('sort_order')
            ->reorderable('sort_order', fn () => static::canManage())
            ->columns([
                TextColumn::make('sort_order')->label('#')->width('3rem'),
                TextColumn::make(Fmt::col('title'))->label(__('admin.fields.title'))->placeholder('-')->weight('bold')
                    ->searchable(['title_ar', 'title_en']),
                TextColumn::make('type')->label(__('admin.fields.section_type'))->badge()
                    ->formatStateUsing(fn ($state) => __("admin.home_sections.types.{$state}")),
                TextColumn::make('layout')->label(__('admin.fields.layout'))
                    ->formatStateUsing(fn ($state) => __("admin.home_sections.layouts.{$state}")),
                TextColumn::make('source')->label(__('admin.fields.source'))->color('gray')
                    ->formatStateUsing(fn ($state) => __("admin.home_sections.sources.{$state}")),
                TextColumn::make('items_count')->label(__('admin.fields.items'))->badge()->color('info'),
                TextColumn::make('visibility')->label(__('admin.fields.visibility'))->badge()
                    ->state(fn (HomeSection $r) => static::visibility($r))
                    ->formatStateUsing(fn ($state) => __("admin.home_sections.visibility.{$state}"))
                    ->color(fn ($state) => match ($state) {
                        'live' => 'success',
                        'scheduled' => 'info',
                        'ended' => 'gray',
                        default => 'danger',
                    }),
                TextColumn::make('starts_at')->label(__('admin.fields.starts_at'))->dateTime()->placeholder('-')->toggleable(),
                TextColumn::make('ends_at')->label(__('admin.fields.ends_at'))->dateTime()->placeholder('-')->toggleable(),
                ToggleColumn::make('is_active')->label(__('admin.fields.is_active'))->disabled(fn () => ! static::canManage()),
            ])
            ->filters([
                SelectFilter::make('type')->label(__('admin.fields.section_type'))->options(static::options('types', HomeSection::TYPES)),
                TernaryFilter::make('is_active')->label(__('admin.fields.is_active')),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function visibility(HomeSection $section): string
    {
        return match (true) {
            ! $section->is_active => 'inactive',
            $section->starts_at && $section->starts_at->isFuture() => 'scheduled',
            $section->ends_at && $section->ends_at->isPast() => 'ended',
            default => 'live',
        };
    }

    public static function getRelations(): array
    {
        return [ItemsRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListHomeSections::route('/'),
            'create' => CreateHomeSection::route('/create'),
            'edit' => EditHomeSection::route('/{record}/edit'),
        ];
    }
}
