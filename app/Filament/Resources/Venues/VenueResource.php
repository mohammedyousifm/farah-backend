<?php

namespace App\Filament\Resources\Venues;

use App\Enums\VenueStatus;
use App\Filament\Concerns\ModuleResource;
use App\Filament\NavGroup;
use App\Filament\Resources\Providers\ProviderResource;
use App\Filament\Resources\Venues\Pages\ListVenues;
use App\Filament\Resources\Venues\Pages\ViewVenue;
use App\Filament\Resources\Venues\RelationManagers\ActivitiesRelationManager;
use App\Filament\Resources\Venues\RelationManagers\BookingsRelationManager;
use App\Filament\Resources\Venues\RelationManagers\OrdersRelationManager;
use App\Filament\Support\Fmt;
use App\Models\Activity;
use App\Models\Sector;
use App\Models\Venue;
use App\Models\VenueHour;
use App\Models\VenueMedia;
use App\Services\KpiService;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Carbon;

class VenueResource extends Resource
{
    use ModuleResource;

    protected static ?string $model = Venue::class;

    protected static string $permissionModule = 'venues';

    protected static string $langKey = 'venues';

    protected static string|\UnitEnum|null $navigationGroup = NavGroup::Catalog;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-map-pin';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name_ar';

    /** Providers create and edit their venues in the provider panel; admins review them. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name_ar', 'name_en', 'slug'];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['provider', 'city']);
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        /** @var Venue $record */
        return (string) $record->tr('name');
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Venue $record */
        return array_filter([
            __('admin.fields.provider') => $record->provider?->displayName(),
            __('admin.fields.city') => $record->city?->tr('name'),
            __('admin.fields.status') => $record->status?->getLabel(),
        ]);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = app(KpiService::class)->queue()['venues'];

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /** Day name for a venue_hours weekday (0 = Sunday) in the panel language. */
    public static function weekday(int $weekday): string
    {
        return Carbon::create(2023, 1, 1)->addDays($weekday)->translatedFormat('l'); // 2023-01-01 was a Sunday
    }

