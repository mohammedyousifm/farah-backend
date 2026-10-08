<?php

namespace App\Services\Integrations\Drivers;

use App\Models\Order;
use App\Services\Integrations\Contracts\AccountingClient;

/** No accounting system connected yet (pending decision: Qoyod / Daftra / other). */
class NullAccountingClient implements AccountingClient
{
    public function pushInvoice(Order $order): ?string
    {
        return null;
    }
}
