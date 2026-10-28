<?php

namespace App\Http\Controllers;

use App\Enums\ProviderStatus;
use App\Models\Provider;
use App\Models\ProviderMember;
use App\Models\User;
use App\Support\PhoneScanner;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The page a provider opens on a phone to scan booking codes for the check-in screen on the
 * computer (see PhoneScanner). No login: the token in the link was created by a signed-in member.
 */
class PhoneScanController extends Controller
{
    public function show(string $token): View
    {
        [$provider, $user] = $this->resolve($token);
        app()->setLocale($user->locale ?: 'ar');
        PhoneScanner::touch($token);

        return view('provider.phone-scan', ['token' => $token, 'provider' => $provider, 'text' => [
            'camera_error' => __('provider.checkin.camera_error'),
            'no_code' => __('provider.checkin.phone_no_code'),
            'offline' => __('provider.checkin.phone_offline'),
            'expired' => __('provider.checkin.phone_expired'),
        ]]);
    }

    public function scan(Request $request, string $token): JsonResponse
    {
        [$provider, $user, $venueId] = $this->resolve($token);
        app()->setLocale($user->locale ?: 'ar');
        PhoneScanner::touch($token);

        // The phone pings while idle so the computer can show it is still connected.
        if ($request->boolean('ping')) {
            return response()->json(['ok' => true]);
        }

        $data = $request->validate(['code' => ['required', 'string', 'max:30']]);

        $result = PhoneScanner::attempt($provider, $data['code'], $venueId, $user);
        PhoneScanner::push($token, $result);

        return response()->json($result);
    }

    /** @return array{0: Provider, 1: User, 2: ?int} */
    private function resolve(string $token): array
    {
        $link = PhoneScanner::find($token);
        abort_if($link === null, 404);

        $member = ProviderMember::with(['provider', 'user'])
            ->where('provider_id', $link['provider_id'])
            ->where('user_id', $link['user_id'])
            ->first();

        // The member who made the link must still be active, allowed to check customers in, and the
        // provider not suspended (the link lives for a whole shift; rights can change meanwhile).
        abort_unless(
            $member?->provider && $member->user?->isActive()
            && $member->allows('bookings.checkin')
            && $member->provider->status !== ProviderStatus::Suspended,
            404,
        );

        return [$member->provider, $member->user, $link['venue_id']];
    }
}
