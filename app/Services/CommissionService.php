<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\CommissionRule;
use App\Models\Order;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Resolves which commission rule applies to an order at a point in time.
 * The sector / category come from the booked activity.
 * Specificity: provider > category > sector > global. Within the same scope
 * the highest version effective at that moment wins (BR-004).
 */
class CommissionService
{
    public function resolve(int $providerId, ?int $categoryId, ?int $sectorId, ?CarbonInterface $at = null): ?CommissionRule
    {
        $at ??= now();

        $candidates = [
            ['provider', $providerId],
            ['category', $categoryId],
            ['sector', $sectorId],
            ['global', null],
        ];

        foreach ($candidates as [$scope, $id]) {
            if ($scope !== 'global' && ! $id) {
                continue;
            }

            $rule = CommissionRule::query()
                ->where('is_active', true)
                ->where('scope', $scope)
                ->when($id, fn ($q) => $q->where('scope_id', $id), fn ($q) => $q->whereNull('scope_id'))
                ->where('effective_from', '<=', $at)
                ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>', $at))
                ->orderByDesc('version')
                ->orderByDesc('effective_from')
                ->first();

            if ($rule) {
                return $rule;
            }
        }

        return null;
    }

    /** @return array{rule: ?CommissionRule, rate: float, fixed: float, amount: float} */
    public function calculate(Order $order, ?CarbonInterface $at = null): array
    {
        $activity = $order->bookings()->with('activity')->first()?->activity;
        $rule = $this->resolve($order->provider_id, $activity?->category_id, $activity?->sector_id, $at);
        $total = $order->total;

        return [
            'rule' => $rule,
            'rate' => $rule && $rule->type !== 'fixed' ? $rule->rate : 0.0,
            'fixed' => $rule && $rule->type !== 'percentage' ? $rule->fixed_amount : 0.0,
            'amount' => $rule ? $rule->calculate($total) : 0.0,
        ];
    }

    /**
     * Create a new version of a rule: the current one is closed at $from and a copy
     * with the new values starts at $from. History stays intact for past orders.
     */
    public function newVersion(CommissionRule $rule, array $changes, CarbonInterface $from): CommissionRule
    {
        return DB::transaction(function () use ($rule, $changes, $from) {
            $locked = CommissionRule::whereKey($rule->id)->lockForUpdate()->firstOrFail();

            // Only the open (latest) version can be superseded, and not before it started:
            // otherwise two versions would overlap or history would be rewritten.
            if ($locked->effective_to !== null) {
                throw new BusinessException(__('admin.wrong_status'), 'rule_already_closed');
            }
            if ($from->lessThanOrEqualTo($locked->effective_from)) {
                throw new BusinessException(__('admin.wrong_status'), 'invalid_effective_from');
            }

            $locked->update(['effective_to' => $from]);
            $rule->setRawAttributes($locked->getAttributes(), true);

            // Scope and version chain are fixed; only the commercial terms change.
            $changes = array_intersect_key($changes, array_flip(['name', 'type', 'rate', 'fixed_amount', 'notes']));

            return CommissionRule::create(array_merge(
                $locked->only(['name', 'scope', 'scope_id', 'type', 'rate', 'fixed_amount', 'notes']),
                $changes,
                [
                    'version' => $locked->version + 1,
                    'effective_from' => $from,
                    'effective_to' => null,
                    'is_active' => true,
                    'created_by' => auth()->id(),
                ],
            ));
        });
    }
}
