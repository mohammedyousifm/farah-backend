<?php

namespace App\Filament\Resources\Providers\Pages;

use App\Enums\InternalRating;
use App\Enums\ProviderStatus;
use App\Filament\Resources\Providers\ProviderResource;
use App\Filament\Support\Act;
use App\Models\Provider;
use App\Services\ProviderService;
use App\Services\SettlementService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Carbon;

class ViewProvider extends ViewRecord
{
    protected static string $resource = ProviderResource::class;

    private function service(): ProviderService
    {
        return app(ProviderService::class);
    }

    protected function getHeaderActions(): array
    {
        $can = fn () => ProviderResource::canManage();
        /** @var Provider $p */
        $p = $this->getRecord();

        return [
            Action::make('approve')->label(__('admin.actions.approve'))->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn () => $can() && in_array($p->status, [ProviderStatus::Pending, ProviderStatus::Rejected], true))
                ->modalDescription(__('admin.providers.approve_hint'))
                ->schema([
                    CheckboxList::make('sector_ids')->label(__('admin.fields.approved_sectors'))
                        ->options(fn () => $p->sectors()->pluck('sectors.name_ar', 'sectors.id'))
                        ->default(fn () => $p->sectors()->pluck('sectors.id')->all())
                        ->columns(2),
                ])
                ->action(fn (array $data) => Act::run(fn () => $this->service()->approve($p, auth()->user(), $data['sector_ids'] ?? []))),

            Act::withReason('reject', __('admin.actions.reject'), fn (Provider $r, string $reason) => $this->service()->reject($r, auth()->user(), $reason))
                ->visible(fn () => $can() && $p->status === ProviderStatus::Pending),

            Action::make('rating')->label(__('admin.actions.internal_rating'))->icon('heroicon-o-swatch')->color('gray')
                ->visible($can)
                ->fillForm(fn () => ['internal_rating' => $p->internal_rating?->value, 'internal_notes' => $p->internal_notes])
                ->schema([
                    Select::make('internal_rating')->label(__('admin.fields.internal_rating'))->options(InternalRating::class),
                    Textarea::make('internal_notes')->label(__('admin.fields.internal_notes'))->rows(4),
                ])
                ->action(fn (array $data) => Act::run(fn () => $p->update($data))),

            ActionGroup::make([
                Action::make('generate_settlement')->label(__('admin.actions.generate_settlement'))->icon('heroicon-o-document-currency-dollar')
                    ->visible(fn () => auth()->user()?->can('settlements.manage') && $p->status === ProviderStatus::Approved)
                    ->schema([DatePicker::make('until')->label(__('admin.fields.until'))->default(now()->toDateString())->required()])
                    ->action(fn (array $data) => Act::run(fn () => app(SettlementService::class)->generate($p, Carbon::parse($data['until']), auth()->user(), false))),

                Act::withReason('suspend', __('admin.actions.suspend'), fn (Provider $r, string $reason) => $this->service()->suspend($r, auth()->user(), $reason), 'danger', 'heroicon-o-pause-circle')
                    ->visible(fn () => $can() && $p->status === ProviderStatus::Approved),

                Action::make('reactivate')->label(__('admin.actions.reactivate'))->icon('heroicon-o-play-circle')->color('success')
                    ->visible(fn () => $can() && $p->status === ProviderStatus::Suspended)
                    ->requiresConfirmation()
                    ->action(fn () => Act::run(fn () => $this->service()->reactivate($p, auth()->user()))),

                EditAction::make(),
            ]),
        ];
    }
}
