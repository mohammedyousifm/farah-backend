<?php

namespace App\Filament\Resources\Settlements\Pages;

use App\Enums\SettlementStatus;
use App\Filament\Resources\Settlements\SettlementResource;
use App\Filament\Support\Act;
use App\Models\Settlement;
use App\Services\SettlementService;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;

class ViewSettlement extends ViewRecord
{
    protected static string $resource = SettlementResource::class;

    private function service(): SettlementService
    {
        return app(SettlementService::class);
    }

    protected function getHeaderActions(): array
    {
        $can = fn () => Act::can('settlements.manage');
        /** @var Settlement $s */
        $s = $this->getRecord();

        return [
            Action::make('approve')->label(__('admin.actions.approve'))->icon('heroicon-o-check-circle')->color('success')
                ->visible(fn () => $can() && $s->status === SettlementStatus::PendingApproval)
                ->requiresConfirmation()
                ->action(fn () => Act::run(fn () => $this->service()->approve($s, auth()->user()))),

            Action::make('mark_paid')->label(__('admin.actions.mark_paid'))->icon('heroicon-o-banknotes')->color('primary')
                ->visible(fn () => $can() && $s->status === SettlementStatus::Approved)
                ->schema([
                    TextInput::make('reference')->label(__('admin.fields.payment_reference'))->required()->maxLength(255),
                    FileUpload::make('receipt')->label(__('admin.fields.receipt'))->disk('public')->directory('settlements')
                        ->acceptedFileTypes(['application/pdf', 'image/*'])->maxSize(10240),
                ])
                ->action(fn (array $data) => Act::run(fn () => $this->service()->markPaid(
                    $s, auth()->user(), $data['reference'], $data['receipt'] ?? null,
                ))),

            Act::withReason('reject', __('admin.actions.reject'), fn (Settlement $r, string $reason) => $this->service()->reject($r, auth()->user(), $reason))
                ->visible(fn () => $can() && in_array($s->status, [SettlementStatus::PendingApproval, SettlementStatus::Approved], true)),
        ];
    }
}
