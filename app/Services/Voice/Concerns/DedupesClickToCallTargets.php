<?php

namespace App\Services\Voice\Concerns;

use App\Models\CallLog;
use App\Services\GroupSmsService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The (user, target-set) dedupe a burst of simultaneous call-placing
 * requests needs — shared by App\Services\Voice\ClickToCall (Hive rings the
 * user first, then the target) and App\Services\Voice\DirectDialStarter
 * (the app's own leg is parked at Telnyx, then the target is dialed), so
 * neither one can create two CallLog rows for the same tap. See
 * ClickToCall's own docblock for why the marker's TTL is
 * CallLog::STALE_ACTIVE_MINUTES rather than a fixed window.
 */
trait DedupesClickToCallTargets
{
    /** How long the (user, target) → call_log_id marker survives in cache. */
    protected const MARKER_TTL_MINUTES = CallLog::STALE_ACTIVE_MINUTES;

    /** How long the per-(user, target) lock itself may be held, as a ceiling if a release is ever missed. */
    protected const LOCK_SECONDS = 15;

    /** How long a request waits for that lock before giving up. */
    protected const LOCK_WAIT_SECONDS = 10;

    /**
     * @param  array<int, string|null>  $targetPhones  numbers as typed or stored
     * @return Collection<int, string>
     */
    protected function normalizedTargets(array $targetPhones): Collection
    {
        return collect($targetPhones)
            ->map(fn ($phone) => self::normalize($phone))
            ->filter()
            ->reject(fn (string $phone) => GroupSmsService::isOurNumber($phone))
            ->unique()
            ->values();
    }

    /** "(224) 555-1234", "2245551234" or "+12245551234" → "+12245551234". */
    public static function normalize(?string $phone): ?string
    {
        if (! is_string($phone) || trim($phone) === '') {
            return null;
        }

        $digits = (string) preg_replace('/\D/', '', $phone);
        if ($digits === '') {
            return null;
        }
        if (strlen($digits) === 10) {
            return '+1' . $digits;
        }
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+' . $digits;
        }

        return '+' . $digits;
    }

    /** A stable key for a burst of requests from this user to this same target set, regardless of the order they were typed or echoed back in. */
    protected static function dedupeKey(int $userId, Collection $targets): string
    {
        return $userId . ':' . $targets->sort()->values()->implode(',');
    }

    /**
     * Run $callback while holding the per-(user, target) lock, so a burst of
     * simultaneous requests cannot each create their own CallLog: the first
     * one through the lock creates it, and every other one in the same burst
     * finds it in activeDuplicate() and is handed it back instead.
     *
     * @throws \Illuminate\Contracts\Cache\LockTimeoutException
     */
    protected function withDedupeLock(string $dedupeKey, \Closure $callback): mixed
    {
        return Cache::lock("click-to-call:lock:{$dedupeKey}", self::LOCK_SECONDS)
            ->block(self::LOCK_WAIT_SECONDS, $callback);
    }

    /**
     * The call another request to this same (user, target) already placed,
     * if it is still live — Telnyx is ringing it, the app's own leg is
     * parked, or it is already connected. This is the actual dedupe
     * decision, made from the CallLog's current status, not from how long
     * ago the marker was set. A call that has since ended no longer counts
     * as a duplicate, so the marker is cleared and a fresh call is allowed
     * right away rather than waiting out the rest of the marker's cache
     * ceiling.
     */
    protected function activeDuplicate(string $dedupeKey): ?CallLog
    {
        $callLogId = Cache::get("click-to-call:recent:{$dedupeKey}");
        if (! $callLogId) {
            return null;
        }

        $callLog = CallLog::find($callLogId);
        if ($callLog && in_array($callLog->status, CallLog::ACTIVE_STATUSES, true)) {
            return $callLog;
        }

        Cache::forget("click-to-call:recent:{$dedupeKey}");

        return null;
    }

    /** Remember the CallLog a (user, target) request just created, so a repeat in the same burst finds it in activeDuplicate(). */
    protected function rememberCallLog(string $dedupeKey, CallLog $callLog): void
    {
        Cache::put("click-to-call:recent:{$dedupeKey}", $callLog->id, now()->addMinutes(self::MARKER_TTL_MINUTES));
    }
}
