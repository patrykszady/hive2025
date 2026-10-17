<?php

namespace App\Services\Calls;

use App\Livewire\Sms\Concerns\HasCallActions;
use App\Models\BlockedCaller;
use App\Models\CallLog;
use App\Models\User;
use App\Services\GroupSmsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The calls dashboard's rules, shared by the web Calls tab (CallList) and the
 * Hive Contractors app: which calls a person sees and how filters and search
 * narrow them, a call's effective status, the real other party behind a
 * phantom leg, consecutive repeats grouped into one row, and blocking.
 * Phone numbers are named with the same helpers the web list uses.
 */
class CallHistory
{
    use HasCallActions;

    public const FILTERS = ['all', 'missed', 'voicemail', 'blocked'];

    /**
     * Phantom-leg origins of the rows grouped last, keyed by call_session_id.
     *
     * @var array<string, string>
     */
    protected array $phantomLegOrigins = [];

    /** The calls a person sees, filtered and searched like the web Calls tab, newest first. */
    public function query(User $user, string $filter = 'all', string $search = ''): Builder
    {
        $ourNumbers = config('services.telnyx.numbers', []);
        $search = trim($search);
        $searchIds = null;
        $searchFailed = false;

        // Full-text search (caller name, numbers, notes, transcript) through
        // Meilisearch; a plain LIKE when the index is unavailable.
        if ($search !== '') {
            try {
                $searchIds = CallLog::search($search)->take(200)->keys()->map(fn ($id) => (int) $id)->all();
            } catch (\Throwable $exception) {
                report($exception);
                $searchFailed = true;
            }
        }

        return CallLog::query()
            ->visibleToMessagesUser($user)
            ->when($search !== '' && ! $searchFailed, fn ($query) => $query->whereIn('id', $searchIds ?? []))
            ->when($search !== '' && $searchFailed, fn ($query) => $query->where(function ($inner) use ($search) {
                $like = '%'.$search.'%';
                $inner->where('caller_name', 'like', $like)
                    ->orWhere('from_number', 'like', $like)
                    ->orWhere('to_number', 'like', $like)
                    ->orWhere('notes', 'like', $like);
            }))
            ->when($filter === 'missed', fn ($query) => $query->where('status', CallLog::STATUS_MISSED))
            ->when($filter === 'voicemail', fn ($query) => $query->where('has_voicemail', true))
            ->when($filter === 'blocked', fn ($query) => $query->where('status', CallLog::STATUS_BLOCKED))
            ->when($filter !== 'blocked', fn ($query) => $query->where('status', '!=', CallLog::STATUS_BLOCKED))
            // Phantom/loopback legs whose from_number is one of our own numbers.
            ->when(! empty($ourNumbers), fn ($query) => $query->where(function ($inner) use ($ourNumbers) {
                $inner->where('direction', '!=', 'incoming')->orWhereNotIn('from_number', $ourNumbers);
            }))
            ->orderByDesc('created_at');
    }

    /** "blocked" for a blocked call (also one misfiled with a blocked_reason), else its status. */
    public static function effectiveStatus(CallLog $call): string
    {
        if ($call->status === CallLog::STATUS_BLOCKED) {
            return 'blocked';
        }

        $metadata = is_array($call->metadata) ? $call->metadata : (is_string($call->metadata) ? json_decode($call->metadata, true) : []);

        return ! empty($metadata['blocked_reason']) ? 'blocked' : (string) $call->status;
    }

    /**
     * Consecutive calls from the same number, direction and status become one
     * row with a count; the rows' numbers are resolved and named in bulk.
     *
     * @param  iterable<CallLog>  $calls
     * @return Collection<int, array{call: CallLog, count: int}>
     */
    public function group(iterable $calls): Collection
    {
        $grouped = collect();
        $previousKey = null;

        foreach ($calls as $call) {
            $otherNumber = $call->direction === 'outgoing' ? $call->to_number : $call->from_number;
            $key = $otherNumber.'|'.$call->direction.'|'.self::effectiveStatus($call);

            if ($key === $previousKey && $grouped->isNotEmpty()) {
                $last = $grouped->pop();
                $last['count']++;
                $grouped->push($last);
            } else {
                $grouped->push(['call' => $call, 'count' => 1]);
            }

            $previousKey = $key;
        }

        $this->resolveNumbers($grouped->pluck('call'));

        return $grouped->values();
    }

