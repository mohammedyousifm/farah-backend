<?php

namespace Tests\Feature\Api;

use App\Enums\UserStatus;
use App\Models\User;

class AuthTest extends ApiTestCase
{
    public function test_otp_login_creates_a_customer_and_returns_a_token(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['identifier' => '0501234567'])
            ->assertOk()
            ->assertJsonPath('data.identifier', '+966501234567')
            ->assertJsonPath('data.channel', 'sms');

        $response = $this->postJson('/api/v1/auth/otp/verify', ['identifier' => '0501234567', 'code' => '1234'])
            ->assertOk()
            ->assertJsonPath('data.is_new', true)
            ->assertJsonPath('data.token_type', 'Bearer')
            ->assertJsonPath('data.user.phone', '+966501234567');

        $this->assertDatabaseHas('users', ['phone' => '+966501234567', 'type' => 'customer']);

        $token = $response->json('data.token');
        $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.phone', '+966501234567');

        // The name needs a first name and a family name.
        foreach (['S', 'Sara', 'Sara A'] as $short) {
            $this->withToken($token)->putJson('/api/v1/me', ['name' => $short])->assertStatus(422)->assertJsonValidationErrors(['name']);
        }

        $this->withToken($token)->putJson('/api/v1/me', ['name' => 'Sara Ahmed', 'tracking_consent' => true])
            ->assertOk()
            ->assertJsonPath('data.name', 'Sara Ahmed');

        // Second login: existing user, not new.
        $this->travel(2)->minutes();
        $this->postJson('/api/v1/auth/otp/request', ['identifier' => '+966501234567'])->assertOk();
        $this->postJson('/api/v1/auth/otp/verify', ['identifier' => '+966501234567', 'code' => '1234'])
            ->assertOk()
            ->assertJsonPath('data.is_new', false);
        $this->assertSame(1, User::where('phone', '+966501234567')->count());
    }

    public function test_wrong_code_is_rejected_with_error_code(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['identifier' => '0509999999'])->assertOk();

        $this->postJson('/api/v1/auth/otp/verify', ['identifier' => '0509999999', 'code' => '0000'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'otp_invalid');
    }

    public function test_validation_unauthenticated_and_suspended_responses_are_json(): void
    {
        $this->postJson('/api/v1/auth/otp/request', ['identifier' => 'not-a-phone'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('identifier');

        $this->getJson('/api/v1/me')
            ->assertStatus(401)
            ->assertJsonPath('code', 'unauthenticated');

        $user = $this->customer('+966502222222', ['status' => UserStatus::Suspended]);
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertStatus(403)
            ->assertJsonPath('code', 'account_suspended');
    }

    public function test_logout_revokes_the_token(): void
    {
        $user = $this->customer();
        $token = $user->createToken('test')->plainTextToken;

        $this->withToken($token)->postJson('/api/v1/auth/logout')->assertOk();
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_locale_follows_accept_language(): void
    {
        $this->getJson('/api/v1/me', ['Accept-Language' => 'en'])
            ->assertStatus(401)
            ->assertHeader('Content-Language', 'en')
            ->assertJsonPath('message', __('api.unauthenticated', [], 'en'));

        // Unsupported language → Arabic (note: the test client sends "en-us" by default).
        $this->getJson('/api/v1/me', ['Accept-Language' => 'fr'])
            ->assertHeader('Content-Language', 'ar')
            ->assertJsonPath('message', __('api.unauthenticated', [], 'ar'));
    }
}
