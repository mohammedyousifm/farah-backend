<?php

namespace App\Filament\Resources\Disputes;

use App\Enums\DisputeStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Filament\Concerns\ModuleResource;
use App\Filament\NavGroup;
use App\Filament\Resources\Disputes\Pages\ListDisputes;
use App\Filament\Resources\Disputes\Pages\ViewDispute;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Providers\ProviderResource;
use App\Filament\Resources\Refunds\RefundResource;
use App\Filament\Support\Act;
use App\Filament\Support\Fmt;
use App\Models\Dispute;
use App\Models\User;
use App\Services\DisputeService;
use App\Services\KpiService;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Disputes between customers and providers. Status moves only through DisputeService
 * (assign → review → resolve / reopen); refunds go through RefundService inside it.
 */
class DisputeResource extends Resource
{
    use ModuleResource;

    protected static ?string $model = Dispute::class;

    protected static string $permissionModule = 'disputes';

    protected static string $langKey = 'disputes';

    protected static string|\UnitEnum|null $navigationGroup = NavGroup::Finance;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-scale';

    protected static ?int $navigationSort = 40;

    protected static ?string $recordTitleAttribute = 'number';

    public const OPEN_STATUSES = [DisputeStatus::Open, DisputeStatus::UnderReview];

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
        $count = app(KpiService::class)->queue()['disputes'];

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    private static function service(): DisputeService
    {
        return app(DisputeService::class);
    }

    private static function isOpen(Dispute $d): bool
    {
        return in_array($d->status, static::OPEN_STATUSES, true);
    }