    /** "16:00 → 02:00" in the business timezone, or "Closed". */
    public static function hoursLabel(VenueHour $hour): string
    {
        return $hour->isOpen() ? $hour->opens().' → '.$hour->closes() : __('admin.venues.closed');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(4)->schema([
                ImageEntry::make('cover_image')->label(__('admin.fields.cover'))->hiddenLabel()->disk('public')->imageHeight(90)->placeholder('-'),
                Grid::make(1)->columnSpan(2)->schema([
                    TextEntry::make('name_ar')->formatStateUsing(Fmt::localized('name'))->label(__('admin.fields.name'))->hiddenLabel()->weight('bold')->size('lg')->helperText(fn (Venue $r) => $r->name_en),
                    TextEntry::make('provider.name_ar')->formatStateUsing(Fmt::localized('provider.name'))->label(__('admin.fields.provider'))->hiddenLabel()->icon('heroicon-o-building-storefront')
                        ->url(fn (Venue $r) => $r->provider_id ? ProviderResource::getUrl('view', ['record' => $r->provider_id]) : null),
                ]),
                Grid::make(1)->schema([
                    TextEntry::make('status')->label(__('admin.fields.status'))->badge(),
                    IconEntry::make('is_featured')->label(__('admin.fields.is_featured'))->boolean(),
                ]),
            ]),
            Tabs::make()->columnSpanFull()->tabs([
                Tab::make(__('admin.tabs.details'))->icon('heroicon-o-information-circle')->columns(3)->schema([
                    TextEntry::make('sectors')->label(__('admin.fields.sectors'))->badge()->color('gray')->placeholder('-')
                        ->state(fn (Venue $r) => $r->activities->map(fn ($a) => $a->sector?->tr('name'))->filter()->unique()->values()->all()),
                    TextEntry::make('city.'.Fmt::col('name'))->label(__('admin.fields.city'))->placeholder('-'),
                    TextEntry::make('address')->label(__('admin.fields.address'))->placeholder('-')
                        ->url(fn (Venue $r) => $r->lat && $r->lng ? "https://www.google.com/maps?q={$r->lat},{$r->lng}" : null, true),
                    TextEntry::make('phone')->label(__('admin.fields.phone'))->formatStateUsing(fn ($state) => Fmt::phone($state))->placeholder('-'),
                    TextEntry::make('audience')->label(__('admin.fields.audience'))->formatStateUsing(fn ($state) => Fmt::label('venues.audiences', $state)),
                    TextEntry::make('min_age')->label(__('admin.fields.min_age'))->placeholder('-'),
                    TextEntry::make('min_price')->label(__('admin.fields.min_price'))->formatStateUsing(fn ($state) => Fmt::sar($state)),
                    TextEntry::make('advance_booking_days')->label(__('admin.fields.advance_booking_days')),
                    TextEntry::make('refund_policy')->label(__('admin.fields.refund_policy'))->formatStateUsing(fn ($state) => Fmt::label('venues.refund_policies', $state))
                        ->helperText(fn (Venue $r) => $r->refund_policy_note),
                    TextEntry::make('views_count')->label(__('admin.fields.views'))->numeric(),
                    TextEntry::make('rating_avg')->label(__('admin.fields.rating'))
                        ->formatStateUsing(fn ($state, Venue $r) => number_format((float) $state, 1).' ★ ('.$r->rating_count.')'),
                    TextEntry::make('submitted_at')->label(__('admin.fields.submitted_at'))->dateTime()->placeholder('-'),
                    TextEntry::make('reviewed_at')->label(__('admin.fields.reviewed_at'))->dateTime()->placeholder('-')
                        ->helperText(fn (Venue $r) => $r->reviewer?->name),
                    TextEntry::make('published_at')->label(__('admin.fields.published_at'))->dateTime()->placeholder('-'),
                    TextEntry::make('rejection_reason')->label(__('admin.fields.status_reason'))->placeholder('-')->color('danger')->columnSpanFull(),
                    TextEntry::make('summary_ar')->formatStateUsing(Fmt::localized('summary'))->label(__('admin.fields.summary'))->placeholder('-')->columnSpanFull(),
                    TextEntry::make('description_ar')->label(__('admin.fields.description_ar'))->placeholder('-')->columnSpanFull(),
                    TextEntry::make('description_en')->label(__('admin.fields.description_en'))->placeholder('-')->columnSpanFull(),
                ]),
                Tab::make(__('admin.tabs.working_hours'))->icon('heroicon-o-clock')->schema([
                    RepeatableEntry::make('hours')->label(__('admin.tabs.working_hours'))->hiddenLabel()->grid(4)->placeholder(__('admin.venues.no_hours'))
                        ->helperText(__('admin.venues.hours_hint', ['timezone' => config('farah.timezone')]))
                        ->schema([
                            TextEntry::make('weekday')->label(__('admin.fields.day'))->hiddenLabel()->weight('bold')
                                ->formatStateUsing(fn ($state) => static::weekday((int) $state)),
                            TextEntry::make('window')->label(__('admin.tabs.working_hours'))->hiddenLabel()->badge()
                                ->state(fn (VenueHour $record) => static::hoursLabel($record))
                                ->color(fn (VenueHour $record) => $record->isOpen() ? 'success' : 'gray'),
                        ]),
                ]),
                Tab::make(__('admin.tabs.media'))->icon('heroicon-o-photo')->schema([
                    RepeatableEntry::make('media')->label(__('admin.fields.media'))->hiddenLabel()->grid(3)->placeholder(__('admin.venues.no_media'))->schema([
                        TextEntry::make('type')->label(__('admin.fields.type'))->hiddenLabel()->badge()->formatStateUsing(fn ($state) => Fmt::label('media_types', $state)),
                        ImageEntry::make('preview')->label(__('admin.fields.preview'))->hiddenLabel()->imageHeight(140)
                            ->state(fn (VenueMedia $record) => $record->src())
                            ->visible(fn (VenueMedia $record) => in_array($record->type, ['image', 'gif'], true)),
                        TextEntry::make('link')->label(__('admin.fields.link'))->hiddenLabel()->icon('heroicon-o-play-circle')
                            ->state(fn (VenueMedia $record) => $record->src())
                            ->url(fn (VenueMedia $record) => $record->src(), true)
                            ->visible(fn (VenueMedia $record) => $record->type === 'video'),
                    ]),
                ]),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['provider', 'city', 'activities.sector'])->withCount(['activities', 'resources']))
            ->defaultSort('created_at', 'desc')
            ->columns([
                ImageColumn::make('cover_image')->label('')->disk('public')->imageSize(40)->square(),
                TextColumn::make('name_ar')->formatStateUsing(Fmt::localized('name'))->label(__('admin.fields.name'))->searchable(['name_ar', 'name_en'])->weight('bold')->limit(40)
                    ->description(fn (Venue $r) => $r->name_en),
                TextColumn::make('provider.name_ar')->formatStateUsing(Fmt::localized('provider.name'))->label(__('admin.fields.provider'))->searchable()->limit(25),
                TextColumn::make('sectors')->label(__('admin.fields.sectors'))->badge()->color('gray')->toggleable()
                    ->state(fn (Venue $r) => $r->activities->map(fn ($a) => $a->sector?->tr('name'))->filter()->unique()->values()->all()),
                TextColumn::make('city.'.Fmt::col('name'))->label(__('admin.fields.city'))->placeholder('-')->toggleable(),
                TextColumn::make('activities_count')->label(__('admin.fields.activities'))->badge()->color('info')->sortable()->toggleable(),
                TextColumn::make('resources_count')->label(__('admin.fields.resources'))->badge()->color('info')->sortable()->toggleable(),
                TextColumn::make('min_price')->label(__('admin.fields.min_price'))->formatStateUsing(fn ($state) => Fmt::sar($state))->sortable(),
                TextColumn::make('rating_avg')->label(__('admin.fields.rating'))->sortable()->toggleable(isToggledHiddenByDefault: true)
                    ->formatStateUsing(fn ($state, Venue $r) => number_format((float) $state, 1).' ('.$r->rating_count.')'),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge(),
                IconColumn::make('is_featured')->label(__('admin.fields.is_featured'))->boolean(),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime('Y-m-d H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))->options(VenueStatus::class),
                SelectFilter::make('sector_id')->label(__('admin.fields.sector'))
                    ->options(fn () => Sector::orderBy('sort_order')->pluck(Fmt::col('name'), 'id')->all())
                    ->query(fn (Builder $query, array $data) => filled($data['value'] ?? null)
                        ? $query->whereHas('activities', fn ($a) => $a->where('sector_id', $data['value']))
                        : $query),
                SelectFilter::make('provider_id')->label(__('admin.fields.provider'))->relationship('provider', 'name_ar')->getOptionLabelFromRecordUsing(fn ($record) => Fmt::tr($record, 'name'))->searchable(),
                SelectFilter::make('city_id')->label(__('admin.fields.city'))->relationship('city', 'name_ar')->getOptionLabelFromRecordUsing(fn ($record) => Fmt::tr($record, 'name')),
                TernaryFilter::make('is_featured')->label(__('admin.fields.is_featured')),
                Filter::make('pending_activities')->label(__('admin.venues.pending_activities'))
                    ->query(fn ($query) => $query->whereHas('activities', fn ($a) => $a->where('review_status', Activity::PENDING))),
                TrashedFilter::make(),
            ])
            ->filtersFormColumns(2)
            ->recordActions([ViewAction::make()]);
    }

    public static function getRelations(): array
    {
        return [ActivitiesRelationManager::class, BookingsRelationManager::class, OrdersRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListVenues::route('/'),
            'view' => ViewVenue::route('/{record}'),
        ];
    }
}
