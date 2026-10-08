<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Auth\DeviceTokenRequest;
use App\Http\Requests\Api\Auth\RequestOtpRequest;
use App\Http\Requests\Api\Auth\UpdateProfileRequest;
use App\Http\Requests\Api\Auth\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Passwordless login: request an OTP for a phone/email, verify it, receive a
 * Sanctum token. The same flow registers new customers (is_new = true).
 */
class AuthController extends Controller
{
    public function __construct(private OtpService $otp) {}

    public function requestOtp(RequestOtpRequest $request): JsonResponse
    {
        $otp = $this->otp->send($request->identifier, 'login', $request->ip());

        return response()->json([
            'message' => __('api.otp.sent'),
            'data' => [
                'identifier' => $otp->identifier,
                'channel' => $otp->channel,
                'expires_in' => config('farah.otp.ttl_minutes') * 60,
                'resend_in' => config('farah.otp.resend_seconds'),
            ],
        ]);
    }

    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $identifier = OtpService::normalize($request->identifier);
        $this->otp->verify($identifier, $request->code);

        $isEmail = str_contains($identifier, '@');
        $column = $isEmail ? 'email' : 'phone';

        $user = User::withTrashed()->where($column, $identifier)->first();
        $isNew = ! $user || $user->trashed();

        if ($user?->trashed()) {
            // A deleted account frees the identifier: start a fresh profile.
            $user->forceFill([$column => null])->saveQuietly();
            $user = null;
        }

        $user ??= User::create([
            'name' => $isEmail ? strstr($identifier, '@', true) : '',
            $column => $identifier,
            'type' => UserType::Customer,
            'status' => UserStatus::Active,
            'locale' => $request->input('locale', app()->getLocale()),
        ]);

        if ($user->status === UserStatus::Suspended) {
            return response()->json(['message' => __('api.account_suspended'), 'code' => 'account_suspended'], 403);
        }

        $user->forceFill([
            $isEmail ? 'email_verified_at' : 'phone_verified_at' => now(),
            'last_login_at' => now(),
        ])->save();

        $token = $user->createToken(
            $request->input('device_name', 'mobile'),
            ['*'],
            now()->addDays((int) config('farah.api.token_ttl_days', 90)),
        )->plainTextToken;

        return response()->json([
            'data' => [
                'token' => $token,
                'token_type' => 'Bearer',
                'is_new' => $isNew || $user->name === '',
                'user' => new UserResource($user->load('city', 'providers')),
            ],
        ]);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('city', 'providers'));
    }

    public function update(UpdateProfileRequest $request): UserResource
    {
        $user = $request->user();
        $data = $request->safe()->except('avatar');

        if ($request->hasFile('avatar')) {
            $data['avatar'] = $request->file('avatar')->store('avatars', 'public');
        }
        if (array_key_exists('email', $data) && $data['email'] !== $user->email) {
            $data['email_verified_at'] = null;
        }

        $user->update($data);

        return new UserResource($user->fresh()->load('city', 'providers'));
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()?->delete();

        return response()->json(['message' => __('api.auth.logged_out')]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();

        DB::transaction(function () use ($user) {
            $user->tokens()->delete();
            $user->deviceTokens()->delete();
            $user->delete();
        });

        return response()->json(['message' => __('api.auth.deleted')]);
    }

    public function storeDeviceToken(DeviceTokenRequest $request): JsonResponse
    {
        $request->user()->deviceTokens()->updateOrCreate(
            ['token' => $request->token],
            ['platform' => $request->platform, 'last_used_at' => now()],
        );

        return response()->json(['message' => __('api.saved')], 201);
    }

    public function destroyDeviceToken(DeviceTokenRequest $request): JsonResponse
    {
        $request->user()->deviceTokens()->where('token', $request->token)->delete();

        return response()->json(['message' => __('api.deleted')]);
    }
}
