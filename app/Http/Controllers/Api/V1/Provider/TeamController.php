<?php

namespace App\Http\Controllers\Api\V1\Provider;

use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Exceptions\BusinessException;
use App\Http\Requests\Api\Provider\MemberRequest;
use App\Http\Resources\MemberResource;
use App\Models\ProviderMember;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\OtpService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Provider team. Members sign in to the app with their own phone (OTP) and act
 * for the provider with the role/permissions granted here.
 */
class TeamController extends ProviderController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        return MemberResource::collection($this->provider($request)->members()->with('user')->orderBy('id')->get());
    }

    public function store(MemberRequest $request): JsonResponse
    {
        $provider = $this->provider($request);
        $permissions = $request->role === 'staff' ? array_values((array) $request->input('permissions', [])) : null;
        $this->assertCanGrant($request, (string) $request->role, $permissions ?? []);

        $phone = OtpService::normalize($request->phone);
        if (User::where('phone', $phone)->where('type', UserType::Admin)->exists()) {
            throw new BusinessException(__('api.forbidden'), 'admin_account', 403);
        }

        $user = User::firstOrCreate(['phone' => $phone], [
            'name' => (string) $request->name,
            'type' => UserType::Customer,
            'status' => UserStatus::Active,
            'locale' => app()->getLocale(),
        ]);

        $existing = $provider->members()->where('user_id', $user->id)->first();
        if ($existing?->is_active) {
            throw new BusinessException(__('api.providers.member_exists'), 'member_exists');
        }

        $attributes = [
            'role' => $request->role,
            'permissions' => $permissions,
            'is_active' => true,
        ];
        $member = $existing ? tap($existing)->update($attributes) : $provider->members()->create($attributes + ['user_id' => $user->id]);

        SystemNotification::send($user, 'team_invited', [
            'provider' => $provider->name_ar,
            'role' => __('api.roles.'.$member->role),
        ], ['provider_id' => $provider->id]);

        return response()->json(['message' => __('api.providers.member_added'), 'data' => new MemberResource($member->load('user'))], 201);
    }

    public function update(MemberRequest $request, int $member): MemberResource
    {
        $model = $this->find($request, $member);
        $data = $request->safe()->only(['role', 'permissions', 'is_active']);

        if (($data['role'] ?? $model->role) !== 'staff') {
            $data['permissions'] = null;
        } elseif (array_key_exists('permissions', $data)) {
            $data['permissions'] = array_values((array) $data['permissions']);
        }
        $this->assertCanGrant($request, $data['role'] ?? $model->role, (array) ($data['permissions'] ?? $model->permissions ?? []), $model);

        $model->update($data);

        return new MemberResource($model->fresh('user'));
    }

    /** Deactivate (memberships are kept for the audit trail). */
    public function destroy(Request $request, int $member): JsonResponse
    {
        $model = $this->find($request, $member);
        $this->assertCanGrant($request, 'staff', [], $model);
        $model->update(['is_active' => false]);

        return response()->json(['message' => __('api.providers.member_deactivated'), 'data' => new MemberResource($model->fresh('user'))]);
    }

    /**
     * Staff holding team.manage cannot hand out more than they have: no managers,
     * no permissions they lack, and no changes to managers.
     */
    private function assertCanGrant(Request $request, string $role, array $permissions, ?ProviderMember $target = null): void
    {
        $actor = $this->member($request);

        if (in_array($actor->role, ['owner', 'manager'], true)) {
            return;
        }

        $own = (array) ($actor->permissions ?? []);
        if ($role !== 'staff' || $target?->role === 'manager' || array_diff($permissions, $own) !== []) {
            throw new BusinessException(__('api.forbidden'), 'cannot_grant', 403);
        }
    }

    private function find(Request $request, int $id): ProviderMember
    {
        $model = $this->provider($request)->members()->findOrFail($id);

        if ($model->role === 'owner') {
            throw new BusinessException(__('api.providers.cannot_edit_owner'), 'cannot_edit_owner', 403);
        }
        if ($model->user_id === $request->user()->id) {
            throw new BusinessException(__('api.providers.cannot_edit_self'), 'cannot_edit_self', 403);
        }

        return $model;
    }
}
