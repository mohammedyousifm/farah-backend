<?php

namespace App\Filament\Resources\Venues\Pages;

use App\Enums\VenueStatus;
use App\Filament\Resources\Venues\VenueResource;
use App\Filament\Support\Act;
use App\Models\Venue;
use App\Services\VenueService;
use Filament\Actions\Action;
use Filament\Resources\Pages\ViewRecord;

class ViewVenue extends ViewRecord
{
    protected static string $resource = VenueResource::class;

    private function service(): VenueService
    {
        return app(VenueService::class);
    }

    protected function getHeaderActions(): array
    {
        $can = fn () => VenueResource::canManage();

        return [
            Action::make('approve')->label(__('admin.actions.approve'))->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn (Venue $record) => $can() && $record->status === VenueStatus::PendingReview)
                ->requiresConfirmation()
                ->modalDescription(__('admin.venues.approve_hint'))
                ->action(fn (Venue $record) => Act::run(fn () => $this->service()->approve($record, auth()->user()))),

            Act::withReason('reject', __('admin.actions.reject'), fn (Venue $r, string $reason) => $this->service()->reject($r, auth()->user(), $reason))
                ->visible(fn (Venue $record) => $can() && $record->status === VenueStatus::PendingReview),

            // A suspended venue comes back through the same approval as a new one.
            Action::make('reactivate')->label(__('admin.actions.reactivate'))->icon('heroicon-o-play-circle')->color('success')
                ->visible(fn (Venue $record) => $can() && $record->status === VenueStatus::Suspended)
                ->requiresConfirmation()
                ->modalDescription(__('admin.venues.approve_hint'))
                ->action(fn (Venue $record) => Act::run(fn () => $this->service()->approve($record, auth()->user()))),

            Action::make('toggle_featured')
                ->label(fn (Venue $record) => $record->is_featured ? __('admin.actions.unfeature') : __('admin.actions.feature'))
                ->icon(fn (Venue $record) => $record->is_featured ? 'heroicon-o-star' : 'heroicon-s-star')
                ->color('warning')
                ->visible($can)
                ->requiresConfirmation()
                ->modalIcon(fn (Venue $record) => $record->is_featured ? 'heroicon-o-star' : 'heroicon-s-star')
                ->action(fn (Venue $record) => Act::run(fn () => $record->update(['is_featured' => ! $record->is_featured]))),

            Act::withReason('suspend', __('admin.actions.suspend'), fn (Venue $r, string $reason) => $this->service()->suspend($r, auth()->user(), $reason), 'danger', 'heroicon-o-pause-circle')
                ->modalDescription(__('admin.venues.suspend_hint'))
                ->visible(fn (Venue $record) => $can() && $record->status === VenueStatus::Published),
        ];
    }
}
