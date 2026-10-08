<?php

namespace App\Filament\Resources\Refunds;

use App\Enums\RefundStatus;
use App\Filament\Concerns\ModuleResource;
use App\Filament\NavGroup;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Refunds\Pages\ListRefunds;
use App\Filament\Resources\Refunds\Pages\ViewRefund;
use App\Filament\Support\Act;
use App\Filament\Support\Fmt;
use App\Models\Refund;
use App\Services\KpiService;
use App\Services\RefundService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/** Work-queue: customer refund requests waiting for approval. */
class RefundResource extends Resource
{
    use ModuleResource;

    protected static ?string $model = Refund::class;

    protected static string $permissionModule = 'refunds';

    protected static string $langKey = 'refunds';

    protected static string|\UnitEnum|null $navigationGroup = NavGroup::Finance;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-arrow-uturn-left';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'number';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = app(KpiService::class)->queue()['refunds'];

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function approveAction(): Action
    {
        return Action::make('approve')->label(__('admin.actions.approve'))->icon('heroicon-o-check-circle')->color('success')
            ->visible(fn (Refund $record) => static::canManage() && $record->status === RefundStatus::Requested)
            ->modalDescription(fn (Refund $record) => __('admin.refunds.approve_hint', ['max' => number_format($record->order->refundableAmount(), 2)]))
            ->fillForm(fn (Refund $record) => ['amount' => $record->amount])
            ->schema(fn (Refund $record) => [
                TextInput::make('amount')->label(__('admin.fields.amount'))->numeric()->required()
                    ->minValue(0.01)->maxValue($record->order->refundableAmount())->suffix(__('admin.currency')),
                Textarea::make('notes')->label(__('admin.fields.admin_notes'))->maxLength(1000),
            ])
            ->action(fn (Refund $record, array $data) => Act::run(fn () => app(RefundService::class)
                ->approve($record, auth()->user(), (float) $data['amount'], $data['notes'] ?? null)));
    }

    public static function rejectAction(): Action
    {
        return Action::make('reject')->label(__('admin.actions.reject'))->icon('heroicon-o-x-circle')->color('danger')
            ->visible(fn (Refund $record) => static::canManage() && $record->status === RefundStatus::Requested)
            ->requiresConfirmation()
            ->schema([
                Textarea::make('notes')->label(__('admin.fields.admin_notes'))->required()->maxLength(1000),
            ])
            ->action(fn (Refund $record, array $data) => Act::run(fn () => app(RefundService::class)
                ->reject($record, auth()->user(), $data['notes'])));
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(4)->schema([
                TextEntry::make('number')->label(__('admin.fields.number'))->weight('bold')->copyable(),
                TextEntry::make('status')->label(__('admin.fields.status'))->badge(),
                TextEntry::make('type')->label(__('admin.fields.type'))->formatStateUsing(fn ($state) => __("admin.refunds.types.{$state}")),
                TextEntry::make('amount')->label(__('admin.fields.amount'))->formatStateUsing(fn ($state) => Fmt::sar($state))->weight('bold'),
                TextEntry::make('order.number')->label(__('admin.fields.order'))
                    ->url(fn (Refund $r) => OrderResource::getUrl('view', ['record' => $r->order_id])),
                TextEntry::make('order.total')->label(__('admin.fields.order_total'))->formatStateUsing(fn ($state) => Fmt::sar($state)),
                TextEntry::make('order.venue.name_ar')->formatStateUsing(Fmt::localized('order.venue.name'))->label(__('admin.fields.venue')),
                TextEntry::make('customer')->label(__('admin.fields.customer'))->state(fn (Refund $r) => $r->order?->customerDisplayName()),
                TextEntry::make('commission_reversed')->label(__('admin.fields.commission_reversed'))->formatStateUsing(fn ($state) => Fmt::sar($state)),
                TextEntry::make('provider_debit')->label(__('admin.fields.provider_debit'))->formatStateUsing(fn ($state) => Fmt::sar($state)),
                TextEntry::make('gateway_refund_id')->label(__('admin.fields.gateway_refund_id'))->placeholder('-'),
                TextEntry::make('requester.name')->label(__('admin.fields.requested_by'))->placeholder('-'),
                TextEntry::make('created_at')->label(__('admin.fields.created_at'))->dateTime(),
                TextEntry::make('reviewed_at')->label(__('admin.fields.reviewed_at'))->dateTime()->placeholder('-')
                    ->helperText(fn (Refund $r) => $r->reviewer?->name),
                TextEntry::make('processed_at')->label(__('admin.fields.processed_at'))->dateTime()->placeholder('-'),
                TextEntry::make('items_display')->label(__('admin.resources.bookings.plural'))
                    ->state(fn (Refund $r) => static::itemsSummary($r))->placeholder(__('admin.refunds.whole_order')),
                TextEntry::make('reason')->label(__('admin.fields.reason'))->placeholder('-')->columnSpanFull(),
                TextEntry::make('admin_notes')->label(__('admin.fields.admin_notes'))->placeholder('-')->columnSpanFull(),
            ]),
        ]);
    }

    /** "Billiards - Table 3 (BK-…), …" from the refund's [{booking_id}] list. */
    public static function itemsSummary(Refund $refund): ?string
    {
        if (empty($refund->items)) {
            return null;
        }

        $bookings = $refund->order->bookings->keyBy('id');

        return collect($refund->items)
            ->map(function ($i) use ($bookings) {
                $booking = $bookings[$i['booking_id'] ?? 0] ?? null;

                return $booking ? $booking->name.' ('.$booking->code.')' : '#'.($i['booking_id'] ?? '?');
            })
            ->implode('، ');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['order.user', 'order.bookings', 'user'])
                ->orderByRaw("status = 'requested' desc"))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('admin.fields.number'))->searchable()->weight('bold'),
                TextColumn::make('order.number')->label(__('admin.fields.order'))->searchable()
                    ->url(fn (Refund $r) => OrderResource::getUrl('view', ['record' => $r->order_id])),
                TextColumn::make('customer')->label(__('admin.fields.customer'))->state(fn (Refund $r) => $r->order?->customerDisplayName()),
                TextColumn::make('amount')->label(__('admin.fields.amount'))->formatStateUsing(fn ($state) => Fmt::sar($state))->sortable(),
                TextColumn::make('type')->label(__('admin.fields.type'))->formatStateUsing(fn ($state) => __("admin.refunds.types.{$state}"))->badge()->color('gray'),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge(),
                TextColumn::make('reason')->label(__('admin.fields.reason'))->limit(40)->placeholder('-')->wrap(),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))->options(RefundStatus::class),
                SelectFilter::make('type')->label(__('admin.fields.type'))
                    ->options(['full' => __('admin.refunds.types.full'), 'partial' => __('admin.refunds.types.partial')]),
            ])
            ->recordActions([ViewAction::make(), static::approveAction(), static::rejectAction()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRefunds::route('/'),
            'view' => ViewRefund::route('/{record}'),
        ];
    }
}
