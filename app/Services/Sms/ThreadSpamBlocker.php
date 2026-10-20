<?php

namespace App\Services\Sms;

use App\Models\BlockedCaller;
use App\Models\SmsGroupThread;
use App\Services\GroupSmsService;

/**
 * "Mark as spam" / "Unblock" for a whole thread's participant numbers —
 * shared by the web conversation
 * (App\Livewire\Sms\SmsConversation::markThreadAsSpam()/unblockThreadSpam())
 * and the app's thread spam endpoints.
 */
class ThreadSpamBlocker
{
    /**
     * This thread's external participant numbers (never our own business
     * numbers), normalized to the dial target format BlockedCaller rows use.
     *
     * @return array<int, string>
     */
    public function targetPhones(SmsGroupThread $thread): array
    {
        $thread->loadMissing('threadParticipants');

        return collect()
            ->merge($thread->threadParticipants->pluck('phone_number')->all())
            ->merge(is_array($thread->participants) ? $thread->participants : [])
            ->filter(fn ($phone) => is_string($phone) && trim($phone) !== '')
            ->map(fn ($phone) => GroupSmsService::formatE164($phone))
            ->filter(fn ($phone) => is_string($phone) && $phone !== '')
            ->reject(fn ($phone) => GroupSmsService::isOurNumber($phone))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Blocks every external participant number. Returns the phones that
     * were (newly or already) blocked, or [] when the thread has none.
     *
     * @return array<int, string>
     */
    public function markAsSpam(SmsGroupThread $thread, int $actorId): array
    {
        $targets = $this->targetPhones($thread);

        foreach ($targets as $phone) {
            BlockedCaller::firstOrCreate(
                ['phone_number' => $phone],
                ['reason' => 'Manually marked as spam from messages', 'blocked_by_user_id' => $actorId, 'auto_blocked' => false]
            );
        }

        return $targets;
    }

    /**
     * Unblocks every external participant number. Returns the number of
     * BlockedCaller rows actually removed.
     */
    public function unblock(SmsGroupThread $thread): int
    {
        $targets = $this->targetPhones($thread);

        if ($targets === []) {
            return 0;
        }

        return BlockedCaller::query()->whereIn('phone_number', $targets)->delete();
    }

    /**
     * Whether any of this thread's participant numbers are currently
     * blocked.
     */
    public function isBlocked(SmsGroupThread $thread): bool
    {
        $targets = $this->targetPhones($thread);

        if ($targets === []) {
            return false;
        }

        return BlockedCaller::query()->whereIn('phone_number', $targets)->exists();
    }
}
