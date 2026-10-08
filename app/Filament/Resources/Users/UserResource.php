<?php

namespace App\Filament\Resources\Users;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Filament\Concerns\ModuleResource;
use App\Filament\NavGroup;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Filament\Resources\Users\Pages\ViewUser;
use App\Filament\Resources\Users\RelationManagers\OrdersRelationManager;
use App\Filament\Support\Act;
use App\Filament\Support\Fmt;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Mobile-app users (customers and provider accounts); admins are managed separately. */
class UserResource extends Resource
{
    use ModuleResource;

    protected static ?string $model = User::class;

    protected static string $permissionModule = 'users';

    protected static string $langKey = 'users';

    protected static string|\UnitEnum|null $navigationGroup = NavGroup::Support;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static ?int $navigationSort = 30;

    protected static ?string $recordTitleAttribute = 'name';

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('type', '!=', UserType::Admin->value);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['name', 'phone', 'email'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return array_filter([
            __('admin.fields.phone') => $record->phone,
            __('admin.fields.email') => $record->email,
        ]);
    }

    /** Suspend / activate actions, shared by the table and the view page. */
    public static function statusActions(): array
    {
        return [
            Act::withReason('suspend', __('admin.actions.suspend'), function (User $user, string $reason) {
                $user->update(['status' => UserStatus::Suspended]);
                $user->tokens()->delete();
            }, 'danger', 'heroicon-o-no-symbol')
                ->visible(fn (User $record) => static::canManage() && $record->status === UserStatus::Active),
            Action::make('activate')->label(__('admin.actions.activate'))->icon('heroicon-o-check-circle')->color('success')
                ->visible(fn (User $record) => static::canManage() && $record->status === UserStatus::Suspended)
                ->requiresConfirmation()
                ->action(fn (User $record) => Act::run(fn () => $record->update(['status' => UserStatus::Active]))),
        ];
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->columnSpanFull()->columns(4)->schema([
                TextEntry::make('name')->label(__('admin.fields.name'))->weight('bold'),
                TextEntry::make('type')->label(__('admin.fields.type'))->badge(),
                TextEntry::make('status')->label(__('admin.fields.status'))->badge(),
                TextEntry::make('phone')->label(__('admin.fields.phone'))->placeholder('-')->formatStateUsing(fn ($state) => Fmt::phone($state))->copyable()->copyableState(fn ($record) => $record->phone),
                TextEntry::make('email')->label(__('admin.fields.email'))->placeholder('-')->copyable(),
                TextEntry::make('city.name_ar')->formatStateUsing(Fmt::localized('city.name'))->label(__('admin.fields.city'))->placeholder('-'),
                TextEntry::make('gender')->label(__('admin.fields.gender'))->placeholder('-'),
                TextEntry::make('birth_date')->label(__('admin.fields.birth_date'))->date()->placeholder('-'),
                TextEntry::make('locale')->label(__('admin.fields.locale'))->placeholder('-')->formatStateUsing(fn ($state) => Fmt::label('locales', $state)),
                IconEntry::make('marketing_consent')->label(__('admin.fields.marketing_consent'))->boolean(),
                IconEntry::make('tracking_consent')->label(__('admin.fields.tracking_consent'))->boolean(),
                TextEntry::make('ownedProvider.name_ar')->formatStateUsing(Fmt::localized('ownedProvider.name'))->label(__('admin.fields.provider'))->placeholder('-'),
                TextEntry::make('created_at')->label(__('admin.fields.created_at'))->dateTime(),
                TextEntry::make('last_login_at')->label(__('admin.fields.last_login_at'))->dateTime()->placeholder('-'),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('city')->withCount('orders'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label(__('admin.fields.name'))->searchable()->weight('bold'),
                TextColumn::make('phone')->label(__('admin.fields.phone'))->searchable()->placeholder('-')->formatStateUsing(fn ($state) => Fmt::phone($state)),
                TextColumn::make('email')->label(__('admin.fields.email'))->searchable()->placeholder('-')->toggleable(),
                TextColumn::make('type')->label(__('admin.fields.type'))->badge(),
                TextColumn::make('city.name_ar')->formatStateUsing(Fmt::localized('city.name'))->label(__('admin.fields.city'))->placeholder('-')->toggleable(),
                TextColumn::make('orders_count')->label(__('admin.resources.orders.plural'))->badge()->color('gray')->sortable(),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge(),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->date()->sortable(),
                TextColumn::make('last_login_at')->label(__('admin.fields.last_login_at'))->since()->placeholder('-')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label(__('admin.fields.status'))->options(UserStatus::class),
                SelectFilter::make('type')->label(__('admin.fields.type'))
                    ->options([UserType::Customer->value => UserType::Customer->getLabel(), UserType::Provider->value => UserType::Provider->getLabel()]),
                SelectFilter::make('city_id')->label(__('admin.fields.city'))->relationship('city', 'name_ar')->getOptionLabelFromRecordUsing(fn ($record) => Fmt::tr($record, 'name')),
            ])
            ->recordActions([ViewAction::make(), ...static::statusActions()]);
    }

    public static function getRelations(): array
    {
        return [OrdersRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'view' => ViewUser::route('/{record}'),
        ];
    }
}
