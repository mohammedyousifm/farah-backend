<?php

namespace App\Filament\Resources\Admins;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Filament\Concerns\ModuleResource;
use App\Filament\NavGroup;
use App\Filament\Resources\Admins\Pages\CreateAdmin;
use App\Filament\Resources\Admins\Pages\EditAdmin;
use App\Filament\Resources\Admins\Pages\ListAdmins;
use App\Filament\Support\Fmt;
use App\Models\User;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** Dashboard staff accounts (users.type = admin) and their roles. */
class AdminResource extends Resource
{
    use ModuleResource;

    protected static ?string $model = User::class;

    protected static string $permissionModule = 'admins';

    protected static string $langKey = 'admins';

    protected static ?string $slug = 'admins';

    protected static string|\UnitEnum|null $navigationGroup = NavGroup::System;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-user-circle';

    protected static ?int $navigationSort = 10;

    protected static ?string $recordTitleAttribute = 'name';

    protected static bool $isGloballySearchable = false;

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('type', UserType::Admin->value);
    }

    public static function canDelete(Model $record): bool
    {
        return $record->getKey() !== auth()->id() && static::canManage();
    }

    public static function roleLabel(string $name): string
    {
        $key = "admin.roles.{$name}";

        return trans()->has($key) ? __($key) : $name;
    }

    public static function form(Schema $schema): Schema
    {
        $isSelf = fn (?Model $record) => $record && $record->getKey() === auth()->id();

        return $schema->components([
            Section::make()->columns(2)->columnSpanFull()->schema([
                TextInput::make('name')->label(__('admin.fields.name'))->required()->maxLength(255),
                TextInput::make('email')->label(__('admin.fields.email'))->email()->required()->maxLength(255)
                    ->unique('users', 'email', ignoreRecord: true),
                TextInput::make('phone')->label(__('admin.fields.phone'))->tel()->maxLength(20)
                    ->unique('users', 'phone', ignoreRecord: true),
                TextInput::make('password')->label(__('admin.fields.password'))->password()->revealable()
                    ->minLength(8)->maxLength(255)
                    ->required(fn (string $operation) => $operation === 'create')
                    ->dehydrated(fn ($state) => filled($state))
                    ->helperText(fn (string $operation) => $operation === 'edit' ? __('admin.hints.password_keep') : null),
                Select::make('status')->label(__('admin.fields.status'))->options(UserStatus::class)
                    ->default(UserStatus::Active->value)->required()
                    ->disabled($isSelf)->dehydrated(fn (?Model $record) => ! $isSelf($record)),
                Select::make('roles')->label(__('admin.fields.roles'))->multiple()->preload()
                    ->relationship('roles', 'name', fn (Builder $query) => $query->where('guard_name', 'web'))
                    ->getOptionLabelFromRecordUsing(fn (Model $record) => static::roleLabel($record->name))
                    ->disabled($isSelf)->saveRelationshipsWhenDisabled(false)
                    ->helperText(fn (?Model $record) => $isSelf($record) ? __('admin.hints.cannot_edit_own_roles') : null),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('roles'))
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('name')->label(__('admin.fields.name'))->searchable()->weight('bold'),
                TextColumn::make('email')->label(__('admin.fields.email'))->searchable()->copyable(),
                TextColumn::make('phone')->label(__('admin.fields.phone'))->placeholder('-')->formatStateUsing(fn ($state) => Fmt::phone($state))->toggleable(),
                TextColumn::make('roles.name')->label(__('admin.fields.roles'))->badge()
                    ->formatStateUsing(fn ($state) => static::roleLabel((string) $state)),
                TextColumn::make('status')->label(__('admin.fields.status'))->badge(),
                TextColumn::make('last_login_at')->label(__('admin.fields.last_login_at'))->since()->placeholder('-'),
                TextColumn::make('created_at')->label(__('admin.fields.created_at'))->date()->sortable(),
            ])
            ->filters([
                SelectFilter::make('roles')->label(__('admin.fields.roles'))->relationship('roles', 'name')
                    ->getOptionLabelFromRecordUsing(fn ($record) => static::roleLabel((string) $record->name)),
                SelectFilter::make('status')->label(__('admin.fields.status'))->options(UserStatus::class),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()->hidden(fn (Model $record) => $record->getKey() === auth()->id())]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAdmins::route('/'),
            'create' => CreateAdmin::route('/create'),
            'edit' => EditAdmin::route('/{record}/edit'),
        ];
    }
}
