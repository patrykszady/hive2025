<?php

namespace App\Livewire\Sms;

use App\Models\Client;
use App\Models\SmsGroupThread;
use App\Services\GroupSmsService;
use Livewire\Attributes\Computed;
use Livewire\Component;

class SmsNewThread extends Component
{
    public bool $showModal = false;

    public ?int $clientId = null;

    public string $message = '';

    public ?int $existingThreadId = null;

    protected $listeners = ['openNewThread' => 'open'];

    public function open(): void
    {
        $this->reset(['clientId', 'message', 'existingThreadId']);
        $this->showModal = true;
    }

    public function updatedClientId(): void
    {
        $this->existingThreadId = null;

        if (! $this->clientId) {
            $this->message = '';

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

        $firstNames = $client->users
            ->pluck('first_name')
            ->filter()
            ->unique()
            ->implode(' & ');

        if (empty($firstNames)) {
            $firstNames = $client->name;
        }

        $vendor = auth()->user()?->vendor;
        $vendorName = $vendor?->short_name ?? 'Our company';
        $vendorEmail = $vendor?->business_email ?? '';

        $this->message = "Hi {$firstNames},\n{$vendorName} welcomes you to our project msg thread. Msgs will be tagged with \"-PS\" for Patryk's replies, \"-GS\" for Grzegorz's, and our automated \"GS Crew\" replies by \"-GSC\". crew@gs.construction will be the best centralized mailbox to reach us via email going forward. Our signatures will be clearly marked as to who's responding. Please add this phone number to your contact list as \"GS Construction\". Let's get this project started!";
    }

    #[Computed]
    public function clients()
    {
        return Client::orderBy('created_at', 'DESC')->get();
    }

    #[Computed]
    public function clientPhoneNumbers(): array
    {
        if (! $this->clientId) {
            return [];
        }

        $client = Client::with('users')->find($this->clientId);

        if (! $client) {
            return [];
        }

        $phones = collect();

        // Add client home_phone
        if ($client->getRawOriginal('home_phone')) {
            $formatted = $this->formatDisplay($client->getRawOriginal('home_phone'));
            $phones->push([
                'number' => GroupSmsService::formatE164($client->getRawOriginal('home_phone')),
                'display' => $formatted,
                'label' => $client->name,
            ]);
        }

        // Add user cell phones
        foreach ($client->users as $user) {
            if ($user->getRawOriginal('cell_phone')) {
                $formatted = $this->formatDisplay($user->getRawOriginal('cell_phone'));
                $phones->push([
                    'number' => GroupSmsService::formatE164($user->getRawOriginal('cell_phone')),
                    'display' => $formatted,
                    'label' => $user->first_name,
                ]);
            }
        }

        return $phones->unique('number')->values()->toArray();
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
        // Double-check no existing thread
        if ($this->clientId && SmsGroupThread::where('client_id', $this->clientId)->exists()) {
            $this->addError('clientId', 'A thread already exists for this client.');

            return;
        }

        $this->validate([
            'clientId' => 'required|exists:clients,id',
            'message' => 'required|string|max:1600',
        ]);

        $phones = collect($this->clientPhoneNumbers)
            ->pluck('number')
            ->unique()
            ->values()
            ->toArray();

        if (empty($phones)) {
            $this->addError('clientId', 'This client has no phone numbers on file.');

            return;
        }

        $messageWithSig = $this->message . "\n" . self::getSignature();

        $thread = $smsService->sendNewGroup($phones, $messageWithSig, null, $this->clientId, auth()->id());

        $this->showModal = false;
        $this->dispatch('threadCreated', threadId: $thread->id);

        \Flux::toast('Message sent!');
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
