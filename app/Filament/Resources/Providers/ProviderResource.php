<?php

namespace App\Filament\Resources\Providers;

use App\Enums\InternalRating;
use App\Enums\ProviderStatus;
use App\Enums\ProviderType;
use App\Enums\UserType;
use App\Filament\Concerns\ModuleResource;
use App\Filament\NavGroup;
use App\Filament\Resources\Providers\Pages\CreateProvider;
use App\Filament\Resources\Providers\Pages\EditProvider;
use App\Filament\Resources\Providers\Pages\ListProviders;
use App\Filament\Resources\Providers\Pages\ViewProvider;
use App\Filament\Resources\Providers\RelationManagers\DocumentsRelationManager;
use App\Filament\Resources\Providers\RelationManagers\MembersRelationManager;
use App\Filament\Resources\Providers\RelationManagers\OrdersRelationManager;
use App\Filament\Resources\Providers\RelationManagers\SectorsRelationManager;
use App\Filament\Resources\Providers\RelationManagers\SettlementsRelationManager;
use App\Filament\Resources\Providers\RelationManagers\VenuesRelationManager;
use App\Filament\Support\Fmt;
use App\Filament\Support\SaudiInput;
use App\Filament\Support\Upload;
use App\Models\City;
use App\Models\Provider;
use App\Models\User;
use App\Rules\GoogleMapsLink;
use App\Services\KpiService;
use App\Services\LedgerService;
use App\Support\Like;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\ImageEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class ProviderResource extends Resource
{
    use ModuleResource;

    protected static ?string $model = Provider::class;

    protected static string $permissionModule = 'providers';

    protected static string $langKey = 'providers';

    protected static string|\UnitEnum|null $navigationGroup = NavGroup::Approvals;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-building-storefront';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name_ar';

    public static function getGloballySearchableAttributes(): array
    {
        return ['name_ar', 'name_en', 'cr_number', 'phone', 'email'];
    }

    public static function getGlobalSearchResultTitle(Model $record): string
    {
        /** @var Provider $record */
        return $record->displayName();
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var Provider $record */
        return array_filter([
            __('admin.fields.status') => $record->status?->getLabel(),
            __('admin.fields.cr_number') => $record->cr_number,
            __('admin.fields.phone') => $record->phone,
        ]);
    }

    public static function getNavigationBadge(): ?string
    {
        $count = app(KpiService::class)->queue()['providers'];

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

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Tabs::make()->columnSpanFull()->tabs([
                Tab::make(__('admin.tabs.profile'))->icon('heroicon-o-identification')->columns(2)->schema([
                    Select::make('owner_id')->label(__('admin.fields.owner'))->required()->searchable()
                        ->visibleOn('create')
                        ->getSearchResultsUsing(fn (string $search) => User::where('type', '!=', UserType::Admin->value)
                            ->whereDoesntHave('ownedProvider')
                            ->where(fn ($q) => $q->where('name', 'like', Like::contains($search))->orWhere('phone', 'like', Like::contains($search))->orWhere('email', 'like', Like::contains($search)))
                            ->limit(20)->get()->mapWithKeys(fn (User $u) => [$u->id => "{$u->name} ({$u->phone}{$u->email})"]))
                        ->getOptionLabelUsing(fn ($value) => User::find($value)?->name),
                    Hidden::make('type')->default(ProviderType::Establishment->value),
                    TextInput::make('name_ar')->label(__('admin.fields.name_ar'))->required()->maxLength(255),
                    TextInput::make('name_en')->label(__('admin.fields.name_en'))->maxLength(255),
                    Textarea::make('bio_ar')->label(__('admin.fields.bio_ar'))->rows(3),
                    Textarea::make('bio_en')->label(__('admin.fields.bio_en'))->rows(3),
                    FileUpload::make('logo')->label(__('admin.fields.logo'))->acceptedFileTypes(Upload::IMAGES)->maxSize(4096)->disk('public')->directory('providers'),
                    FileUpload::make('cover')->label(__('admin.fields.cover'))->acceptedFileTypes(Upload::IMAGES)->maxSize(8192)->disk('public')->directory('providers'),
                ]),
                Tab::make(__('admin.tabs.contact'))->icon('heroicon-o-phone')->columns(2)->schema([
                    SaudiInput::mobile()->label(__('admin.fields.phone')),
                    TextInput::make('email')->label(__('admin.fields.email'))->email(),
                    TextInput::make('website')->label(__('admin.fields.website'))->url()->rule('url:http,https')->maxLength(200),
                    TextInput::make('maps_url')->label(__('admin.fields.maps_url'))->url()->rule('url:http,https')->maxLength(500)->rule(new GoogleMapsLink),
                    Select::make('city_id')->label(__('admin.fields.city'))->options(fn () => City::orderBy('sort_order')->pluck('name_ar', 'id'))->searchable(),
                    TextInput::make('address')->label(__('admin.fields.address'))->columnSpanFull(),
                    TextInput::make('lat')->label(__('admin.fields.lat'))->numeric(),
                    TextInput::make('lng')->label(__('admin.fields.lng'))->numeric(),
                ]),
                Tab::make(__('admin.tabs.legal'))->icon('heroicon-o-scale')->columns(2)->schema([
                    TextInput::make('cr_number')->label(__('admin.fields.cr_number'))->maxLength(20),
                    TextInput::make('legal_name')->label(__('admin.fields.legal_name')),
                    TextInput::make('vat_number')->label(__('admin.fields.vat_number'))->maxLength(20),
                ]),
                Tab::make(__('admin.tabs.bank'))->icon('heroicon-o-building-library')->columns(2)->schema([
                    TextInput::make('bank_name')->label(__('admin.fields.bank_name')),
                    TextInput::make('account_holder')->label(__('admin.fields.account_holder')),
                    SaudiInput::iban()->label(__('admin.fields.iban'))->helperText(__('admin.hints.iban')),
                ]),
            ]),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(4)->schema([
                ImageEntry::make('logo')->label(__('admin.fields.logo'))->hiddenLabel()->disk('public')->circular()->imageSize(72)->placeholder('-'),
                Grid::make(1)->columnSpan(2)->schema([
                    TextEntry::make('name_ar')->formatStateUsing(Fmt::localized('name'))->label(__('admin.fields.name'))->hiddenLabel()->weight('bold')->size('lg')
                        ->helperText(fn (Provider $r) => $r->name_en),
                    TextEntry::make('type')->label(__('admin.fields.provider_type'))->hiddenLabel()->badge(),
                ]),
                Grid::make(1)->schema([
                    TextEntry::make('status')->label(__('admin.fields.status'))->badge(),
                    TextEntry::make('internal_rating')->label(__('admin.fields.internal_rating'))->badge()->placeholder('-'),
                ]),
            ]),
            Tabs::make()->columnSpanFull()->tabs([
                Tab::make(__('admin.tabs.overview'))->icon('heroicon-o-identification')->columns(3)->schema([
                    TextEntry::make('owner.name')->label(__('admin.fields.owner')),
                    TextEntry::make('phone')->label(__('admin.fields.phone'))->placeholder('-')->formatStateUsing(fn ($state) => Fmt::phone($state)),
                    TextEntry::make('email')->label(__('admin.fields.email'))->placeholder('-'),
                    TextEntry::make('city.name_ar')->formatStateUsing(Fmt::localized('city.name'))->label(__('admin.fields.city'))->placeholder('-'),
                    TextEntry::make('address')->label(__('admin.fields.address'))->placeholder('-'),
                    TextEntry::make('website')->label(__('admin.fields.website'))->placeholder('-')->url(fn ($state) => $state, true),
                    TextEntry::make('maps_url')->label(__('admin.fields.maps_url'))->placeholder('-')
                        ->formatStateUsing(fn () => __('admin.actions.open_map'))->url(fn ($state) => $state, true)->color('primary'),
                    TextEntry::make('rating_avg')->label(__('admin.fields.rating'))
                        ->formatStateUsing(fn ($state, Provider $r) => number_format((float) $state, 1).' ★ ('.$r->rating_count.')'),
                    TextEntry::make('submitted_at')->label(__('admin.fields.submitted_at'))->dateTime()->placeholder('-'),
                    TextEntry::make('approved_at')->label(__('admin.fields.approved_at'))->dateTime()->placeholder('-')
                        ->helperText(fn (Provider $r) => $r->approver?->name),
                    TextEntry::make('rejection_reason')->label(__('admin.fields.status_reason'))->placeholder('-')->columnSpanFull()->color('danger'),
                    TextEntry::make('bio_ar')->label(__('admin.fields.bio_ar'))->placeholder('-')->columnSpanFull(),
                    TextEntry::make('internal_notes')->label(__('admin.fields.internal_notes'))->placeholder('-')->columnSpanFull(),
                ]),
                Tab::make(__('admin.tabs.legal'))->icon('heroicon-o-scale')->columns(3)->schema([
                    TextEntry::make('cr_number')->label(__('admin.fields.cr_number'))->placeholder('-')->copyable(),
                    TextEntry::make('legal_name')->label(__('admin.fields.legal_name'))->placeholder('-'),
                    TextEntry::make('vat_number')->label(__('admin.fields.vat_number'))->placeholder('-'),
                ]),
                Tab::make(__('admin.tabs.bank'))->icon('heroicon-o-building-library')->columns(3)->schema([
                    TextEntry::make('bank_name')->label(__('admin.fields.bank_name'))->placeholder('-'),
                    TextEntry::make('account_holder')->label(__('admin.fields.account_holder'))->placeholder('-'),
                    TextEntry::make('iban')->label(__('admin.fields.iban'))->placeholder('-')->copyable()->fontFamily('mono'),
                ]),
                Tab::make(__('admin.tabs.balances'))->icon('heroicon-o-banknotes')->columns(3)
                    ->visible(fn () => auth()->user()?->can('ledger.view') || auth()->user()?->can('settlements.view'))
                    ->schema(fn () => collect(['pending', 'available', 'in_settlement', 'paid_out', 'lifetime_sales', 'lifetime_commission'])
                        ->map(fn (string $key) => TextEntry::make("balance_{$key}")->label(__("admin.balances.{$key}"))
                            ->state(fn (Provider $r) => Fmt::sar(static::balances($r)[$key]))
                            ->weight($key === 'available' ? 'bold' : null)
                            ->color($key === 'available' ? 'success' : null))
                        ->all()),
            ]),
        ]);
    }

    /** Cached per request so the six balance entries run the queries once. */
    public static function balances(Provider $provider): array
    {
        static $cache = [];

        return $cache[$provider->id] ??= app(LedgerService::class)->balances($provider);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['city', 'owner'])->withCount('venues'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                ImageColumn::make('logo')->label('')->disk('public')->circular()->imageSize(36),
                TextColumn::make('name_ar')->formatStateUsing(Fmt::localized('name'))->label(__('admin.fields.name'))->searchable(['name_ar', 'name_en', 'cr_number'])->weight('bold')
                    ->description(fn (Provider $r) => $r->name_en),
                TextColumn::make('type')->label(__('admin.fields.provider_type'))->badge(),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge(),
                TextColumn::make('internal_rating')->label(__('admin.fields.internal_rating'))->badge()->placeholder('-'),
                TextColumn::make('city.name_ar')->formatStateUsing(Fmt::localized('city.name'))->label(__('admin.fields.city'))->placeholder('-')->toggleable(),
                TextColumn::make('venues_count')->label(__('admin.resources.venues.plural'))->badge()->color('gray'),
                TextColumn::make('rating_avg')->label(__('admin.fields.rating'))->formatStateUsing(fn ($state) => number_format((float) $state, 1).' ★')->sortable(),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->date()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))->options(ProviderStatus::class),
                SelectFilter::make('type')->label(__('admin.fields.provider_type'))->options(ProviderType::class),
                SelectFilter::make('internal_rating')->label(__('admin.fields.internal_rating'))->options(InternalRating::class),
                SelectFilter::make('city_id')->label(__('admin.fields.city'))->relationship('city', 'name_ar')->getOptionLabelFromRecordUsing(fn ($record) => Fmt::tr($record, 'name')),
                SelectFilter::make('sector')->label(__('admin.fields.sector'))->relationship('sectors', 'name_ar')->getOptionLabelFromRecordUsing(fn ($record) => Fmt::tr($record, 'name')),
                TrashedFilter::make(),
            ])
            ->recordActions([ViewAction::make(), EditAction::make()]);
    }

    public static function getRelations(): array
    {
        return [
            DocumentsRelationManager::class,
            SectorsRelationManager::class,
            MembersRelationManager::class,
            VenuesRelationManager::class,
            OrdersRelationManager::class,
            SettlementsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProviders::route('/'),
            'create' => CreateProvider::route('/create'),
            'view' => ViewProvider::route('/{record}'),
            'edit' => EditProvider::route('/{record}/edit'),
        ];
    }
}
