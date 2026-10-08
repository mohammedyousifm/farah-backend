<?php

namespace App\Services\Integrations\Drivers;

use App\Services\Integrations\Contracts\SmsSender;
use Illuminate\Support\Facades\Log;

class LogSmsSender implements SmsSender
{
    public function send(string $phone, string $message): bool
    {
        // Never write OTP codes (or any message body) to production logs.
        $body = app()->isProduction() ? preg_replace('/\d{4,}/', '****', $message) : $message;
        Log::info("[SMS to {$phone}] {$body}");

        return true;
    }
}
