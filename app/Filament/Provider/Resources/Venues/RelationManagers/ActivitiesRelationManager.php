<?php

namespace App\Filament\Provider\Resources\Venues\RelationManagers;

use App\Exceptions\BusinessException;
use App\Filament\Provider\Concerns\ProviderRelationManager;
use App\Filament\Provider\Resources\Venues\VenueResource;
use App\Filament\Provider\Support\ProviderAccess;
use App\Filament\Support\Fmt;
use App\Models\Activity;
use App\Models\Category;
use App\Services\VenueService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * What can be played in the venue (billiards, bowling, ...): hourly price and slot rules.
 * The activity type is a sector the provider is approved for (VenueService::assertSectorAllowed).
 */
class ActivitiesRelationManager extends RelationManager
{
    use ProviderRelationManager;

    protected static string $relationship = 'activities';

    protected static string $area = 'venues';

    protected static ?string $managePermission = 'venues.manage';

    public const SLOT_MINUTES = [30, 60, 90, 120];

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('provider.fields.activities');
    }

    protected function canModifyOwner(): bool
    {
        return ! in_array($this->getOwnerRecord()->status, VenueResource::LOCKED, true);
    }

    public function form(Schema $schema): Schema
    {
        $provider = ProviderAccess::provider();

        return $schema->columns(2)->components([
            Select::make('sector_id')->label(__('provider.fields.activity_type'))->required()->live()
                ->options(fn () => $provider?->approvedSectors()->orderBy('sort_order')->pluck('sectors.'.Fmt::col('name'), 'sectors.id') ?? [])
                ->afterStateUpdated(fn (Set $set) => $set('category_id', null))
                ->helperText(__('provider.venues.sector_hint')),
            Select::make('category_id')->label(__('provider.fields.category'))->searchable()
                ->options(fn (Get $get) => Category::where('sector_id', $get('sector_id'))->where('is_active', true)
                    ->orderBy('sort_order')->pluck(Fmt::col('name'), 'id')),
            TextInput::make('name_ar')->label(__('provider.fields.name_ar'))->required()->maxLength(120),
            TextInput::make('name_en')->label(__('provider.fields.name_en'))->maxLength(120),
            TextInput::make('description_ar')->label(__('provider.fields.description_ar'))->maxLength(1000),
            TextInput::make('description_en')->label(__('provider.fields.description_en'))->maxLength(1000),
            TextInput::make('price_per_hour')->label(__('provider.fields.price_per_hour'))->numeric()->minValue(0)->maxValue(1000000)->required()
                ->suffix(__('provider.currency'))->helperText(__('provider.venues.price_hint')),
            Select::make('slot_minutes')->label(__('provider.fields.slot_minutes'))->required()->default(60)
                ->options(collect(self::SLOT_MINUTES)->mapWithKeys(fn ($m) => [$m => __('provider.venues.minutes', ['count' => $m])]))
                ->helperText(__('provider.venues.slot_hint')),
            TextInput::make('min_slots')->label(__('provider.fields.min_slots'))->integer()->minValue(1)->maxValue(48)->default(1)->required(),
            TextInput::make('max_slots')->label(__('provider.fields.max_slots'))->integer()->minValue(1)->maxValue(48)->default(4)->required()
                ->gte('min_slots')->helperText(__('provider.venues.slots_hint')),
            TextInput::make('capacity')->label(__('provider.fields.capacity'))->integer()->minValue(1)->maxValue(1000)
                ->helperText(__('provider.venues.capacity_hint')),
            Toggle::make('is_active')->label(__('provider.fields.is_active'))->default(true)->inline(false),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('sector')->withCount('resources'))
            ->reorderable('sort_order', fn () => $this->canWrite())
            ->defaultSort('sort_order')
            ->emptyStateHeading(__('provider.venues.no_activities'))
            ->emptyStateDescription(__('provider.venues.no_activities_hint'))
            ->columns([
                TextColumn::make('name_ar')->label(__('provider.fields.name'))->weight('bold')
                    ->state(fn (Activity $r) => Fmt::tr($r, 'name')),
                TextColumn::make('sector.'.Fmt::col('name'))->label(__('provider.fields.activity_type'))->badge()->color('gray'),
                TextColumn::make('price_per_hour')->label(__('provider.fields.price_per_hour'))->formatStateUsing(fn ($state) => Fmt::sar($state)),
                TextColumn::make('slot_minutes')->label(__('provider.fields.slot_minutes'))
                    ->formatStateUsing(fn ($state) => __('provider.venues.minutes', ['count' => $state]))
                    ->description(fn (Activity $r) => Fmt::sar($r->slotPrice())),
                TextColumn::make('slots_range')->label(__('provider.fields.slots_range'))
                    ->state(fn (Activity $r) => $r->min_slots.' - '.$r->max_slots),
                TextColumn::make('resources_count')->label(__('provider.fields.resources'))->numeric()
                    ->color(fn ($state) => $state ? null : 'danger'),
                IconColumn::make('is_active')->label(__('provider.fields.is_active'))->boolean(),
            ])
            ->headerActions([
                CreateAction::make()->label(__('provider.venues.add_activity'))
                    ->using(function (array $data) {
                        $this->assertSector((int) $data['sector_id']);

                        return $this->getOwnerRecord()->activities()->create($data + [
                            'sort_order' => (int) $this->getOwnerRecord()->activities()->max('sort_order') + 1,
                        ]);
                    }),
            ])
            ->recordActions([
                Action::make('add_resources')->label(__('provider.venues.add_resources'))->icon('heroicon-o-squares-plus')
                    ->visible(fn () => $this->canWrite())
                    ->modalDescription(__('provider.venues.add_resources_hint'))
                    ->schema(ResourcesRelationManager::bulkSchema())
                    ->action(function (Activity $record, array $data) {
                        $count = ResourcesRelationManager::bulkCreate($record, $data);
                        Notification::make()->success()->title(__('provider.venues.resources_added', ['count' => $count]))->send();
                    }),
                EditAction::make()->using(function (Activity $record, array $data) {
                    $this->assertSector((int) $data['sector_id']);
                    $record->update($data);

                    return $record;
                }),
                DeleteAction::make()->using(function (Activity $record) {
                    if ($record->bookings()->exists()) {
                        $this->fail(__('provider.venues.activity_has_bookings'));
                    }

                    return $record->delete();
                }),
            ]);
    }

    private function assertSector(int $sectorId): void
    {
        try {
            app(VenueService::class)->assertSectorAllowed(ProviderAccess::provider(), $sectorId);
        } catch (BusinessException $e) {
            $this->fail($e->getMessage());
        }
    }

    private function fail(string $message): never
    {
        Notification::make()->danger()->title($message)->send();

        throw new Halt;
    }
}
