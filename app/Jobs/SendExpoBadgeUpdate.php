<?php

namespace App\Jobs;

use App\Models\PushToken;
use App\Services\Notifications\ExpoPushClient;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * A silent badge-only push — see AppNotificationFeed::markAllRead(), which
 * dispatches this once every notification is read so a device's OS badge
 * clears immediately instead of waiting for the app's own 60s poll.
 */
class SendExpoBadgeUpdate implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $userId, public int $badge = 0)
    {
    }

    /**
     * Execute the job.
     */
    public function handle(ExpoPushClient $expo): void
    {
        $tokens = PushToken::where('user_id', $this->userId)->pluck('token')->all();

        if ($tokens === []) {
            return;
        }

        $expo->sendBadgeOnly($tokens, $this->badge);
    }
}
