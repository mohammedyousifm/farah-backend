<?php

namespace App\Filament\Provider\Resources\Venues\RelationManagers;

use App\Enums\VenueStatus;
use App\Exceptions\BusinessException;
use App\Filament\Provider\Concerns\ProviderRelationManager;
use App\Filament\Provider\Resources\Venues\VenueResource;
use App\Filament\Provider\Support\ProviderAccess;
use App\Filament\Support\Fmt;
use App\Models\Activity;
use App\Models\Category;
use App\Models\Sector;
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
use Filament\Schemas\Components\Text;
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

    /** Booking lengths a provider can pick for a game: shorter than an hour, an hour, or longer. */
    public const SLOT_MINUTES = [15, 20, 30, 45, 60, 90, 120, 180, 240];

    public static function lengthLabel(int|string|null $minutes): string
    {
        $key = 'provider.venues.lengths.'.(int) $minutes;

        return __($key) === $key ? __('provider.venues.minutes', ['count' => (int) $minutes]) : __($key);
    }

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
            // Every activity type, the store's own first ("approved"), then the others ("needs the platform's
            // approval": picking one sends the activity for review, see VenueService::prepareSector).
            Select::make('sector_id')->label(__('provider.fields.activity_type'))->required()->live()->searchable()
                ->options(function () use ($provider) {
                    $ready = $provider ? app(VenueService::class)->readySectorIds($provider) : [];

                    return Sector::where('is_active', true)->orderBy('sort_order')->get()
                        ->sortBy(fn (Sector $s) => in_array($s->id, $ready, true) ? 0 : 1, SORT_REGULAR, false)
                        ->mapWithKeys(fn (Sector $s) => [$s->id => $s->tr('name').' · '.(in_array($s->id, $ready, true)
                            ? __('provider.venues.sector_ready') : __('provider.venues.sector_needs_approval'))]);
                })
                ->afterStateUpdated(fn (Set $set) => $set('category_id', null))
                ->helperText(__('provider.venues.sector_hint')),
            Select::make('category_id')->label(__('provider.fields.category'))->searchable()
                ->options(fn (Get $get) => Category::where('sector_id', $get('sector_id'))->where('is_active', true)
                    ->orderBy('sort_order')->pluck(Fmt::col('name'), 'id')),
            TextInput::make('name_ar')->label(__('provider.fields.name_ar'))->required()->maxLength(120),
            TextInput::make('name_en')->label(__('provider.fields.name_en'))->maxLength(120),
            TextInput::make('description_ar')->label(__('provider.fields.description_ar'))->maxLength(1000),
            TextInput::make('description_en')->label(__('provider.fields.description_en'))->maxLength(1000),
            TextInput::make('price_per_hour')->label(__('provider.fields.price_per_hour'))->numeric()->minValue(0)->maxValue(1000000)->required()->live(onBlur: true)
                ->suffix(__('provider.currency'))->helperText(__('provider.venues.price_hint')),
            Select::make('slot_minutes')->label(__('provider.fields.slot_minutes'))->required()->default(60)->live()
                // A length saved through the API that is not in the list stays selectable.
                ->options(fn (?Activity $record) => collect(self::SLOT_MINUTES)->push($record?->slot_minutes)->filter()->unique()->sort()
                    ->mapWithKeys(fn ($m) => [$m => self::lengthLabel($m)]))
                ->helperText(fn (Get $get) => is_numeric($get('price_per_hour')) && $get('slot_minutes')
                    ? __('provider.venues.slot_price_hint', ['price' => Fmt::sar(round((float) $get('price_per_hour') * (int) $get('slot_minutes') / 60, 2))])
                    : __('provider.venues.slot_hint')),
            TextInput::make('min_slots')->label(__('provider.fields.min_slots'))->integer()->minValue(1)->maxValue(48)->default(1)->required(),
            TextInput::make('max_slots')->label(__('provider.fields.max_slots'))->integer()->minValue(1)->maxValue(48)->default(4)->required()
                ->gte('min_slots')->helperText(__('provider.venues.slots_hint')),
            TextInput::make('capacity')->label(__('provider.fields.capacity'))->integer()->minValue(1)->maxValue(1000)
                ->helperText(__('provider.venues.capacity_hint')),
            // Only an approved activity can be switched on; a new one in a live store waits for review first.
            Toggle::make('is_active')->label(__('provider.fields.is_active'))->default(true)->inline(false)
                ->visible(fn (?Activity $record) => $record ? $record->isApproved() : $this->getOwnerRecord()->status !== VenueStatus::Published),
            Text::make(__('provider.venues.activity_review_hint'))->columnSpanFull()->color('warning')
                ->visible(fn (?Activity $record) => $record ? ! $record->isApproved() : $this->getOwnerRecord()->status === VenueStatus::Published),
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
                    ->formatStateUsing(fn ($state) => self::lengthLabel($state))
                    ->description(fn (Activity $r) => Fmt::sar($r->slotPrice())),
                TextColumn::make('slots_range')->label(__('provider.fields.slots_range'))
                    ->state(fn (Activity $r) => $r->min_slots.' - '.$r->max_slots),
                TextColumn::make('resources_count')->label(__('provider.fields.resources'))->numeric()
                    ->color(fn ($state) => $state ? null : 'danger'),
                TextColumn::make('review_status')->label(__('provider.fields.review_status'))->badge()
                    ->formatStateUsing(fn ($state) => __('provider.venues.review.'.($state ?: Activity::APPROVED)))
                    ->color(fn ($state) => match ($state) {
                        Activity::PENDING => 'warning', Activity::REJECTED => 'danger', default => 'success'
                    })
                    ->description(fn (Activity $r) => $r->review_status === Activity::REJECTED ? $r->review_note : null),
                IconColumn::make('is_active')->label(__('provider.fields.is_active'))->boolean(),
            ])
            ->headerActions([
                CreateAction::make()->label(__('provider.venues.add_activity'))
                    ->using(function (array $data) {
                        $venue = $this->getOwnerRecord();
                        $state = $this->prepareSector((int) $data['sector_id']) + app(VenueService::class)->newActivityState($venue);

                        $activity = $venue->activities()->create($state + $data + [
                            'sort_order' => (int) $venue->activities()->max('sort_order') + 1,
                        ]);
                        if ($activity->review_status === Activity::PENDING) {
                            Notification::make()->warning()->title(__('provider.venues.activity_sent_for_review'))->send();
                        }

                        return $activity;
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
                    $sectorState = $record->sector_id === (int) $data['sector_id'] ? [] : $this->prepareSector((int) $data['sector_id']);
                    $record->update($sectorState + app(VenueService::class)->activityUpdateState($record, $data));

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

    /** @return array<string, mixed> the review state a type the store is not approved for puts the activity in */
    private function prepareSector(int $sectorId): array
    {
        try {
            return app(VenueService::class)->prepareSector(ProviderAccess::provider(), $sectorId);
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
