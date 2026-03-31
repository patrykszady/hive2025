<?php

namespace App\Livewire\Sms;

use App\Livewire\Sms\SmsIndex;
use App\Livewire\Sms\SmsNewThread;
use App\Models\CallLog;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\SmsThreadParticipant;
use App\Models\SmsThreadRead;
use App\Models\User;
use App\Models\Vendor;
use App\Services\GroupSmsService;
use Flux;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Isolate;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Isolate]
class SmsConversation extends Component
{
    use WithFileUploads;

    public ?int $threadId = null;

    public string $newMessage = '';

    public $attachment;

    public bool $showImageLightbox = false;

    public ?string $lightboxImageUrl = null;

    public bool $isClientUser = false;

    public ?int $activeCallLogId = null;

    public bool $showOptInModal = false;

    public bool $showDeleteConfirm = false;

    public string $manualOptInReason = '';

    public ?int $manualOptInParticipantId = null;

    protected ?int $lastMarkedMessageId = null;

    public int $messageLimit = 30;

    public function mount(): void
    {
        $this->isClientUser = (bool) auth()->user()->is_browsing_as_client;
        $this->authorizeThread();
        $this->markThreadAsRead();
    }

    /**
     * Swap to a different thread without destroying/recreating the component.
     * Called from SmsIndex when user selects a thread or navigates back.
     */
    #[On('loadThread')]
    public function loadThread(?int $threadId): void
    {
        if ($threadId === $this->threadId) {
            // Same thread — refresh messages (e.g., after notification click)
            unset($this->smsMessages, $this->processedMessages, $this->phoneNameMap);
            $this->markThreadAsRead();
            $this->dispatch('thread-ready');
            return;
        }

        $this->threadId = $threadId;
        $this->authorizeThread();
        $this->messageLimit = 30;
        $this->newMessage = '';
        $this->attachment = null;
        $this->showImageLightbox = false;
        $this->lightboxImageUrl = null;
        $this->activeCallLogId = null;
        $this->showOptInModal = false;
        $this->manualOptInReason = '';
        $this->manualOptInParticipantId = null;
        $this->lastMarkedMessageId = null;

        $this->markThreadAsRead();
        $this->dispatch('thread-ready');
    }

    /** @return array<string, string> */
    public function getListeners(): array
    {
        $listeners = [];

        if ($this->threadId) {
            $listeners["echo-private:sms.thread.{$this->threadId},SmsMessageReceived"] = 'handleIncomingMessage';
        }

        return $listeners;
    }

    /**
     * Ensure the current user is allowed to view the selected thread.
     * Client users may only access threads belonging to their client(s).
     * Vendor users may only access threads belonging to their vendor.
     */
    private function authorizeThread(): void
    {
        if (! $this->threadId) {
            return;
        }

        $user = auth()->user();

        if ($this->isClientUser) {
            $clientIds = $user->clients()->pluck('clients.id');
            $allowed = SmsGroupThread::where('id', $this->threadId)
                ->whereIn('client_id', $clientIds)
                ->exists();
        } else {
            $vendorId = $user->vendor?->id;
            $allowed = $vendorId && SmsGroupThread::where('id', $this->threadId)
                ->visibleToVendor($vendorId)
                ->exists();
        }

        if (! $allowed) {
            $this->threadId = null;
        }
    }

    public function handleIncomingMessage(): void
    {
        $this->markThreadAsRead();
        $this->dispatch('sms-new-message-received');
    }

    #[On('refreshMessages')]
    public function refreshMessages(): void
    {
        unset($this->smsMessages, $this->processedMessages, $this->phoneNameMap);
        $this->markThreadAsRead();
    }

    public function updatedAttachment(): void
    {
        $this->validate([
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,gif,webp|max:15360',
        ], [
            'attachment.max' => 'The attachment must not be greater than 15MB.',
        ]);
    }

    public function removeAttachment(): void
    {
        $this->attachment = null;
    }

    public function openImageLightbox(string $url): void
    {
        $this->lightboxImageUrl = $url;
        $this->showImageLightbox = true;
        $this->dispatch('lightbox-images-updated', images: $this->threadImages);
    }

