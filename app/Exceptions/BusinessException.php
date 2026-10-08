<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * A rule of the business was violated (sold out, wrong status, ...).
 * Rendered as 422 JSON for the API; Filament actions catch it and show a notification.
 */
class BusinessException extends RuntimeException
{
    public function __construct(string $message, public readonly string $errorCode = 'business_rule', public readonly int $status = 422)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => $this->errorCode,
        ], $this->status);
    }
}
