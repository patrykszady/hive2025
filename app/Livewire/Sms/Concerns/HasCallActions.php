<?php

namespace App\Livewire\Sms\Concerns;

use App\Models\BlockedCaller;
use App\Models\CallLog;
use App\Models\SmsGroupThread;
use App\Models\User;
use App\Models\Vendor;
use App\Services\GroupSmsService;
use Flux;

trait HasCallActions
{
    public function callBack(string $phone, array $recipientIds = []): void
    {
        $result = app(\App\Services\Voice\ClickToCall::class)->start(auth()->user(), [$phone], $recipientIds);

        if (! $result['ok']) {
            Flux::toast(variant: 'danger', heading: 'Call Failed', text: (string) $result['error'], duration: 5000, position: 'top right');

            return;
        }

        Flux::toast(variant: 'success', heading: 'Calling', text: 'Your phone will ring shortly...', duration: 8000, position: 'top right');
    }

    public function textBack(string $phone): void
    {
        $thread = SmsGroupThread::whereJsonContains('participants', $phone)
            ->orderByDesc('last_activity_at')
            ->first();

        if ($thread) {
            $this->dispatch('switchToThread', threadId: $thread->id);
        } else {
            $this->dispatch('openNewThreadWithPhone', phone: $phone)->to(\App\Livewire\Sms\SmsNewThread::class);
        }
    }

    public function markAsSpam(int $callId): void
    {
        $user = auth()->user();

        // find($callId) alone had no tenant check at all — any signed-in
        // vendor user could pass any call id.
        $call = CallLog::query()->visibleToMessagesUser($user)->find($callId);
        if (! $call) {
            return;
        }

        $phone = app(\App\Services\Calls\CallHistory::class)->block($user, $call);
        if (! $phone) {
            Flux::toast(variant: 'danger', heading: 'Error', text: 'No phone number found for this call.', duration: 3000, position: 'top right');
            return;
        }

        Flux::toast(variant: 'success', heading: 'Marked as Spam', text: $this->formatPhone($phone) . ' has been blocked.', duration: 5000, position: 'top right');
    }

    public function unblockNumber(string $phone): void
    {
        // Scoped to this vendor's own block so one company can't remove a
        // block another company (or a global block) put in place.
        $deleted = app(\App\Services\Calls\CallHistory::class)->unblock(auth()->user(), $phone);

        if (! $deleted) {
            Flux::toast(variant: 'warning', heading: 'Not Blocked', text: $this->formatPhone($phone) . ' is not currently blocked.', duration: 4000, position: 'top right');
            return;
        }

        Flux::toast(variant: 'success', heading: 'Unblocked', text: $this->formatPhone($phone) . ' has been removed from the blocked list.', duration: 5000, position: 'top right');
    }

    /**
     * Shared per-process caches for phone -> display name / known-contact.
     * Filled row-by-row on demand, or in bulk by warmPhoneContacts().
     *
     * @var array<string, string>
     */
    protected static array $phoneDisplayCache = [];

    /** @var array<string, bool> */
    protected static array $phoneKnownCache = [];

    /** Split a phone into the digit variants stored across the DB. */
    protected static function phoneVariants(string $e164): array
    {
        $digits = preg_replace('/[^0-9]/', '', $e164);
        $normalized = (strlen($digits) === 11 && str_starts_with($digits, '1')) ? substr($digits, 1) : $digits;
        $last10 = strlen($digits) > 10 ? substr($digits, -10) : $digits;

        return array_values(array_unique([$normalized, '1'.$normalized, $digits, $last10]));
    }

