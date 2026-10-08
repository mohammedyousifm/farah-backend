<?php

namespace App\Filament\Concerns;

use App\Filament\Support\Fmt;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Maps Filament resource authorization onto "<module>.view" / "<module>.manage"
 * permissions (config/farah.php → admin_modules). The using class declares:
 *   protected static string $permissionModule = 'providers';
 *   protected static string $langKey = 'providers';          // admin.resources.<key>
 * and optionally overrides readOnly() to block create/update/delete entirely.
 */
trait ModuleResource
{
    public static function readOnly(): bool
    {
        return false;
    }

    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $action = $action instanceof UnitEnum ? $action->name : $action;
        $user = auth()->user();

        if (! $user) {
            return Response::deny();
        }

        $module = static::$permissionModule;

        if (in_array($action, ['viewAny', 'view'], true)) {
            return $user->can("{$module}.view") || $user->can("{$module}.manage") ? Response::allow() : Response::deny();
        }

        if (static::readOnly()) {
            return Response::deny();
        }

        return $user->can("{$module}.manage") ? Response::allow() : Response::deny();
    }

    public static function canManage(): bool
    {
        return (bool) auth()->user()?->can(static::$permissionModule.'.manage');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.'.static::$langKey.'.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.'.static::$langKey.'.plural');
    }

    public static function getNavigationLabel(): string
    {
        return static::getPluralModelLabel();
    }

    /** Record titles backed by an Arabic column (title_ar / name_ar) follow the panel language. */
    public static function getRecordTitle(?Model $record): string|Htmlable|null
    {
        $attribute = static::getRecordTitleAttribute();

        if ($record && $attribute && str_ends_with($attribute, '_ar')) {
            return Fmt::tr($record, substr($attribute, 0, -3)) ?? parent::getRecordTitle($record);
        }

        return parent::getRecordTitle($record);
    }
}
