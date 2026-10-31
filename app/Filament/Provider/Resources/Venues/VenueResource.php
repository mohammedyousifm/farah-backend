<?php

namespace App\Filament\Provider\Resources\Venues;

use App\Enums\VenueStatus;
use App\Exceptions\BusinessException;
use App\Filament\Provider\Concerns\ProviderResource;
use App\Filament\Provider\Pages\Schedule;
use App\Filament\Provider\ProviderNavGroup;
use App\Filament\Provider\Resources\Venues\Pages\CreateVenue;
use App\Filament\Provider\Resources\Venues\Pages\EditVenue;
use App\Filament\Provider\Resources\Venues\Pages\ListVenues;
use App\Filament\Provider\Resources\Venues\Pages\ViewVenue;
use App\Filament\Provider\Resources\Venues\RelationManagers\ActivitiesRelationManager;
use App\Filament\Provider\Resources\Venues\RelationManagers\MediaRelationManager;
use App\Filament\Provider\Resources\Venues\RelationManagers\ResourcesRelationManager;
use App\Filament\Provider\Support\ProviderAccess;
use App\Filament\Support\Act;
use App\Filament\Support\Fmt;
use App\Filament\Support\SaudiInput;
use App\Filament\Support\Upload;
use App\Models\City;
use App\Models\Venue;
use App\Models\VenueHour;
use App\Rules\GoogleMapsLink;
use App\Services\VenueService;
use App\Support\Geo;
use App\Support\MapsLink;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TimePicker;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Text;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Support\Exceptions\Halt;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** The provider's own venues. Every state change goes through VenueService. */
class VenueResource extends Resource
{
    use ProviderResource {
        getAuthorizationResponse as protected providerAuthorizationResponse;
    }

    protected static ?string $model = Venue::class;

    protected static string $area = 'venues';

    protected static ?string $viewPermission = null;

    protected static ?string $managePermission = 'venues.manage';

    protected static string $langKey = 'venues';

    protected static ?string $slug = 'venues';

