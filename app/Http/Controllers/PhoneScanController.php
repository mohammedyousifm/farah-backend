<?php

namespace App\Http\Controllers;

use App\Models\Provider;
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

        $provider = Provider::find($link['provider_id']);
        $user = User::find($link['user_id']);

        // The member who made the link must still be an active member of this provider.
        abort_unless($provider && $user && $user->activeProviders()->whereKey($provider->id)->exists(), 404);

        return [$provider, $user, $link['venue_id']];
    }
}
