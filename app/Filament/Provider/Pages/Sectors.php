<?php

namespace App\Filament\Provider\Pages;

use App\Filament\Provider\ProviderNavGroup;
use App\Filament\Provider\Resources\SectorRequests\SectorRequestResource;
use App\Filament\Provider\Support\ProviderAccess;
use App\Filament\Support\Fmt;
use App\Models\Sector;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/** The provider's sectors with their approval status (provider_sector pivot). */
class Sectors extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\UnitEnum|null $navigationGroup = ProviderNavGroup::Account;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-squares-2x2';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'sectors';

    public static function canAccess(): bool
    {
        return ProviderAccess::canView('sectors');
    }

    public static function getNavigationLabel(): string
    {
        return __('provider.sectors.title');
    }

    public function getTitle(): string
    {
        return __('provider.sectors.title');
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('request_sector')->label(__('provider.sectors.request'))->icon('heroicon-o-plus')
                ->url(fn () => SectorRequestResource::getUrl())
                ->visible(fn () => SectorRequestResource::canViewAny()),
        ];
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([EmbeddedTable::make()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel(__('provider.fields.sector'))
            ->pluralModelLabel(__('provider.sectors.title'))
            ->query(fn () => Sector::query()
                ->join('provider_sector', 'provider_sector.sector_id', '=', 'sectors.id')
                ->where('provider_sector.provider_id', ProviderAccess::provider()?->id)
                ->select('sectors.*', 'provider_sector.status as sector_status', 'provider_sector.created_at as joined_at'))
            ->defaultSort('sectors.sort_order')
            ->paginated(false)
            ->emptyStateHeading(__('provider.sectors.empty'))
            ->columns([
                TextColumn::make(Fmt::col('name'))->label(__('provider.fields.sector'))->weight('bold'),
                TextColumn::make('sector_status')->label(__('provider.fields.status'))->badge()
                    ->formatStateUsing(fn ($state) => __("provider.sectors.statuses.{$state}"))
                    ->color(fn ($state) => match ($state) {
                        'approved' => 'success',
                        'rejected' => 'danger',
                        default => 'warning',
                    }),
                IconColumn::make('requires_license')->label(__('provider.fields.requires_license'))->boolean(),
                TextColumn::make('joined_at')->label(__('provider.fields.created_at'))->date('Y-m-d'),
            ]);
    }
}
