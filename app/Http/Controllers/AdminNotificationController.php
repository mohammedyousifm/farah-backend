<?php

namespace App\Http\Controllers;

use App\Support\AdminNotificationLinks;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Str;

/** "Open" on a bell row: mark it read and go to the record it is about, if it still exists and the admin may see it. */
class AdminNotificationController
{
    public function open(string $id): RedirectResponse
    {
        abort_unless(Str::isUuid($id), 404);

        // Only the signed-in admin's own notifications: someone else's id is simply not found.
        $notification = Filament::auth()->user()->notifications()->whereKey($id)->firstOrFail();
        if (! $notification->read_at) {
            $notification->markAsRead();
        }

        $link = AdminNotificationLinks::resolve((array) ($notification->data['data'] ?? []));
        if ($link['url']) {
            return redirect()->to($link['url']);
        }

        Notification::make()->warning()
            ->title(__('admin.bell.'.$link['problem']))
            ->send();

        return redirect()->to(Filament::getUrl());
    }
}
