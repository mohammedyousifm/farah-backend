<?php

namespace App\Services\Integrations\Contracts;

interface WathqClient
{
    /** Look up a commercial register; null when not found. Keys: cr_number, name, status, expiry_date, activities. */
    public function commercialRegister(string $crNumber): ?array;
}
