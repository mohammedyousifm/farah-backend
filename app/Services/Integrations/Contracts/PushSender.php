<?php

namespace App\Services\Integrations\Contracts;

interface PushSender
{
    /**
     * @param  array<int, string>  $tokens
     * @return int number of tokens the message was delivered to
     */
    public function send(array $tokens, string $title, string $body, array $data = []): int;
}