    /**
     * Flat list of all media URLs in this thread for lightbox gallery navigation.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function threadImages(): array
    {
        return $this->smsMessages
            ->flatMap(fn (SmsMessage $msg) => $msg->media_urls ?? [])
            ->values()
            ->all();
    }

    public function loadMoreMessages(): void
    {
        $this->messageLimit += 50;
    }

    public function deleteThread(): void
    {
        if ($this->isClientUser) {
            abort(403, 'Client users cannot delete threads.');
        }

        if (! $this->threadId) {
            return;
        }

        $thread = SmsGroupThread::findOrFail($this->threadId);

        $thread->messages()->delete();
        $thread->reads()->delete();
        $thread->threadParticipants()->delete();
        $thread->delete();

        $this->showDeleteConfirm = false;
        $this->threadId = null;

        $this->dispatch('threadDeleted')->to(SmsIndex::class);
    }

    public function updatedThreadId(): void
    {
        $this->markThreadAsRead();
    }

    public function sendMessage(GroupSmsService $smsService): void
    {
        if ($this->isClientUser) {
            abort(403, 'Client users cannot send messages.');
        }

        $this->validate([
            'newMessage' => 'required_without:attachment|nullable|string|max:1600',
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,gif,webp|max:15360',
            'threadId' => 'required|exists:sms_group_threads,id',
        ], [
            'attachment.max' => 'The attachment must not be greater than 15MB.',
        ]);

        $thread = SmsGroupThread::findOrFail($this->threadId);

        if ($thread->hasPendingOptIn()) {
            Flux::toast(
                variant: 'warning',
                heading: 'Awaiting START Replies',
                text: 'Each recipient must reply START before sending project messages.',
                duration: 5000,
                position: 'top right'
            );

            return;
        }

        $text = trim($this->newMessage);
        $messageWithSig = $text ? $text . "\n" . SmsNewThread::getSignature() : SmsNewThread::getSignature();

        $mediaUrls = [];
        if ($this->attachment) {
            $path = $this->attachment->store('sms-attachments', 'public');
            // Store a relative path so images display correctly regardless of domain.
            // The SendGroupMms job converts to an absolute URL when calling Telnyx.
            $mediaUrls[] = '/storage/' . $path;
        }

        $smsService->sendToThread($thread, $messageWithSig, $mediaUrls, auth()->id());

        $this->newMessage = '';
        $this->attachment = null;

        // Clear memoized computed properties so the re-render fetches fresh data
        unset($this->smsMessages, $this->processedMessages, $this->phoneNameMap);

        $this->dispatch('messageSent');
    }

    public function resendOptInPrompt(GroupSmsService $smsService): void
    {
        if ($this->isClientUser) {
            abort(403, 'Client users cannot send messages.');
        }

        if (! $this->threadId) {
            Flux::toast(variant: 'danger', heading: 'No Thread', text: 'No conversation selected.', duration: 4000, position: 'top right');
            return;
        }

        $thread = SmsGroupThread::findOrFail($this->threadId);

        if (! $thread->hasPendingOptIn()) {
            Flux::toast(variant: 'info', heading: 'Already Opted In', text: 'All recipients have already replied START.', duration: 4000, position: 'top right');
            return;
        }

        $smsService->resendConsentPrompt($thread);

        Flux::toast(variant: 'success', heading: 'Prompt Sent', text: 'START opt-in message was resent to this thread.', duration: 4500, position: 'top right');

        $this->dispatch('messageSent');
    }

    public function openOptInModal(): void
    {
        $this->showOptInModal = true;
        $this->manualOptInReason = '';
        $this->manualOptInParticipantId = null;
    }

    public function manualOptIn(GroupSmsService $smsService): void
    {
        if ($this->isClientUser) {
            abort(403, 'Client users cannot send messages.');
        }

        $this->validate([
            'manualOptInParticipantId' => 'required|exists:sms_thread_participants,id',
            'manualOptInReason' => 'required|string|max:500',
        ], [
            'manualOptInParticipantId.required' => 'Please select a participant.',
            'manualOptInReason.required' => 'Please provide a reason for the manual opt-in.',
        ]);

        $participant = SmsThreadParticipant::findOrFail($this->manualOptInParticipantId);

        if ((int) $participant->thread_id !== $this->threadId) {
            abort(403);
        }

        $participant->update([
            'opted_in_at' => now(),
            'manual_opt_in_reason' => $this->manualOptInReason,
            'manual_opt_in_by' => auth()->id(),
        ]);

        $thread = SmsGroupThread::findOrFail($this->threadId);

        // If all participants are now opted in, send the welcome message
        $smsService->markParticipantOptedInAndSendWelcomeIfReady(
            $thread,
            $participant->phone_number,
            auth()->id(),
        );

        $name = $this->resolvePhoneDisplay($participant->phone_number);

        Flux::toast(variant: 'success', heading: 'Opted In', text: "{$name} has been manually opted in.", duration: 5000, position: 'top right');

        $this->manualOptInReason = '';
        $this->manualOptInParticipantId = null;
        $this->showOptInModal = false;

        $this->dispatch('messageSent');
    }

    /**
     * Participants pending opt-in for the current thread.
     *
     * @return \Illuminate\Support\Collection<int, SmsThreadParticipant>
     */
    #[Computed]
    public function pendingParticipants(): \Illuminate\Support\Collection
    {
        if (! $this->threadId) {
            return collect();
        }

        return SmsThreadParticipant::where('thread_id', $this->threadId)
            ->whereNull('opted_in_at')
            ->get();
    }