    /**
     * Resolve many numbers in 3 queries instead of 2-5 per number. Call this
     * once with every phone a page will render, before the rows are drawn.
     */
    public function warmPhoneContacts(iterable $phones): void
    {
        $pending = [];

        foreach ($phones as $phone) {
            if (! is_string($phone) || $phone === '') {
                continue;
            }
            if (isset(static::$phoneDisplayCache[$phone]) && isset(static::$phoneKnownCache[$phone])) {
                continue;
            }
            $pending[$phone] = static::phoneVariants($phone);
        }

        if (empty($pending)) {
            return;
        }

        $needles = collect($pending)->flatten()->unique()->values()->all();

        $usersByDigits = User::whereIn('cell_phone', $needles)
            ->get(['id', 'first_name', 'last_name', 'nickname', 'cell_phone'])
            ->keyBy(fn ($u) => preg_replace('/[^0-9]/', '', (string) $u->cell_phone));

        $vendorsByDigits = Vendor::whereIn('business_phone', $needles)
            ->get(['id', 'business_name', 'business_phone', 'options'])
            ->keyBy(fn ($v) => preg_replace('/[^0-9]/', '', (string) $v->business_phone));

        $cnamByNumber = CallLog::where(function ($q) use ($pending) {
                foreach (array_keys($pending) as $phone) {
                    $q->orWhere('from_number', $phone)->orWhere('to_number', $phone);
                }
            })
            ->whereNotNull('caller_name')
            ->whereNotIn('caller_name', ['Incoming Call', 'Outgoing Call'])
            ->latest()
            ->get(['from_number', 'to_number', 'caller_name']);

        foreach ($pending as $phone => $variants) {
            $user = collect($variants)->map(fn ($v) => $usersByDigits->get($v))->filter()->first();
            $vendor = collect($variants)->map(fn ($v) => $vendorsByDigits->get($v))->filter()->first();

            static::$phoneKnownCache[$phone] = (bool) ($user || $vendor);

            // Nickname first (Brad, not Bradley): legal names are for estimates and other legal documents.
            if ($user && \App\Support\Sms\ConversationPresenter::preferredUserDisplayName($user) !== '') {
                static::$phoneDisplayCache[$phone] = \App\Support\Sms\ConversationPresenter::preferredUserDisplayName($user);
                continue;
            }

            if ($vendor && $vendor->short_name) {
                static::$phoneDisplayCache[$phone] = $vendor->short_name;
                continue;
            }

            $cnam = $cnamByNumber
                ->first(fn ($log) => $log->from_number === $phone || $log->to_number === $phone)?->caller_name;

            static::$phoneDisplayCache[$phone] = $cnam
                ? CallLog::formatCallerNameForDisplay($cnam)
                : $this->formatPhone($phone);
        }
    }

    public function isKnownContact(string $e164): bool
    {
        $cache = &static::$phoneKnownCache;

        if (isset($cache[$e164])) {
            return $cache[$e164];
        }

        $digits = preg_replace('/[^0-9]/', '', $e164);
        $normalized = $digits;
        if (strlen($normalized) === 11 && str_starts_with($normalized, '1')) {
            $normalized = substr($normalized, 1);
        }
        $last10 = strlen($digits) > 10 ? substr($digits, -10) : $digits;

        $userExists = User::where('cell_phone', $normalized)
            ->orWhere('cell_phone', '1' . $normalized)
            ->orWhere('cell_phone', $digits)
            ->orWhere('cell_phone', $last10)
            ->exists();

        if ($userExists) {
            return $cache[$e164] = true;
        }

        $vendorExists = Vendor::where('business_phone', $normalized)
            ->orWhere('business_phone', $last10)
            ->orWhere('business_phone', $digits)
            ->exists();

        return $cache[$e164] = $vendorExists;
    }

    public function resolvePhoneDisplay(string $e164): string
    {
        $cache = &static::$phoneDisplayCache;

        if (isset($cache[$e164])) {
            return $cache[$e164];
        }

        $digits = preg_replace('/[^0-9]/', '', $e164);
        $normalized = $digits;
        if (strlen($normalized) === 11 && str_starts_with($normalized, '1')) {
            $normalized = substr($normalized, 1);
        }
        $last10 = strlen($digits) > 10 ? substr($digits, -10) : $digits;

        $user = User::where('cell_phone', $normalized)
            ->orWhere('cell_phone', '1' . $normalized)
            ->orWhere('cell_phone', $digits)
            ->orWhere('cell_phone', $last10)
            ->first();

        if ($user && \App\Support\Sms\ConversationPresenter::preferredUserDisplayName($user) !== '') {
            return $cache[$e164] = \App\Support\Sms\ConversationPresenter::preferredUserDisplayName($user);
        }

        $vendor = Vendor::where('business_phone', $normalized)
            ->orWhere('business_phone', $last10)
            ->orWhere('business_phone', $digits)
            ->first();

        if ($vendor && $vendor->short_name) {
            return $cache[$e164] = $vendor->short_name;
        }

        $callLogName = CallLog::where(fn ($q) => $q->where('from_number', $e164)->orWhere('to_number', $e164))
            ->whereNotNull('caller_name')
            ->whereNotIn('caller_name', ['Incoming Call', 'Outgoing Call'])
            ->latest()
            ->value('caller_name');

        if ($callLogName) {
            return $cache[$e164] = CallLog::formatCallerNameForDisplay($callLogName);
        }

        return $cache[$e164] = $this->formatPhone($e164);
    }

