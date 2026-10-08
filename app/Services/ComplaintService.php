<?php

namespace App\Services;

use App\Enums\ComplaintStatus;
use App\Exceptions\BusinessException;
use App\Models\AuditLog;
use App\Models\Complaint;
use App\Models\ComplaintReply;
use App\Models\User;
use App\Notifications\SystemNotification;
use Illuminate\Support\Facades\DB;

/**
 * Support tickets: open → in_progress → resolved → closed (resolved / closed can be re-opened).
 * All transitions are validated, row-locked and audit-logged.
 */
class ComplaintService
{
    /** Allowed status transitions. */
    public const TRANSITIONS = [
        'open' => ['in_progress', 'resolved', 'closed'],
        'in_progress' => ['open', 'resolved', 'closed'],
        'resolved' => ['in_progress', 'closed'],
        'closed' => ['in_progress'],
    ];

    /** Assign (or unassign with null). Assigning an open ticket starts work on it. */
    public function assign(Complaint $complaint, ?User $assignee, User $by): Complaint
    {
        return $this->mutate($complaint, 'complaint.assigned', $by, function (Complaint $c) use ($assignee) {
            $changes = ['assigned_to' => $assignee?->id];
            if ($assignee && $c->status === ComplaintStatus::Open) {
                $changes['status'] = ComplaintStatus::InProgress;
            }

            return $changes;
        });
    }

    /**
     * Add a reply. Staff replies move an open ticket to in_progress and notify the customer
     * (unless internal); a customer reply re-opens a resolved ticket. Closed tickets are read-only.
     */
    public function reply(Complaint $complaint, User $by, string $body, bool $internal = false): ComplaintReply
    {
        $isCustomer = $by->id === $complaint->user_id;
        $internal = $internal && ! $isCustomer;

        $reply = DB::transaction(function () use ($complaint, $by, $body, $internal, $isCustomer) {
            $locked = Complaint::whereKey($complaint->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === ComplaintStatus::Closed) {
                throw new BusinessException(__('api.complaints.closed'), 'complaint_closed');
            }

            $reply = $locked->replies()->create(['user_id' => $by->id, 'body' => $body, 'is_internal' => $internal]);

            $next = match (true) {
                $isCustomer && $locked->status === ComplaintStatus::Resolved => ComplaintStatus::InProgress,
                ! $isCustomer && $locked->status === ComplaintStatus::Open => ComplaintStatus::InProgress,
                default => null,
            };

            if ($next) {
                $old = $locked->status->value;
                $locked->update(['status' => $next, 'resolved_at' => null]);
                AuditLog::record('complaint.status_changed', $locked, ['status' => $old], ['status' => $next->value, 'by' => $by->id]);
            }

            $complaint->setRawAttributes($locked->getAttributes(), true);

            return $reply;
        });

        if (! $isCustomer && ! $internal && $complaint->user) {
            SystemNotification::send($complaint->user, 'complaint_replied', ['number' => $complaint->number], ['complaint_id' => $complaint->id]);
        }

        return $reply;
    }

    public function resolve(Complaint $complaint, User $by, string $resolution): Complaint
    {
        $complaint = $this->changeStatus($complaint, ComplaintStatus::Resolved, $by, $resolution);

        if ($complaint->user) {
            SystemNotification::send($complaint->user, 'complaint_resolved', ['number' => $complaint->number], ['complaint_id' => $complaint->id]);
        }

        return $complaint;
    }

    public function close(Complaint $complaint, User $by): Complaint
    {
        return $this->changeStatus($complaint, ComplaintStatus::Closed, $by);
    }

    public function changeStatus(Complaint $complaint, ComplaintStatus $to, User $by, ?string $resolution = null): Complaint
    {
        return $this->mutate($complaint, 'complaint.status_changed', $by, function (Complaint $c) use ($to, $resolution) {
            if (! in_array($to->value, self::TRANSITIONS[$c->status->value] ?? [], true)) {
                throw new BusinessException(__('api.moderation.invalid_transition'), 'invalid_transition');
            }

            $done = in_array($to, [ComplaintStatus::Resolved, ComplaintStatus::Closed], true);

            return array_filter([
                'status' => $to,
                'resolved_at' => $done ? ($c->resolved_at ?? now()) : null,
                'resolution' => $resolution,
            ], fn ($v, $k) => $v !== null || $k === 'resolved_at', ARRAY_FILTER_USE_BOTH);
        });
    }

    public function setPriority(Complaint $complaint, string $priority, User $by): Complaint
    {
        if (! in_array($priority, Complaint::PRIORITIES, true)) {
            throw new BusinessException(__('api.moderation.invalid_transition'), 'invalid_priority');
        }

        return $this->mutate($complaint, 'complaint.priority_changed', $by, fn () => ['priority' => $priority]);
    }

    /** @param  callable(Complaint): array  $changes */
    private function mutate(Complaint $complaint, string $action, User $by, callable $changes): Complaint
    {
        return DB::transaction(function () use ($complaint, $action, $by, $changes) {
            $locked = Complaint::whereKey($complaint->id)->lockForUpdate()->firstOrFail();
            $data = $changes($locked);

            $old = array_map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v, $locked->only(array_keys($data)));
            $locked->update($data);
            $new = array_map(fn ($v) => $v instanceof \BackedEnum ? $v->value : $v, $locked->only(array_keys($data)));

            AuditLog::record($action, $locked, $old, $new + ['by' => $by->id]);
            $complaint->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }
}
