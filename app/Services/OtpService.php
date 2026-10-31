<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\OtpCode;
use App\Services\Integrations\Contracts\SmsSender;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

class OtpService
{
    public function __construct(private SmsSender $sms) {}

    /** Normalise Saudi mobile numbers to +9665XXXXXXXX. Emails are lower-cased. */
    public static function normalize(string $identifier): string
    {
        $identifier = trim($identifier);

        if (str_contains($identifier, '@')) {
            return strtolower($identifier);
        }

        // Arabic keyboards type ٠١٢...: read them as 0-9 before dropping everything that is not a digit.
        $digits = preg_replace('/\D/', '', strtr($identifier, array_combine(
            [...mb_str_split('٠١٢٣٤٥٦٧٨٩'), ...mb_str_split('۰۱۲۳۴۵۶۷۸۹')],
            [...range(0, 9), ...range(0, 9)],
        )));

        return match (true) {
            str_starts_with($digits, '9665') => '+'.$digits,
            str_starts_with($digits, '05') => '+966'.substr($digits, 1),
            str_starts_with($digits, '5') && strlen($digits) === 9 => '+966'.$digits,
            default => '+'.ltrim($digits, '+'),
        };
    }

    public function send(string $identifier, string $purpose = 'login', ?string $ip = null): OtpCode
    {
        $identifier = self::normalize($identifier);
        $channel = str_contains($identifier, '@') ? 'email' : 'sms';

        $last = OtpCode::where('identifier', $identifier)->latest('id')->first();
        $wait = config('farah.otp.resend_seconds');
        if ($last && $last->created_at->diffInSeconds(now()) < $wait) {
            throw new BusinessException(__('api.otp.too_soon', ['seconds' => $wait]), 'otp_throttled', 429);
        }

        $length = max(4, (int) config('farah.otp.length', 6));
        $code = (string) random_int(10 ** ($length - 1), 10 ** $length - 1);

        $otp = OtpCode::create([
            'identifier' => $identifier,
            'channel' => $channel,
            'purpose' => $purpose,
            'code_hash' => Hash::make($code),
            'expires_at' => now()->addMinutes(config('farah.otp.ttl_minutes')),
            'ip' => $ip,
        ]);

        $message = __('api.otp.message', ['code' => $code]);

        // Sent after the reply has gone out, so a slow SMS or mail service never holds up the screen.
        $subject = __('api.otp.subject');
        $sms = $this->sms;
        defer(fn () => $channel === 'sms'
            ? $sms->send($identifier, $message)
            : Mail::raw($message, fn ($m) => $m->to($identifier)->subject($subject)));

        return $otp;
    }

    /** Verify and consume the latest code. Throws on failure. */
    public function verify(string $identifier, string $code): void
    {
        $identifier = self::normalize($identifier);
        $devCode = config('farah.otp.dev_code');

        // Only the most recently issued code is valid: older (unused) codes never come back into play.
        $otp = OtpCode::where('identifier', $identifier)
            ->latest('id')
            ->first();

        if (! $otp || $otp->consumed_at || $otp->expires_at->isPast()) {
            throw new BusinessException(__('api.otp.expired'), 'otp_expired');
        }

        // Count the attempt atomically before checking, so parallel guesses cannot exceed the limit.
        $counted = OtpCode::whereKey($otp->id)
            ->where('attempts', '<', (int) config('farah.otp.max_attempts'))
            ->increment('attempts');

        if ($counted === 0) {
            throw new BusinessException(__('api.otp.too_many_attempts'), 'otp_locked', 429);
        }

        $valid = Hash::check($code, $otp->code_hash) || ($devCode && hash_equals((string) $devCode, $code));

        if (! $valid) {
            throw new BusinessException(__('api.otp.invalid'), 'otp_invalid');
        }

        // Single use: only one concurrent request can consume the code.
        $consumed = OtpCode::whereKey($otp->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);

        if ($consumed === 0) {
            throw new BusinessException(__('api.otp.expired'), 'otp_expired');
        }
    }
}