    #[Computed]
    public function thread(): ?SmsGroupThread
    {
        if (! $this->threadId) {
            return null;
        }

        return SmsGroupThread::with([
            'project:id,address',
            'client',
            'client.users:id,first_name,last_name,cell_phone',
        ])->find($this->threadId);
    }

    /**
     * Initiate a click-to-call: dials the logged-in user first, then bridges to the target.
     */
    public function initiateCall(string $targetPhone): void
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
        if (GroupSmsService::isOurNumber($targetPhone)) {
            Log::channel('telnyx')->warning('Click-to-call blocked: target is own Telnyx number', [
                'target_phone' => $targetPhone,
            ]);
            Flux::toast(variant: 'danger', heading: 'Invalid Number', text: 'Cannot call the company phone number.', duration: 5000, position: 'top right');
            return;
        }

        if (! $apiKey || ! $connectionId) {
            Flux::toast(variant: 'danger', heading: 'Not Configured', text: 'Voice calling is not configured.', duration: 5000, position: 'top right');
            return;
        }

        // Create a call log for this outbound call
        $callLog = CallLog::create([
            'direction' => 'outgoing',
            'from_number' => $from,
            'to_number' => $targetPhone,
            'status' => CallLog::STATUS_INITIATED,
            'user_id' => $user->id,
            'metadata' => [
                'type' => 'click_to_call',
                'target_phone' => $targetPhone,
                'user_phone' => $userPhone,
            ],
        ]);

