<?php

namespace App\Services;

use App\Enums\DocumentStatus;
use App\Enums\ProviderStatus;
use App\Enums\RequestStatus;
use App\Enums\UserType;
use App\Enums\VenueStatus;
use App\Exceptions\BusinessException;
use App\Models\Provider;
use App\Models\ProviderDocument;
use App\Models\Sector;
use App\Models\SectorRequest;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\SystemNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Provider onboarding, verification and approval workflow (FR-PRO, FR-ADM). */
class ProviderService
{
    public function register(User $owner, array $data): Provider
    {
        if ($owner->ownedProvider()->exists()) {
            throw new BusinessException(__('api.providers.already_registered'), 'already_registered');
        }

        return DB::transaction(function () use ($owner, $data) {
            $sectorIds = $data['sector_ids'] ?? [];
            unset($data['sector_ids']);

            $provider = Provider::create(array_merge($data, [
                'owner_id' => $owner->id,
                'slug' => Str::slug($data['name_en'] ?? '') ?: Str::lower(Str::random(8)),
                'status' => ProviderStatus::Pending,
                'submitted_at' => now(),
            ]));
            $provider->update(['slug' => $provider->slug.'-'.$provider->id]);

            $provider->members()->create(['user_id' => $owner->id, 'role' => 'owner', 'is_active' => true]);
            $provider->sectors()->sync(collect($sectorIds)->mapWithKeys(fn ($id) => [$id => ['status' => 'pending']])->all());

            $owner->update(['type' => UserType::Provider]);

            return $provider;
        });
    }

    public function approve(Provider $provider, User $by, array $sectorIds = []): void
    {
        $missing = $provider->documents()->where('status', DocumentStatus::Pending)->count();
        if ($missing > 0) {
            throw new BusinessException(__('admin.providers.pending_documents', ['count' => $missing]), 'pending_documents');
        }

        DB::transaction(function () use ($provider, $by, $sectorIds) {
            $provider->update([
                'status' => ProviderStatus::Approved,
                'approved_at' => now(),
                'approved_by' => $by->id,
                'rejection_reason' => null,
            ]);

            $ids = $sectorIds ?: $provider->sectors()->pluck('sectors.id')->all();
            foreach ($ids as $id) {
                $provider->sectors()->syncWithoutDetaching([$id => ['status' => 'approved']]);
            }
        });

        SystemNotification::send($provider->owner, 'provider_approved', ['name' => $provider->name_ar]);

        // One request, one decision: a store the provider already sent for review goes live with the approval.
        foreach ($provider->venues()->where('status', VenueStatus::PendingReview)->get() as $venue) {
            app(VenueService::class)->approve($venue, $by);
        }
    }

    public function reject(Provider $provider, User $by, string $reason): void
    {
        $provider->update(['status' => ProviderStatus::Rejected, 'rejection_reason' => $reason]);
        SystemNotification::send($provider->owner, 'provider_rejected', ['reason' => $reason]);
    }

    /** Suspension hides all the provider's venues immediately. */
    public function suspend(Provider $provider, User $by, string $reason): void
    {
        DB::transaction(function () use ($provider, $reason) {
            $provider->update(['status' => ProviderStatus::Suspended, 'rejection_reason' => $reason]);
            $provider->venues()->where('status', VenueStatus::Published)->update(['status' => VenueStatus::Suspended]);
        });

        SystemNotification::send($provider->owner, 'provider_suspended', ['reason' => $reason]);
    }

    public function reactivate(Provider $provider, User $by): void
    {
        DB::transaction(function () use ($provider, $by) {
            $provider->update(['status' => ProviderStatus::Approved, 'rejection_reason' => null, 'approved_by' => $by->id]);
            $provider->venues()->where('status', VenueStatus::Suspended)->update(['status' => VenueStatus::Published]);
        });

        SystemNotification::send($provider->owner, 'provider_approved', ['name' => $provider->name_ar]);
    }

    public function approveDocument(ProviderDocument $document, User $by): void
    {
        $document->update(['status' => DocumentStatus::Approved, 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'rejection_reason' => null]);
        SystemNotification::send($document->provider->owner, 'document_approved', ['type' => $document->type->getLabel()]);
    }

    public function rejectDocument(ProviderDocument $document, User $by, string $reason): void
    {
        $document->update(['status' => DocumentStatus::Rejected, 'reviewed_by' => $by->id, 'reviewed_at' => now(), 'rejection_reason' => $reason]);
        SystemNotification::send($document->provider->owner, 'document_rejected', ['type' => $document->type->getLabel(), 'reason' => $reason]);
    }

    /**
     * Scheduler: warn owners about documents close to expiry and mark expired ones.
     * Returns [warned, expired].
     */
    public function checkDocumentExpiry(): array
    {
        $days = (int) Setting::get('document_expiry_warning_days');
        $warned = 0;

        ProviderDocument::with('provider.owner')
            ->where('status', DocumentStatus::Approved)
            ->whereNull('expiry_notified_at')
            ->whereBetween('expires_at', [now()->toDateString(), now()->addDays($days)->toDateString()])
            ->each(function (ProviderDocument $doc) use (&$warned) {
                SystemNotification::send($doc->provider->owner, 'document_expiring', [
                    'type' => $doc->type->getLabel(),
                    'date' => $doc->expires_at->toDateString(),
                ]);
                $doc->update(['expiry_notified_at' => now()]);
                $warned++;
            });

        $expired = ProviderDocument::where('status', DocumentStatus::Approved)
            ->where('expires_at', '<', now()->toDateString())
            ->update(['status' => DocumentStatus::Expired]);

        return [$warned, $expired];
    }

    public function approveSectorRequest(SectorRequest $request, User $by, ?Sector $existing = null): Sector
    {
        if ($request->status !== RequestStatus::Pending) {
            throw new BusinessException(__('admin.wrong_status'), 'wrong_status');
        }

        return DB::transaction(function () use ($request, $by, $existing) {
            $sector = $existing ?? Sector::create([
                'name_ar' => $request->name_ar,
                'name_en' => $request->name_en ?: $request->name_ar,
                'slug' => Str::slug($request->name_en ?: '') ?: 'sector-'.Str::lower(Str::random(6)),
                'description_ar' => $request->description,
                'is_active' => true,
            ]);

            $request->update(['status' => RequestStatus::Approved, 'sector_id' => $sector->id, 'reviewed_by' => $by->id, 'reviewed_at' => now()]);
            $request->provider->sectors()->syncWithoutDetaching([$sector->id => ['status' => 'approved']]);

            SystemNotification::send($request->provider->owner, 'sector_request_approved', ['name' => $sector->name_ar]);

            return $sector;
        });
    }

    public function rejectSectorRequest(SectorRequest $request, User $by, string $notes): void
    {
        $request->update(['status' => RequestStatus::Rejected, 'admin_notes' => $notes, 'reviewed_by' => $by->id, 'reviewed_at' => now()]);
        SystemNotification::send($request->provider->owner, 'sector_request_rejected', ['name' => $request->name_ar, 'reason' => $notes]);
    }
}
