<?php

namespace App\Livewire\Sms;

use App\Livewire\Sms\SmsNewThread;
use App\Models\CallLog;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\SmsThreadRead;
use App\Models\User;
use App\Models\Vendor;
use App\Services\GroupSmsService;
use Flux;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

class SmsConversation extends Component
{
    use WithFileUploads;

    public ?int $threadId = null;

    public string $newMessage = '';

    public $attachment;

    public bool $showImageLightbox = false;

    public ?string $lightboxImageUrl = null;

    public bool $isClientUser = false;

    protected ?int $lastMarkedMessageId = null;

    public function mount(): void
    {
        $this->markThreadAsRead();
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

    public function handleIncomingMessage(): void
    {
        $this->markThreadAsRead();
        $this->dispatch('sms-new-message-received');
    }

    public function updatedAttachment(): void
    {
        $this->validate([
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,gif,webp|max:5120',
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
            'attachment' => 'nullable|file|mimes:jpg,jpeg,png,gif,webp|max:5120',
            'threadId' => 'required|exists:sms_group_threads,id',
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
                    'timeout_secs' => (int) config('services.telnyx.voice_timeout', 30),
                    'client_state' => base64_encode(json_encode([
                        'action' => 'click_to_call',
                        'target_phone' => $targetPhone,
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

                Log::channel('telnyx')->info('Click-to-call initiated', [
                    'call_log_id' => $callLog->id,
                    'user_phone' => $userPhone,
                    'target_phone' => $targetPhone,
                    'call_control_id' => $data['call_control_id'] ?? null,
                ]);

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

    #[Computed]
    public function smsMessages()
    {
        if (! $this->threadId) {
            return collect();
        }

        return SmsMessage::where('thread_id', $this->threadId)
            ->select(['id', 'thread_id', 'direction', 'from_number', 'text', 'media_urls', 'created_at', 'sent_by_user_id'])
            ->with('sentByUser:id,first_name,last_name')
            ->orderBy('created_at', 'asc')
            ->get();
    }

    public function render()
    {
        $phoneNameMap = $this->smsMessages
            ->where('direction', 'inbound')
            ->pluck('from_number')
            ->unique()
            ->filter()
            ->mapWithKeys(fn (string $number) => [$number => $this->resolvePhoneDisplay($number)])
            ->all();

        return view('livewire.sms.conversation', compact('phoneNameMap'));
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
            return trim($user->first_name . ' ' . $user->last_name);
        }

        $vendor = Vendor::where('business_phone', $normalized)
            ->orWhere('business_phone', $last10)
            ->orWhere('business_phone', $digits)
            ->first();

        if ($vendor && $vendor->short_name) {
            return $vendor->short_name;
        }

        $display10 = strlen($normalized) === 10 ? $normalized : $last10;
        if (strlen($display10) === 10) {
            return '(' . substr($display10, 0, 3) . ') ' . substr($display10, 3, 3) . '-' . substr($display10, 6);
        }

        return $e164;
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

        $this->dispatch('threadRead')->to(SmsIndex::class);
        $this->dispatch('sms-thread-read');
    }
}
