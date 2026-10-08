<?php

namespace App\Filament\Resources\SectorRequests;

use App\Enums\RequestStatus;
use App\Filament\Concerns\ModuleResource;
use App\Filament\NavGroup;
use App\Filament\Resources\SectorRequests\Pages\ManageSectorRequests;
use App\Filament\Support\Act;
use App\Filament\Support\Fmt;
use App\Models\Sector;
use App\Models\SectorRequest;
use App\Services\KpiService;
use App\Services\ProviderService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Work-queue: providers asking for a sector that does not exist yet. */
class SectorRequestResource extends Resource
{
    use ModuleResource;

    protected static ?string $model = SectorRequest::class;

    protected static string $permissionModule = 'sectors';

    protected static string $langKey = 'sector_requests';

    protected static string|\UnitEnum|null $navigationGroup = NavGroup::Approvals;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-plus';

    protected static ?int $navigationSort = 30;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = app(KpiService::class)->queue()['sector_requests'];

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextEntry::make('provider.name_ar')->formatStateUsing(Fmt::localized('provider.name'))->label(__('admin.fields.provider')),
            TextEntry::make('status')->label(__('admin.fields.status'))->badge(),
            TextEntry::make('name_ar')->label(__('admin.fields.name_ar')),
            TextEntry::make('name_en')->label(__('admin.fields.name_en'))->placeholder('-'),
            TextEntry::make('description')->label(__('admin.fields.description'))->columnSpanFull()->placeholder('-'),
            TextEntry::make('admin_notes')->label(__('admin.fields.admin_notes'))->columnSpanFull()->placeholder('-'),
            TextEntry::make('sector.name_ar')->formatStateUsing(Fmt::localized('sector.name'))->label(__('admin.fields.created_sector'))->placeholder('-'),
            TextEntry::make('reviewer.name')->label(__('admin.fields.reviewed_by'))->placeholder('-'),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['provider', 'sector'])->orderByRaw("status = 'pending' desc"))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('provider.name_ar')->formatStateUsing(Fmt::localized('provider.name'))->label(__('admin.fields.provider'))->searchable(),
                TextColumn::make('name_ar')->formatStateUsing(Fmt::localized('name'))->label(__('admin.fields.requested_sector'))->searchable()->weight('bold')
                    ->description(fn (SectorRequest $r) => $r->name_en),
                TextColumn::make('description')->label(__('admin.fields.description'))->limit(50)->placeholder('-'),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge(),
                TextColumn::make('sector.name_ar')->formatStateUsing(Fmt::localized('sector.name'))->label(__('admin.fields.created_sector'))->placeholder('-'),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))->options(RequestStatus::class)->default('pending'),
            ])
            ->recordActions([
                ViewAction::make(),
                Action::make('approve')->label(__('admin.actions.approve'))->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (SectorRequest $r) => $r->status === RequestStatus::Pending && static::canManage())
                    ->schema([
                        Select::make('existing_sector_id')->label(__('admin.fields.map_to_existing'))
                            ->helperText(__('admin.hints.map_to_existing'))
                            ->options(fn () => Sector::pluck('name_ar', 'id'))->searchable()->placeholder(__('admin.fields.create_new_sector')),
                    ])
                    ->action(fn (SectorRequest $record, array $data) => Act::run(fn () => app(ProviderService::class)->approveSectorRequest(
                        $record, auth()->user(), filled($data['existing_sector_id'] ?? null) ? Sector::find($data['existing_sector_id']) : null,
                    ))),
                Act::withReason('reject', __('admin.actions.reject'), fn (SectorRequest $r, string $reason) => app(ProviderService::class)->rejectSectorRequest($r, auth()->user(), $reason))
                    ->visible(fn (SectorRequest $r) => $r->status === RequestStatus::Pending && static::canManage()),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageSectorRequests::route('/')];
    }
}
