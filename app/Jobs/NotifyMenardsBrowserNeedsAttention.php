<?php

namespace App\Jobs;

use App\Models\PushSubscription;
use App\Models\User;
use App\Services\WebPushService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Tells an admin the Menards browser is stuck and needs ten seconds of a human.
 *
 * The receipt sync is unattended in every respect but one: when Imperva decides
 * to show its "I am human" challenge, only a person can clear it. Nothing here
 * solves that captcha — we deliberately do not — so the next best thing is that
 * nobody has to notice on their own.
 *
 * That is the failure this whole rebuild exists to prevent. The original scraper
 * broke in mid-August and stayed broken for two weeks because it failed into a
 * log nobody read. A wall that waits silently for someone to wonder about it is
 * the same bug wearing different clothes.
 */
class NotifyMenardsBrowserNeedsAttention implements ShouldQueue
{
    use Queueable;

    /**
     * One notification per reason per 12 hours.
     *
     * ensure runs on a schedule, so an unattended wall would otherwise notify on
     * every single pass. A person who has been told once and has not cleared it
     * does not need telling again an hour later — they need it tomorrow, in case
     * the first one was missed.
     */
    protected const QUIET_HOURS = 12;

    /**
     * @param  string  $reason  Machine key, also the throttle key: 'wall',
     *                          'signed_out', 'extension_missing', 'down'.
     * @param  string  $detail  One human-readable sentence.
     */
    public function __construct(
        public string $reason,
        public string $detail,
    ) {
        $this->onQueue('background');
    }

    public function handle(WebPushService $webPush): void
    {
        $key = 'menards-browser-notified:' . $this->reason;

        if (! Cache::add($key, true, now()->addHours(self::QUIET_HOURS))) {
            return;
        }

        // Admins only: this links to a page that drives a browser signed into
        // the company's Menards account, and only Admins can open it anyway.
        $admins = User::all()->filter(fn (User $u) => $u->vendor_role === 'Admin');

        if ($admins->isEmpty()) {
            Log::channel('menards')->warning('Menards browser needs attention but no admin was found to notify', [
                'reason' => $this->reason,
            ]);

            return;
        }

        $subscriptions = PushSubscription::query()
            ->whereIn('user_id', $admins->pluck('id'))
            ->get();

        if ($subscriptions->isEmpty()) {
            // Worth saying out loud: the notification path is configured but
            // nobody has actually subscribed a browser, so this is silent.
            Log::channel('menards')->warning('Menards browser needs attention but no admin has a push subscription', [
                'reason' => $this->reason,
                'detail' => $this->detail,
            ]);

            return;
        }

        $webPush->sendToSubscriptions($subscriptions, [
            'title' => $this->title(),
            'body' => $this->detail,
            'icon' => '/favicons/icon-192x192.png',
            'badge' => '/favicons/icon-96x96.png',
            'data' => [
                'url' => '/menards/browser',
                'type' => 'menards_browser',
                'reason' => $this->reason,
            ],
            // Stays on screen until acknowledged: the whole point is that it is
            // not missed, and receipts stop arriving until someone acts.
            'requireInteraction' => true,
        ]);

        Log::channel('menards')->info('Menards browser: notified admins', [
            'reason' => $this->reason,
            'subscriptions' => $subscriptions->count(),
        ]);
    }

    protected function title(): string
    {
        return match ($this->reason) {
            'wall' => 'Menards needs one click',
            'signed_out' => 'Menards is signed out',
            'extension_missing' => 'Menards sync is broken',
            'down' => 'Menards browser is down',
            default => 'Menards browser needs attention',
        };
    }
}
