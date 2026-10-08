<?php

namespace App\Filament\Provider\Concerns;

use App\Filament\Provider\Support\ProviderAccess;
use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Relation managers follow their owner resource's area / permissions (declare
 * `protected static string $area` and `protected static ?string $managePermission`).
 * Override canModifyOwner() to lock writes by the owner record's state.
 */
trait ProviderRelationManager
{
    public function isReadOnly(): bool
    {
        return false;
    }

    protected function canModifyOwner(): bool
    {
        return true;
    }

    protected function canWrite(): bool
    {
        return static::$managePermission !== null
            && ProviderAccess::canManage(static::$area, static::$managePermission)
            && $this->canModifyOwner();
    }

    public function getAuthorizationResponse(string $action, ?Model $record = null): Response
    {
        if (in_array($action, ['viewAny', 'view'], true)) {
            return ProviderAccess::canView(static::$area) ? Response::allow() : Response::deny();
        }

        return $this->canWrite() ? Response::allow() : Response::deny();
    }
}
