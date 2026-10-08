<?php

namespace App\Services\Integrations\Drivers;

use App\Services\Integrations\Contracts\PushSender;
use Illuminate\Support\Facades\Log;

class LogPushSender implements PushSender
{
    public function send(array $tokens, string $title, string $body, array $data = []): int
    {
        Log::info('[PUSH x'.count($tokens)."] {$title}: {$body}", $data);

        return count($tokens);
    }
}
