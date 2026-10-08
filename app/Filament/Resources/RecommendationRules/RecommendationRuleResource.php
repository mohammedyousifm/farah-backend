<?php

namespace App\Filament\Resources\RecommendationRules;

use App\Filament\Concerns\ModuleResource;
use App\Filament\NavGroup;
use App\Filament\Resources\RecommendationRules\Pages\ManageRecommendationRules;
use App\Filament\Support\Fmt;
use App\Models\RecommendationRule;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Weighted strategies used by RecommendationService ("recommended for you").
 * Params read by the service: limit (all), days (trending), min_reviews (top_rated).
 */
class RecommendationRuleResource extends Resource
{
    use ModuleResource;

    protected static ?string $model = RecommendationRule::class;

    protected static string $permissionModule = 'content';

    protected static string $langKey = 'recommendation_rules';

    protected static string|\UnitEnum|null $navigationGroup = NavGroup::Content;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-sparkles';

    protected static ?int $navigationSort = 40;

    /** @return array<string, string> */
    public static function strategies(): array
    {
        // "upcoming" belonged to dated events; the service treats it as "latest", so it is no longer offered.
        return collect(RecommendationRule::STRATEGIES)->reject(fn ($s) => $s === 'upcoming')->push('latest')->unique()
            ->mapWithKeys(fn ($s) => [$s => __("admin.strategies.{$s}")])->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label(__('admin.fields.name'))->required()->maxLength(255),
            Select::make('strategy')->label(__('admin.fields.strategy'))->options(static::strategies())->required()->live(),
            TextInput::make('weight')->label(__('admin.fields.weight'))->numeric()->minValue(0)->maxValue(1000)->default(10)->required()
                ->helperText(__('admin.hints.rule_weight')),
            Toggle::make('is_active')->label(__('admin.fields.is_active'))->default(true)->inline(false),
            KeyValue::make('params')->label(__('admin.fields.params'))->columnSpanFull()
                ->keyLabel(__('admin.fields.key'))->valueLabel(__('admin.fields.value'))
                ->helperText(fn (Get $get) => __('admin.hints.rule_params').' '.match ($get('strategy')) {
                    'trending' => __('admin.hints.rule_params_trending'),
                    'top_rated' => __('admin.hints.rule_params_top_rated'),
                    default => '',
                }),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('weight', 'desc')
            ->columns([
                TextColumn::make('name')->label(__('admin.fields.name'))->searchable()->weight('bold'),
                TextColumn::make('strategy')->label(__('admin.fields.strategy'))->badge()
                    ->formatStateUsing(fn ($state) => Fmt::label('strategies', $state)),
                TextColumn::make('weight')->label(__('admin.fields.weight'))->sortable(),
                TextColumn::make('params')->label(__('admin.fields.params'))->placeholder('-')
                    ->state(fn (RecommendationRule $r) => collect($r->params ?? [])->map(fn ($v, $k) => $k.'='.(is_scalar($v) ? $v : json_encode($v)))->implode(', ') ?: null),
                ToggleColumn::make('is_active')->label(__('admin.fields.is_active'))->disabled(fn () => ! static::canManage()),
            ])
            ->filters([
                SelectFilter::make('strategy')->label(__('admin.fields.strategy'))->options(static::strategies()),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageRecommendationRules::route('/')];
    }
}
