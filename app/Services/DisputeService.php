<?php

namespace App\Services;

use App\Enums\DisputeStatus;
use App\Exceptions\BusinessException;
use App\Models\AuditLog;
use App\Models\Dispute;
use App\Models\Order;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Support\DocNumber;
use Illuminate\Support\Facades\DB;

/**
 * Dispute workflow: open → under_review → resolved_customer | resolved_provider | closed.
 * Every transition is row-locked, validated and written to the audit log.
 * Resolving in the customer's favour can issue a refund through RefundService.
 */
class DisputeService
{
    public const OPEN = [DisputeStatus::Open, DisputeStatus::UnderReview];

    public const OUTCOMES = [DisputeStatus::ResolvedCustomer, DisputeStatus::ResolvedProvider, DisputeStatus::Closed];

    public function __construct(private RefundService $refunds) {}

    public function open(Order $order, User $by, string $reason, ?string $details = null, ?float $amount = null): Dispute
    {
        if ($amount !== null && ($amount <= 0 || $amount > $order->total)) {
            throw new BusinessException(__('api.refunds.invalid_amount', ['max' => number_format($order->total, 2)]), 'invalid_amount');
        }

        $dispute = Dispute::create([
            'number' => DocNumber::make('DSP', 'disputes'),
            'order_id' => $order->id,
            'provider_id' => $order->provider_id,
            'user_id' => $order->user_id,
            'reason' => $reason,
            'details' => $details,
            'amount' => $amount !== null ? round($amount, 2) : null,
            'status' => DisputeStatus::Open,
        ]);

        AuditLog::record('dispute.opened', $dispute, [], ['by' => $by->id, 'order' => $order->number]);

        return $dispute;
    }

    public function assign(Dispute $dispute, User $assignee, User $by): Dispute
    {
        return $this->transition($dispute, self::OPEN, 'dispute.assigned', fn (Dispute $d) => [
            'assigned_to' => $assignee->id,
        ], $by);
    }

    public function startReview(Dispute $dispute, User $by): Dispute
    {
        return $this->transition($dispute, [DisputeStatus::Open], 'dispute.review_started', fn (Dispute $d) => [
            'status' => DisputeStatus::UnderReview,
            'assigned_to' => $d->assigned_to ?? $by->id,
        ], $by);
    }

    /**
     * Close the dispute with an outcome. A refund (only for resolved_customer) is issued
     * after the dispute is claimed; if the refund fails the dispute is put back as it was.
     */
    public function resolve(Dispute $dispute, User $by, DisputeStatus $outcome, string $resolution, ?float $refundAmount = null): Dispute
    {
        if (! in_array($outcome, self::OUTCOMES, true)) {
            throw new BusinessException(__('api.moderation.invalid_transition'), 'invalid_transition');
        }
        if ($refundAmount !== null && $outcome !== DisputeStatus::ResolvedCustomer) {
            throw new BusinessException(__('api.moderation.refund_not_allowed'), 'refund_not_allowed');
        }

        $previous = null;
        $dispute = $this->transition($dispute, self::OPEN, 'dispute.resolved', function (Dispute $d) use ($by, $outcome, $resolution, &$previous) {
            $previous = $d->only(['status', 'resolution', 'resolved_by', 'resolved_at']);

            return [
                'status' => $outcome,
                'resolution' => $resolution,
                'resolved_by' => $by->id,
                'resolved_at' => now(),
            ];
        }, $by, ['refund_amount' => $refundAmount]);

        if ($refundAmount !== null && $refundAmount > 0) {
            try {
                $refund = $this->refunds->createByAdmin($dispute->order, $refundAmount, null, 'dispute '.$dispute->number.': '.$resolution, $by);
                $dispute->update(['refund_id' => $refund->id]);
            } catch (BusinessException $e) {
                $dispute->update($previous);
                AuditLog::record('dispute.resolve_reverted', $dispute, [], ['error' => $e->errorCode]);
                throw $e;
            }
        }

        if ($dispute->user) {
            SystemNotification::send($dispute->user, 'dispute_resolved', ['number' => $dispute->number], ['dispute_id' => $dispute->id, 'order_id' => $dispute->order_id]);
        }

        return $dispute->fresh();
    }

    /** Re-open a decided dispute (e.g. new evidence). Refunds already issued are not undone. */
    public function reopen(Dispute $dispute, User $by, string $note): Dispute
    {
        return $this->transition($dispute, self::OUTCOMES, 'dispute.reopened', fn (Dispute $d) => [
            'status' => DisputeStatus::UnderReview,
            'resolved_by' => null,
            'resolved_at' => null,
        ], $by, ['note' => $note]);
    }

    /**
     * @param  array<int, DisputeStatus>  $from
     * @param  callable(Dispute): array  $changes
     */
    private function transition(Dispute $dispute, array $from, string $action, callable $changes, User $by, array $meta = []): Dispute
    {
        return DB::transaction(function () use ($dispute, $from, $action, $changes, $by, $meta) {
            $locked = Dispute::whereKey($dispute->id)->lockForUpdate()->firstOrFail();

            if (! in_array($locked->status, $from, true)) {
                throw new BusinessException(__('api.moderation.invalid_transition'), 'invalid_transition');
            }

            $old = ['status' => $locked->status->value, 'assigned_to' => $locked->assigned_to];
            $locked->update($changes($locked));

            AuditLog::record($action, $locked, $old, array_filter([
                'status' => $locked->status->value,
                'assigned_to' => $locked->assigned_to,
                'by' => $by->id,
            ] + $meta, fn ($v) => $v !== null));

            $dispute->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }
}
