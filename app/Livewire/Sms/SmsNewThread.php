<?php

namespace App\Livewire\Sms;

use App\Models\Client;
use App\Models\SmsGroupThread;
use App\Models\User;
use App\Models\Vendor;
use App\Services\GroupSmsService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SmsNewThread extends Component
{
    public bool $showModal = false;

    public ?int $clientId = null;

    public string $message = '';

    public ?int $existingThreadId = null;

    /** @var array<int, array{number: string, display: string, label: string}> */
    public array $recipients = [];

    public string $newNumber = '';

    protected $listeners = [
        'openNewThread' => 'open',
        'openNewThreadWithPhone' => 'openWithPhone',
    ];

    public function open(): void
    {
        $this->reset(['clientId', 'message', 'existingThreadId', 'recipients', 'newNumber']);
        $this->showModal = true;
    }

    public function openWithPhone(string $phone): void
    {
        $this->reset(['clientId', 'message', 'existingThreadId', 'recipients', 'newNumber']);
        $this->newNumber = $phone;
        $this->addNumber();
        $this->showModal = true;
    }

    public function updatedClientId(): void
    {
        $this->existingThreadId = null;

        if (! $this->clientId) {
            return;
        }

        // Check if a thread already exists for this client
        $existing = SmsGroupThread::where('client_id', $this->clientId)->first();
        if ($existing) {
            $this->existingThreadId = $existing->id;
            $this->message = '';

            return;
        }

        $client = Client::with('users')->find($this->clientId);

        if (! $client) {
            return;
        }

        // Auto-add client phone numbers to recipients list
        $this->addClientPhoneNumbers($client);

        $firstNames = $client->users
            ->pluck('first_name')
            ->filter()
            ->unique()
            ->join(', ', ' & ');

        if ($firstNames === '') {
            $firstNames = $client->name;
        }

        $this->message = "Hi {$firstNames},\n" . GroupSmsService::START_CONSENT_TEXT;
    }

    /**
     * Add all phone numbers from a client to the recipients list.
     */
    private function addClientPhoneNumbers(Client $client): void
    {
        $existingNumbers = collect($this->recipients)->pluck('number')->toArray();

        if ($client->getRawOriginal('home_phone')) {
            $e164 = GroupSmsService::formatE164($client->getRawOriginal('home_phone'));
            if (! in_array($e164, $existingNumbers)) {
                $this->recipients[] = [
                    'number' => $e164,
                    'display' => $this->formatDisplay($client->getRawOriginal('home_phone')),
                    'label' => $client->name,
                ];
            }
        }

        foreach ($client->users as $user) {
            if ($user->getRawOriginal('cell_phone')) {
                $e164 = GroupSmsService::formatE164($user->getRawOriginal('cell_phone'));
                if (! in_array($e164, $existingNumbers)) {
                    $this->recipients[] = [
                        'number' => $e164,
                        'display' => $this->formatDisplay($user->getRawOriginal('cell_phone')),
                        'label' => $user->first_name ?? '',
                    ];
                }
            }
        }
    }

    /**
     * Add a manually typed phone number to the recipients list.
     */
    public function addNumber(): void
    {
        $digits = preg_replace('/[^0-9]/', '', $this->newNumber);

        if (strlen($digits) < 10) {
            $this->addError('newNumber', 'Enter a valid 10+ digit phone number.');

            return;
        }

        $e164 = GroupSmsService::formatE164($digits);

        // Check for duplicates
        $existingNumbers = collect($this->recipients)->pluck('number')->toArray();
        if (in_array($e164, $existingNumbers)) {
            $this->addError('newNumber', 'This number is already added.');

            return;
        }

        // Look up user by phone number to get their name
        $label = $this->resolveNameForPhone($digits);

        $this->recipients[] = [
            'number' => $e164,
            'display' => $this->formatDisplay($digits),
            'label' => $label,
        ];

        $this->newNumber = '';
        $this->resetErrorBag('newNumber');

        // Pre-populate default consent message if empty
        if ($this->message === '') {
            $this->message = "Hi,\n" . GroupSmsService::START_CONSENT_TEXT;
        }
    }

    /**
     * Try to find a user or client matching the given phone digits and return their name.
     */
    private function resolveNameForPhone(string $digits): string
    {
        // Normalize: strip leading 1 for 11-digit US numbers
        $normalized = $digits;
        if (strlen($normalized) === 11 && str_starts_with($normalized, '1')) {
            $normalized = substr($normalized, 1);
        }

        // Also extract last 10 digits as fallback (handles non-standard E.164)
        $last10 = strlen($digits) > 10 ? substr($digits, -10) : $digits;

        // Search users by cell_phone (stored as raw digits)
        $user = User::where('cell_phone', $normalized)
            ->orWhere('cell_phone', '1' . $normalized)
            ->orWhere('cell_phone', $digits)
            ->orWhere('cell_phone', $last10)
            ->first();

        if ($user) {
            return trim($user->first_name . ' ' . $user->last_name);
        }

        // Search vendors by business_phone
        $vendor = Vendor::where('business_phone', $normalized)
            ->orWhere('business_phone', $last10)
            ->orWhere('business_phone', $digits)
            ->first();

        if ($vendor && $vendor->short_name) {
            return $vendor->short_name;
        }

        // Search clients by home_phone
        $client = Client::whereRaw("REPLACE(REPLACE(REPLACE(REPLACE(home_phone, ' ', ''), '-', ''), '(', ''), ')', '') LIKE ?", ['%' . $last10])
            ->first();

        if ($client) {
            return $client->name;
        }

        return '';
    }

    /**
     * Remove a recipient by index.
     */
    public function removeRecipient(int $index): void
    {
        unset($this->recipients[$index]);
        $this->recipients = array_values($this->recipients);
    }

    #[Computed]
    public function clients()
    {
        return Client::orderBy('created_at', 'DESC')->get();
    }

    /**
     * Format a raw phone number as (XXX) XXX-XXXX.
     */
    private function formatDisplay(string $raw): string
    {
        $digits = preg_replace('/[^0-9]/', '', $raw);

        // Strip leading 1 for US numbers
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) === 10) {
            return '(' . substr($digits, 0, 3) . ') ' . substr($digits, 3, 3) . '-' . substr($digits, 6);
        }

        return $raw;
    }

    public function goToExistingThread(): void
    {
        if ($this->existingThreadId) {
            $this->showModal = false;
            $this->dispatch('threadSelected', threadId: $this->existingThreadId);
        }
    }

    public function send(GroupSmsService $smsService): void
    {
        // Double-check no existing thread for the selected client
        if ($this->clientId && SmsGroupThread::where('client_id', $this->clientId)->exists()) {
            $this->addError('clientId', 'A thread already exists for this client.');

            return;
        }

        $this->validate([
            'clientId' => 'nullable|exists:clients,id',
            'message' => 'required|string|max:1600',
        ]);

        $phones = collect($this->recipients)
            ->pluck('number')
            ->unique()
            ->values()
            ->toArray();

        if (empty($phones)) {
            $this->addError('newNumber', 'Add at least one phone number.');

            return;
        }

        $thread = $smsService->sendNewGroup($phones, $this->message, null, $this->clientId, auth()->id(), auth()->user()->vendor?->id);

        $this->showModal = false;
        $this->dispatch('threadCreated', threadId: $thread->id);

        \Flux::toast('Consent request sent. Welcome message will be sent after all recipients reply START.');
    }

    /**
     * Get the SMS signature tag for the current user.
     * -PS for Patryk (ID 1), -GS for Grzegorz (ID 2), -GSC otherwise.
     */
    public static function getSignature(?int $userId = null): string
    {
        $userId ??= auth()->id();

        return match ($userId) {
            1 => '-PS',
            2 => '-GS',
            default => '-GSC',
        };
    }

    public function render()
    {
        return view('livewire.sms.new-thread');
    }
}