    /**
     * Where this call's contact lives: the client page when the number
     * belongs to a client member, the vendor page for a vendor's (or vendor
     * team member's) number. Null for unknown callers. Same matching as
     * resolvePhoneDisplay, so a link (web route or app screen) can never
     * point at someone other than the name shown. Shared by
     * resolvePhoneContactUrl() (the web's href) and the Hive Contractors
     * app's CallLogResource, which navigates to its own client/vendor
     * screen instead of a web route.
     *
     * @return array{type: 'client'|'vendor', id: int, name: string}|null
     */
    public function resolvePhoneContact(string $e164): ?array
    {
        static $cache = [];

        if (array_key_exists($e164, $cache)) {
            return $cache[$e164];
        }

        $digits = preg_replace('/[^0-9]/', '', $e164);
        $normalized = $digits;
        if (strlen($normalized) === 11 && str_starts_with($normalized, '1')) {
            $normalized = substr($normalized, 1);
        }
        $last10 = strlen($digits) > 10 ? substr($digits, -10) : $digits;

        $user = User::where('cell_phone', $normalized)
            ->orWhere('cell_phone', '1' . $normalized)
            ->orWhere('cell_phone', $digits)
            ->orWhere('cell_phone', $last10)
            ->first();

        if ($user) {
            $client = $user->clients()->first();
            if ($client) {
                return $cache[$e164] = ['type' => 'client', 'id' => $client->id, 'name' => $client->name];
            }

            if ($user->vendor) {
                return $cache[$e164] = ['type' => 'vendor', 'id' => $user->vendor->id, 'name' => $user->vendor->business_name];
            }
        }

        $vendor = Vendor::where('business_phone', $normalized)
            ->orWhere('business_phone', $last10)
            ->orWhere('business_phone', $digits)
            ->first();

        if ($vendor) {
            return $cache[$e164] = ['type' => 'vendor', 'id' => $vendor->id, 'name' => $vendor->business_name];
        }

        return $cache[$e164] = null;
    }

    /**
     * Where this call's contact lives in the app: the client page when the
     * number belongs to a client member, the vendor page for a vendor's (or
     * vendor team member's) number. Null for unknown callers — the caller
     * name then renders as plain text. Same matching as resolvePhoneDisplay,
     * so the link can never point at someone other than the name shown.
     */
    public function resolvePhoneContactUrl(string $e164): ?string
    {
        $contact = $this->resolvePhoneContact($e164);

        if (! $contact) {
            return null;
        }

        return $contact['type'] === 'client'
            ? route('clients.show', $contact['id'])
            : route('vendors.show', $contact['id']);
    }

    public function formatPhone(?string $phone): string
    {
        if (! $phone) {
            return 'Unknown';
        }

        return phone_display($phone);
    }

    /**
     * Dial someone into a live conference. $participantId is a User id when
     * inviting a known team/client/vendor contact, and null for a vendor's
     * bare business line or a hand-typed phone number — it is only used for
     * logging here. The actual dialing — and the same on-call/conference
     * guards — live in App\Services\Calls\ConferenceInviter, shared with the
     * app's CallParticipantController.
     */
    public function inviteParticipantToCall(int $callLogId, ?int $participantId, string $participantPhone, string $participantName): void
    {
        $user = auth()->user();
        if (! $user) {
            Flux::toast(variant: 'danger', heading: 'Not Authenticated', text: 'You must be logged in to add participants.', duration: 5000, position: 'top right');
            return;
        }

        $result = app(\App\Services\Calls\ConferenceInviter::class)
            ->invite($user, $callLogId, $participantId, $participantPhone, $participantName);

        Flux::toast(
            variant: $result['ok'] ? 'success' : 'danger',
            heading: $result['heading'],
            text: $result['message'],
            duration: 5000,
            position: 'top right',
        );
    }
}
