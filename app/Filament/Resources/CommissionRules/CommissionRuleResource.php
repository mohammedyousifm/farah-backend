<?php

namespace App\Filament\Resources\CommissionRules;

use App\Filament\Concerns\ModuleResource;
use App\Filament\NavGroup;
use App\Filament\Resources\CommissionRules\Pages\ManageCommissionRules;
use App\Filament\Support\Act;
use App\Filament\Support\Fmt;
use App\Models\Category;
use App\Models\CommissionRule;
use App\Models\Provider;
use App\Models\Sector;
use App\Services\CommissionService;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Commission rules are versioned: history is never edited. A change creates a new
 * version via CommissionService::newVersion, which closes the current one.
 */
class CommissionRuleResource extends Resource
{
    use ModuleResource;

    protected static ?string $model = CommissionRule::class;

    protected static string $permissionModule = 'commissions';

    protected static string $langKey = 'commission_rules';

    protected static string|\UnitEnum|null $navigationGroup = NavGroup::Finance;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-receipt-percent';

    protected static ?int $navigationSort = 70;

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /** @return array<string, string> */
    public static function typeOptions(): array
    {
        return collect(CommissionRule::TYPES)->mapWithKeys(fn ($t) => [$t => __("admin.commission_types.{$t}")])->all();
    }

    /** @return array<string, string> */
    public static function scopeOptions(): array
    {
        return collect(CommissionRule::SCOPES)->mapWithKeys(fn ($s) => [$s => __("admin.scopes.{$s}")])->all();
    }

    /** @return array<int, string> */
    public static function targetOptions(?string $scope): array
    {
        $col = Fmt::col('name');

        return match ($scope) {
            'sector' => Sector::orderBy('sort_order')->pluck($col, 'id')->all(),
            'category' => Category::with('sector')->orderBy('sector_id')->get()
                ->mapWithKeys(fn (Category $c) => [$c->id => ($c->sector?->tr('name') ?? '').' / '.$c->tr('name')])->all(),
            'provider' => Provider::orderBy('name_ar')->pluck('name_ar', 'id')->all(),
            default => [],
        };
    }

