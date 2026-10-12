<?php

namespace App\Filament\Resources\Orders\Pages;

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Support\Act;
use App\Models\Order;
use App\Services\OrderService;
use App\Services\RefundService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ViewRecord;

class ViewOrder extends ViewRecord
{
    protected static string $resource = OrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('refund')->label(__('admin.actions.refund'))->icon('heroicon-o-arrow-uturn-left')->color('danger')
                ->visible(fn (Order $record) => Act::can('refunds.manage') && $record->isPaid() && $record->refundableAmount() > 0)
                ->modalDescription(fn (Order $record) => __('admin.orders.refund_hint', ['max' => number_format($record->refundableAmount(), 2)]))
                ->fillForm(fn (Order $record) => ['amount' => $record->refundableAmount()])
                ->schema(fn (Order $record) => [
                    TextInput::make('amount')->label(__('admin.fields.amount'))->numeric()->required()
                        ->minValue(0.01)->maxValue($record->refundableAmount())->suffix(__('admin.currency')),
                    Textarea::make('reason')->label(__('admin.fields.reason'))->required()->maxLength(1000),
                ])
                ->action(fn (Order $record, array $data) => Act::run(fn () => app(RefundService::class)
                    ->createByAdmin($record, (float) $data['amount'], null, $data['reason'], auth()->user()))),

            Action::make('resync_accounting')->label(__('admin.actions.resync_accounting'))->icon('heroicon-o-arrow-path')->color('gray')
                ->visible(fn (Order $record) => OrderResource::canManage() && $record->isPaid())
                ->requiresConfirmation()
                ->action(fn (Order $record) => Act::run(fn () => app(OrderService::class)->syncAccounting($record))),
        ];
    }
}
