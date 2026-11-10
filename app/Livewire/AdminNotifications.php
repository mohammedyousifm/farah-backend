<?php

namespace App\Livewire;

use App\Support\AdminNotificationLinks;
use Filament\Actions\Action;
use Filament\Notifications\Livewire\DatabaseNotifications;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Livewire\Attributes\On;

/**
 * The admin bell (TODAY-04).
 *  - Each row gets an "Open" button that goes through a server route, which checks the record and the admin's permission.
 *  - "Clear" and the × archive rows (dismissed_at) instead of deleting them, so nothing is lost from the record,
 *    and the state is the same in every browser the admin is signed in on.
 *  - New rows and the unread badge refresh by polling (panel setting), with no page reload.
 */
class AdminNotifications extends DatabaseNotifications
{
    public function getNotificationsQuery(): Builder|Relation
    {
        return parent::getNotificationsQuery()->whereNull('dismissed_at');
    }

    public function getNotification(DatabaseNotification $notification): Notification
    {
        $built = parent::getNotification($notification);
        $data = (array) ($notification->data['data'] ?? []);

        if (AdminNotificationLinks::hasTarget($data)) {
            $built->actions([
                Action::make('open')
                    ->label(__('admin.bell.open'))
                    ->icon('heroicon-m-arrow-top-right-on-square')
                    ->button()->size('sm')
                    ->url(route('filament.admin.notifications.open', $notification->getKey())),
            ]);
        }

        return $built;
    }

    #[On('notificationClosed')]
    public function removeNotification(string $id): void
    {
        if (Str::isUuid($id)) {
            $this->getNotificationsQuery()->whereKey($id)->update(['dismissed_at' => now(), 'read_at' => now()]);
        }
    }

    public function clearNotifications(): void
    {
        $this->getNotificationsQuery()->update(['dismissed_at' => now(), 'read_at' => now()]);
    }
}
