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
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
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
    /** A booked consult runs half an hour. */
    public const CONSULT_MINUTES = 30;

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

    /** Exact start time (24h "14:30") picked within the selected slot's window. */
    public ?string $selectedExactTime = null;

    /** Name for the project the consult booking creates (when the client has none yet). */
    public string $projectName = '';

    public bool $showLeadDelete = false;

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

        // The modal footer has no Update button — a status change saves right
        // away, same as the status dropdowns on the /leads index rows.
        if ($field === 'lead_status' && $this->lead->exists && $this->lead_status) {
            // A replied lead can't go back to New (the option is disabled in
            // the UI; this guards stale clients).
            if ($this->lead_status === 'New' && $this->hasReplied) {
                $this->lead_status = $this->lead->last_status?->title;

                return;
            }

            $this->lead->setStatus($this->lead_status);
            $this->lead->unsetRelation('last_status');
            unset($this->hasReplied);
            $this->dispatch('refreshComponent')->to('leads.leads-index');
            $this->dispatch('lead-status-updated');

            Flux::toast(
                duration: 4000,
                position: 'top right',
                variant: 'success',
                heading: 'Lead status updated.',
                text: '',
            );
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

    #[On('addLead')]
    public function addLead()
    {
        $this->modal('lead_form_modal')->show();
    }

    #[On('editLead')]
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
        $this->selectedExactTime = null;
        $this->projectName = '';
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

        // Fresh open lands on Details (the Message tab may not exist for
        // Replied leads).
        $this->dispatch('lead-modal-opened');

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
        $this->dispatch('lead-status-updated');

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Lead Updated.',
            // route / href / wire:click
            text: '',
        );
    }

    /**
     * Replied leads are locked in: the Message composer is gone (the reply
     * went out — tracking shows on Details) and the lead can't be deleted.
     */
    #[Computed]
    public function hasReplied(): bool
    {
        return ($this->lead?->last_status?->title ?? null) === 'Replied';
    }

    public function confirmRemove(): void
    {
        if (! $this->lead?->exists || $this->hasReplied) {
            return;
        }

        $this->showLeadDelete = true;
    }

    /**
     * What removing this lead takes with it — see Lead::deleteImpact().
     */
    #[Computed]
    public function deleteImpact(): array
    {
        return $this->lead?->deleteImpact() ?? ['clients' => [], 'user' => null];
    }

    public function remove()
    {
        $this->showLeadDelete = false;

        if ($this->hasReplied) {
            return;
        }

        $this->lead->deleteWithOrphans();

        $this->lead_status = null;
        $this->modal('lead_form_modal')->close();
        $this->dispatch('refreshComponent')->to('leads.leads-index');
        $this->dispatch('lead-status-updated');

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Lead Deleted.',
            // route / href / wire:click
            text: '',
        );
    }

    /**
     * Sending a reply with an availability slot selected books the consult:
     * the client gets a project (reusing any they already have) and a Meet
     * task titled "{short_vendor_name}/{client_last_names} Consult" scheduled
     * at the chosen slot. Idempotent — resending never duplicates either.
     * Project creation also flips the lead to Won (MarkClientLeadWon via the
     * project observer).
     */
    protected function bookConsult(): bool
    {
        $slotIndex = $this->selectedAvailability[0] ?? null;
        $slot = $slotIndex !== null ? ($this->availability[$slotIndex] ?? null) : null;

        if (! $slot || ! ($slot['date'] ?? null) || ! $this->client) {
            return false;
        }

        $project = $this->client->projects()->first();

        if (! $project) {
            $addressParts = $this->lead->shortAddressParts();
            preg_match('/\b([A-Z]{2})\b[,\s]*(\d{5})?/', (string) $this->address, $m);

            $project = \App\Models\Project::create([
                'project_name' => trim($this->projectName) !== '' ? trim($this->projectName) : 'Consult',
                'client_id' => $this->client->id,
                'address' => ($addressParts['street'] ?: $this->address) ?? '',
                'city' => $addressParts['city'] ?: '',
                'state' => $m[1] ?? '',
                'zip_code' => $m[2] ?? '',
            ]);
        }

        $vendor = Vendor::find($this->lead->belongs_to_vendor_id);
        $shortVendorName = data_get($vendor?->options, 'short_name') ?: ($vendor?->name ?? config('app.name'));

        $name = preg_replace('/\s+/', ' ', trim((string) $this->full_name));
        $parts = $name !== '' ? explode(' ', $name) : [];
        $lastName = count($parts) > 1 ? array_pop($parts) : $name;
        $clientLastNames = $this->client->last_names ?: $lastName;

        $title = $shortVendorName . ' | ' . $clientLastNames . ' | Consult';

        $exists = \App\Models\Task::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->where('title', $title)
            ->exists();

        if ($exists) {
            return false;
        }

        $date = $slot['date'];

        // A consult is a 30-minute meeting: it starts at the exact time picked
        // (or at the start of the offered window) and ends half an hour later.
        // Without this the task was zero-length — start and end were identical
        // — so the calendar event had no duration.
        $startTime = $this->selectedExactTime
            ?: (($window = $this->parseSlotTimes((string) ($slot['time'] ?? ''))) ? $window[0] : null);

        $times = $startTime
            ? [$startTime, Carbon::createFromFormat('H:i', $startTime)->addMinutes(self::CONSULT_MINUTES)->format('H:i')]
            : null;

        $task = \App\Models\Task::create([
            'title' => $title,
            'project_id' => $project->id,
            'type' => 'Meet',
            'start_date' => $date,
            'end_date' => $date,
            'order' => 0,
            'user_ids' => [auth()->id()],
            'notes' => 'Booked from lead email reply — ' . $this->formatAvailabilitySlot((array) $slot) . '.',
            'options' => [
                'dates' => [$date],
                'checklist' => [],
                'time_settings' => [
                    $date => $times
                        ? ['use_time' => true, 'start_time' => $times[0], 'end_time' => $times[1]]
                        : ['use_time' => false],
                ],
                'meeting_participants' => [],
                'meeting_location_type' => 'in_person',
            ],
        ]);

        // Earlier reply emails now belong to this project too — stamp them so
        // the project/client tracking cards show the full thread.
        \App\Models\EmailTracking::withoutGlobalScopes()
            ->where('lead_id', $this->lead->id)
            ->whereNull('project_id')
            ->update(['project_id' => $project->id]);

        // Same path TaskCreate uses — the queued job creates the Nylas
        // calendar event for the Meet task.
        \App\Jobs\CreateMeetTaskCalendarEvent::dispatch($task->id, auth()->id());

        return true;
    }

    /**
     * Parse lead-form time ranges like "2-4 PM", "10-11:30 AM", "11 AM-1 PM"
     * into 24h [start, end]. Null when the format isn't recognizable — the
     * Meet task then keeps the date without fixed times.
     *
     * @return array{0: string, 1: string}|null
     */
    /** Delegates to the model so slot parsing has one home. */
    protected function parseSlotTimes(string $time): ?array
    {
        return Lead::parseSlotTimes($time);
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

        if ($reason = $this->sendBlockedReason) {
            Flux::toast(
                duration: 5000,
                position: 'top right',
                variant: 'warning',
                heading: 'Not sent',
                text: $reason . '.',
            );

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

        // Replying moves a New lead to Replied (never downgrades a lead that
        // already progressed — Won/Lost/etc stay put). Runs BEFORE bookConsult
        // so a booked consult's MarkClientLeadWon job (which only converts New
        // leads) can't race this into Won.
        if (($this->lead->last_status?->title ?? 'New') === 'New') {
            $this->lead->setStatus('Replied');
            $this->lead->unsetRelation('last_status');
            $this->lead_status = 'Replied';
            unset($this->hasReplied);
        }

        $booked = $this->bookConsult();

        $this->modal('lead_form_modal')->close();
        $this->dispatch('refreshComponent')->to('leads.leads-index');
        $this->dispatch('lead-status-updated');

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Email Queued',
            text: 'Sending message to ' . count($this->to) . ' recipient(s)'
                . ($booked ? '. Consult booked — project and Meet task created.' : ''),
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

        // A slot that's no longer bookable (date passed, or today's window
        // already over) can't be selected — the email offers the
        // pick-new-times link instead (see {{lead_time_block}}).
        if (! Lead::slotIsBookable((array) $this->availability[$index])) {
            return;
        }

        if (in_array($index, $this->selectedAvailability, true)) {
            $this->selectedAvailability = [];
        } else {
            $this->selectedAvailability = [$index];
        }

        $this->selectedExactTime = null;
        unset($this->exactTimeOptions, $this->awaitingExactTime, $this->needsProjectName, $this->sendBlockedReason);
        $this->rerenderTemplate();
    }

    /**
     * Exact start-time choices within the selected slot's window, on the half
     * hour ("2-4 PM" -> 2:00, 2:30, 3:00, 3:30 PM). Empty when no slot is
     * selected or its time isn't parseable — the email then keeps the range.
     *
     * @return array<int, array{value: string, label: string}>
     */
    #[Computed]
    public function exactTimeOptions(): array
    {
        $slotIndex = $this->selectedAvailability[0] ?? null;
        $slot = $slotIndex !== null ? ($this->availability[$slotIndex] ?? null) : null;

        if (! $slot) {
            return [];
        }

        $times = $this->parseSlotTimes((string) ($slot['time'] ?? ''));

        if (! $times) {
            return [];
        }

        $cursor = Carbon::createFromFormat('H:i', $times[0]);
        $end = Carbon::createFromFormat('H:i', $times[1]);

        $options = [];
        while ($cursor < $end) {
            $options[] = [
                'value' => $cursor->format('H:i'),
                'label' => $cursor->format('g:i A'),
            ];
            $cursor->addMinutes(30);
        }

        return $options;
    }

    public function selectExactTime(string $time): void
    {
        if (! in_array($time, array_column($this->exactTimeOptions, 'value'), true)) {
            return;
        }

        // Clicking the selected time again falls back to the whole range.
        $this->selectedExactTime = $this->selectedExactTime === $time ? null : $time;
        unset($this->awaitingExactTime, $this->needsProjectName, $this->sendBlockedReason);
        $this->rerenderTemplate();
    }

    /**
     * Any preferred slot still bookable (today or later)? When none are, the
     * consult email swaps its confirm-line for the pick-new-times link and
     * sending is allowed without booking.
     */
    #[Computed]
    public function hasUsableAvailability(): bool
    {
        return collect($this->availability)
            ->contains(fn ($slot) => Lead::slotIsBookable((array) $slot));
    }

    /**
     * The email still carries an unresolved placeholder — {{SELECT
     * Availability}} (no slot picked) or {{SELECT Time}} (slot picked, exact
     * time not yet) — so sending is blocked until it's resolved. Templates
     * without the availability placeholder are never blocked.
     */
    #[Computed]
    public function awaitingExactTime(): bool
    {
        return str_contains($this->emailBody, '{{SELECT');
    }

    /**
     * Booking this consult will CREATE a project (client has none yet), so the
     * form needs a project name before sending.
     */
    #[Computed]
    public function needsProjectName(): bool
    {
        return $this->selectedExactTime !== null
            && $this->client !== null
            && ! $this->client->projects()->exists();
    }

    /** Why Send Email is blocked right now, or null when it isn't. */
    #[Computed]
    public function sendBlockedReason(): ?string
    {
        if ($this->awaitingExactTime) {
            return 'Pick the exact consult time first';
        }

        if ($this->needsProjectName && trim($this->projectName) === '') {
            return 'Name the project for this consult first';
        }

        return null;
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
            $slot = (array) $slots->first();

            if ($this->selectedExactTime) {
                $date = $slot['date'] ?? null;
                $datePart = $date ? \Carbon\Carbon::parse($date)->format('D, M j') : '';

                return trim($datePart . ' · ' . Carbon::createFromFormat('H:i', $this->selectedExactTime)->format('g:i A'));
            }

            // A parseable window offers exact-time chips — hold the email back
            // until one is picked. Unparseable windows have no chips, so the
            // range itself is the final value.
            if ($this->exactTimeOptions !== []) {
                return '{{SELECT Time}}';
            }

            return $this->formatAvailabilitySlot($slot);
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

        // The consult email's middle paragraph: confirm a shared time when one
        // is still bookable, otherwise ask for new times via the signed public
        // picker (also the path for leads that never gave any availability).
        $rescheduled = $this->lead->hasRescheduled();

        // Opening line: a first reply greets them; a reply after they sent new
        // times thanks them for rescheduling instead.
        $intro = $rescheduled
            ? 'Thank you for sending over your new availability! We appreciate you taking the time to reschedule.'
            : 'Thank you for reaching out to '.e($vendorName).'! We&rsquo;d love to learn more about your project and set up a consultation.';

        if ($this->hasUsableAvailability) {
            $timeBlock = ($rescheduled ? 'Based on your updated availability' : 'Based on the availability you shared')
                .', we&rsquo;d like to confirm this consultation time:<br><strong>'
                .$availabilityList.'</strong>'
                // Same signed picker as the no-time branch: let them reschedule
                // themselves rather than asking for a reply we'd hand-handle.
                .'</p><p></p><p>If this time no longer works for you, you can '
                .'<a href="'.e($this->lead->availabilityUrl()).'">pick new consultation times</a>'
                .' and we&rsquo;ll confirm the new one ASAP.';
        } else {
            // No proposed time, so the "if that time doesn't work" follow-up
            // would be nonsense — each branch carries its own closing line.
            $timeBlock = 'We&rsquo;d love to find a time that works for you &mdash; please '
                .'<a href="'.e($this->lead->availabilityUrl()).'">select new consultation times</a>'
                .' that suit you and we&rsquo;ll confirm ASAP.';
        }

        return str_replace(
            [
                '{{client_name}}',
                '{{client_first_name}}',
                '{{client_first_names}}',
                '{{client_last_names}}',
                '{{lead_message}}',
                '{{lead_address}}',
                '{{lead_origin}}',
                '{{lead_availability}}',
                '{{lead_intro}}',
                '{{lead_time_block}}',
                '{{vendor_name}}',
                '{{short_vendor_name}}',
                '{{sender_first_name}}',
                '{{sender_last_name}}',
            ],
            [
                $clientName,
                strtok(trim((string) $clientFirstNames), ' ') ?: $clientFirstNames,
                $clientFirstNames,
                $clientLastNames,
                (string) ($this->message ?? ''),
                (string) ($this->address ?? ''),
                (string) ($this->origin ?? ''),
                $availabilityList,
                $intro,
                $timeBlock,
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
