<?php

namespace App\Filament\Resources\Plans;

use App\Enums\PlanFeature;
use App\Filament\Concerns\ModuleResource;
use App\Filament\NavGroup;
use App\Filament\Resources\Plans\Pages\ManagePlans;
use App\Filament\Support\Fmt;
use App\Models\Plan;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

/**
 * Packages providers subscribe to: name, price, duration and features. One plan can be the free plan, which
 * every provider is on while no paid plan runs (its features apply at once and never end). Editing a paid plan
 * changes what new subscriptions get; running subscriptions keep the features they started with.
 */
class PlanResource extends Resource
{
    use ModuleResource;

    protected static ?string $model = Plan::class;

    protected static string $permissionModule = 'plans';

    protected static string $langKey = 'plans';

    protected static string|\UnitEnum|null $navigationGroup = NavGroup::Finance;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static ?int $navigationSort = 70;

    protected static ?string $recordTitleAttribute = 'name_ar';

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name_ar')->label(__('admin.fields.name_ar'))->required()->maxLength(255),
            TextInput::make('name_en')->label(__('admin.fields.name_en'))->maxLength(255),
            Textarea::make('description_ar')->label(__('admin.fields.description_ar'))->rows(2)->maxLength(1000),
            Textarea::make('description_en')->label(__('admin.fields.description_en'))->rows(2)->maxLength(1000),
            Toggle::make('is_free')->label(__('admin.plans.is_free'))->live()->inline(false)->columnSpanFull()
                ->helperText(__('admin.plans.is_free_hint')),
            // The free plan has no price and no end: both stay stored (0 SAR, the default length) but hidden.
            TextInput::make('price')->label(__('admin.fields.price'))->numeric()->minValue(0)->required()->default(0)->suffix(__('admin.currency'))
                ->hidden(fn (Get $get) => (bool) $get('is_free'))->dehydratedWhenHidden(),
            TextInput::make('duration_days')->label(__('admin.fields.duration_days'))->integer()->minValue(1)->maxValue(730)->required()->default(30)
                ->suffix(__('admin.plans.days'))
                ->hidden(fn (Get $get) => (bool) $get('is_free'))->dehydratedWhenHidden(),
            TextInput::make('sort_order')->label(__('admin.fields.sort_order'))->integer()->default(0),
            Toggle::make('is_active')->label(__('admin.fields.is_active'))->default(true)->inline(false)
                ->helperText(__('admin.plans.active_hint')),
            Repeater::make('items')->label(__('admin.plans.features'))->relationship()
                ->columnSpanFull()->columns(3)->defaultItems(1)
                ->addActionLabel(__('admin.plans.add_feature'))
                ->schema([
                    Select::make('feature')->label(__('admin.plans.feature'))->required()->live()
                        ->options(PlanFeature::options())->distinct()->disableOptionsWhenSelectedInSiblingRepeaterItems(),
                    TextInput::make('value')->label(__('admin.plans.limit'))->integer()->minValue(1)
                        ->visible(fn (Get $get) => PlanFeature::tryFrom((string) $get('feature'))?->hasValue())
                        ->required(fn (Get $get) => PlanFeature::tryFrom((string) $get('feature'))?->hasValue()),
                    TextInput::make('days')->label(__('admin.plans.feature_days'))->integer()->minValue(1)->maxValue(730)
                        ->helperText(__('admin.plans.feature_days_hint'))
                        ->hidden(fn (Get $get) => (bool) $get('../../is_free')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('items')->withCount(['subscriptions as running_count' => fn ($q) => $q->where('status', 'active')->where('ends_at', '>', now())]))
            ->modifyQueryUsing(fn ($query) => $query->orderByDesc('is_free'))
            ->defaultSort('sort_order')
            ->reorderable('sort_order')
            ->columns([
                TextColumn::make(Fmt::col('name'))->label(__('admin.fields.name'))->weight('bold')->searchable(['name_ar', 'name_en'])
                    ->description(fn (Plan $record) => $record->tr('description')),
                TextColumn::make('price')->label(__('admin.fields.price'))
                    ->formatStateUsing(fn ($state, Plan $record) => $record->is_free ? __('admin.plans.free') : Fmt::sar($state))
                    ->badge(fn (Plan $record) => $record->is_free)->color(fn (Plan $record) => $record->is_free ? 'success' : null),
                TextColumn::make('duration_days')->label(__('admin.fields.duration_days'))
                    ->formatStateUsing(fn ($state, Plan $record) => $record->is_free ? __('admin.plans.no_end') : $state.' '.__('admin.plans.days')),
                TextColumn::make('items')->label(__('admin.plans.features'))->badge()->wrap()
                    ->state(fn (Plan $record) => $record->items->map(fn ($item) => $item->feature->getLabel()
                        .($item->value ? " ({$item->value})" : '')
                        .($item->days ? ' · '.__('admin.plans.for_days', ['days' => $item->days]) : ''))->all()),
                TextColumn::make('running_count')->label(__('admin.plans.subscribers'))->badge()->color('success'),
                ToggleColumn::make('is_active')->label(__('admin.fields.is_active'))->disabled(fn () => ! static::canManage()),
            ])
            ->recordActions([
                EditAction::make(),
                // A plan someone subscribed to stays (switch it off instead): its history points at it.
                DeleteAction::make()->visible(fn (Plan $record) => ! $record->subscriptions()->exists()),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManagePlans::route('/')];
    }
}