    /** Fields shared by "create" and "new version". */
    /** @param  CommissionRule|null  $current  when set (new version), the start must be after the current version's start. */
    public static function rateFields(?CommissionRule $current = null): array
    {
        $showRate = fn (Get $get) => in_array($get('type'), ['percentage', 'percentage_plus_fixed'], true);
        $showFixed = fn (Get $get) => in_array($get('type'), ['fixed', 'percentage_plus_fixed'], true);

        return [
            Select::make('type')->label(__('admin.fields.commission_type'))->options(static::typeOptions())
                ->default('percentage')->required()->live(),
            TextInput::make('rate')->label(__('admin.fields.rate'))->numeric()->minValue(0)->maxValue(100)->suffix('%')
                ->default(0)->visible($showRate)->required($showRate),
            TextInput::make('fixed_amount')->label(__('admin.fields.fixed_amount'))->numeric()->minValue(0)->suffix(__('admin.currency'))
                ->default(0)->visible($showFixed)->required($showFixed),
            DateTimePicker::make('effective_from')->label(__('admin.fields.effective_from'))->default(now())->required()->seconds(false)
                ->when($current?->effective_from, fn (DateTimePicker $c) => $c
                    ->minDate($current->effective_from)
                    ->after($current->effective_from->toDateTimeString())
                    ->helperText(__('admin.hints.commission_after', ['date' => $current->effective_from->format('Y-m-d H:i')]))),
            Textarea::make('notes')->label(__('admin.fields.notes'))->rows(2)->columnSpanFull(),
        ];
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('name')->label(__('admin.fields.name'))->required()->maxLength(255)->columnSpanFull(),
            Select::make('scope')->label(__('admin.fields.scope'))->options(static::scopeOptions())
                ->default('global')->required()->live()
                ->afterStateUpdated(fn (callable $set) => $set('scope_id', null)),
            Select::make('scope_id')->label(__('admin.fields.scope_target'))->searchable()
                ->options(fn (Get $get) => static::targetOptions($get('scope')))
                ->visible(fn (Get $get) => filled($get('scope')) && $get('scope') !== 'global')
                ->required(fn (Get $get) => filled($get('scope')) && $get('scope') !== 'global'),
            ...static::rateFields(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('creator'))
            ->defaultSort('effective_from', 'desc')
            ->columns([
                TextColumn::make('name')->label(__('admin.fields.name'))->searchable()->weight('bold')
                    ->description(fn (CommissionRule $r) => $r->scopeTarget()),
                TextColumn::make('scope')->label(__('admin.fields.scope'))->badge()->color('gray')
                    ->formatStateUsing(fn ($state) => __("admin.scopes.{$state}")),
                TextColumn::make('type')->label(__('admin.fields.commission_type'))
                    ->formatStateUsing(fn ($state) => __("admin.commission_types.{$state}")),
                TextColumn::make('rate')->label(__('admin.fields.rate'))->suffix('%'),
                TextColumn::make('fixed_amount')->label(__('admin.fields.fixed_amount'))->formatStateUsing(fn ($state) => Fmt::sar($state)),
                TextColumn::make('version')->label(__('admin.fields.version'))->badge()->color('info')->prefix('v'),
                TextColumn::make('effective_from')->label(__('admin.fields.effective_from'))->dateTime()->sortable(),
                TextColumn::make('effective_to')->label(__('admin.fields.effective_to'))->dateTime()->placeholder('-'),
                IconColumn::make('is_current')->label(__('admin.fields.is_current'))->boolean()
                    ->state(fn (CommissionRule $r) => $r->is_active && $r->effective_to === null),
                IconColumn::make('is_active')->label(__('admin.fields.is_active'))->boolean(),
                TextColumn::make('creator.name')->label(__('admin.fields.created_by'))->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('scope')->label(__('admin.fields.scope'))->options(static::scopeOptions()),
                TernaryFilter::make('current')->label(__('admin.fields.is_current'))
                    ->queries(
                        true: fn (Builder $q) => $q->where('is_active', true)->whereNull('effective_to'),
                        false: fn (Builder $q) => $q->where(fn ($w) => $w->where('is_active', false)->orWhereNotNull('effective_to')),
                    ),
                TernaryFilter::make('is_active')->label(__('admin.fields.is_active')),
            ])
            ->recordActions([
                Action::make('new_version')->label(__('admin.actions.new_version'))->icon('heroicon-o-document-duplicate')->color('primary')
                    ->visible(fn (CommissionRule $r) => static::canManage() && $r->effective_to === null && $r->is_active)
                    ->modalDescription(__('admin.hints.commission_new_version'))
                    ->fillForm(fn (CommissionRule $r) => [
                        'type' => $r->type, 'rate' => $r->rate, 'fixed_amount' => $r->fixed_amount,
                        'effective_from' => $r->effective_from && $r->effective_from->gte(now()) ? $r->effective_from->copy()->addDay() : now(), 'notes' => $r->notes,
                    ])
                    ->schema(fn (CommissionRule $record) => static::rateFields($record))
                    ->action(function (CommissionRule $record, array $data) {
                        $from = Carbon::parse($data['effective_from']);
                        unset($data['effective_from']);
                        $data['rate'] = (float) ($data['rate'] ?? 0);
                        $data['fixed_amount'] = (float) ($data['fixed_amount'] ?? 0);
                        Act::run(fn () => app(CommissionService::class)->newVersion($record, $data, $from));
                    }),
                Action::make('deactivate')->label(__('admin.actions.deactivate'))->icon('heroicon-o-no-symbol')->color('danger')
                    ->visible(fn (CommissionRule $r) => static::canManage() && $r->is_active)
                    ->requiresConfirmation()
                    ->action(fn (CommissionRule $record) => Act::run(fn () => $record->update(['is_active' => false]))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageCommissionRules::route('/')];
    }
}
