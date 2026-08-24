<?php

namespace App\Jobs;

use App\Models\AppNotification;
use App\Models\User;
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
 *
 * These land in the Notifications tab, NOT as browser push. Push was the wrong
 * register for this: it interrupts whatever you are doing, on every device, for
 * something that waits perfectly well until you next look — and at four
 * scheduled syncs a day a recurring wall made it noise. The in-app list is
 * where a message can sit until it is read, which is all this ever needed.
 *
 * To act on one of these, reach the browser's screen over an SSH tunnel:
 *
 *   ssh -L 6098:127.0.0.1:6098 forge@<server>
 *   http://127.0.0.1:6098/vnc.html?autoconnect=1&resize=scale
 *
 * Click "I am human"; `menards:browser login` handles the rest from the stored
 * credentials. x11vnc and websockify bind to loopback only, so the tunnel is
 * the only way in and there is no VNC password to manage.
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

    public function handle(): void
    {
        $key = 'menards-browser-notified:' . $this->reason;

        if (! Cache::add($key, true, now()->addHours(self::QUIET_HOURS))) {
            return;
        }

        // Admins only: this concerns a browser signed into the company's
        // Menards account, and only an Admin can act on it.
        $admins = User::all()->filter(fn (User $u) => $u->vendor_role === 'Admin');

        if ($admins->isEmpty()) {
            Log::channel('menards')->warning('Menards browser needs attention but no admin was found to notify', [
                'reason' => $this->reason,
            ]);

            return;
        }

        foreach ($admins as $admin) {
            AppNotification::create([
                'user_id' => $admin->id,
                'type' => 'menards_browser',
                'title' => $this->title(),
                'body' => $this->detail,
                // No page frames the browser any more — the viewer was removed
                // rather than publish a password-less VNC behind a session
                // cookie. The Notifications tab is the destination; a link to
                // the dashboard only moved people away from the message.
                'action_url' => null,
                'data' => [
                    'reason' => $this->reason,
                ],
            ]);
        }

        Log::channel('menards')->info('Menards browser: notified admins', [
            'reason' => $this->reason,
            'admins' => $admins->count(),
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
