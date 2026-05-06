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

    /** 'client' or 'vendor' */
    public string $recipientType = 'client';

    public ?int $clientId = null;

    public ?int $vendorId = null;

    public string $message = '';

    public ?int $existingThreadId = null;

    public ?string $recipientPreset = null;

    /** @var array<int, array{value: string, label: string, recipients: array<int, array{number: string, display: string, label: string}>, existingThreadId: ?int}> */
    public array $recipientPresetOptions = [];

    /** @var array<int, array{number: string, display: string, label: string}> */
    public array $recipients = [];

    public string $newNumber = '';

    protected $listeners = [
        'openNewThread' => 'open',
        'openNewThreadWithPhone' => 'openWithPhone',
    ];

    public function open(): void
    {
        $this->reset(['recipientType', 'clientId', 'vendorId', 'message', 'existingThreadId', 'recipientPreset', 'recipientPresetOptions', 'recipients', 'newNumber']);
        $this->showModal = true;
    }

    public function openWithPhone(string $phone): void
    {
        $this->reset(['recipientType', 'clientId', 'vendorId', 'message', 'existingThreadId', 'recipientPreset', 'recipientPresetOptions', 'recipients', 'newNumber']);
        $this->newNumber = $phone;
        $this->addNumber();
        $this->showModal = true;
    }

    public function updatedRecipientType(): void
    {
        $this->reset(['clientId', 'vendorId', 'existingThreadId', 'recipientPreset', 'recipientPresetOptions', 'recipients', 'message']);
        $this->resetValidation();
    }

    public function updatedClientId(): void
    {
        $this->existingThreadId = null;
        $this->recipientPreset = null;
        $this->recipientPresetOptions = [];
        $this->recipients = [];

        if (! $this->clientId) {
            return;
        }

        $client = Client::with('users')->find($this->clientId);

        if (! $client) {
            return;
        }

        $existingClientThreads = SmsGroupThread::query()
            ->where('client_id', $client->id)
            ->get(['id', 'participants']);

        $this->recipientPresetOptions = $this->buildRecipientPresetOptions($client->users, $existingClientThreads);

        if (! empty($this->recipientPresetOptions)) {
            $defaultOption = collect($this->recipientPresetOptions)
                ->sortByDesc(fn (array $option): int => count($option['recipients']))
                ->first();

            if (is_array($defaultOption)) {
                $this->recipientPreset = $defaultOption['value'];
                $this->applyRecipientPreset($this->recipientPreset);
            }
        } else {
            $this->addClientPhoneNumbers($client);
        }

        $this->setDefaultMessageFromRecipients($client);
    }

    public function updatedRecipientPreset(): void
    {
        $this->applyRecipientPreset($this->recipientPreset);
    }

    public function updatedVendorId(): void
    {
        $this->existingThreadId = null;
        $this->recipientPreset = null;
        $this->recipientPresetOptions = [];
        $this->recipients = [];

        if (! $this->vendorId) {
            return;
        }

        $vendor = Vendor::with('users')->find($this->vendorId);
        if (! $vendor) {
            return;
        }

        $existingVendorThreads = SmsGroupThread::query()
            ->where('subject_vendor_id', $vendor->id)
            ->get(['id', 'participants']);

        $this->recipientPresetOptions = $this->buildVendorRecipientPresetOptions($vendor, $existingVendorThreads);

        if (! empty($this->recipientPresetOptions)) {
            $defaultOption = collect($this->recipientPresetOptions)
                ->sortByDesc(fn (array $option): int => count($option['recipients']))
                ->first();

            if (is_array($defaultOption)) {
                $this->recipientPreset = $defaultOption['value'];
                $this->applyRecipientPreset($this->recipientPreset);
            }
        } else {
            $this->addVendorPhoneNumbers($vendor);
        }

        $this->setDefaultMessageFromVendor($vendor);
    }

    /**
     * Build presets for a vendor: business phone, each user, and a group of all phones.
     *
     * @param  iterable<SmsGroupThread>  $threads
     * @return array<int, array{value: string, label: string, recipients: array<int, array{number: string, display: string, label: string}>, existingThreadId: ?int}>
     */
    public function buildVendorRecipientPresetOptions(Vendor $vendor, iterable $threads = []): array
    {
        $recipientEntries = collect();

        $businessPhone = $vendor->getRawOriginal('business_phone');
        if (! is_string($businessPhone) || $businessPhone === '') {
            $businessPhone = (string) ($vendor->business_phone ?? '');
        }
        if (is_string($businessPhone) && $businessPhone !== '') {
            $recipientEntries->push([
                'number' => GroupSmsService::formatE164($businessPhone),
                'display' => $this->formatDisplay($businessPhone),
                'label' => $vendor->short_name ?: $vendor->name,
            ]);
        }

        foreach ($vendor->users as $user) {
            $entry = $this->buildRecipientEntryFromUser($user);
            if ($entry) {
                $recipientEntries->push($entry);
            }
        }

        $recipientEntries = $recipientEntries->unique('number')->values();

        if ($recipientEntries->isEmpty()) {
            return [];
        }

        $options = [];

        foreach ($recipientEntries as $entry) {
            $phones = [$entry['number']];
            $signature = $this->participantSignature($phones);

            $options[] = [
                'value' => $signature,
                'label' => $entry['label'] !== '' ? $entry['label'] : $entry['display'],
                'recipients' => [$entry],
                'existingThreadId' => $this->resolveExistingThreadIdForParticipants($threads, $phones),
            ];
        }

        if ($recipientEntries->count() > 1) {
            $groupRecipients = $recipientEntries->all();
            $groupPhones = $recipientEntries->pluck('number')->all();
            $groupSignature = $this->participantSignature($groupPhones);
            $groupLabel = $recipientEntries
                ->pluck('label')
                ->filter()
                ->join(', ', ' & ');

            $options[] = [
                'value' => $groupSignature,
                'label' => $groupLabel !== '' ? $groupLabel : 'Group',
                'recipients' => $groupRecipients,
                'existingThreadId' => $this->resolveExistingThreadIdForParticipants($threads, $groupPhones),
            ];
        }

        return collect($options)
            ->unique('value')
            ->values()
            ->all();
    }

    private function addVendorPhoneNumbers(Vendor $vendor): void
    {
        $existingNumbers = collect($this->recipients)->pluck('number')->toArray();

        $businessPhone = $vendor->getRawOriginal('business_phone');
        if (is_string($businessPhone) && $businessPhone !== '') {
            $e164 = GroupSmsService::formatE164($businessPhone);
            if (! in_array($e164, $existingNumbers)) {
                $this->recipients[] = [
                    'number' => $e164,
                    'display' => $this->formatDisplay($businessPhone),
                    'label' => $vendor->short_name ?: $vendor->name,
                ];
                $existingNumbers[] = $e164;
            }
        }

        foreach ($vendor->users as $user) {
            if ($user->getRawOriginal('cell_phone')) {
                $e164 = GroupSmsService::formatE164($user->getRawOriginal('cell_phone'));
                if (! in_array($e164, $existingNumbers)) {
                    $this->recipients[] = [
                        'number' => $e164,
                        'display' => $this->formatDisplay($user->getRawOriginal('cell_phone')),
                        'label' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
                    ];
                }
            }
        }
    }

    private function setDefaultMessageFromVendor(Vendor $vendor): void
    {
        $recipientNames = collect($this->recipients)
            ->pluck('label')
            ->map(fn ($name) => explode(' ', trim($name))[0])
            ->filter()
            ->unique()
            ->join(', ', ' & ');

        if ($recipientNames === '') {
            $recipientNames = $vendor->short_name ?: $vendor->name;
        }

        $this->message = "Hi {$recipientNames},\n" . GroupSmsService::START_CONSENT_TEXT;
    }

    /**
     * @param  iterable<User>  $users
     * @param  iterable<SmsGroupThread>  $threads
     * @return array<int, array{value: string, label: string, recipients: array<int, array{number: string, display: string, label: string}>, existingThreadId: ?int}>
     */
    public function buildRecipientPresetOptions(iterable $users, iterable $threads = []): array
    {
        $recipientEntries = collect($users)
            ->map(fn (User $user): ?array => $this->buildRecipientEntryFromUser($user))
            ->filter()
            ->values();

        if ($recipientEntries->isEmpty()) {
            return [];
        }

        $options = [];

        foreach ($recipientEntries as $entry) {
            $phones = [$entry['number']];
            $signature = $this->participantSignature($phones);

            $options[] = [
                'value' => $signature,
                'label' => $entry['label'] !== '' ? $entry['label'] : $entry['display'],
                'recipients' => [$entry],
                'existingThreadId' => $this->resolveExistingThreadIdForParticipants($threads, $phones),
            ];
        }

        if ($recipientEntries->count() > 1) {
            $groupRecipients = $recipientEntries->all();
            $groupPhones = $recipientEntries->pluck('number')->all();
            $groupSignature = $this->participantSignature($groupPhones);
            $groupLabel = $recipientEntries
                ->pluck('label')
                ->filter()
                ->join(', ', ' & ');

            $options[] = [
                'value' => $groupSignature,
                'label' => $groupLabel !== '' ? $groupLabel : 'Group',
                'recipients' => $groupRecipients,
                'existingThreadId' => $this->resolveExistingThreadIdForParticipants($threads, $groupPhones),
            ];
        }

        return collect($options)
            ->unique('value')
            ->values()
            ->all();
    }

    private function applyRecipientPreset(?string $value): void
    {
        if (! $value) {
            return;
        }

        $option = collect($this->recipientPresetOptions)
            ->firstWhere('value', $value);

        if (! is_array($option)) {
            return;
        }

        $this->recipients = $option['recipients'];
        $this->existingThreadId = $option['existingThreadId'];

        if (! $this->existingThreadId && $this->message === '') {
            $this->message = "Hi,\n" . GroupSmsService::START_CONSENT_TEXT;
        }
    }

    private function buildRecipientEntryFromUser(User $user): ?array
    {
        $rawPhone = $user->getRawOriginal('cell_phone');

        if (! is_string($rawPhone) || $rawPhone === '') {
            $rawPhone = (string) ($user->cell_phone ?? '');
        }

        if (! is_string($rawPhone) || $rawPhone === '') {
            return null;
        }

        $e164 = GroupSmsService::formatE164($rawPhone);
        $label = trim(implode(' ', array_filter([
            (string) ($user->first_name ?? ''),
            (string) ($user->last_name ?? ''),
        ])));

        return [
            'number' => $e164,
            'display' => $this->formatDisplay($rawPhone),
            'label' => $label,
        ];
    }

    /**
     * @param  iterable<SmsGroupThread>  $threads
     * @param  array<int, string>  $phones
     */
    private function resolveExistingThreadIdForParticipants(iterable $threads, array $phones): ?int
    {
        $targetSignature = $this->participantSignature($phones);

        foreach ($threads as $thread) {
            $threadParticipants = $thread->participants;
            if (! is_array($threadParticipants)) {
                continue;
            }

            if ($this->participantSignature($threadParticipants) === $targetSignature) {
                return $thread->id;
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>  $phones
     */
    private function participantSignature(array $phones): string
    {
        return collect($phones)
            ->map(fn (string $phone): string => GroupSmsService::formatE164($phone))
            ->unique()
            ->sort()
            ->implode('|');
    }

    private function setDefaultMessageFromRecipients(Client $client): void
    {
        $recipientNames = collect($this->recipients)
            ->pluck('label')
            ->map(fn ($name) => explode(' ', trim($name))[0])
            ->filter()
            ->unique()
            ->join(', ', ' & ');

        if ($recipientNames === '') {
            $recipientNames = $client->name;
        }

        $this->message = "Hi {$recipientNames},\n" . GroupSmsService::START_CONSENT_TEXT;
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

    #[Computed]
    public function vendors()
    {
        return Vendor::orderBy('business_name')->get();
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
        $this->validate([
            'recipientType' => 'required|in:client,vendor',
            'clientId' => 'exclude_unless:recipientType,client|nullable|exists:clients,id',
            'vendorId' => 'exclude_unless:recipientType,vendor|nullable|exists:vendors,id',
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

        if ($this->recipientType === 'client' && $this->clientId) {
            $existingThread = SmsGroupThread::query()->where('client_id', $this->clientId);

            foreach ($phones as $phone) {
                $existingThread->whereJsonContains('participants', $phone);
            }

            $existingThread = $existingThread
                ->whereJsonLength('participants', count($phones))
                ->first();

            if ($existingThread) {
                $this->existingThreadId = $existingThread->id;
                $this->addError('clientId', 'A thread already exists for this participant group.');

                return;
            }
        }

        if ($this->recipientType === 'vendor' && $this->vendorId) {
            $existingThread = SmsGroupThread::query()->where('subject_vendor_id', $this->vendorId);

            foreach ($phones as $phone) {
                $existingThread->whereJsonContains('participants', $phone);
            }

            $existingThread = $existingThread
                ->whereJsonLength('participants', count($phones))
                ->first();

            if ($existingThread) {
                $this->existingThreadId = $existingThread->id;
                $this->addError('vendorId', 'A thread already exists for this participant group.');

                return;
            }
        }

        $thread = $smsService->sendNewGroup(
            $phones,
            $this->message,
            null,
            $this->recipientType === 'client' ? $this->clientId : null,
            auth()->id(),
            auth()->user()->vendor?->id,
            $this->recipientType === 'vendor' ? $this->vendorId : null,
        );

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
