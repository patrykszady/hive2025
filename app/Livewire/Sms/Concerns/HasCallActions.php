<?php

namespace App\Livewire\Sms\Concerns;

use App\Models\BlockedCaller;
use App\Models\CallLog;
use App\Models\SmsGroupThread;
use App\Models\User;
use App\Models\Vendor;
use App\Services\GroupSmsService;
use Flux;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

trait HasCallActions
{
    public function callBack(string $phone, array $recipientIds = []): void
    {
        $user = auth()->user();
        $userPhone = $user->routeNotificationForTelnyx();

        if (! $userPhone) {
            Flux::toast(variant: 'danger', heading: 'No Phone', text: 'You don\'t have a cell phone on file.', duration: 5000, position: 'top right');
            return;
        }

        $apiKey = config('services.telnyx.api_key');
        $connectionId = config('services.telnyx.connection_id');
        $from = config('services.telnyx.from');

        if (GroupSmsService::isOurNumber($phone)) {
            Log::channel('telnyx')->warning('Click-to-call blocked: target is own Telnyx number', [
                'target_phone' => $phone,
            ]);
            Flux::toast(variant: 'danger', heading: 'Invalid Number', text: 'Cannot call the company phone number.', duration: 5000, position: 'top right');
            return;
        }

        if (! $apiKey || ! $connectionId) {
            Flux::toast(variant: 'danger', heading: 'Not Configured', text: 'Voice calling is not configured.', duration: 5000, position: 'top right');
            return;
        }

        // Store selected call recipients in metadata for the webhook to use
        $metadata = [
            'type' => 'click_to_call',
            'target_phone' => $phone,
            'user_phone' => $userPhone,
        ];

        if (! empty($recipientIds)) {
            // Validate that all recipient IDs belong to users with phones
            $validRecipients = User::whereIn('id', $recipientIds)
                ->whereNotNull('cell_phone')
                ->where('cell_phone', '!=', '')
                ->pluck('id')
                ->all();
            $metadata['click_to_call_recipient_ids'] = $validRecipients;
        }

        $callLog = CallLog::create([
            'direction' => 'outgoing',
            'from_number' => $from,
            'to_number' => $phone,
            'status' => CallLog::STATUS_INITIATED,
            'user_id' => $user->id,
            'metadata' => $metadata,
        ]);

        try {
            $response = Http::withToken($apiKey)
                ->post('https://api.telnyx.com/v2/calls', [
                    'connection_id' => $connectionId,
                    'to' => $userPhone,
                    'from' => $from,
                    'from_display_name' => 'GS Construction',
                    'timeout_secs' => (int) config('services.telnyx.voice_timeout', 30),
                    'preferred_codecs' => config('services.telnyx.preferred_codecs'),
                    'client_state' => base64_encode(json_encode([
                        'action' => 'click_to_call',
                        'target_phone' => $phone,
                        'call_log_id' => $callLog->id,
                    ])),
                    'webhook_url' => rtrim(config('app.url'), '/') . '/webhooks/telnyx/voice',
                ]);

            if ($response->successful()) {
                $data = $response->json('data');
                $callLog->update([
                    'call_control_id' => $data['call_control_id'] ?? null,
                    'call_session_id' => $data['call_session_id'] ?? null,
                    'call_leg_id' => $data['call_leg_id'] ?? null,
                ]);

                Flux::toast(variant: 'success', heading: 'Calling', text: 'Your phone will ring shortly...', duration: 8000, position: 'top right');
            } else {
                $callLog->update(['status' => CallLog::STATUS_FAILED]);
                Log::channel('telnyx')->error('Click-to-call failed', ['status' => $response->status(), 'error' => $response->json()]);
                Flux::toast(variant: 'danger', heading: 'Call Failed', text: 'Could not initiate the call.', duration: 5000, position: 'top right');
            }
        } catch (\Exception $e) {
            $callLog->update(['status' => CallLog::STATUS_FAILED]);
            Log::channel('telnyx')->error('Click-to-call exception', ['error' => $e->getMessage()]);
            Flux::toast(variant: 'danger', heading: 'Error', text: 'Something went wrong initiating the call.', duration: 5000, position: 'top right');
        }
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
        $call = CallLog::find($callId);
        if (! $call) {
            return;
        }

        $phone = $call->direction === 'outgoing' ? $call->to_number : $call->from_number;
        if (! $phone) {
            Flux::toast(variant: 'danger', heading: 'Error', text: 'No phone number found for this call.', duration: 3000, position: 'top right');
            return;
        }

        BlockedCaller::firstOrCreate(
            ['phone_number' => $phone],
            ['reason' => 'Manually marked as spam', 'blocked_by_user_id' => auth()->id(), 'auto_blocked' => false]
        );

        CallLog::where('from_number', $phone)
            ->where('status', '!=', CallLog::STATUS_BLOCKED)
            ->update(['status' => CallLog::STATUS_BLOCKED]);

        Flux::toast(variant: 'success', heading: 'Marked as Spam', text: $this->formatPhone($phone) . ' has been blocked.', duration: 5000, position: 'top right');
    }