        try {
            // Step 1: Call the logged-in user's cell phone first
            $response = Http::withToken($apiKey)
                ->post('https://api.telnyx.com/v2/calls', [
                    'connection_id' => $connectionId,
                    'to' => $userPhone,
                    'from' => $from,
                    'from_display_name' => 'GS Construction',
                    'timeout_secs' => (int) config('services.telnyx.voice_timeout', 30),
                    'client_state' => base64_encode(json_encode([
                        'action' => 'click_to_call',
                        'target_phone' => $targetPhone,
                        'call_log_id' => $callLog->id,
                    ])),
                    'webhook_url' => $this->telnyxWebhookUrl(),
                ]);

            if ($response->successful()) {
                $data = $response->json('data');
                $callLog->update([
                    'call_control_id' => $data['call_control_id'] ?? null,
                    'call_session_id' => $data['call_session_id'] ?? null,
                    'call_leg_id' => $data['call_leg_id'] ?? null,
                ]);

                Log::channel('telnyx')->info('Click-to-call initiated', [
                    'call_log_id' => $callLog->id,
                    'user_phone' => $userPhone,
                    'target_phone' => $targetPhone,
                    'call_control_id' => $data['call_control_id'] ?? null,
                ]);

                $this->activeCallLogId = $callLog->id;

                Flux::toast(variant: 'success', heading: 'Calling', text: 'Your phone will ring shortly...', duration: 8000, position: 'top right');
            } else {
                $callLog->update(['status' => CallLog::STATUS_FAILED]);
                Log::channel('telnyx')->error('Click-to-call API failed', [
                    'status' => $response->status(),
                    'error' => $response->json(),
                ]);
                Flux::toast(variant: 'danger', heading: 'Call Failed', text: 'Could not initiate the call.', duration: 5000, position: 'top right');
            }
        } catch (\Exception $e) {
            $callLog->update(['status' => CallLog::STATUS_FAILED]);
            Log::channel('telnyx')->error('Click-to-call exception', ['error' => $e->getMessage()]);
            Flux::toast(variant: 'danger', heading: 'Call Failed', text: 'Could not initiate the call.', duration: 5000, position: 'top right');
        }
    }

    /**
     * Invite another person to the active conference call.
     */
    public function inviteToConference(string $targetPhone): void
    {
        if (! $this->activeCallLogId) {
            Flux::toast(variant: 'warning', heading: 'No Active Call', text: 'You must be on a call to invite someone.', duration: 4000, position: 'top right');
            return;
        }

        $callLog = CallLog::find($this->activeCallLogId);

        if (! $callLog) {
            $this->activeCallLogId = null;
            Flux::toast(variant: 'warning', heading: 'Call Ended', text: 'The call is no longer active.', duration: 4000, position: 'top right');
            return;
        }

        // Check if call is still in a connectable state
        if (in_array($callLog->status, [CallLog::STATUS_COMPLETED, CallLog::STATUS_FAILED, CallLog::STATUS_MISSED])) {
            $this->activeCallLogId = null;
            Flux::toast(variant: 'warning', heading: 'Call Ended', text: 'The call has already ended.', duration: 4000, position: 'top right');
            return;
        }

        $conferenceName = $callLog->metadata['conference_name'] ?? "outbound-{$callLog->id}";

        $apiKey = config('services.telnyx.api_key');
        $connectionId = config('services.telnyx.connection_id');
        $from = config('services.telnyx.from');

        $callerUser = auth()->user();
        $callerFirstName = $callerUser?->first_name ?? 'Someone';

        try {
            $response = Http::withToken($apiKey)
                ->post('https://api.telnyx.com/v2/calls', [
                    'connection_id' => $connectionId,
                    'to' => $targetPhone,
                    'from' => $from,
                    'from_display_name' => 'GS Construction',
                    'timeout_secs' => (int) config('services.telnyx.voice_timeout', 30),
                    'client_state' => base64_encode(json_encode([
                        'action' => 'conference_invite',
                        'call_log_id' => $callLog->id,
                        'conference_name' => $conferenceName,
                        'caller_name' => $callerFirstName,
                    ])),
                    'webhook_url' => $this->telnyxWebhookUrl(),
                ]);

            if ($response->successful()) {
                $data = $response->json('data') ?? [];

                // Track the invited call control ID in metadata
                $metadata = $callLog->metadata ?? [];
                $invited = $metadata['invited_call_control_ids'] ?? [];
                $invited[] = $data['call_control_id'] ?? null;
                $metadata['invited_call_control_ids'] = array_filter($invited);
                $callLog->update(['metadata' => $metadata]);

                // Resolve a display name for the toast
                $inviteName = $this->resolvePhoneDisplay($targetPhone);

                Log::channel('telnyx')->info('Conference invite dialed', [
                    'call_log_id' => $callLog->id,
                    'conference_name' => $conferenceName,
                    'target_phone' => $targetPhone,
                    'invite_call_control_id' => $data['call_control_id'] ?? null,
                ]);

                Flux::toast(variant: 'success', heading: 'Inviting', text: "Calling {$inviteName} to join the call...", duration: 6000, position: 'top right');
            } else {
                Log::channel('telnyx')->error('Conference invite failed', [
                    'status' => $response->status(),
                    'error' => $response->json(),
                ]);
                Flux::toast(variant: 'danger', heading: 'Invite Failed', text: 'Could not dial the participant.', duration: 5000, position: 'top right');
            }
        } catch (\Exception $e) {
            Log::channel('telnyx')->error('Conference invite exception', ['error' => $e->getMessage()]);
            Flux::toast(variant: 'danger', heading: 'Invite Failed', text: 'Something went wrong.', duration: 5000, position: 'top right');
        }
    }

    /**
     * Clear the active call state (user dismisses the call bar).
     */
    public function clearActiveCall(): void
    {
        $this->activeCallLogId = null;
    }

    /**
     * Get contacts that can be invited to the active conference.
     * Includes vendor admin users + thread client users, excluding anyone already on the call.
     *
     * @return \Illuminate\Support\Collection<int, array{name: string, e164: string, display: string, type: string}>
     */
    #[Computed]
    public function conferenceInvitableContacts()
    {
        if (! $this->activeCallLogId) {
            return collect();
        }

        $callLog = CallLog::find($this->activeCallLogId);
        $calledNumber = $callLog?->to_number;
        $userPhone = auth()->user()->routeNotificationForTelnyx();

        $contacts = collect();

        // Add vendor admin users (team members)
        $vendor = Vendor::find(1);
        if ($vendor) {
            $adminUsers = User::whereHas('vendors', fn ($q) => $q->where('vendors.id', $vendor->id))
                ->whereNotNull('cell_phone')
                ->where('cell_phone', '!=', '')
                ->where('id', '!=', auth()->id())
                ->get();

            foreach ($adminUsers as $admin) {
                $e164 = $admin->routeNotificationForTelnyx();
                if (! $e164 || $e164 === $calledNumber) {
                    continue;
                }
                $contacts->push([
                    'name' => trim($admin->first_name . ' ' . $admin->last_name),
                    'e164' => $e164,
                    'display' => $this->formatPhoneForDisplay($e164),
                    'type' => 'team',
                ]);
            }
        }

        // Add client users from thread
        if ($this->thread?->client) {
            foreach ($this->thread->client->users as $clientUser) {
                $e164 = $clientUser->routeNotificationForTelnyx();
                if (! $e164 || $e164 === $calledNumber || $e164 === $userPhone) {
                    continue;
                }
                // Skip if already in the team list
                if ($contacts->contains('e164', $e164)) {
                    continue;
                }
                $contacts->push([
                    'name' => trim($clientUser->first_name . ' ' . $clientUser->last_name),
                    'e164' => $e164,
                    'display' => $this->formatPhoneForDisplay($e164),
                    'type' => 'client',
                ]);
            }
        }

        return $contacts;
    }

    protected function formatPhoneForDisplay(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }
        if (strlen($digits) === 10) {
            return '(' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6);
        }
        return $phone;
    }

    #[Computed]
    public function smsMessages()
    {
        if (! $this->threadId) {
            return collect();
        }

        return SmsMessage::where('thread_id', $this->threadId)
            ->select(['id', 'thread_id', 'direction', 'from_number', 'text', 'media_urls', 'created_at', 'sent_by_user_id'])
            ->with('sentByUser:id,first_name,last_name')
            ->orderByDesc('created_at')
            ->limit($this->messageLimit)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * Build phone number → display name lookup for all inbound senders.
     * Merges client user first names with resolvePhoneDisplay fallback.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function phoneNameMap(): array
    {
        $map = $this->smsMessages
            ->where('direction', 'inbound')
            ->pluck('from_number')
            ->unique()
            ->filter()
            ->mapWithKeys(fn (string $number) => [$number => $this->resolvePhoneDisplay($number)])
            ->all();

        // Client user first names take precedence
        if ($this->thread?->client) {
            foreach ($this->thread->client->users as $user) {
                $telnyx = $user->routeNotificationForTelnyx();
                if ($telnyx) {
                    $map[$telnyx] = $user->first_name;
                }
            }
            $rawHome = $this->thread->client->getRawOriginal('home_phone');
            if ($rawHome) {
                $formatted = \App\Services\GroupSmsService::formatE164($rawHome);
                if (! isset($map[$formatted])) {
                    $map[$formatted] = $this->thread->client->name;
                }
            }
        }

        return $map;
    }

    /**
     * Parse tapback reactions and build:
     * - visibleMessages: messages with tapbacks filtered out
     * - reactionsMap: message ID → [emoji => [sender_name, ...]]
     *
     * @return array{visible: \Illuminate\Support\Collection, reactions: array}
     */
    #[Computed]
    public function processedMessages(): array
    {
        $allMessages = $this->smsMessages;
        $phoneNameMap = $this->phoneNameMap;
        $tapbackIds = collect();
        $reactionsMap = [];

        foreach ($allMessages as $msg) {
            $tapback = $msg->parseTapback();
            if (! $tapback || ! $tapback['emoji']) {
                continue;
            }

            $quotedNormalized = mb_strtolower(trim($tapback['quoted']));
            $quotedLen = mb_strlen($quotedNormalized);
            $matched = $allMessages
                ->filter(function ($candidate) use ($quotedNormalized, $msg) {
                    if ($candidate->id === $msg->id) {
                        return false;
                    }
                    $candidateText = $candidate->display_text;
                    if (! $candidateText) {
                        return false;
                    }
                    $candidateNormalized = mb_strtolower(trim($candidateText));

                    return str_contains($candidateNormalized, $quotedNormalized)
                        || str_contains($quotedNormalized, $candidateNormalized);
                })
                ->sortBy(fn ($c) => abs(mb_strlen(mb_strtolower(trim($c->display_text))) - $quotedLen))
                ->first();

            // Generic (strict) tapbacks are only processed when the quoted text
            // actually matches a message in the thread — avoids hiding normal messages.
            if (($tapback['strict'] ?? false) && ! $matched) {
                continue;
            }

            $tapbackIds->push($msg->id);

            if ($matched) {
                $senderName = $phoneNameMap[$msg->from_number] ?? substr($msg->from_number, -4);
                $reactionsMap[$matched->id][$tapback['emoji']][] = $senderName;
            }
        }

        return [
            'visible' => $allMessages->reject(fn ($m) => $tapbackIds->contains($m->id)),
            'reactions' => $reactionsMap,
        ];
    }

    /**
     * Build a publicly reachable webhook URL for Telnyx voice callbacks.
     */
    protected function telnyxWebhookUrl(): string
    {
        $appUrl = config('app.url');

        if (str_contains($appUrl, '127.0.0.1') || str_contains($appUrl, 'localhost')) {
            $publicUrl = config('services.telnyx.public_url');

            if ($publicUrl) {
                return rtrim($publicUrl, '/') . '/webhooks/telnyx/voice';
            }
        }

        return rtrim($appUrl, '/') . '/webhooks/telnyx/voice';
    }

    public function render()
    {
        return view('livewire.sms.conversation');
    }

    public function placeholder()
    {
        return view('livewire.sms.conversation_placeholder');
    }

    /**
     * Resolve a display name for an E.164 phone number.
     */
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

        $display10 = strlen($normalized) === 10 ? $normalized : $last10;
        if (strlen($display10) === 10) {
            return $cache[$e164] = '(' . substr($display10, 0, 3) . ') ' . substr($display10, 3, 3) . '-' . substr($display10, 6);
        }

        return $cache[$e164] = $e164;
    }

    protected function markThreadAsRead(): void
    {
        if (! $this->threadId || ! auth()->id()) {
            return;
        }

        $latestMessageId = SmsMessage::where('thread_id', $this->threadId)->max('id');

        if (! $latestMessageId || $latestMessageId === $this->lastMarkedMessageId) {
            return;
        }

        SmsThreadRead::updateOrCreate(
            [
                'thread_id' => $this->threadId,
                'user_id' => auth()->id(),
            ],
            [
                'last_read_message_id' => $latestMessageId,
            ]
        );

        $this->lastMarkedMessageId = $latestMessageId;

        // Notify thread list to update unread indicators
        $this->dispatch('sms-thread-read');
    }
}
