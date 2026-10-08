<?php

namespace App\Services\Integrations\Drivers;

use App\Services\Integrations\Contracts\WathqClient;

/** Sandbox: any 10-digit CR is "active" except those ending in 0 (not found) or 9 (expired). */
class FakeWathqClient implements WathqClient
{
    public function commercialRegister(string $crNumber): ?array
    {
        if (! preg_match('/^\d{10}$/', $crNumber) || str_ends_with($crNumber, '0')) {
            return null;
        }

        $expired = str_ends_with($crNumber, '9');

        return [
            'cr_number' => $crNumber,
            'name' => 'مؤسسة تجريبية '.substr($crNumber, -4),
            'status' => $expired ? 'expired' : 'active',
            'issue_date' => now()->subYears(3)->toDateString(),
            'expiry_date' => ($expired ? now()->subMonth() : now()->addYear())->toDateString(),
            'activities' => ['تنظيم الفعاليات', 'الترفيه'],
        ];
    }
}
