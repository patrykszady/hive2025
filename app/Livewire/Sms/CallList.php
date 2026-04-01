<?php

namespace App\Livewire\Sms;

use App\Models\BlockedCaller;
use App\Models\CallLog;
use App\Models\SmsGroupThread;
use App\Models\User;
use App\Models\Vendor;
use App\Services\GroupSmsService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Isolate;
use Livewire\Attributes\On;
use Livewire\Component;
use Flux;

#[Isolate]
class CallList extends Component
{
    public int $limit = 25;

    public string $callFilter = 'all';

    public ?int $selectedCallId = null;

    public bool $showNewCallModal = false;

    public string $newCallNumber = '';

    public ?int $selectedUserId = null;

    public function mount(): void
    {
        $this->normalizeCallFilter();
    }

    #[On('openNewCall')]
    public function openNewCall(): void
    {
        $this->newCallNumber = '';
        $this->selectedUserId = null;
        $this->showNewCallModal = true;
    }

    public function updatedSelectedUserId(): void
    {
        if ($this->selectedUserId) {
            $user = User::find($this->selectedUserId);
            if ($user?->cell_phone) {
                $this->newCallNumber = $this->formatPhone($user->routeNotificationForTelnyx() ?? $user->cell_phone);
            }
        }
    }

    public function updatedCallFilter(): void
    {
        $this->normalizeCallFilter();
        $this->limit = 25;
        $this->selectedCallId = null;
    }

