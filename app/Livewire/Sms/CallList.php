<?php

namespace App\Livewire\Sms;

use App\Models\CallLog;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithPagination;
use Flux;

class CallList extends Component
{
    use WithPagination;

    public string $callFilter = 'all';

    public ?int $selectedCallId = null;

    public bool $showNewCallModal = false;

    public string $newCallNumber = '';

    public ?int $selectedUserId = null;

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
        $this->resetPage();
        $this->selectedCallId = null;
    }

    /**
     * @return \Illuminate\Database\Eloquent\Collection<User>
     */
    #[Computed]
    public function contactUsers(): mixed
    {
        return User::whereNotNull('cell_phone')
            ->where('cell_phone', '!=', '')
            ->orderBy('first_name')
            ->get();
    }

    /**
     * @return \Illuminate\Pagination\LengthAwarePaginator<CallLog>
     */
    #[Computed]
    public function calls(): mixed
    {
        return CallLog::query()
            ->where('direction', 'incoming')
            ->when($this->callFilter === 'missed', fn ($q) => $q->where('status', CallLog::STATUS_MISSED))
            ->when($this->callFilter === 'voicemail', fn ($q) => $q->where('status', CallLog::STATUS_VOICEMAIL))
            ->when($this->callFilter === 'completed', fn ($q) => $q->where('status', CallLog::STATUS_COMPLETED))
            ->orderByDesc('created_at')
            ->paginate(25);
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
                    'timeout_secs' => (int) config('services.telnyx.voice_timeout', 30),
                    'client_state' => base64_encode(json_encode([
                        'action' => 'click_to_call',
                        'target_phone' => $phone,
                        'call_log_id' => $callLog->id,
                    ])),
                    'webhook_url' => rtrim(config('services.telnyx.public_url', config('app.url')), '/') . '/webhooks/telnyx/voice',
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
