<?php

namespace App\Filament\Resources\Wallet;

use App\Enums\UserType;
use App\Enums\WalletTransactionType;
use App\Filament\Concerns\ModuleResource;
use App\Filament\NavGroup;
use App\Filament\Resources\Wallet\Pages\ListWalletTransactions;
use App\Filament\Support\Act;
use App\Filament\Support\Fmt;
use App\Models\Complaint;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\WalletService;
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
 * Customer wallets: every movement, read-only. Money goes in through a refund, or through
 * the "add balance" action (compensation or a reward). There is no top-up.
 */
class WalletTransactionResource extends Resource
{
    use ModuleResource;

    protected static ?string $model = WalletTransaction::class;

    protected static string $permissionModule = 'wallet';

    protected static string $langKey = 'wallet';

    protected static ?string $slug = 'wallet';

    protected static string|\UnitEnum|null $navigationGroup = NavGroup::Finance;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-wallet';

    protected static ?int $navigationSort = 65;

    public static function readOnly(): bool
    {
        return true;
    }

    /**
     * "Add balance": compensation or a reward. With $user the customer is fixed (user and
     * complaint pages); without it the admin picks the customer (wallet list).
     */
    public static function grantAction(string $name = 'wallet_grant', ?\Closure $user = null, ?\Closure $complaint = null): Action
    {
        return Action::make($name)
            ->label(__($complaint ? 'admin.actions.compensate' : 'admin.actions.wallet_grant'))
            ->icon('heroicon-o-wallet')->color('warning')
            ->visible(fn () => Act::can('wallet.manage'))
            ->modalDescription(__('admin.hints.wallet_grant', ['max' => number_format((float) config('farah.wallet.max_grant'), 2)]))
            ->schema(array_values(array_filter([
                $user ? null : Select::make('user_id')->label(__('admin.fields.customer'))->required()->searchable()
                    ->getSearchResultsUsing(fn (string $search) => User::where('type', '!=', UserType::Admin->value)
                        ->where(fn (Builder $q) => $q->where('name', 'like', "%{$search}%")->orWhere('phone', 'like', "%{$search}%"))
                        ->limit(20)->get()->mapWithKeys(fn (User $u) => [$u->id => "{$u->name} · {$u->phone}"])->all())
                    ->getOptionLabelUsing(fn ($value) => User::find($value)?->name),
                $complaint ? null : Select::make('type')->label(__('admin.fields.type'))->required()
                    ->options(collect(WalletTransactionType::grantable())->mapWithKeys(fn ($t) => [$t->value => $t->getLabel()])->all())
                    ->default(WalletTransactionType::Compensation->value),
                TextInput::make('amount')->label(__('admin.fields.amount'))->numeric()->required()
                    ->minValue(1)->maxValue((float) config('farah.wallet.max_grant'))->suffix(__('admin.currency')),
                Textarea::make('note')->label(__('admin.fields.wallet_note'))->required()->maxLength(500),
            ])))
            ->action(function (array $data) use ($user, $complaint) {
                /** @var Complaint|null $about */
                $about = $complaint ? $complaint() : null;

                Act::run(fn () => app(WalletService::class)->grant(
                    $user ? $user() : User::findOrFail($data['user_id']),
                    (float) $data['amount'],
                    $about ? WalletTransactionType::Compensation : WalletTransactionType::from($data['type']),
                    $data['note'],
                    auth()->user(),
                    $about,
                ));
            });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['user', 'order', 'creator']))
            ->defaultSort('id', 'desc')
            ->columns([
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime()->sortable(),
                TextColumn::make('user.name')->label(__('admin.fields.customer'))->weight('bold')->searchable()
                    ->description(fn (WalletTransaction $record) => Fmt::phone($record->user?->phone)),
                TextColumn::make('type')->label(__('admin.fields.type'))->badge(),
                TextColumn::make('amount')->label(__('admin.fields.amount'))
                    ->formatStateUsing(fn ($state) => ($state > 0 ? '+' : '').Fmt::sar($state))
                    ->color(fn ($state) => $state < 0 ? 'danger' : 'success')->weight('bold'),
                TextColumn::make('balance_after')->label(__('admin.fields.balance_after'))->formatStateUsing(fn ($state) => Fmt::sar($state)),
                TextColumn::make('order.number')->label(__('admin.fields.order'))->placeholder('-')->searchable(),
                TextColumn::make('note')->label(__('admin.fields.wallet_note'))->placeholder('-')->limit(40)->wrap(),
                TextColumn::make('creator.name')->label(__('admin.fields.created_by'))->placeholder(__('admin.system'))->toggleable(),
            ])
            ->filters([
                SelectFilter::make('type')->label(__('admin.fields.type'))->options(WalletTransactionType::class)->multiple(),
                SelectFilter::make('user_id')->label(__('admin.fields.customer'))->relationship('user', 'name')->searchable(),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWalletTransactions::route('/'),
        ];
    }
}