    public function loadMore(): void
    {
        $this->limit += 25;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<User>
     */
    #[Computed(cache: true, key: 'call-list-contacts', seconds: 300)]
    public function contactUsers(): mixed
    {
        return User::whereNotNull('cell_phone')
            ->where('cell_phone', '!=', '')
            ->orderBy('first_name')
            ->get();
    }

    /**
     * @return array<int, string>
     */
    #[Computed]
    public function blockedNumbers(): array
    {
        return BlockedCaller::pluck('phone_number')->all();
    }

    /**
     * @return \Illuminate\Support\Collection<int, array{call: CallLog, count: int}>
     */
    #[Computed]
    public function calls(): mixed
    {
        $ourNumbers = config('services.telnyx.numbers', []);

        $rawCalls = CallLog::query()
            ->when($this->callFilter === 'missed', fn ($q) => $q->where('status', CallLog::STATUS_MISSED))
            ->when($this->callFilter === 'voicemail', fn ($q) => $q->where('has_voicemail', true))
            ->when($this->callFilter === 'blocked', fn ($q) => $q->where('status', CallLog::STATUS_BLOCKED))
            ->when(! in_array($this->callFilter, ['blocked']), fn ($q) => $q->where('status', '!=', CallLog::STATUS_BLOCKED))
            ->when(! empty($ourNumbers), fn ($q) => $q->where(function ($q) use ($ourNumbers) {
                // Exclude phantom/loopback legs where from_number is one of our own Telnyx numbers
                $q->where('direction', '!=', 'incoming')
                    ->orWhereNotIn('from_number', $ourNumbers);
            }))
            ->orderByDesc('created_at')
            ->limit($this->limit)
            ->get();

        // Group consecutive calls from the same number with the same status
        return $this->groupConsecutiveCalls($rawCalls);
    }

    /**
     * Group consecutive calls from the same number with the same effective status.
     *
     * @return \Illuminate\Support\Collection<int, array{call: CallLog, count: int}>
     */
    protected function groupConsecutiveCalls(\Illuminate\Database\Eloquent\Collection $calls): \Illuminate\Support\Collection
    {
        $grouped = collect();
        $prevKey = null;

        foreach ($calls as $call) {
            $otherNumber = $call->direction === 'outgoing' ? $call->to_number : $call->from_number;
            $effectiveStatus = $this->effectiveStatus($call);
            $key = $otherNumber . '|' . $call->direction . '|' . $effectiveStatus;

            if ($key === $prevKey && $grouped->isNotEmpty()) {
                $last = $grouped->last();
                $last['count']++;
                $grouped->put($grouped->count() - 1, $last);
            } else {
                $grouped->push(['call' => $call, 'count' => 1]);
            }

            $prevKey = $key;
        }

        return $grouped;
    }

    /**
     * Determine effective display status, accounting for misclassified blocked calls.
     */
    public function effectiveStatus(CallLog $call): string
    {
        if ($call->status === CallLog::STATUS_BLOCKED) {
            return 'blocked';
        }

        $metadata = is_array($call->metadata) ? $call->metadata : (is_string($call->metadata) ? json_decode($call->metadata, true) : []);
        if (! empty($metadata['blocked_reason'])) {
            return 'blocked';
        }

        return $call->status;
    }

    public function generateDemoCalls(): void
    {
        $userId = auth()->id();

        $examples = [
            [
                'direction' => 'incoming',
                'from_number' => '+18474304439',
                'to_number' => '+12249993880',
                'caller_name' => null,
                'status' => CallLog::STATUS_COMPLETED,
                'duration_seconds' => 187,
                'created_at' => now()->subMinutes(12),
            ],
            [
                'direction' => 'outgoing',
                'from_number' => '+12249993880',
                'to_number' => '+13129092818',
                'caller_name' => null,
                'status' => CallLog::STATUS_COMPLETED,
                'duration_seconds' => 73,
                'created_at' => now()->subMinutes(28),
            ],
            [
                'direction' => 'incoming',
                'from_number' => '+18472123894',
                'to_number' => '+12249993880',
                'caller_name' => null,
                'status' => CallLog::STATUS_MISSED,
                'duration_seconds' => 0,
                'created_at' => now()->subHours(2),
            ],
            [
                'direction' => 'incoming',
                'from_number' => '+12249993881',
                'to_number' => '+12249993880',
                'caller_name' => null,
                'status' => CallLog::STATUS_VOICEMAIL,
                'duration_seconds' => 42,
                'has_voicemail' => true,
                'created_at' => now()->subHours(7),
            ],
            [
                'direction' => 'outgoing',
                'from_number' => '+12249993880',
                'to_number' => '+18474305555',
                'caller_name' => null,
                'status' => CallLog::STATUS_FAILED,
                'duration_seconds' => 0,
                'created_at' => now()->subDay(),
            ],
        ];

        foreach ($examples as $example) {
            $timestamp = $example['created_at'];

            CallLog::create([
                'call_id' => (string) Str::uuid(),
                'direction' => $example['direction'],
                'from_number' => $example['from_number'],
                'to_number' => $example['to_number'],
                'caller_name' => $example['caller_name'],
                'status' => $example['status'],
                'duration_seconds' => $example['duration_seconds'],
                'has_voicemail' => $example['has_voicemail'] ?? false,
                'user_id' => $userId,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }

        $this->callFilter = 'all';
        $this->limit = 25;
        $this->selectedCallId = null;

        Flux::toast(
            variant: 'success',
            heading: 'Demo calls added',
            text: 'Added sample incoming, outgoing, missed, voicemail, and failed calls.'
        );
    }

    private function normalizeCallFilter(): void
    {
        if (! in_array($this->callFilter, ['all', 'missed', 'voicemail', 'blocked'], true)) {
            $this->callFilter = 'all';
        }
    }

    public function selectCall(int $callId): void
    {
        $this->selectedCallId = $this->selectedCallId === $callId ? null : $callId;
    }

    /**
     * Place a new outbound call to the entered number.
     */
    public function placeNewCall(): void
    {
        $phone = trim($this->newCallNumber);

        if (! $phone) {
            Flux::toast(variant: 'danger', heading: 'No Number', text: 'Please enter a phone number.', duration: 3000, position: 'top right');
            return;
        }

        // Normalize to E.164
        $digits = preg_replace('/\D/', '', $phone);
        if (strlen($digits) === 10) {
            $phone = '+1' . $digits;
        } elseif (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $phone = '+' . $digits;
        } elseif (! str_starts_with($phone, '+')) {
            $phone = '+' . $digits;
        }

        $this->showNewCallModal = false;
        $this->newCallNumber = '';
        $this->callBack($phone);
    }

    /**
     * Initiate a click-to-call back to a caller's number.
     * Dials the logged-in user first, then bridges to the target.
     */
    public function callBack(string $phone): void
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

        // Prevent calling our own Telnyx number (loopback)
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

        $callLog = CallLog::create([
            'direction' => 'outgoing',
            'from_number' => $from,
            'to_number' => $phone,
            'status' => CallLog::STATUS_INITIATED,
            'user_id' => $user->id,
            'metadata' => [
                'type' => 'click_to_call',
                'target_phone' => $phone,
                'user_phone' => $userPhone,
            ],
        ]);

        try {
            $response = Http::withToken($apiKey)
                ->post('https://api.telnyx.com/v2/calls', [
                    'connection_id' => $connectionId,
                    'to' => $userPhone,
                    'from' => $from,
                    'from_display_name' => 'GS Construction',
                    'timeout_secs' => (int) config('services.telnyx.voice_timeout', 30),
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

                Log::channel('telnyx')->info('Click-to-call initiated from Calls tab', [
                    'call_log_id' => $callLog->id,
                    'user_phone' => $userPhone,
                    'target_phone' => $phone,
                ]);

                Flux::toast(variant: 'success', heading: 'Calling', text: 'Your phone will ring shortly...', duration: 8000, position: 'top right');
            } else {
                $callLog->update(['status' => CallLog::STATUS_FAILED]);

                Log::channel('telnyx')->error('Click-to-call failed from Calls tab', [
                    'status' => $response->status(),
                    'error' => $response->json(),
                ]);

                Flux::toast(variant: 'danger', heading: 'Call Failed', text: 'Could not initiate the call.', duration: 5000, position: 'top right');
            }
        } catch (\Exception $e) {
            $callLog->update(['status' => CallLog::STATUS_FAILED]);

            Log::channel('telnyx')->error('Click-to-call exception', [
                'error' => $e->getMessage(),
            ]);

            Flux::toast(variant: 'danger', heading: 'Error', text: 'Something went wrong initiating the call.', duration: 5000, position: 'top right');
        }
    }

    /**
     * Navigate to the SMS thread for a phone number.
     */
    public function textBack(string $phone): void
    {
        $thread = SmsGroupThread::whereJsonContains('participants', $phone)
            ->orderByDesc('last_activity_at')
            ->first();

        if ($thread) {
            // Switch to messages tab and select the thread via parent component
            // (redirect + navigate: true can cause blank pages on mobile webapps)
            $this->dispatch('switchToThread', threadId: $thread->id);
        } else {
            $this->dispatch('openNewThreadWithPhone', phone: $phone)->to(SmsNewThread::class);
        }
    }

    /**
     * Mark a call's phone number as spam and add it to the blocked callers database.
     */
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
            [
                'reason' => 'Manually marked as spam',
                'blocked_by_user_id' => auth()->id(),
                'auto_blocked' => false,
            ]
        );

        // Update all call logs from this number to blocked status
        CallLog::where('from_number', $phone)
            ->where('status', '!=', CallLog::STATUS_BLOCKED)
            ->update(['status' => CallLog::STATUS_BLOCKED]);

        Flux::toast(variant: 'success', heading: 'Marked as Spam', text: $this->formatPhone($phone) . ' has been blocked.', duration: 5000, position: 'top right');
    }

    /**
     * Resolve a phone number to a contact name (User → Vendor → formatted number).
     */
    /**
     * Check if a phone number belongs to a known User or Vendor.
     */
    public function isKnownContact(string $e164): bool
    {
        static $cache = [];

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
        static $cache = [];

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

        // Fall back to caller_name from a previous call log for this number
        $callLogName = CallLog::where(fn ($q) => $q->where('from_number', $e164)->orWhere('to_number', $e164))
            ->whereNotNull('caller_name')
            ->whereNotIn('caller_name', ['Incoming Call', 'Outgoing Call'])
            ->latest()
            ->value('caller_name');

        if ($callLogName) {
            return $cache[$e164] = $callLogName;
        }

        return $cache[$e164] = $this->formatPhone($e164);
    }

    /**
     * Format a phone number for display.
     */
    public function formatPhone(?string $phone): string
    {
        if (! $phone) {
            return 'Unknown';
        }

        $digits = preg_replace('/\D/', '', $phone);

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) === 10) {
            return sprintf('(%s) %s-%s',
                substr($digits, 0, 3),
                substr($digits, 3, 3),
                substr($digits, 6)
            );
        }

        return $phone;
    }

    public function render()
    {
        return view('livewire.sms.call-list');
    }

    public function placeholder(): \Illuminate\Contracts\View\View
    {
        return view('livewire.sms.call-list-placeholder');
    }
}
