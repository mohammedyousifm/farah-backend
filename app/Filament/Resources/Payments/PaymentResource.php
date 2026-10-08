<?php

namespace App\Filament\Resources\Payments;

use App\Enums\PaymentStatus;
use App\Filament\Concerns\ModuleResource;
use App\Filament\NavGroup;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Payments\Pages\ListPayments;
use App\Filament\Resources\Payments\Pages\ViewPayment;
use App\Filament\Support\Fmt;
use App\Models\Order;
use App\Models\Payment;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Gateway payments (read-only). */
class PaymentResource extends Resource
{
    use ModuleResource;

    protected static ?string $model = Payment::class;

    protected static string $permissionModule = 'orders';

    protected static string $langKey = 'payments';

    protected static string|\UnitEnum|null $navigationGroup = NavGroup::Finance;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-credit-card';

    protected static ?int $navigationSort = 20;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function readOnly(): bool
    {
        return true;
    }

    /** Pretty-printed JSON block for payload columns. */
    public static function jsonEntry(string $name, string $attribute, ?string $label = null): TextEntry
    {
        return TextEntry::make($name)->label($label ?? __('admin.fields.payload'))->columnSpanFull()
            ->state(fn (Model $record) => filled($record->{$attribute})
                ? json_encode($record->{$attribute}, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : null)
            ->formatStateUsing(fn (?string $state) => $state ? '<pre class="farah-json">'.e($state).'</pre>' : null)
            ->html()
            ->placeholder('-');
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            Section::make()->columnSpan(2)->columns(3)->schema([
                TextEntry::make('reference')->label(__('admin.fields.reference'))->fontFamily('mono')->copyable()->columnSpan(2),
                TextEntry::make('status')->label(__('admin.fields.status'))->badge(),
                TextEntry::make('amount')->label(__('admin.fields.amount'))->formatStateUsing(fn ($state) => Fmt::sar($state))->weight('bold'),
                TextEntry::make('refunded_amount')->label(__('admin.fields.refunded_amount'))->formatStateUsing(fn ($state) => Fmt::sar($state)),
                TextEntry::make('currency')->label(__('admin.fields.currency')),
                TextEntry::make('gateway')->label(__('admin.fields.gateway')),
                TextEntry::make('gateway_payment_id')->label(__('admin.fields.gateway_payment_id'))->placeholder('-')->copyable(),
                TextEntry::make('method')->label(__('admin.fields.method'))->placeholder('-')->formatStateUsing(fn ($state) => Fmt::label('payment_methods', $state)),
                TextEntry::make('user.name')->label(__('admin.fields.customer'))->placeholder('-'),
                TextEntry::make('created_at')->label(__('admin.fields.created_at'))->dateTime(),
                TextEntry::make('paid_at')->label(__('admin.fields.paid_at'))->dateTime()->placeholder('-'),
                TextEntry::make('failure_reason')->label(__('admin.fields.failure_reason'))->placeholder('-')->color('danger')->columnSpanFull(),
            ]),
            Section::make(__('admin.resources.orders.plural'))->columnSpan(1)->schema([
                RepeatableEntry::make('orders')->label(__('admin.resources.orders.plural'))->hiddenLabel()->placeholder('-')->schema([
                    TextEntry::make('number')->label(__('admin.fields.number'))->hiddenLabel()->weight('bold')
                        ->url(fn (Order $record) => OrderResource::getUrl('view', ['record' => $record]))
                        ->helperText(fn (Order $record) => Fmt::sar($record->total).' · '.$record->status->getLabel()),
                ]),
            ]),
            Section::make(__('admin.fields.payload'))->columnSpanFull()->collapsible()->collapsed()->schema([
                static::jsonEntry('payload_json', 'payload')->hiddenLabel(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('user')->withCount('orders'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('reference')->label(__('admin.fields.reference'))->fontFamily('mono')->limit(13)->searchable(['reference', 'gateway_payment_id'])->copyable(),
                TextColumn::make('user.name')->label(__('admin.fields.customer'))->placeholder('-')->searchable(),
                TextColumn::make('amount')->label(__('admin.fields.amount'))->formatStateUsing(fn ($state) => Fmt::sar($state))->sortable(),
                TextColumn::make('refunded_amount')->label(__('admin.fields.refunded_amount'))->formatStateUsing(fn ($state) => Fmt::sar($state))->toggleable(),
                TextColumn::make('orders_count')->label(__('admin.resources.orders.plural'))->badge()->color('gray'),
                TextColumn::make('gateway')->label(__('admin.fields.gateway'))->badge()->color('gray'),
                TextColumn::make('method')->label(__('admin.fields.method'))->placeholder('-')->formatStateUsing(fn ($state) => Fmt::label('payment_methods', $state)),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge(),
                TextColumn::make('paid_at')->label(__('admin.fields.paid_at'))->dateTime('Y-m-d H:i')->placeholder('-')->sortable(),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->dateTime('Y-m-d H:i')->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))->options(PaymentStatus::class),
                SelectFilter::make('gateway')->label(__('admin.fields.gateway'))
                    ->options(fn () => Payment::query()->distinct()->pluck('gateway', 'gateway')->all()),
                SelectFilter::make('method')->label(__('admin.fields.method'))
                    ->options(fn () => Payment::query()->whereNotNull('method')->distinct()->pluck('method')->mapWithKeys(fn ($m) => [$m => Fmt::label('payment_methods', $m)])->all()),
            ])
            ->recordActions([ViewAction::make()]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPayments::route('/'),
            'view' => ViewPayment::route('/{record}'),
        ];
    }
}