    /** @return array<int, Action|ActionGroup> */
    public static function workflowActions(): array
    {
        return [
            Action::make('assign')->label(__('admin.actions.assign'))->icon('heroicon-o-user-plus')->color('gray')
                ->visible(fn (Dispute $record) => static::canManage() && static::isOpen($record))
                ->fillForm(fn (Dispute $record) => ['assigned_to' => $record->assigned_to ?? auth()->id()])
                ->schema([
                    Select::make('assigned_to')->label(__('admin.fields.assignee'))->required()->searchable()
                        ->options(fn () => User::where('type', UserType::Admin)->where('status', UserStatus::Active)->orderBy('name')->pluck('name', 'id')),
                ])
                ->action(fn (Dispute $record, array $data) => Act::run(fn () => static::service()->assign($record, User::findOrFail($data['assigned_to']), auth()->user()))),

            Action::make('start_review')->label(__('admin.actions.start_review'))->icon('heroicon-o-magnifying-glass')->color('info')
                ->visible(fn (Dispute $record) => static::canManage() && $record->status === DisputeStatus::Open)
                ->requiresConfirmation()
                ->action(fn (Dispute $record) => Act::run(fn () => static::service()->startReview($record, auth()->user()))),

            Action::make('resolve')->label(__('admin.actions.resolve'))->icon('heroicon-o-check-badge')->color('success')
                ->visible(fn (Dispute $record) => static::canManage() && static::isOpen($record))
                ->fillForm(fn (Dispute $record) => [
                    'refund_amount' => $record->amount ? min($record->amount, $record->order->refundableAmount()) : $record->order->refundableAmount(),
                ])
                ->schema(fn (Dispute $record) => [
                    Select::make('outcome')->label(__('admin.fields.outcome'))->required()->live()
                        ->options([
                            DisputeStatus::ResolvedCustomer->value => DisputeStatus::ResolvedCustomer->getLabel(),
                            DisputeStatus::ResolvedProvider->value => DisputeStatus::ResolvedProvider->getLabel(),
                            DisputeStatus::Closed->value => DisputeStatus::Closed->getLabel(),
                        ]),
                    Textarea::make('resolution')->label(__('admin.fields.resolution'))->required()->maxLength(2000),
                    Toggle::make('refund')->label(__('admin.disputes.issue_refund'))->live()
                        ->visible(fn (Get $get) => $get('outcome') === DisputeStatus::ResolvedCustomer->value && $record->order->isPaid() && $record->order->refundableAmount() > 0 && Act::can('refunds.manage')),
                    TextInput::make('refund_amount')->label(__('admin.fields.refund_amount'))->numeric()->suffix(__('admin.currency'))
                        ->minValue(0.01)->maxValue($record->order->refundableAmount())
                        ->helperText(__('admin.disputes.refundable', ['max' => number_format($record->order->refundableAmount(), 2)]))
                        ->visible(fn (Get $get) => $get('outcome') === DisputeStatus::ResolvedCustomer->value && $get('refund'))
                        ->required(fn (Get $get) => (bool) $get('refund')),
                ])
                ->modalSubmitActionLabel(__('admin.actions.resolve'))
                ->action(fn (Dispute $record, array $data) => Act::run(fn () => static::service()->resolve(
                    $record,
                    auth()->user(),
                    DisputeStatus::from($data['outcome']),
                    $data['resolution'],
                    (($data['refund'] ?? false) && $data['outcome'] === DisputeStatus::ResolvedCustomer->value) ? (float) $data['refund_amount'] : null,
                ))),

            Act::withReason('reopen', __('admin.actions.reopen'), fn (Dispute $record, string $reason) => static::service()->reopen($record, auth()->user(), $reason),
                'warning', 'heroicon-o-arrow-path')
                ->visible(fn (Dispute $record) => static::canManage() && ! static::isOpen($record)),
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(4)->schema([
                TextEntry::make('number')->label(__('admin.fields.number'))->weight('bold')->copyable(),
                TextEntry::make('status')->label(__('admin.fields.status'))->badge(),
                TextEntry::make('amount')->label(__('admin.fields.disputed_amount'))->formatStateUsing(fn ($state) => Fmt::sar($state))->placeholder('-'),
                TextEntry::make('assignee.name')->label(__('admin.fields.assignee'))->placeholder(__('admin.disputes.unassigned')),
                TextEntry::make('order.number')->label(__('admin.fields.order'))
                    ->url(fn (Dispute $r) => OrderResource::getUrl('view', ['record' => $r->order_id]))
                    ->helperText(fn (Dispute $r) => $r->order ? Fmt::sar($r->order->total).' · '.$r->order->status->getLabel() : null),
                TextEntry::make('provider.name_ar')->formatStateUsing(Fmt::localized('provider.name'))->label(__('admin.fields.provider'))
                    ->url(fn (Dispute $r) => ProviderResource::getUrl('view', ['record' => $r->provider_id])),
                TextEntry::make('customer')->label(__('admin.fields.customer'))->state(fn (Dispute $r) => $r->user?->name ?? $r->order?->customerDisplayName()),
                TextEntry::make('created_at')->label(__('admin.fields.created_at'))->dateTime(),
                TextEntry::make('reason')->label(__('admin.fields.reason'))->columnSpanFull(),
                TextEntry::make('details')->label(__('admin.fields.details'))->placeholder('-')->columnSpanFull(),
            ]),
            Section::make(__('admin.disputes.resolution'))->columnSpanFull()->columns(4)
                ->visible(fn (Dispute $r) => ! static::isOpen($r))
                ->schema([
                    TextEntry::make('resolver.name')->label(__('admin.fields.resolved_by'))->placeholder('-'),
                    TextEntry::make('resolved_at')->label(__('admin.fields.resolved_at'))->dateTime()->placeholder('-'),
                    TextEntry::make('refund.number')->label(__('admin.fields.refund'))->placeholder('-')
                        ->url(fn (Dispute $r) => $r->refund_id ? RefundResource::getUrl('view', ['record' => $r->refund_id]) : null)
                        ->helperText(fn (Dispute $r) => $r->refund ? Fmt::sar($r->refund->amount) : null),
                    TextEntry::make('resolution')->label(__('admin.fields.resolution'))->placeholder('-')->columnSpanFull(),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['order.user', 'provider', 'user', 'assignee'])
                ->orderByRaw("status in ('open', 'under_review') desc"))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('number')->label(__('admin.fields.number'))->searchable()->weight('bold'),
                TextColumn::make('order.number')->label(__('admin.fields.order'))->searchable(),
                TextColumn::make('customer')->label(__('admin.fields.customer'))
                    ->state(fn (Dispute $r) => $r->user?->name ?? $r->order?->customerDisplayName()),
                TextColumn::make('provider.name_ar')->formatStateUsing(Fmt::localized('provider.name'))->label(__('admin.fields.provider'))->limit(25),
                TextColumn::make('reason')->label(__('admin.fields.reason'))->limit(40)->wrap(),
                TextColumn::make('amount')->label(__('admin.fields.disputed_amount'))->formatStateUsing(fn ($state) => Fmt::sar($state))->placeholder('-'),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge(),
                TextColumn::make('assignee.name')->label(__('admin.fields.assignee'))->placeholder('-'),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))->options(DisputeStatus::class)->multiple(),
                SelectFilter::make('assigned_to')->label(__('admin.fields.assignee'))
                    ->options(fn () => User::where('type', UserType::Admin)->orderBy('name')->pluck('name', 'id')),
                SelectFilter::make('provider_id')->label(__('admin.fields.provider'))->relationship('provider', 'name_ar')->getOptionLabelFromRecordUsing(fn ($record) => Fmt::tr($record, 'name'))->searchable(),
            ])
            ->recordActions([ViewAction::make(), ActionGroup::make(static::workflowActions())]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDisputes::route('/'),
            'view' => ViewDispute::route('/{record}'),
        ];
    }
}
