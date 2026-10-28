<?php

namespace App\Livewire;

use App\Models\CallLog;
use App\Models\User;
use App\Services\Voice\AppVoiceCredentials;
use App\Services\Voice\InternalCalls;
use Illuminate\Contracts\View\View;
use Livewire\Component;

/**
 * The Hive phone on every page for GS Construction's team (2026-10-27):
 * the browser signs in with the person's Hive calling login and stays
 * signed in across pages (the layout persists it), so a teammate's call —
 * or a business call, as on /phone-system/app-calling — rings here with
 * Answer and Decline, and "Call Greg" anywhere places a call that stays
 * inside Hive (App\Services\Voice\InternalCalls). Renders nothing for
 * anyone else.
 */
class TeamPhone extends Component
{
    public bool $enabled = false;

    public function mount(): void
    {
        $this->enabled = self::enabledFor(auth()->user());
    }

    /**
     * Who can call teammates in Hive: GS company users with the browser phone
     * configured. The Team tab and the conversation header hide their Call
     * buttons for everyone else.
     */
    public static function enabledFor(?\App\Models\User $user): bool
    {
        return (bool) $user
            && (int) $user->primary_vendor_id === 1
            && ! $user->is_browsing_as_client
            && \App\Support\Team\TeamRoster::isMember($user)
            && app(AppVoiceCredentials::class)->enabled();
    }

    /** @return array{ok: bool, token?: string, error?: string} */
    public function token(): array
    {
        if (! $this->enabled) {
            return ['ok' => false, 'error' => 'Calling in Hive is off for this account.'];
        }

        try {
            return ['ok' => true, 'token' => app(AppVoiceCredentials::class)->tokenFor(auth()->user())];
        } catch (\Throwable $e) {
            report($e);

            return ['ok' => false, 'error' => 'Your Hive calling login could not be made.'];
        }
    }

    /** @return array{ok: bool, error: ?string, call_log_id: ?int, name: ?string} */
    public function callUser(int $userId): array
    {
        abort_unless($this->enabled, 403);
        $callee = User::withoutGlobalScopes()->find($userId);
        if (! $callee) {
            return ['ok' => false, 'error' => 'No such person.', 'call_log_id' => null, 'name' => null];
        }

        $result = app(InternalCalls::class)->start(auth()->user(), $callee);

        return ['ok' => $result['ok'], 'error' => $result['error'], 'call_log_id' => $result['call_log']?->id, 'name' => InternalCalls::displayName($callee)];
    }

    /** Ring everyone else on the crew. @return array{ok: bool, error: ?string, call_log_id: ?int, name: ?string} */
    public function callCrew(): array
    {
        abort_unless($this->enabled, 403);
        $result = app(InternalCalls::class)->startCrew(auth()->user());

        return ['ok' => $result['ok'], 'error' => $result['error'], 'call_log_id' => $result['call_log']?->id, 'name' => 'the crew'];
    }

    /** @return array{ok: bool, error: ?string} */
    public function answer(int $callLogId): array
    {
        abort_unless($this->enabled, 403);
        $callLog = CallLog::find($callLogId);

        return $callLog ? app(InternalCalls::class)->answer($callLog, auth()->user()) : ['ok' => false, 'error' => 'That call has ended.'];
    }

    public function decline(int $callLogId): bool
    {
        abort_unless($this->enabled, 403);
        $callLog = CallLog::find($callLogId);

        return $callLog ? app(InternalCalls::class)->decline($callLog, auth()->user()) : false;
    }

    /** Who is calling, for the banner a push opened (the SIP ring may have missed this tab). */
    public function ringing(int $callLogId): array
    {
        abort_unless($this->enabled, 403);
        $callLog = CallLog::find($callLogId);
        $calls = app(InternalCalls::class);
        if (! $callLog || ! $calls->isCalleeOf($callLog, auth()->user()) || $calls->hasEnded($callLog)) {
            return ['ok' => false];
        }

        return ['ok' => true, 'name' => InternalCalls::displayName(User::withoutGlobalScopes()->find($callLog->metadata['caller_user_id'] ?? $callLog->user_id)), 'crew' => $calls->isCrew($callLog)];
    }

    public function render(): View
    {
        return view('livewire.team-phone');
    }
}
