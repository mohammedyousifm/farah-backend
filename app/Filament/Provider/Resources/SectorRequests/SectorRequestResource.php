<?php

namespace App\Filament\Provider\Resources\SectorRequests;

use App\Enums\RequestStatus;
use App\Exceptions\BusinessException;
use App\Filament\Provider\Concerns\ProviderResource;
use App\Filament\Provider\ProviderNavGroup;
use App\Filament\Provider\Resources\SectorRequests\Pages\ManageSectorRequests;
use App\Filament\Provider\Support\ProviderAccess;
use App\Filament\Support\Fmt;
use App\Models\Sector;
use App\Models\SectorRequest;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** Requests to join an existing sector or to add a new one (same rules as the provider API). */
class SectorRequestResource extends Resource
{
    use ProviderResource;

    protected static ?string $model = SectorRequest::class;

    protected static string $area = 'sectors';

    protected static ?string $viewPermission = null;

    protected static ?string $managePermission = 'profile.manage';

    protected static string $langKey = 'sector_requests';

    protected static ?string $slug = 'sector-requests';

    protected static string|\UnitEnum|null $navigationGroup = ProviderNavGroup::Account;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-plus-circle';

    protected static ?int $navigationSort = 4;

    public static function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('sector_id')->label(__('provider.fields.existing_sector'))->live()->columnSpanFull()
                ->options(fn () => Sector::where('is_active', true)
                    ->whereNotIn('id', ProviderAccess::provider()?->sectors()->pluck('sectors.id') ?? [])
                    ->orderBy('sort_order')->pluck(Fmt::col('name'), 'id'))
                ->helperText(__('provider.sector_requests.existing_hint')),
            TextInput::make('name_ar')->label(__('provider.fields.new_sector_ar'))->maxLength(120)
                ->required(fn (Get $get) => blank($get('sector_id')))->hidden(fn (Get $get) => filled($get('sector_id'))),
            TextInput::make('name_en')->label(__('provider.fields.new_sector_en'))->maxLength(120)
                ->hidden(fn (Get $get) => filled($get('sector_id'))),
            Textarea::make('description')->label(__('provider.fields.description'))->rows(3)->maxLength(2000)->columnSpanFull(),
        ]);
    }

    public static function store(array $data): SectorRequest
    {
        $provider = ProviderAccess::provider();
        $sectorId = $data['sector_id'] ?? null;

        if ($sectorId && $provider->sectors()->where('sectors.id', $sectorId)->exists()) {
            throw new BusinessException(__('api.providers.sector_exists'), 'sector_exists');
        }
        if ($provider->sectorRequests()->where('status', RequestStatus::Pending)
            ->where(fn ($q) => $sectorId ? $q->where('sector_id', $sectorId) : $q->where('name_ar', $data['name_ar'] ?? ''))->exists()) {
            throw new BusinessException(__('api.providers.sector_request_pending'), 'sector_request_pending');
        }

        $sector = $sectorId ? Sector::find($sectorId) : null;

        return $provider->sectorRequests()->create([
            'sector_id' => $sector?->id,
            'name_ar' => $sector?->name_ar ?? $data['name_ar'],
            'name_en' => $sector?->name_en ?? ($data['name_en'] ?? null),
            'description' => $data['description'] ?? null,
            'status' => RequestStatus::Pending,
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make(Fmt::col('name'))->label(__('provider.fields.requested_sector'))->weight('bold')
                    ->state(fn (SectorRequest $r) => Fmt::tr($r, 'name')),
                TextColumn::make('description')->label(__('provider.fields.description'))->limit(60)->placeholder('-'),
                TextColumn::make('status')->label(__('provider.fields.status'))->badge()
                    ->description(fn (SectorRequest $r) => $r->status === RequestStatus::Rejected ? $r->admin_notes : null),
                TextColumn::make('reviewed_at')->label(__('provider.fields.reviewed_at'))->dateTime('Y-m-d')->placeholder('-'),
                TextColumn::make('created_at')->label(__('provider.fields.created_at'))->since(),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSectorRequests::route('/')];
    }
}
