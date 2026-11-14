<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A call is ringing this person: every browser they have Hive open in shows
 * its ring card (the team phone), without being signed in to Telnyx.
 *
 * A browser signed in with the person's calling login took the ring itself,
 * and Telnyx then never woke their iPhone (call 3126, 2026-11-14). Browsers
 * now sign in only to answer or place a call, so the phone always gets its
 * push and the computer still rings — one login, both devices (Patryk:
 * "send it to the app on the phone and browser on a computer").
 */
class TeamPhoneRinging implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  string  $kind  'business' (a caller on the business line) or 'internal' (a teammate in Hive)
     */
    public function __construct(
        public int $userId,
        public int $callLogId,
        public string $name,
        public string $kind,
        public bool $crew = false,
    ) {}

    /** @return array<int, PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('App.Models.User.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'team-phone.ringing';
    }

    /** @return array{call_log_id: int, name: string, kind: string, crew: bool} */
    public function broadcastWith(): array
    {
        return ['call_log_id' => $this->callLogId, 'name' => $this->name, 'kind' => $this->kind, 'crew' => $this->crew];
    }

    /**
     * Tell the person's browsers; never let a broadcast problem touch the
     * call itself — the phone and the cell ring regardless.
     */
    public static function announce(int $userId, int $callLogId, string $name, string $kind, bool $crew = false): void
    {
        try {
            $user = \App\Models\User::withoutGlobalScopes()->find($userId);
            if ($user && \App\Livewire\TeamPhone::enabledFor($user)) {
                self::dispatch($userId, $callLogId, $name, $kind, $crew);
            }
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::channel('telnyx')->warning('Team phone: could not tell the browsers about a ring', ['user_id' => $userId, 'call_log_id' => $callLogId, 'error' => $e->getMessage()]);
        }
    }
}
