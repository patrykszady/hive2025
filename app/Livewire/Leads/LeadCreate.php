<?php

namespace App\Livewire\Leads;

use App\Jobs\SendLeadReplyJob;
use App\Livewire\Forms\LeadForm;
use App\Mail\LeadMessage;
use App\Models\CompanyEmail;
use App\Models\EmailTemplate;
use App\Models\Lead;
use App\Models\Vendor;
use App\Services\NylasService;
use Carbon\Carbon;
use Flux;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Livewire\Component;

class LeadCreate extends Component
{
    public LeadForm $form;

    public $lead = null;

    public $user = null;

    public $client = null;

    public $reply = null;

    public $full_name = null;

    public $date = null;

    public $lead_status = null;

    public $message = null;
    public $phone = null;
    public $email = null;
    public $address = null;
    public $reply_to_email = null;
    public ?string $origin = null;
    public array $availability = [];

    // Email composer state (Messages tab)
    public array $to = [];
    public string $newRecipientEmail = '';
    public array $availableContacts = [];
    public $availableFromEmails = [];
    public string $from = '';
    public $availableTemplates = [];
    public ?int $selectedTemplateId = null;
    public string $subject = '';
    public string $emailBody = '';
    public ?string $nylasMessageId = null;
    public array $nylasReferences = [];
    public array $selectedAvailability = [];

    protected $listeners = ['editLead', 'addLead'];

    public $view_text = [
        'card_title' => 'Create Expense',
        'button_text' => 'Create',
        'form_submit' => 'save',
    ];

    public function rules()
    {
        return [
            'lead_status' => 'required',
            'message' => 'required',
            'address' => 'nullable',
            'origin' => 'required',
            'phone' => 'nullable',
            'email' => 'nullable',
            'reply' => 'nullable',
            'reply_to_email' => 'nullable',
            'full_name' => 'nullable',
            'date' => 'required',
            'to' => ['array'],
            'to.*' => ['email'],
            'from' => ['nullable', 'email'],
            'subject' => ['nullable', 'string', 'max:255'],
            'emailBody' => ['nullable', 'string'],
        ];
    }

    public function updated($field)
    {
        if (! $this->lead) {
            return;
        }

        if ($field === 'selectedTemplateId' && $this->selectedTemplateId) {
            $template = EmailTemplate::find($this->selectedTemplateId);
            if ($template) {
                $this->subject = $this->replacePlaceholders((string) $template->subject);
                $this->emailBody = $this->replacePlaceholders((string) $template->body);
            }
        }

        if ($field === 'from' && $this->selectedTemplateId) {
            $template = EmailTemplate::find($this->selectedTemplateId);
            if ($template) {
                $this->subject = $this->replacePlaceholders((string) $template->subject);
                $this->emailBody = $this->replacePlaceholders((string) $template->body);
            }
        }
    }

    public function addLead()
    {
        $this->modal('lead_form_modal')->show();
    }

    public function editLead(Lead $lead)
    {
        $this->lead = $lead->fresh(['user.clients.users', 'last_status']);

        $this->message = $this->lead->lead_data->message ?? null;
        $this->address = $this->lead->lead_data->address ?? null;
        $this->reply_to_email = $this->lead->lead_data->reply_to_email ?? null;
        $this->phone = $this->lead->lead_data->phone ?? null;
        $this->email = $this->lead->lead_data->email ?? null;
        $rawAvailability = $this->lead->lead_data['availability'] ?? [];
        $this->availability = is_array($rawAvailability) || $rawAvailability instanceof \Traversable
            ? collect($rawAvailability)->map(fn ($slot) => (array) $slot)->values()->all()
            : [];
        $this->selectedAvailability = [];
        $this->origin = $this->lead->origin;
        $this->date = $this->lead->date->format('Y-m-d');
        $this->user = $this->lead->user;
        $this->client = $this->resolveClientForLead();
        $this->lead_status = $this->lead->last_status ? $this->lead->last_status->title : null;

        if (! is_null($this->user)) {
            // $this->user->full_name = !is_null($this->user) ? $this->user->full_name : 'Create User';
            $this->full_name = $this->user->full_name;
        } else {
            $this->full_name = $this->lead->lead_data['name'];
        }

        $name = preg_replace('/\s+/', ' ', trim($this->full_name));
        $nameParts = explode(' ', $name);
        $lastName = array_pop($nameParts);
        $firstName = implode(' ', $nameParts);

        $this->reply = 'Hi '.$firstName.',';

        $this->prepareEmailComposer();

        $this->view_text = [
            'card_title' => 'Edit Lead',
            'button_text' => 'Update',
            'form_submit' => 'edit',
        ];

        $this->modal('lead_form_modal')->show();
    }

