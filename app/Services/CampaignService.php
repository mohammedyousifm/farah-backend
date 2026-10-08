<?php

namespace App\Services;

use App\Enums\ProviderStatus;
use App\Enums\UserStatus;
use App\Enums\UserType;
use App\Exceptions\BusinessException;
use App\Models\PushCampaign;
use App\Models\User;
use App\Notifications\SystemNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Marketing push campaigns: in-app notification + push to devices for an audience.
 * Customers only receive campaigns when they opted in to marketing.
 */
class CampaignService
{
    public const AUDIENCES = ['all', 'customers', 'providers'];

    /** Active users a campaign would reach. */
    public function recipients(PushCampaign $campaign): Builder
    {
        $customers = fn (Builder $q) => $q->where('type', UserType::Customer)->where('marketing_consent', true);
        $providers = fn (Builder $q) => $q
            ->whereHas('ownedProvider', fn ($p) => $p->where('status', ProviderStatus::Approved))
            ->orWhereHas('providers', fn ($p) => $p->where('providers.status', ProviderStatus::Approved)->where('provider_members.is_active', true));

        return User::query()
            ->where('status', UserStatus::Active)
            ->where('type', '!=', UserType::Admin)
            ->where(fn (Builder $q) => match ($campaign->audience) {
                'customers' => $customers($q),
                'providers' => $providers($q),
                default => $q->where(fn ($c) => $customers($c))->orWhere(fn ($p) => $providers($p)),
            });
    }

    /** Send now. Returns the number of recipients. */
    public function send(PushCampaign $campaign): int
    {
        $claimed = DB::transaction(function () use ($campaign) {
            $locked = PushCampaign::whereKey($campaign->id)->lockForUpdate()->first();
            if (! $locked || $locked->status === 'sent' || $locked->sent_at) {
                return false;
            }
            $locked->update(['status' => 'sent', 'sent_at' => now()]);

            return true;
        });

        if (! $claimed) {
            throw new BusinessException(__('admin.campaigns.already_sent'), 'already_sent');
        }

        $count = 0;
        $data = [
            'campaign_id' => $campaign->id,
            'link_type' => $campaign->link_type,
            'link_value' => $campaign->link_value,
        ];

        $this->recipients($campaign)->chunkById(500, function ($users) use ($campaign, $data, &$count) {
            foreach ($users as $user) {
                $en = ($user->locale ?? 'ar') === 'en';
                SystemNotification::send($user, 'campaign', [
                    'title' => ($en ? $campaign->title_en : null) ?: $campaign->title_ar,
                    'body' => ($en ? $campaign->body_en : null) ?: $campaign->body_ar,
                ], $data);
                $count++;
            }
        });

        $campaign->forceFill(['status' => 'sent', 'sent_at' => $campaign->sent_at ?? now(), 'recipients_count' => $count])->save();

        return $count;
    }

    /** Scheduler: send scheduled campaigns whose time has come. Returns campaigns sent. */
    public function sendDue(): int
    {
        $sent = 0;

        PushCampaign::where('status', 'scheduled')
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->orderBy('scheduled_at')
            ->each(function (PushCampaign $campaign) use (&$sent) {
                try {
                    $this->send($campaign);
                    $sent++;
                } catch (BusinessException) {
                    // already sent by a concurrent run
                }
            });

        return $sent;
    }
}