    protected static string|\UnitEnum|null $navigationGroup = ProviderNavGroup::Venues;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'name_ar';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name_ar', 'name_en'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        /** @var Venue $record */
        return static::title($record);
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Venue $record */
        return array_filter([
            __('provider.fields.address') => $record->address,
            __('provider.fields.status') => $record->status?->getLabel(),
        ]);
    }

    public const AUDIENCES = ['all', 'families', 'men', 'women', 'kids'];

    public const REFUND_POLICIES = ['none', 'flexible', 'moderate', 'strict'];

    public const LOCKED = [VenueStatus::Suspended];

    /** One venue per provider (VenueService::create enforces it too): no "add" once it exists. */
    public static function getAuthorizationResponse(string|\UnitEnum $action, ?Model $record = null): Response
    {
        $name = $action instanceof \UnitEnum ? $action->name : $action;

        if ($name === 'create' && ProviderAccess::provider()?->venues()->exists()) {
            return Response::deny();
        }

        return static::providerAuthorizationResponse($action, $record);
    }

    /** "My venue" opens the provider's venue directly; the list is for a provider with none yet (or, on older accounts, several). */
    public static function getNavigationUrl(): string
    {
        $ids = ProviderAccess::provider()?->venues()->limit(2)->pluck('id') ?? collect();

        return $ids->count() === 1 ? static::getUrl('view', ['record' => $ids->first()]) : static::getUrl();
    }

    /** The activities tab of the provider's store, or null when there is no single store to point at. */
    public static function activitiesUrl(): ?string
    {
        $ids = ProviderAccess::provider()?->venues()->limit(2)->pluck('id') ?? collect();

        return $ids->count() === 1 ? static::getUrl('view', ['record' => $ids->first(), 'relation' => '0']) : null;
    }

    public static function canEdit(Model $record): bool
    {
        return static::canManage() && ! in_array($record->status, self::LOCKED, true);
    }

    public static function canDelete(Model $record): bool
    {
        return static::canManage() && ! $record->orders()->exists();
    }

    public static function canDeleteAny(): bool
    {
        return false;
    }

    public static function title(Venue $venue): string
    {
        return app()->getLocale() === 'en' && $venue->name_en ? $venue->name_en : $venue->name_ar;
    }

    /** @return array<string, string> */
    public static function options(string $group, array $values): array
    {
        return collect($values)->mapWithKeys(fn ($v) => [$v => __("provider.venues.{$group}.{$v}")])->all();
    }

    /** Run a VenueService call from a form: business errors become a danger toast and stop the save. */
    public static function runService(callable $callback): mixed
    {
        try {
            return $callback();
        } catch (BusinessException $e) {
            Notification::make()->danger()->title($e->getMessage())->send();
        }

        throw (new Halt)->rollBackDatabaseTransaction();
    }

    /** A Google Maps link was pasted: its point, address and city go into the form (or the owner is told to type them). */
    public static function fillFromMapsLink(?string $link, Set $set): void
    {
        if (blank($link)) {
            return;
        }

        $point = MapsLink::resolve($link);
        if (! $point) {
            Notification::make()->warning()->title(__('provider.venues.maps_link_unread'))->send();

            return;
        }

        $set('lat', $point['lat']);
        $set('lng', $point['lng']);
        if ($point['address']) {
            $set('address', $point['address']);
        }
        if ($city = Geo::nearestCity($point['lat'], $point['lng'])) {
            $set('city_id', $city->id);
        }
        Notification::make()->success()->title(__('provider.venues.maps_link_read'))->send();
    }

    /**
     * The venue form in clear sections. Edit shows them as tabs; create walks through
     * them as wizard steps (CreateVenue::getSteps()). Working hours are part of the
     * create wizard only; afterwards they are changed with hoursAction().
     *
     * @return array<string, array{label: string, description: string, icon: string, columns: int|null, schema: array}>
     */
    public static function formSections(bool $withHours = true): array
    {
        $provider = ProviderAccess::provider();
        // A new store starts where the sign-up link points (read once per form, only when the form is filled).
        $point = null;
        $fromLink = function () use (&$point, $provider): array {
            return $point ??= MapsLink::resolve($provider?->maps_url) ?? [];
        };

        $sections = [
            'details' => [
                'label' => __('provider.tabs.details'),
                'description' => __('provider.venues.steps.details'),
                'icon' => 'heroicon-o-information-circle',
                'columns' => 2,
                'schema' => [
                    // Starts as the business name typed at sign-up (one store per business); it can be changed
                    // when the store trades under another name.
                    TextInput::make('name_ar')->label(__('provider.fields.store_name_ar'))->required()->maxLength(200)
                        ->default($provider?->name_ar)->helperText(__('provider.venues.name_hint')),
                    TextInput::make('name_en')->label(__('provider.fields.store_name_en'))->maxLength(200)
                        ->default($provider?->name_en),
                    Textarea::make('summary_ar')->label(__('provider.fields.summary_ar'))->rows(2)->maxLength(500),
                    Textarea::make('summary_en')->label(__('provider.fields.summary_en'))->rows(2)->maxLength(500),
                    Textarea::make('description_ar')->label(__('provider.fields.description_ar'))->rows(6)->maxLength(20000),
                    Textarea::make('description_en')->label(__('provider.fields.description_en'))->rows(6)->maxLength(20000),
                    FileUpload::make('cover_image')->label(__('provider.fields.cover'))->acceptedFileTypes(Upload::IMAGES)->maxSize(8192)
                        ->disk('public')->directory(fn () => 'venues/'.$provider?->id)->columnSpanFull(),
                ],
            ],
            'location' => [
                'label' => __('provider.tabs.location'),
                'description' => __('provider.venues.steps.location'),
                'icon' => 'heroicon-o-map-pin',
                'columns' => 2,
                'schema' => [
                    Select::make('city_id')->label(__('provider.fields.city'))->required()->searchable()
                        ->default(fn () => isset($fromLink()['lat'])
                            ? Geo::nearestCity($fromLink()['lat'], $fromLink()['lng'])?->id ?? $provider?->city_id
                            : $provider?->city_id)
                        ->options(fn () => City::where('is_active', true)->orderBy('sort_order')->pluck(Fmt::col('name'), 'id')),
                    SaudiInput::mobile()->label(__('provider.fields.venue_phone'))
                        ->default(SaudiInput::localMobile($provider?->phone))
                        ->helperText(__('provider.venues.phone_hint')),
                    // The Google Maps link from sign-up fills the point, the address and the city; pasting
                    // another link (the store moved) fills them again. The link itself is not stored on the store.
                    TextInput::make('maps_link')->label(__('provider.fields.maps_url'))->columnSpanFull()->dehydrated(false)
                        ->url()->rule('url:http,https')->rule(new GoogleMapsLink)->maxLength(500)
                        ->default($provider?->maps_url)->placeholder('https://maps.app.goo.gl/...')
                        ->helperText(__('provider.venues.maps_link_hint'))->extraInputAttributes(['dir' => 'ltr'])
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (?string $state, Set $set) => static::fillFromMapsLink($state, $set)),
                    TextInput::make('address')->label(__('provider.fields.address'))->maxLength(255)->columnSpanFull()
                        ->default(fn () => $fromLink()['address'] ?? null),
                    TextInput::make('lat')->label(__('provider.fields.lat'))->numeric()->minValue(-90)->maxValue(90)
                        ->default(fn () => $fromLink()['lat'] ?? null),
                    TextInput::make('lng')->label(__('provider.fields.lng'))->numeric()->minValue(-180)->maxValue(180)
                        ->default(fn () => $fromLink()['lng'] ?? null),
                ],
            ],
            'hours' => [
                'label' => __('provider.tabs.hours'),
                'description' => __('provider.venues.steps.hours'),
                'icon' => 'heroicon-o-clock',
                'columns' => null,
                'schema' => static::hoursComponents(),
            ],
            'booking_rules' => [
                'label' => __('provider.tabs.booking_rules'),
                'description' => __('provider.venues.steps.booking_rules'),
                'icon' => 'heroicon-o-adjustments-horizontal',
                'columns' => 2,
                'schema' => [
                    Select::make('audience')->label(__('provider.fields.audience'))->options(static::options('audiences', self::AUDIENCES))
                        ->default('all')->required(),
                    TextInput::make('min_age')->label(__('provider.fields.min_age'))->numeric()->minValue(0)->maxValue(99),
                    TextInput::make('advance_booking_days')->label(__('provider.fields.advance_booking_days'))->integer()->minValue(1)->maxValue(365)
                        ->default(14)->required()->helperText(__('provider.venues.advance_hint')),
                ],
            ],
        ];

        if (! $withHours) {
            unset($sections['hours']);
        }

        return $sections;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()->columnSpanFull()->persistTabInQueryString()->tabs(
                collect(static::formSections(withHours: false))->map(fn (array $section) => Tab::make($section['label'])
                    ->icon($section['icon'])
                    ->columns($section['columns'])
                    ->schema($section['schema']))->values()->all()
            ),
        ]);
    }

    /**
     * Weekly working hours editor: one row per weekday (0 = Sunday), stored under the
     * "hours" state key as hours.<weekday>.{opens_at, closes_at, is_closed}.
     */
    public static function hoursComponents(): array
    {
        $rows = [Text::make(__('provider.venues.hours_hint'))];

        foreach (range(0, 6) as $day) {
            $rows[] = Fieldset::make(__("provider.days.{$day}"))->statePath("hours.{$day}")->columns(3)->schema([
                TimePicker::make('opens_at')->label(__('provider.fields.opens_at'))->seconds(false)->format('H:i')->default('10:00')
                    ->disabled(fn (Get $get) => (bool) $get('is_closed'))
                    ->required(fn (Get $get) => ! $get('is_closed')),
                TimePicker::make('closes_at')->label(__('provider.fields.closes_at'))->seconds(false)->format('H:i')->default('00:00')
                    ->disabled(fn (Get $get) => (bool) $get('is_closed'))
                    ->required(fn (Get $get) => ! $get('is_closed')),
                Toggle::make('is_closed')->label(__('provider.fields.is_closed'))->inline(false)->live()->default(false),
            ]);
        }

        return $rows;
    }

    /** Form state for hoursComponents() from the stored rows (missing weekdays are closed). */
    public static function hoursState(Venue $venue): array
    {
        $stored = $venue->hours()->get()->keyBy('weekday');

        return collect(range(0, 6))->mapWithKeys(function (int $day) use ($stored) {
            /** @var VenueHour|null $row */
            $row = $stored->get($day);

            return [$day => [
                'opens_at' => $row?->opens(),
                'closes_at' => $row?->closes(),
                'is_closed' => ! $row || ! $row->isOpen(),
            ]];
        })->all();
    }

    /** Rows for VenueService::syncHours from the form state. */
    public static function hoursRows(array $state): array
    {
        return collect(range(0, 6))->map(fn (int $day) => [
            'weekday' => $day,
            'opens_at' => filled($state[$day]['opens_at'] ?? null) ? substr((string) $state[$day]['opens_at'], 0, 5) : null,
            'closes_at' => filled($state[$day]['closes_at'] ?? null) ? substr((string) $state[$day]['closes_at'], 0, 5) : null,
            'is_closed' => (bool) ($state[$day]['is_closed'] ?? false),
        ])->all();
    }

    /** "10:00 - 02:00 (next day)" or "closed", one line per weekday. */
    public static function hoursLines(Venue $venue): array
    {
        $stored = $venue->hours->keyBy('weekday');

        return collect(range(0, 6))->map(function (int $day) use ($stored) {
            /** @var VenueHour|null $row */
            $row = $stored->get($day);
            $label = __("provider.days.{$day}").': ';

            if (! $row || ! $row->isOpen()) {
                return $label.__('provider.venues.closed');
            }

            return $label.$row->opens().' - '.$row->closes()
                .($row->closes() <= $row->opens() ? ' ('.__('provider.venues.next_day').')' : '');
        })->all();
    }

    /** Working hours are saved on their own (VenueService::syncHours): no review needed, bookings are untouched. */
    public static function hoursAction(): Action
    {
        return Action::make('hours')->label(__('provider.venues.edit_hours'))->icon('heroicon-o-clock')->color('gray')
            ->visible(fn (Venue $record) => static::canEdit($record))
            ->modalWidth('3xl')
            ->modalDescription(__('provider.venues.steps.hours'))
            ->fillForm(fn (Venue $record) => ['hours' => static::hoursState($record)])
            ->schema(static::hoursComponents())
            ->action(fn (Venue $record, array $data) => Act::run(
                fn () => app(VenueService::class)->syncHours($record, static::hoursRows((array) ($data['hours'] ?? []))),
                __('provider.venues.hours_saved'),
            ));
    }

    public static function submitAction(): Action
    {
        return Action::make('submit')->label(__('provider.venues.submit'))->icon('heroicon-o-paper-airplane')->color('success')
            ->visible(fn (Venue $record) => static::canManage() && in_array($record->status, [VenueStatus::Draft, VenueStatus::Rejected], true))
            ->requiresConfirmation()
            ->modalDescription(__('provider.venues.submit_hint'))
            ->action(fn (Venue $record) => Act::run(fn () => app(VenueService::class)->submit($record), __('provider.venues.submitted')));
    }

    public static function scheduleAction(): Action
    {
        return Action::make('schedule')->label(__('provider.schedule.title'))->icon('heroicon-o-calendar-days')->color('gray')
            ->visible(fn () => Schedule::canAccess())
            ->url(fn (Venue $record) => Schedule::getUrl(['venueId' => $record->id]));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(4)->schema([
                ImageEntry::make('cover_image')->label(__('provider.fields.cover'))->hiddenLabel()->disk('public')->imageHeight(90)->placeholder('-'),
                Grid::make(1)->columnSpan(2)->schema([
                    TextEntry::make('name_ar')->formatStateUsing(Fmt::localized('name'))->label(__('provider.fields.name'))->hiddenLabel()->weight('bold')->size('lg')->helperText(fn (Venue $r) => $r->name_en),
                    TextEntry::make('address')->label(__('provider.fields.address'))->hiddenLabel()->icon('heroicon-o-map-pin')->placeholder('-'),
                ]),
                TextEntry::make('status')->label(__('provider.fields.status'))->badge(),
                TextEntry::make('rejection_reason')->label(__('provider.fields.rejection_reason'))->color('danger')->columnSpanFull()
                    ->visible(fn (Venue $r) => filled($r->rejection_reason) && in_array($r->status, [VenueStatus::Rejected, VenueStatus::Suspended], true)),
            ]),
            Tabs::make()->columnSpanFull()->tabs([
                Tab::make(__('provider.tabs.details'))->icon('heroicon-o-information-circle')->columns(3)->schema([
                    TextEntry::make('city.'.Fmt::col('name'))->label(__('provider.fields.city'))->placeholder('-'),
                    TextEntry::make('phone')->label(__('provider.fields.venue_phone'))->formatStateUsing(fn ($state) => Fmt::phone($state))->placeholder('-'),
                    TextEntry::make('audience')->label(__('provider.fields.audience'))->formatStateUsing(fn ($state) => __("provider.venues.audiences.{$state}")),
                    TextEntry::make('min_age')->label(__('provider.fields.min_age'))->placeholder('-'),
                    TextEntry::make('advance_booking_days')->label(__('provider.fields.advance_booking_days')),
                    TextEntry::make('min_price')->label(__('provider.fields.min_price'))->formatStateUsing(fn ($state) => Fmt::sar($state)),
                    TextEntry::make('views_count')->label(__('provider.fields.views'))->numeric(),
                    TextEntry::make('rating_avg')->label(__('provider.fields.rating'))
                        ->formatStateUsing(fn ($state, Venue $r) => number_format((float) $state, 1).' ★ ('.$r->rating_count.')'),
                    TextEntry::make('submitted_at')->label(__('provider.fields.submitted_at'))->dateTime('Y-m-d H:i')->placeholder('-'),
                    TextEntry::make('published_at')->label(__('provider.fields.published_at'))->dateTime('Y-m-d H:i')->placeholder('-'),
                    TextEntry::make('summary_ar')->label(__('provider.fields.summary_ar'))->placeholder('-')->columnSpanFull(),
                    TextEntry::make('description_ar')->label(__('provider.fields.description_ar'))->placeholder('-')->columnSpanFull(),
                ]),
                Tab::make(__('provider.tabs.hours'))->icon('heroicon-o-clock')->schema([
                    TextEntry::make('working_hours')->label(__('provider.tabs.hours'))->hiddenLabel()->listWithLineBreaks()
                        ->state(fn (Venue $r) => static::hoursLines($r))
                        ->helperText(__('provider.venues.hours_timezone')),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['city'])->withCount(['activities', 'resources']))
            ->defaultSort('created_at', 'desc')
            ->emptyStateIcon('heroicon-o-building-storefront')
            ->emptyStateHeading(__('provider.venues.empty'))
            ->emptyStateDescription(__('provider.venues.empty_hint'))
            ->emptyStateActions([CreateAction::make()->icon('heroicon-o-plus')])
            ->columns([
                ImageColumn::make('cover_image')->label('')->disk('public')->imageSize(40)->square(),
                TextColumn::make('name_ar')->formatStateUsing(Fmt::localized('name'))->label(__('provider.fields.name'))->searchable(['name_ar', 'name_en'])->weight('bold')->limit(40)
                    ->description(fn (Venue $r) => $r->name_en),
                TextColumn::make('city.'.Fmt::col('name'))->label(__('provider.fields.city'))->placeholder('-')->toggleable(),
                TextColumn::make('activities_count')->label(__('provider.fields.activities'))->numeric()->sortable(),
                TextColumn::make('resources_count')->label(__('provider.fields.resources'))->numeric()->sortable(),
                TextColumn::make('min_price')->label(__('provider.fields.min_price'))->formatStateUsing(fn ($state) => Fmt::sar($state))->sortable(),
                TextColumn::make('status')->label(__('provider.fields.status'))->badge()
                    ->description(fn (Venue $r) => $r->status === VenueStatus::Rejected ? str($r->rejection_reason)->limit(50) : null),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('provider.fields.status'))->options(VenueStatus::class)->multiple(),
            ])
            ->recordActions([ViewAction::make(), EditAction::make(), static::hoursAction(), static::scheduleAction(), static::submitAction()]);
    }

    public static function getRelations(): array
    {
        return [ActivitiesRelationManager::class, ResourcesRelationManager::class, MediaRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVenues::route('/'),
            'create' => CreateVenue::route('/create'),
            'view' => ViewVenue::route('/{record}'),
            'edit' => EditVenue::route('/{record}/edit'),
        ];
    }
}