    public function unblockNumber(string $phone): void
    {
        $deleted = BlockedCaller::where('phone_number', $phone)->delete();

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
            ->get(['id', 'first_name', 'last_name', 'cell_phone'])
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

            if ($user && trim($user->first_name.' '.$user->last_name) !== '') {
                static::$phoneDisplayCache[$phone] = trim($user->first_name.' '.$user->last_name);
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

        if ($user && trim($user->first_name . ' ' . $user->last_name) !== '') {
            return $cache[$e164] = trim($user->first_name . ' ' . $user->last_name);
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
     * Where this call's contact lives in the app: the client page when the
     * number belongs to a client member, the vendor page for a vendor's (or
     * vendor team member's) number. Null for unknown callers — the caller
     * name then renders as plain text. Same matching as resolvePhoneDisplay,
     * so the link can never point at someone other than the name shown.
     */
    public function resolvePhoneContactUrl(string $e164): ?string
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
                return $cache[$e164] = route('clients.show', $client->id);
            }

            if ($user->vendor) {
                return $cache[$e164] = route('vendors.show', $user->vendor->id);
            }
        }

        $vendor = Vendor::where('business_phone', $normalized)
            ->orWhere('business_phone', $last10)
            ->orWhere('business_phone', $digits)
            ->first();

        if ($vendor) {
            return $cache[$e164] = route('vendors.show', $vendor->id);
        }

        return $cache[$e164] = null;
    }

    public function formatPhone(?string $phone): string
    {
        if (! $phone) {
            return 'Unknown';
        }

        return phone_display($phone);
    }

    public function inviteParticipantToCall(int $callLogId, int $participantId, string $participantPhone, string $participantName): void
    {
        $user = auth()->user();
        if (!$user) {
            Flux::toast(variant: 'danger', heading: 'Not Authenticated', text: 'You must be logged in to add participants.', duration: 5000, position: 'top right');
            return;
        }

        $callLog = CallLog::find($callLogId);
        if (!$callLog || $callLog->user_id !== $user->id) {
            Flux::toast(variant: 'danger', heading: 'Invalid Call', text: 'Call not found or does not belong to you.', duration: 5000, position: 'top right');
            return;
        }

        // Ensure call is in a conference (TRANSFERRED status with conference_id in metadata)
        $metadata = $callLog->metadata ?? [];
        $conferenceId = $metadata['conference_id'] ?? null;

        if (!$conferenceId) {
            Flux::toast(variant: 'danger', heading: 'No Conference', text: 'This call is not currently in a conference.', duration: 5000, position: 'top right');
            return;
        }

        $apiKey = config('services.telnyx.api_key');
        $connectionId = config('services.telnyx.connection_id');
        $from = config('services.telnyx.from');

        if (!$apiKey || !$connectionId) {
            Flux::toast(variant: 'danger', heading: 'Not Configured', text: 'Voice calling is not configured.', duration: 5000, position: 'top right');
            return;
        }

        try {
            $response = Http::withHeaders([
                'Authorization' => "Bearer {$apiKey}",
                'Accept' => 'application/json',
            ])->post('https://api.telnyx.com/v2/calls', [
                'connection_id' => $connectionId,
                'to' => $participantPhone,
                'from' => $from,
                'from_display_name' => 'GS Construction',
                'timeout_secs' => 30,
                'preferred_codecs' => config('services.telnyx.preferred_codecs'),
                'client_state' => base64_encode(json_encode([
                    'action' => 'conference_invite',
                    'call_log_id' => $callLogId,
                    'conference_name' => $metadata['conference_name'] ?? null,
                    'caller_name' => $user->first_name ?? 'Someone',
                ])),
                'webhook_url' => config('app.url') . '/webhooks/telnyx/voice',
            ]);

            if ($response->successful()) {
                Log::channel('telnyx')->info('Conference invite: participant call initiated', [
                    'call_log_id' => $callLogId,
                    'participant_id' => $participantId,
                    'participant_phone' => $participantPhone,
                    'participant_name' => $participantName,
                    'conference_id' => $conferenceId,
                ]);

                Flux::toast(variant: 'success', heading: 'Invited', text: "Calling {$participantName}...", duration: 5000, position: 'top right');
            } else {
                Log::channel('telnyx')->error('Conference invite: API call failed', [
                    'call_log_id' => $callLogId,
                    'participant_id' => $participantId,
                    'status' => $response->status(),
                    'error' => $response->json(),
                ]);

                Flux::toast(variant: 'danger', heading: 'Failed', text: 'Unable to invite participant. Please try again.', duration: 5000, position: 'top right');
            }
        } catch (\Exception $e) {
            Log::channel('telnyx')->error('Conference invite: exception', [
                'call_log_id' => $callLogId,
                'participant_id' => $participantId,
                'error' => $e->getMessage(),
            ]);

            Flux::toast(variant: 'danger', heading: 'Error', text: 'Something went wrong inviting the participant.', duration: 5000, position: 'top right');
        }
    }
}
