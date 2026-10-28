<?php

namespace App\Filament\Resources\Subscriptions;

use App\Enums\ProviderStatus;
use App\Enums\SubscriptionStatus;
use App\Filament\Concerns\ModuleResource;
use App\Filament\NavGroup;
use App\Filament\Resources\Subscriptions\Pages\ManageProviderSubscriptions;
use App\Filament\Support\Act;
use App\Filament\Support\Fmt;
use App\Models\Plan;
use App\Models\Provider;
use App\Models\ProviderSubscription;
use App\Services\SubscriptionService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Providers' plans: requests to activate (once paid), running and past subscriptions. An admin can also
 * give a plan directly. Activating a new plan closes the one running (an upgrade replaces it).
 */
class ProviderSubscriptionResource extends Resource
{
    use ModuleResource;

    protected static ?string $model = ProviderSubscription::class;

    protected static string $permissionModule = 'plans';

    protected static string $langKey = 'subscriptions';

    protected static ?string $slug = 'subscriptions';

    protected static string|\UnitEnum|null $navigationGroup = NavGroup::Finance;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-identification';

    protected static ?int $navigationSort = 71;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationBadge(): ?string
    {
        $count = ProviderSubscription::where('status', SubscriptionStatus::Pending)->count();

        return $count ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /** "Give a plan": the admin picks the provider and the plan; it starts now. */
    public static function assignAction(): Action
    {
        return Action::make('assign_plan')->label(__('admin.subscriptions.assign'))->icon('heroicon-o-plus')
            ->visible(fn () => static::canManage())
            ->modalDescription(__('admin.subscriptions.assign_hint'))
            ->schema([
                Select::make('provider_id')->label(__('admin.fields.provider'))->required()->searchable()
                    // Only approved providers: a plan's days count from when the store can use them.
                    ->options(fn () => Provider::where('status', ProviderStatus::Approved)->orderBy('name_ar')->pluck('name_ar', 'id')),
                Select::make('plan_id')->label(__('admin.subscriptions.plan'))->required()
                    ->options(fn () => Plan::active()->where('is_free', false)->orderBy('sort_order')->get()->mapWithKeys(fn (Plan $p) => [$p->id => $p->tr('name').' · '.Fmt::sar($p->price)])),
                TextInput::make('payment_reference')->label(__('admin.fields.payment_reference'))->maxLength(120),
            ])
            ->action(fn (array $data) => Act::run(fn () => app(SubscriptionService::class)->assign(
                Provider::findOrFail($data['provider_id']), Plan::findOrFail($data['plan_id']), auth()->user(), $data['payment_reference'] ?? null,
            )));
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['provider', 'plan', 'decider'])->orderByRaw("status = 'pending' desc"))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('provider.name_ar')->formatStateUsing(Fmt::localized('provider.name'))->label(__('admin.fields.provider'))->weight('bold')->searchable(),
                TextColumn::make('plan.name_ar')->formatStateUsing(Fmt::localized('plan.name'))->label(__('admin.subscriptions.plan')),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge()
                    ->formatStateUsing(fn (ProviderSubscription $record) => $record->isExpired() ? __('enums.subscription_status.expired') : $record->status->getLabel())
                    ->color(fn (ProviderSubscription $record) => $record->isExpired() ? 'gray' : $record->status->getColor()),
                TextColumn::make('price')->label(__('admin.fields.price'))->formatStateUsing(fn ($state) => Fmt::sar($state)),
                TextColumn::make('starts_at')->label(__('admin.fields.starts_at'))->date('Y-m-d')->placeholder('-'),
                TextColumn::make('ends_at')->label(__('admin.fields.ends_at'))->date('Y-m-d')->placeholder('-')
                    ->description(fn (ProviderSubscription $record) => $record->isCurrent() ? __('admin.subscriptions.days_left', ['days' => $record->daysLeft()]) : null),
                TextColumn::make('payment_reference')->label(__('admin.fields.payment_reference'))->placeholder('-')->toggleable(),
                TextColumn::make('note')->label(__('admin.fields.reason'))->placeholder('-')->limit(40)->toggleable(),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))->options(SubscriptionStatus::class),
                SelectFilter::make('plan_id')->label(__('admin.subscriptions.plan'))->relationship('plan', 'name_ar'),
            ])
            ->recordActions([
                Action::make('activate')->label(__('admin.subscriptions.activate'))->icon('heroicon-o-check-circle')->color('success')
                    ->visible(fn (ProviderSubscription $r) => $r->status === SubscriptionStatus::Pending && static::canManage())
                    ->modalDescription(__('admin.subscriptions.activate_hint'))
                    ->schema([TextInput::make('payment_reference')->label(__('admin.fields.payment_reference'))->maxLength(120)])
                    ->action(fn (ProviderSubscription $record, array $data) => Act::run(fn () => app(SubscriptionService::class)->activate($record, auth()->user(), $data['payment_reference'] ?? null))),
                Act::withReason('reject', __('admin.actions.reject'), fn (ProviderSubscription $r, string $reason) => app(SubscriptionService::class)->reject($r, auth()->user(), $reason))
                    ->visible(fn (ProviderSubscription $r) => $r->status === SubscriptionStatus::Pending && static::canManage()),
                Action::make('stop')->label(__('admin.subscriptions.stop'))->icon('heroicon-o-stop-circle')->color('danger')
                    ->visible(fn (ProviderSubscription $r) => $r->isCurrent() && static::canManage())
                    ->requiresConfirmation()
                    ->schema([Textarea::make('reason')->label(__('admin.fields.reason'))->required()->maxLength(1000)])
                    ->action(fn (ProviderSubscription $record, array $data) => Act::run(fn () => app(SubscriptionService::class)->cancel($record, auth()->user(), $data['reason']))),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => ManageProviderSubscriptions::route('/')];
    }
}
