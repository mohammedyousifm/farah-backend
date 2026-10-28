<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\AuditLog;
use App\Models\Review;
use App\Models\User;
use App\Notifications\SystemNotification;
use Illuminate\Support\Facades\DB;

/**
 * Review moderation (published ⇄ hidden). Venue / provider rating aggregates are
 * refreshed by the Review model on save; every change is audit-logged.
 */
class ReviewModerationService
{
    public const STATUSES = ['published', 'hidden'];

    public function hide(Review $review, User $by, ?string $reason = null): Review
    {
        return $this->setStatus($review, 'hidden', $by, $reason);
    }

    public function publish(Review $review, User $by): Review
    {
        return $this->setStatus($review, 'published', $by);
    }

    /** @param  iterable<Review>  $reviews  Returns how many actually changed. */
    public function hideMany(iterable $reviews, User $by, ?string $reason = null): int
    {
        $changed = 0;
        foreach ($reviews as $review) {
            if ($review->status !== 'hidden') {
                $this->hide($review, $by, $reason);
                $changed++;
            }
        }

        return $changed;
    }

    /** The provider answers a review (or edits its answer); the customer is told. Used by the provider app and panel. */
    public function reply(Review $review, string $reply): Review
    {
        $review->update(['provider_reply' => $reply, 'replied_at' => now()]);

        if ($review->user) {
            SystemNotification::send($review->user, 'review_replied', ['venue' => $review->venue?->tr('name') ?? ''], ['review_id' => $review->id, 'venue_id' => $review->venue_id]);
        }

        return $review;
    }

    /** Remove an abusive provider reply (the review itself stays). */
    public function removeReply(Review $review, User $by, ?string $reason = null): Review
    {
        return DB::transaction(function () use ($review, $by, $reason) {
            $locked = Review::whereKey($review->id)->lockForUpdate()->firstOrFail();
            $old = $locked->provider_reply;
            $locked->update(['provider_reply' => null, 'replied_at' => null]);

            AuditLog::record('review.reply_removed', $locked, ['provider_reply' => $old], array_filter(['by' => $by->id, 'reason' => $reason]));

            return $locked;
        });
    }

    public function setStatus(Review $review, string $status, User $by, ?string $reason = null): Review
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw new BusinessException(__('api.moderation.invalid_transition'), 'invalid_transition');
        }

        return DB::transaction(function () use ($review, $status, $by, $reason) {
            $locked = Review::whereKey($review->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === $status) {
                return $locked; // idempotent
            }

            $old = $locked->status;
            $locked->update(['status' => $status]); // model hook refreshes rating aggregates

            AuditLog::record('review.'.$status, $locked, ['status' => $old], array_filter([
                'status' => $status,
                'by' => $by->id,
                'reason' => $reason,
            ]));

            $review->setRawAttributes($locked->getAttributes(), true);

            return $locked;
        });
    }
}