    public function edit()
    {
        $lead = Lead::findOrFail($this->lead->id);
        $lead->lead_data['address'] = $this->address;
        $lead->lead_data['phone'] = $this->phone;
        $lead->lead_data['email'] = $this->email;
        $lead->origin = $this->origin;
        $lead->save();

        $lead->statuses()->create([
            'title' => $this->lead_status,
            'belongs_to_vendor_id' => $lead->belongs_to_vendor_id,
        ]);

        $this->lead_status = null;
        $this->modal('lead_form_modal')->close();
        $this->dispatch('refreshComponent')->to('leads.leads-index');

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Lead Updated.',
            // route / href / wire:click
            text: '',
        );
    }

    public function remove()
    {
        $this->lead->delete();

        $this->lead_status = null;
        $this->modal('lead_form_modal')->close();
        $this->dispatch('refreshComponent')->to('leads.leads-index');

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Lead Deleted.',
            // route / href / wire:click
            text: '',
        );
    }

    public function message_reply()
    {
        $this->send_message();
    }

    public function addRecipient(): void
    {
        $email = trim((string) $this->newRecipientEmail);

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addError('newRecipientEmail', 'Please enter a valid email address.');
            return;
        }

        if (! in_array($email, $this->to, true)) {
            $this->to[] = $email;
        }

        $this->newRecipientEmail = '';
        $this->resetErrorBag('newRecipientEmail');
    }

    public function removeRecipient(string $email): void
    {
        $this->to = array_values(array_filter($this->to, fn ($e) => $e !== $email));
    }

    public function toggleContact(string $email): void
    {
        if (in_array($email, $this->to, true)) {
            $this->removeRecipient($email);
            return;
        }

        $this->to[] = $email;
    }

    public function send_message(): void
    {
        if (! $this->lead) {
            return;
        }

        $this->validate([
            'to' => ['required', 'array', 'min:1'],
            'to.*' => ['required', 'email'],
            'from' => ['required', 'email'],
            'subject' => ['required', 'string', 'max:255'],
            'emailBody' => ['required', 'string'],
        ]);

        $vendorId = $this->lead->belongs_to_vendor_id;
        $trackingProvider = (string) config('email_tracking.provider', 'nylas');

        $companyEmailQuery = CompanyEmail::query()
            ->where('email', $this->from)
            ->where('vendor_id', $vendorId);

        if ($trackingProvider !== 'mailtrap') {
            $companyEmailQuery->whereNotNull('grant_id');
        }

        $companyEmail = $companyEmailQuery->first();

        if (! $companyEmail) {
            Flux::toast(
                duration: 7000,
                position: 'top right',
                variant: 'danger',
                heading: 'Email Not Sent',
                text: $trackingProvider === 'mailtrap'
                    ? 'The selected sender email is not available.'
                    : 'The selected sender email is not connected to Nylas.',
            );
            return;
        }

        $templateName = null;
        if ($this->selectedTemplateId) {
            $template = EmailTemplate::find($this->selectedTemplateId);
            $templateName = $template?->name;
        }

        SendLeadReplyJob::dispatch(
            leadId: $this->lead->id,
            companyEmailId: $companyEmail->id,
            userId: auth()->id(),
            recipients: $this->to,
            fromEmail: $this->from,
            subject: $this->subject,
            body: $this->emailBody,
            emailTemplateName: $templateName,
            senderIp: request()->ip(),
            inReplyToMessageId: $this->nylasMessageId,
            references: $this->nylasReferences,
        );

        $this->modal('lead_form_modal')->close();
        $this->dispatch('refreshComponent')->to('leads.leads-index');

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Email Queued',
            text: 'Sending message to ' . count($this->to) . ' recipient(s)',
        );
    }

    protected function prepareEmailComposer(): void
    {
        $vendorId = $this->lead->belongs_to_vendor_id;

        // From: vendor's connected company emails
        $companyEmails = CompanyEmail::where('vendor_id', $vendorId)
            ->whereNotNull('grant_id')
            ->get();

        $this->availableFromEmails = $companyEmails;

        $currentUserEmail = auth()->user()?->email;
        $current = $companyEmails->firstWhere('email', $currentUserEmail);
        $this->from = $current?->email ?? ($companyEmails->first()?->email ?? '');

        // To: lead user emails + lead_data emails
        $recipients = collect();
        $leadEmail = $this->lead->lead_data['email'] ?? null;
        $replyToEmail = $this->lead->lead_data['reply_to_email'] ?? null;

        if ($this->client) {
            foreach ($this->client->users as $u) {
                if (! empty($u->email)) {
                    $recipients->push($u->email);
                }
            }
        } elseif ($this->user && ! empty($this->user->email)) {
            $recipients->push($this->user->email);
        }

        if ($leadEmail) {
            $recipients->push($leadEmail);
        }
        if ($replyToEmail) {
            $recipients->push($replyToEmail);
        }

        $this->to = $recipients->filter()->unique()->values()->all();

        // Available contacts (display)
        $contacts = collect();
        if ($this->client) {
            foreach ($this->client->users as $u) {
                if (! empty($u->email)) {
                    $contacts->push([
                        'email' => $u->email,
                        'name' => trim(($u->first_name ?? '') . ' ' . ($u->last_name ?? '')) ?: $u->email,
                        'group' => 'Client',
                    ]);
                }
            }
        }
        if ($leadEmail) {
            $contacts->push([
                'email' => $leadEmail,
                'name' => $this->full_name ?: $leadEmail,
                'group' => 'Lead',
            ]);
        }
        if ($replyToEmail && $replyToEmail !== $leadEmail) {
            $contacts->push([
                'email' => $replyToEmail,
                'name' => $replyToEmail,
                'group' => 'Reply-To',
            ]);
        }
        $this->availableContacts = $contacts->unique('email')->values()->all();

        // Templates
        $this->availableTemplates = EmailTemplate::where('type', 'lead')
            ->orderBy('name')
            ->get();

        $defaultTemplate = $this->availableTemplates->first();
        $this->selectedTemplateId = $defaultTemplate?->id;
        $this->subject = $defaultTemplate ? $this->replacePlaceholders((string) $defaultTemplate->subject) : 'Re: ' . ($this->origin ? ucfirst($this->origin) . ' inquiry' : 'your inquiry');
        $this->emailBody = $defaultTemplate ? $this->replacePlaceholders((string) $defaultTemplate->body) : '';

        // Look up matching inbound Nylas message for threading
        $this->lookupNylasInboundMessage($current ?? $companyEmails->first(), $leadEmail ?: $replyToEmail);
    }

    protected function lookupNylasInboundMessage(?CompanyEmail $companyEmail, ?string $leadEmail): void
    {
        $this->nylasMessageId = null;
        $this->nylasReferences = [];

        if (! $companyEmail || ! $companyEmail->grant_id || empty($leadEmail)) {
            return;
        }

        try {
            $nylas = app(NylasService::class);

            $receivedAfter = $this->lead->date
                ? Carbon::parse($this->lead->date)->subDays(2)->timestamp
                : Carbon::now()->subDays(60)->timestamp;

            $response = $nylas->getMessages($companyEmail->grant_id, [
                'any_email' => $leadEmail,
                'limit' => 5,
                'received_after' => $receivedAfter,
            ], false, $companyEmail);

            $messages = data_get($response, 'data', []);

            $match = collect($messages)->first(function ($message) use ($leadEmail) {
                $from = data_get($message, 'from', []);
                foreach ($from as $sender) {
                    if (strcasecmp((string) data_get($sender, 'email', ''), $leadEmail) === 0) {
                        return true;
                    }
                }
                return false;
            }) ?? collect($messages)->first();

            if ($match) {
                $this->nylasMessageId = data_get($match, 'id') ?: null;
                $references = (array) data_get($match, 'references', []);
                $this->nylasReferences = array_values(array_filter(array_map('strval', $references)));
            }
        } catch (\Throwable $exception) {
            Log::warning('Lead Nylas inbound lookup failed', [
                'lead_id' => $this->lead?->id,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function insertAvailabilitySlot(int $index): void
    {
        if (! isset($this->availability[$index])) {
            return;
        }

        if (in_array($index, $this->selectedAvailability, true)) {
            $this->selectedAvailability = [];
        } else {
            $this->selectedAvailability = [$index];
        }

        $this->rerenderTemplate();
    }

    protected function rerenderTemplate(): void
    {
        if (! $this->selectedTemplateId) {
            return;
        }

        $template = EmailTemplate::find($this->selectedTemplateId);

        if (! $template) {
            return;
        }

        $this->subject = $this->replacePlaceholders((string) $template->subject);
        $this->emailBody = $this->replacePlaceholders((string) $template->body);
    }

    protected function formatAvailabilityList(): string
    {
        if (empty($this->availability)) {
            return '';
        }

        if (empty($this->selectedAvailability)) {
            return '{{SELECT Availability}}';
        }

        $slots = collect($this->selectedAvailability)
            ->map(fn ($i) => $this->availability[$i] ?? null)
            ->filter()
            ->values();

        if ($slots->count() === 1) {
            return $this->formatAvailabilitySlot((array) $slots->first());
        }

        return $slots
            ->map(fn ($slot) => '• ' . $this->formatAvailabilitySlot((array) $slot))
            ->implode("\n");
    }

    protected function formatAvailabilitySlot(array $slot): string
    {
        $date = $slot['date'] ?? null;
        $time = $slot['time'] ?? null;

        $datePart = $date ? \Carbon\Carbon::parse($date)->format('D, M j') : '';

        return trim($datePart . ($time ? ' · ' . $time : ''));
    }

    protected function replacePlaceholders(string $text): string
    {
        $name = preg_replace('/\s+/', ' ', trim((string) $this->full_name));
        $parts = $name !== '' ? explode(' ', $name) : [];
        $lastName = count($parts) > 1 ? array_pop($parts) : '';
        $firstNames = $parts ? implode(' ', $parts) : ($lastName !== '' ? '' : $name);

        $clientName = $this->client?->name ?: $name;
        $clientFirstNames = $this->client?->first_names ?: $firstNames;
        $clientLastNames = $this->client?->last_names ?: $lastName;

        $vendor = Vendor::find($this->lead->belongs_to_vendor_id);
        $vendorName = $vendor?->name ?? config('app.name');
        $shortVendorName = data_get($vendor?->options, 'short_name') ?: $vendorName;

        $sender = null;
        if ($this->from) {
            $sender = \App\Models\User::where('email', $this->from)->first();
        }
        $sender = $sender ?: auth()->user();
        $senderFirstName = $sender?->first_name ?? '';
        $senderLastName = $sender?->last_name ?? '';

        $availabilityList = $this->formatAvailabilityList();

        return str_replace(
            [
                '{{client_name}}',
                '{{client_first_names}}',
                '{{client_last_names}}',
                '{{lead_message}}',
                '{{lead_address}}',
                '{{lead_origin}}',
                '{{lead_availability}}',
                '{{vendor_name}}',
                '{{short_vendor_name}}',
                '{{sender_first_name}}',
                '{{sender_last_name}}',
            ],
            [
                $clientName,
                $clientFirstNames,
                $clientLastNames,
                (string) ($this->message ?? ''),
                (string) ($this->address ?? ''),
                (string) ($this->origin ?? ''),
                $availabilityList,
                $vendorName,
                $shortVendorName,
                $senderFirstName,
                $senderLastName,
            ],
            $text
        );
    }

    public function getUserDisplayName(string $email): string
    {
        $contact = collect($this->availableContacts)->firstWhere('email', $email);
        return $contact['name'] ?? $email;
    }

    public function getFromUserDisplayName(string $email): string
    {
        $user = \App\Models\User::where('email', $email)->first();
        if ($user) {
            $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
            if ($name !== '') {
                return $name . ' (' . $email . ')';
            }
        }

        return $email;
    }

    public function render()
    {
        return view('livewire.leads.form');
    }

    protected function resolveClientForLead(): ?\App\Models\Client
    {
        if (! $this->lead?->user) {
            return null;
        }

        $clients = $this->lead->user->clients;

        if ($clients->isEmpty()) {
            return null;
        }

        $address = $this->lead->lead_data->address ?? null;

        if (is_string($address) && $address !== '') {
            $needle = mb_strtolower($address);

            $matched = $clients->first(
                fn ($c) => $c->address && str_contains($needle, mb_strtolower((string) $c->address))
            );

            if ($matched) {
                return $matched;
            }
        }

        return $clients->first();
    }
}
