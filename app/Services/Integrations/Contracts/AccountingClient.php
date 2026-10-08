<?php

namespace App\Services\Integrations\Contracts;

use App\Models\Order;

interface AccountingClient
{
    /** Push a confirmed order as a sales invoice. Returns the external reference, or null if disabled. */
    public function pushInvoice(Order $order): ?string;
}
