<?php

namespace App\Filament\Concerns;

use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;

/**
 * Relation managers follow the owning module's permissions
 * (declare `protected static string $permissionModule`). They stay interactive
 * on view pages so work-queue actions (approve / reject) are available there.
 */
trait ModuleRelationManager
{
    public function isReadOnly(): bool
    {
        return false;
    }

    public function getAuthorizationResponse(string $action, ?Model $record = null): Response
    {
        $user = auth()->user();
        $module = static::$permissionModule;

        if (! $user) {
            return Response::deny();
        }

        if (in_array($action, ['viewAny', 'view'], true)) {
            return $user->can("{$module}.view") || $user->can("{$module}.manage") ? Response::allow() : Response::deny();
        }

        return $user->can("{$module}.manage") ? Response::allow() : Response::deny();
    }
}
