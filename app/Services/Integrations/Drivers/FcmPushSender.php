<?php

namespace App\Services\Integrations\Drivers;

use App\Models\DeviceToken;
use App\Services\Integrations\Contracts\PushSender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Push notifications through Firebase Cloud Messaging (HTTP v1).
 *
 * Switch on with PUSH_DRIVER=fcm and FIREBASE_CREDENTIALS=<path to the service-account JSON downloaded from
 * the Firebase console> (Project settings → Service accounts → Generate new private key). No Firebase package
 * is needed: the access token is requested with a signed JWT, and each device gets one HTTPS call.
 */
class FcmPushSender implements PushSender
{
    private const SCOPE = 'https://www.googleapis.com/auth/firebase.messaging';

    public function send(array $tokens, string $title, string $body, array $data = []): int
    {
        $tokens = array_values(array_unique(array_filter($tokens)));
        if ($tokens === []) {
            return 0;
        }

        try {
            $account = $this->account();
            $access = $this->accessToken($account);
        } catch (Throwable $e) {
            // A push must never break the action that caused it (a payment, an approval, ...).
            Log::error('[PUSH] Firebase is not usable: '.$e->getMessage());

            return 0;
        }

        $url = "https://fcm.googleapis.com/v1/projects/{$account['project_id']}/messages:send";
        // FCM data values must all be strings.
        $payload = array_map(fn ($value) => is_scalar($value) || $value === null ? (string) $value : json_encode($value), $data);
        $delivered = 0;

        foreach ($tokens as $token) {
            try {
                $response = Http::withToken($access)->timeout(10)->post($url, ['message' => [
                    'token' => $token,
                    'notification' => ['title' => $title, 'body' => $body],
                    'data' => $payload,
                    'android' => ['priority' => 'HIGH', 'notification' => ['channel_id' => 'farah', 'sound' => 'default']],
                    'apns' => ['payload' => ['aps' => ['sound' => 'default']]],
                ]]);

                if ($response->successful()) {
                    $delivered++;
                } elseif ($this->isDeadToken($response->status(), (string) $response->json('error.status'), $response->json('error.details') ?? [])) {
                    // The app was removed or the token rotated: stop sending to it.
                    DeviceToken::where('token', $token)->delete();
                } else {
                    Log::warning('[PUSH] Firebase refused a message', ['status' => $response->status(), 'error' => $response->json('error.message')]);
                }
            } catch (Throwable $e) {
                Log::warning('[PUSH] Firebase call failed: '.$e->getMessage());
            }
        }

        return $delivered;
    }

    /** @return array{project_id: string, client_email: string, private_key: string, token_uri?: string} */
    private function account(): array
    {
        $path = (string) config('farah.integrations.push.credentials');
        if ($path === '' || ! is_file($path)) {
            throw new RuntimeException('FIREBASE_CREDENTIALS does not point to a service-account file');
        }

        $account = json_decode((string) file_get_contents($path), true);
        foreach (['project_id', 'client_email', 'private_key'] as $field) {
            if (empty($account[$field])) {
                throw new RuntimeException("the service-account file has no {$field}");
            }
        }

        return $account;
    }

    /** Google access token for the service account, reused until shortly before it expires. */
    private function accessToken(array $account): string
    {
        return Cache::remember('fcm.access.'.sha1($account['client_email']), 3300, function () use ($account) {
            $uri = $account['token_uri'] ?? 'https://oauth2.googleapis.com/token';
            $now = time();
            $segments = [
                $this->base64Url(json_encode(['alg' => 'RS256', 'typ' => 'JWT'])),
                $this->base64Url(json_encode(['iss' => $account['client_email'], 'scope' => self::SCOPE, 'aud' => $uri, 'iat' => $now, 'exp' => $now + 3600])),
            ];

            if (! openssl_sign(implode('.', $segments), $signature, $account['private_key'], OPENSSL_ALGO_SHA256)) {
                throw new RuntimeException('the private key in the service-account file could not sign');
            }
            $segments[] = $this->base64Url($signature);

            $response = Http::asForm()->timeout(10)->post($uri, [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => implode('.', $segments),
            ]);

            if (! $response->successful() || ! $response->json('access_token')) {
                throw new RuntimeException('Google did not issue an access token ('.$response->status().')');
            }

            return (string) $response->json('access_token');
        });
    }

    private function isDeadToken(int $status, string $error, array $details): bool
    {
        $codes = collect($details)->pluck('errorCode')->filter()->all();

        return $status === 404 || $error === 'NOT_FOUND' || in_array('UNREGISTERED', $codes, true)
            || ($error === 'INVALID_ARGUMENT' && in_array('INVALID_ARGUMENT', $codes, true));
    }

    private function base64Url(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
