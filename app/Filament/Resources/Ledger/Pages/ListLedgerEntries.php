<?php

namespace App\Filament\Resources\Ledger\Pages;

use App\Filament\Resources\Ledger\LedgerEntryResource;
use App\Filament\Support\Act;
use App\Models\Provider;
use App\Services\LedgerService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\ListRecords;

class ListLedgerEntries extends ListRecords
{
    protected static string $resource = LedgerEntryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('adjustment')->label(__('admin.actions.manual_adjustment'))->icon('heroicon-o-adjustments-horizontal')
                ->visible(fn () => Act::can('ledger.manage'))
                ->modalDescription(__('admin.hints.ledger_adjustment'))
                ->schema([
                    Select::make('provider_id')->label(__('admin.fields.provider'))->required()->searchable()
                        ->options(fn () => Provider::orderBy('name_ar')->pluck('name_ar', 'id')),
                    TextInput::make('amount')->label(__('admin.fields.amount'))->numeric()->required()
                        ->suffix(__('admin.currency'))->helperText(__('admin.hints.signed_amount'))
                        ->rule('not_in:0'),
                    Textarea::make('description')->label(__('admin.fields.description'))->required()->maxLength(255),
                ])
                ->action(fn (array $data) => Act::run(fn () => app(LedgerService::class)->adjustment(
                    Provider::findOrFail($data['provider_id']), (float) $data['amount'], $data['description'],
                ))),
        ];
    }
}
