<?php

namespace App\Services\Integrations;

use App\Models\IntegrationLog;

class IntegrationLogger
{
    /**
     * Run an outbound call and record request/response/timing in integration_logs.
     *
     * @template T
     *
     * @param  callable(): T  $call
     * @return T
     */
    public static function call(string $service, string $action, array $request, callable $call): mixed
    {
        $started = microtime(true);

        try {
            $response = $call();
            static::write($service, $action, $request, is_array($response) ? $response : ['result' => $response], true, $started);

            return $response;
        } catch (\Throwable $e) {
            static::write($service, $action, $request, ['error' => $e->getMessage()], false, $started);
            throw $e;
        }
    }

    private static function write(string $service, string $action, array $request, array $response, bool $success, float $started): void
    {
        IntegrationLog::create([
            'service' => $service,
            'action' => $action,
            'request' => $request,
            'response' => $response,
            'success' => $success,
            'duration_ms' => (int) ((microtime(true) - $started) * 1000),
        ]);
    }
}
