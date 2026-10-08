<?php

namespace App\Filament\Provider\Concerns;

use App\Filament\Provider\Support\ProviderAccess;
use App\Filament\Support\Fmt;
use Illuminate\Auth\Access\Response;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Provider-panel resource authorization. The using class declares:
 *   protected static string $area = 'venues';               // ProviderAccess area
 *   protected static ?string $viewPermission = null;        // null = any active member
 *   protected static ?string $managePermission = 'venues.manage'; // null = read-only
 *   protected static string $langKey = 'venues';            // provider.resources.<key>
 * Records are scoped to the current provider by Filament tenancy (Provider tenant).
 */
trait ProviderResource
{
    public static function getAuthorizationResponse(string|UnitEnum $action, ?Model $record = null): Response
    {
        $action = $action instanceof UnitEnum ? $action->name : $action;

        if (in_array($action, ['viewAny', 'view'], true)) {
            return ProviderAccess::canView(static::$area, static::$viewPermission) ? Response::allow() : Response::deny();
        }

        return static::canManage() ? Response::allow() : Response::deny();
    }

    /**
     * Global search runs outside the resource pages; scope it to the current provider
     * explicitly as well (defence in depth on top of Filament's tenancy scope).
     */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        $query = parent::getGlobalSearchEloquentQuery();

        return $query->where($query->getModel()->qualifyColumn('provider_id'), ProviderAccess::provider()?->getKey() ?? 0);
    }

    public static function canManage(): bool
    {
        return static::$managePermission !== null
            && ProviderAccess::canManage(static::$area, static::$managePermission);
    }

    public static function getModelLabel(): string
    {
        return __('provider.resources.'.static::$langKey.'.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('provider.resources.'.static::$langKey.'.plural');
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
