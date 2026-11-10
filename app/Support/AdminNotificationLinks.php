<?php

namespace App\Support;

use App\Filament\Resources\Complaints\ComplaintResource;
use App\Filament\Resources\Disputes\DisputeResource;
use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\Payments\PaymentResource;
use App\Filament\Resources\Providers\ProviderResource;
use App\Filament\Resources\Refunds\RefundResource;
use App\Filament\Resources\Reviews\ReviewResource;
use App\Filament\Resources\Subscriptions\ProviderSubscriptionResource;
use App\Filament\Resources\Venues\VenueResource;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;

/**
 * Where an admin notification leads (TODAY-04). The target is worked out on the server from the
 * notification's stored ids, never from a URL in the payload, and only to a page the admin may open.
 *
 * Order matters: the most specific record first (a refund notice also carries its order id).
 */
class AdminNotificationLinks
{
    /** data key => [resource, page, how the id finds the record] */
    private const TARGETS = [
        'refund_id' => [RefundResource::class, 'view'],
        'complaint_id' => [ComplaintResource::class, 'view'],
        'dispute_id' => [DisputeResource::class, 'view'],
        'payment_reference' => [PaymentResource::class, 'view', 'reference'],
        'subscription_id' => [ProviderSubscriptionResource::class, 'index'],
        'order_id' => [OrderResource::class, 'view'],
        'review_id' => [ReviewResource::class, 'index'],
        'venue_id' => [VenueResource::class, 'view'],
        'provider_id' => [ProviderResource::class, 'view'],
    ];

    /** The first target this notification has an id for, without touching the database (to draw the "Open" button). */
    public static function hasTarget(array $data): bool
    {
        foreach (self::TARGETS as $key => [$resource]) {
            if (filled($data[$key] ?? null) && $resource::canViewAny()) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{url: string|null, problem: 'missing'|'forbidden'|null}
     */
    public static function resolve(array $data): array
    {
        $problem = null;

        foreach (self::TARGETS as $key => $target) {
            $id = $data[$key] ?? null;
            if (blank($id) || ! is_scalar($id)) {
                continue;
            }
            /** @var class-string<resource> $resource */
            [$resource, $page] = $target;
            $column = $target[2] ?? null;

            if (! $resource::canViewAny()) {
                $problem ??= 'forbidden';

                continue;
            }

            $record = self::find($resource, $column, $id);
            if (! $record) {
                $problem ??= 'missing';

                continue;
            }

            if ($page === 'index') {
                return ['url' => $resource::getUrl('index'), 'problem' => null];
            }

            if (! $resource::canView($record)) {
                $problem ??= 'forbidden';

                continue;
            }

            return ['url' => $resource::getUrl('view', ['record' => $record]), 'problem' => null];
        }

        return ['url' => null, 'problem' => $problem ?? 'missing'];
    }

    /** @param  class-string<resource>  $resource */
    private static function find(string $resource, ?string $column, mixed $id): ?Model
    {
        $query = $resource::getModel()::query();

        if ($column) {
            return $query->where($column, (string) $id)->first();
        }

        return ctype_digit((string) $id) ? $query->find((int) $id) : null;
    }
}
