<?php

namespace App\Filament\Resources\WebhookEvents;

use App\Filament\Concerns\ModuleResource;
use App\Filament\NavGroup;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\WebhookEvents\Pages\ListWebhookEvents;
use App\Filament\Resources\WebhookEvents\Pages\ViewWebhookEvent;
use App\Models\WebhookEvent;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Incoming payment-gateway webhooks, kept for idempotency and troubleshooting (read-only). */
class WebhookEventResource extends Resource
{
    use ModuleResource;

    protected static ?string $model = WebhookEvent::class;

    protected static string $permissionModule = 'integrations';

    protected static string $langKey = 'webhook_events';

    protected static string|\UnitEnum|null $navigationGroup = NavGroup::Finance;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bolt';

    protected static ?int $navigationSort = 80;

    public static function readOnly(): bool
    {
        return true;
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(3)->schema([
                TextEntry::make('gateway')->label(__('admin.fields.gateway'))->badge()->color('gray'),
                TextEntry::make('event_id')->label(__('admin.fields.event_id'))->fontFamily('mono')->copyable(),
                TextEntry::make('type')->label(__('admin.fields.type'))->placeholder('-'),
                IconEntry::make('signature_valid')->label(__('admin.fields.signature_valid'))->boolean(),
                TextEntry::make('created_at')->label(__('admin.fields.received_at'))->dateTime(),
                TextEntry::make('processed_at')->label(__('admin.fields.processed_at'))->dateTime()->placeholder(__('admin.webhooks.not_processed')),
                TextEntry::make('error')->label(__('admin.fields.error'))->placeholder('-')->color('danger')->columnSpanFull(),
                PaymentResource::jsonEntry('payload_json', 'payload'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('admin.fields.received_at'))->dateTime('Y-m-d H:i:s')->sortable(),
                TextColumn::make('gateway')->label(__('admin.fields.gateway'))->badge()->color('gray'),
                TextColumn::make('event_id')->label(__('admin.fields.event_id'))->fontFamily('mono')->limit(24)->searchable(),
                TextColumn::make('type')->label(__('admin.fields.type'))->placeholder('-')->searchable(),
                IconColumn::make('signature_valid')->label(__('admin.fields.signature_valid'))->boolean(),
                TextColumn::make('processed_at')->label(__('admin.fields.processed_at'))->since()->placeholder('-'),
                TextColumn::make('error')->label(__('admin.fields.error'))->limit(40)->placeholder('-')->color('danger'),
            ])
            ->filters([
                SelectFilter::make('gateway')->label(__('admin.fields.gateway'))
                    ->options(fn () => WebhookEvent::query()->distinct()->pluck('gateway', 'gateway')->all()),
                TernaryFilter::make('signature_valid')->label(__('admin.fields.signature_valid')),
                TernaryFilter::make('processed')->label(__('admin.fields.processed_at'))
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('processed_at'),
                        false: fn (Builder $q) => $q->whereNull('processed_at'),
                    ),
                TernaryFilter::make('has_error')->label(__('admin.fields.error'))
                    ->queries(
                        true: fn (Builder $q) => $q->whereNotNull('error'),
                        false: fn (Builder $q) => $q->whereNull('error'),
                    ),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWebhookEvents::route('/'),
            'view' => ViewWebhookEvent::route('/{record}'),
        ];
    }
}
