<?php

namespace App\Filament\Resources\Settlements\Pages;

use App\Enums\ProviderStatus;
use App\Enums\SettlementStatus;
use App\Filament\Resources\Settlements\SettlementResource;
use App\Filament\Support\Act;
use App\Models\Provider;
use App\Services\SettlementService;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Tabs\Tab;
use Illuminate\Support\Carbon;

class ListSettlements extends ListRecords
{
    protected static string $resource = SettlementResource::class;

    protected function getHeaderActions(): array
    {
        $can = fn () => Act::can('settlements.manage');

        return [
            Action::make('generate_all')->label(__('admin.actions.generate_all_settlements'))->icon('heroicon-o-arrow-path')
                ->visible($can)
                ->modalDescription(__('admin.hints.generate_all_settlements'))
                ->schema([
                    DatePicker::make('until')->label(__('admin.fields.until'))->default(now()->subDay()->toDateString())->required(),
                ])
                ->action(function (array $data) {
                    [$created, $skipped] = app(SettlementService::class)->generateAll(Carbon::parse($data['until']), auth()->user());
                    Notification::make()->success()
                        ->title(__('admin.settlements.generated', ['created' => $created, 'skipped' => $skipped]))
                        ->send();
                }),

            Action::make('generate_one')->label(__('admin.actions.generate_settlement'))->icon('heroicon-o-document-currency-dollar')
                ->color('gray')
                ->visible($can)
                ->schema([
                    Select::make('provider_id')->label(__('admin.fields.provider'))->required()->searchable()
                        ->options(fn () => Provider::where('status', ProviderStatus::Approved)->orderBy('name_ar')->pluck('name_ar', 'id')),
                    DatePicker::make('until')->label(__('admin.fields.until'))->default(now()->toDateString())->required(),
                    Toggle::make('enforce_minimum')->label(__('admin.fields.enforce_minimum'))->default(true),
                ])
                ->action(fn (array $data) => Act::run(fn () => app(SettlementService::class)->generate(
                    Provider::findOrFail($data['provider_id']),
                    Carbon::parse($data['until']),
                    auth()->user(),
                    (bool) $data['enforce_minimum'],
                ))),
        ];
    }

    public function getTabs(): array
    {
        $tabs = ['all' => Tab::make(__('admin.tabs.all'))];

        foreach (SettlementStatus::cases() as $status) {
            $tabs[$status->value] = Tab::make($status->getLabel())
                ->modifyQueryUsing(fn ($query) => $query->where('status', $status))
                ->badge(fn () => $status === SettlementStatus::PendingApproval ? (SettlementResource::getNavigationBadge() ?: null) : null)
                ->badgeColor('warning');
        }

        return $tabs;
    }
}