    /** The real other party: the phantom leg's origin when the stored number is one of ours. */
    public function otherPartyNumber(CallLog $call): ?string
    {
        $otherNumber = $call->direction === 'outgoing' ? $call->to_number : $call->from_number;

        if ($otherNumber && GroupSmsService::isOurNumber($otherNumber) && $call->call_session_id) {
            return $this->phantomLegOrigins[$call->call_session_id] ?? $otherNumber;
        }

        return $otherNumber;
    }

    /** The name the dashboard shows for a number (contact, vendor, caller ID), else null. */
    public function nameFor(?string $phone): ?string
    {
        if (! $phone) {
            return null;
        }

        $name = $this->resolvePhoneDisplay($phone);

        return $name === $this->formatPhone($phone) ? null : $name;
    }

    /**
     * Phantom-leg origins and phone names for these calls, in a few queries.
     *
     * @param  iterable<CallLog>  $calls
     */
    public function resolveNumbers(iterable $calls): void
    {
        $ourNumbers = config('services.telnyx.numbers', []);
        $sessionIds = [];
        $phones = [];

        foreach ($calls as $call) {
            $otherNumber = $call->direction === 'outgoing' ? $call->to_number : $call->from_number;

            if ($otherNumber && GroupSmsService::isOurNumber($otherNumber) && $call->call_session_id) {
                $sessionIds[] = $call->call_session_id;
            } elseif ($otherNumber) {
                $phones[] = $otherNumber;
            }
        }

        if ($sessionIds !== []) {
            $this->phantomLegOrigins = CallLog::whereIn('call_session_id', array_unique($sessionIds))
                ->where('direction', 'incoming')
                ->whereNotIn('from_number', $ourNumbers)
                ->get(['call_session_id', 'from_number'])
                ->keyBy('call_session_id')
                ->map(fn ($log) => $log->from_number)
                ->all();

            $phones = array_merge($phones, array_values($this->phantomLegOrigins));
        }

        $this->warmPhoneContacts(array_unique($phones));
    }

    /**
     * Block the call's number for the person's company and file its calls as
     * blocked. Scoped to the company: a block without a vendor_id would be
     * global. Returns the number, or null when the call has none.
     */
    public function block(User $user, CallLog $call): ?string
    {
        $phone = $call->direction === 'outgoing' ? $call->to_number : $call->from_number;
        if (! $phone) {
            return null;
        }

        BlockedCaller::firstOrCreate(
            ['phone_number' => $phone, 'vendor_id' => $user->vendor?->id],
            ['reason' => 'Manually marked as spam', 'blocked_by_user_id' => $user->id, 'auto_blocked' => false]
        );

        CallLog::query()
            ->visibleToMessagesUser($user)
            ->where('from_number', $phone)
            ->where('status', '!=', CallLog::STATUS_BLOCKED)
            ->update(['status' => CallLog::STATUS_BLOCKED]);

        return $phone;
    }

    /** Remove the company's own block on a number (never another company's or a global one). */
    public function unblock(User $user, string $phone): bool
    {
        return BlockedCaller::where('phone_number', $phone)
            ->where('vendor_id', $user->vendor?->id)
            ->delete() > 0;
    }

    /** Whether a number is blocked for the person's company (its own block or a global one). */
    public function isBlocked(User $user, ?string $phone): bool
    {
        if (! $phone) {
            return false;
        }

        $vendorId = $user->vendor?->id;

        return BlockedCaller::where('phone_number', $phone)
            ->where(fn ($query) => $query->whereNull('vendor_id')->orWhere('vendor_id', $vendorId))
            ->exists();
    }
}
