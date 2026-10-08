<?php

namespace App\Services\Integrations\Contracts;

interface SmsSender
{
    public function send(string $phone, string $message): bool;
}
