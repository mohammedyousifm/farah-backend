<?php

namespace App\Notifications;

use App\Services\Integrations\Contracts\PushSender;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * One notification type for everything the platform tells a user
 * (order confirmed, provider approved, document expiring, ...).
 * Stored in the in-app notification centre and pushed to the user's devices.
 * Text is resolved from lang/{ar,en}/notifications.php using the recipient's locale.
 */
class SystemNotification extends Notification
{
    use Queueable;

    public function __construct(
        public string $key,
        public array $params = [],
        public array $data = [],
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'key' => $this->key,
            'title_ar' => __("notifications.{$this->key}.title", $this->params, 'ar'),
            'title_en' => __("notifications.{$this->key}.title", $this->params, 'en'),
            'body_ar' => __("notifications.{$this->key}.body", $this->params, 'ar'),
            'body_en' => __("notifications.{$this->key}.body", $this->params, 'en'),
            'data' => $this->data,
        ];
    }

    /** Store in-app and push to devices. */
    public static function send(object $user, string $key, array $params = [], array $data = []): void
    {
        $notification = new self($key, $params, $data);
        $user->notify($notification);

        $tokens = method_exists($user, 'deviceTokens') ? $user->deviceTokens()->pluck('token')->all() : [];
        if ($tokens !== []) {
            $locale = $user->locale ?? 'ar';
            app(PushSender::class)->send(
                $tokens,
                __("notifications.{$key}.title", $params, $locale),
                __("notifications.{$key}.body", $params, $locale),
                $data + ['key' => $key],
            );
        }
    }
}
