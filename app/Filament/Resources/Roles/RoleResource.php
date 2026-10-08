<?php

namespace App\Filament\Resources\Roles;

use App\Filament\Concerns\ModuleResource;
use App\Filament\NavGroup;
use App\Filament\Resources\Admins\AdminResource;
use App\Filament\Resources\Roles\Pages\CreateRole;
use App\Filament\Resources\Roles\Pages\EditRole;
use App\Filament\Resources\Roles\Pages\ListRoles;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;
use UnitEnum;

/**
 * Admin roles with "<module>.view" / "<module>.manage" permissions grouped by module.
 * The super_admin role bypasses checks (Gate::before) and cannot be edited or deleted.
 */
class RoleResource extends Resource
{
    use ModuleResource {
        getAuthorizationResponse as moduleAuthorizationResponse;
    }

    public const PROTECTED_ROLE = 'super_admin';

    protected static ?string $model = Role::class;

    protected static string $permissionModule = 'admins';

    protected static string $langKey = 'roles';

    protected static string|\UnitEnum|null $navigationGroup = NavGroup::System;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-shield-check';

    protected static ?int $navigationSort = 20;

    protected static bool $isGloballySearchable = false;

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $name = $action instanceof UnitEnum ? $action->name : $action;

        if ($record && $record->getAttribute('name') === self::PROTECTED_ROLE && in_array($name, ['update', 'delete'], true)) {
            return Response::deny();
        }

        return static::moduleAuthorizationResponse($action, $record);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('guard_name', 'web');
    }

    public static function permissionField(string $module): string
    {
        return 'perm_'.$module;
    }

    /** Split "perm_<module>" form fields into a flat list of permission names. */
    public static function extractPermissions(array &$data): array
    {
        $names = [];
        foreach (config('farah.admin_modules') as $module) {
            $names = array_merge($names, (array) ($data[static::permissionField($module)] ?? []));
            unset($data[static::permissionField($module)]);
        }

        return array_values(array_unique($names));
    }

    /** @return array<string, array<int, string>> form state from a role's permissions */
    public static function permissionState(Role $role): array
    {
        $owned = $role->permissions->pluck('name');
        $state = [];
        foreach (config('farah.admin_modules') as $module) {
            $state[static::permissionField($module)] = $owned->filter(fn ($p) => str_starts_with($p, $module.'.'))->values()->all();
        }

        return $state;
    }

    public static function form(Schema $schema): Schema
    {
        $modules = collect(config('farah.admin_modules'))->map(fn (string $module) => Section::make(__("admin.modules.{$module}"))
            ->compact()
            ->schema([
                CheckboxList::make(static::permissionField($module))->label(__("admin.modules.{$module}"))->hiddenLabel()
                    ->options([
                        "{$module}.view" => __('admin.permissions.view'),
                        "{$module}.manage" => __('admin.permissions.manage'),
                    ])
                    ->columns(2),
            ]))->all();

        return $schema->components([
            Section::make()->columnSpanFull()->schema([
                TextInput::make('name')->label(__('admin.fields.role_name'))->required()->maxLength(125)
                    ->alphaDash()
                    ->unique('roles', 'name', ignoreRecord: true)
                    ->helperText(__('admin.hints.role_name')),
            ]),
            Section::make(__('admin.fields.permissions'))->description(__('admin.hints.permissions'))->columnSpanFull()->schema([
                Grid::make(['default' => 1, 'md' => 2, 'xl' => 3])->schema($modules),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->withCount(['permissions', 'users']))
            ->defaultSort('id')
            ->columns([
                TextColumn::make('name')->label(__('admin.fields.role_name'))->weight('bold')->searchable()
                    ->formatStateUsing(fn ($state) => AdminResource::roleLabel((string) $state))
                    ->description(fn (Role $r) => $r->name),
                TextColumn::make('permissions_count')->label(__('admin.fields.permissions'))->badge()->color('info')
                    ->formatStateUsing(fn ($state, Role $record) => $record->name === self::PROTECTED_ROLE ? __('admin.permissions.all') : $state),
                TextColumn::make('users_count')->label(__('admin.resources.admins.plural'))->badge()->color('gray'),
                TextColumn::make('updated_at')->label(__('admin.fields.updated_at'))->since(),
            ])
            ->recordActions([
                EditAction::make(),
                DeleteAction::make()->hidden(fn (Role $record) => $record->users_count > 0),
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRoles::route('/'),
            'create' => CreateRole::route('/create'),
            'edit' => EditRole::route('/{record}/edit'),
        ];
    }
}
