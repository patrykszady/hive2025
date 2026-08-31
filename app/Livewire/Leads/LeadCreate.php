<?php

namespace App\Livewire\Leads;

use App\Jobs\SendLeadReplyJob;
use App\Livewire\Forms\LeadForm;
use App\Livewire\Leads\PickTimes;
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

    /**
     * The phone-entry box on Details, kept SEPARATE from $phone: the gate is
     * derived from what's persisted, so binding the box to $phone let any
     * keystroke — even garbage, even a half-typed number flushed by an
     * unrelated round-trip — satisfy the gate and open the Message tab.
     */
    public ?string $phoneEntry = null;
    public $email = null;
    public $address = null;
    public $city = null;
    public $state = null;
    public $zip = null;
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

    /** Office-proposed consult date (GS-side scheduling/reschedule). */
    public ?string $proposeDate = null;

    /** Name for the project the consult booking creates (when the client has none yet). */
    public string $projectName = '';

    /**
     * How the consult happens: 'in_person' at the jobsite, or 'virtual' — a
     * Teams call whose join link rides the calendar invite (Nylas
     * autocreates the meeting). Defaults to whatever the homeowner asked
     * for on the pick-times page.
     */
    public string $consultMeetingType = 'in_person';

    public bool $showLeadDelete = false;

    /** A matching existing contact found while creating a lead by hand. */
    public ?array $duplicateMatch = null;

    /** Set by "Create anyway" after the duplicate warning. */
    public bool $createAnyway = false;

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
        // Replied → New is deliberate and allowed: it reopens the composer
        // so a consult email can be re-sent from scratch.
        if ($field === 'lead_status' && $this->lead->exists && $this->lead_status) {
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
        // A previously viewed lead's fields must not bleed into the blank
        // form — this modal is reused for every lead on the page.
        $this->reset([
            'lead', 'full_name', 'email', 'phone', 'phoneEntry', 'address',
            'city', 'state', 'zip',
            'message', 'origin', 'availability', 'selectedAvailability',
            'selectedExactTime', 'proposeDate', 'projectName', 'to', 'subject', 'emailBody',
            'selectedTemplateId', 'nylasMessageId', 'nylasReferences',
            'duplicateMatch', 'createAnyway', 'consultMeetingType',
        ]);
        $this->view_text = [
            'card_title' => 'New Lead',
            'button_text' => 'Create Lead',
            'form_submit' => 'save',
        ];

        $this->modal('lead_form_modal')->show();
    }

    /**
     * Create a lead by hand — the person who CALLED instead of emailing or
     * filling the website form. Once the record exists, everything downstream
     * (the consult email, the pick-times link, booking) works for them
     * exactly as it does for any other lead — no project needed.
     */
    public function save(): void
    {
        // A queued second submit (double-tap) arrives AFTER the first save
        // already created the lead and flipped this modal into edit mode —
        // it must not mint a twin.
        if ($this->lead?->exists) {
            return;
        }

        $this->validate([
            'full_name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:30'],
        ], [], ['full_name' => 'name']);

        if (blank($this->email) && blank($this->phone)) {
            $this->addError('email', 'An email or phone number is needed to reach them.');

            return;
        }

        // The same person may already be here — as a lead (open that one
        // instead of splitting the history) or as a client contact (worth
        // knowing before creating a "lead" for an existing customer). One
        // warning, then "Create anyway" goes through.
        if (! $this->createAnyway && ($match = $this->findExistingContact())) {
            $this->duplicateMatch = $match;

            return;
        }

        $vendorId = auth()->user()->vendor->id;

        $lead = Lead::create([
            'date' => now(),
            'origin' => $this->origin ?: 'Manual',
            'lead_data' => array_filter([
                'name' => trim((string) $this->full_name),
                'email' => trim((string) $this->email) ?: null,
                'phone' => preg_replace('/\D/', '', (string) $this->phone) ?: null,
                'address' => $this->address,
                'message' => $this->message,
            ], fn ($v) => $v !== null && $v !== ''),
            'belongs_to_vendor_id' => $vendorId,
            'created_by_user_id' => auth()->id(),
        ]);

        // Same parity story as email leads: without the status row the lead
        // has no pipeline stage; without provisioning it has no contact.
        $lead->statuses()->create([
            'title' => 'New',
            'belongs_to_vendor_id' => $vendorId,
        ]);

        try {
            app(\App\Services\LeadContactProvisioner::class)->provision($lead->fresh());
        } catch (\Throwable $e) {
            Log::warning('Manual lead: contact provisioning failed', [
                'lead_id' => $lead->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->dispatch('refreshComponent')->to('leads.leads-index');
        $this->dispatch('lead-status-updated');

        // Straight into the working view — the composer with the schedule
        // link is usually the very next thing needed.
        $this->editLead($lead->fresh());

        Flux::toast(
            duration: 4000,
            position: 'top right',
            variant: 'success',
            heading: 'Lead created',
            text: 'Ready to send them a consultation email.',
        );
    }

    #[On('editLead')]
    public function editLead(Lead $lead)
    {
        $this->lead = $lead->fresh(['user.clients.users', 'last_status']);

        $this->message = $this->lead->lead_data->message ?? null;
        $this->address = $this->lead->lead_data->address ?? null;
        $this->city = $this->lead->lead_data->city ?? null;
        $this->state = $this->lead->lead_data->state ?? null;
        $this->zip = $this->lead->lead_data->zip ?? null;
        $this->reply_to_email = $this->lead->lead_data->reply_to_email ?? null;
        $this->phone = $this->lead->lead_data->phone ?? null;
        $this->phoneEntry = null;
        $this->email = $this->lead->lead_data->email ?? null;
        $this->consultMeetingType = in_array($this->lead->lead_data['meeting_preference'] ?? null, ['in_person', 'virtual'], true)
            ? $this->lead->lead_data['meeting_preference']
            : 'in_person';
        $rawAvailability = $this->lead->lead_data['availability'] ?? [];
        $this->availability = is_array($rawAvailability) || $rawAvailability instanceof \Traversable
            ? collect($rawAvailability)->map(fn ($slot) => (array) $slot)->values()->all()
            : [];
        $this->selectedAvailability = [];
        $this->selectedExactTime = null;
        $this->proposeDate = null;
        $this->projectName = '';
        $this->origin = $this->lead->origin;
        $this->date = $this->lead->date->format('Y-m-d');
        $this->completeAddress();
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
        $lead->lead_data['city'] = $this->city;
        $lead->lead_data['state'] = $this->state;
        $lead->lead_data['zip'] = $this->zip;
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
     *
     * Unless it BOUNCED. A bounce means nobody read it, so the lead is only
     * "replied" on paper — locking the composer would leave the one person who
     * could fix the address with no way to send again.
     */
    #[Computed]
    public function hasReplied(): bool
    {
        if (($this->lead?->last_status?->title ?? null) !== 'Replied') {
            return false;
        }

        return ! $this->lastEmailBounced;
    }

    /**
     * Did the most recent send attempt bounce? Judged on the latest delivery
     * outcome only — a later successful send supersedes an older bounce.
     */
    #[Computed]
    public function lastEmailBounced(): bool
    {
        if (! $this->lead?->exists) {
            return false;
        }

        $outcome = \App\Models\EmailTracking::withoutGlobalScopes()
            ->where('lead_id', $this->lead->id)
            ->whereIn('event_type', ['bounced', 'delivered', 'opened', 'clicked'])
            ->orderByDesc('event_at')
            ->orderByDesc('id')
            ->value('event_type');

        return $outcome === 'bounced';
    }

    /**
     * Save a hand-entered phone onto the lead AND its contact, so the number
     * we just learned is on the record people actually call from, not only in
     * the lead payload.
     */
    public function saveContactPhone(): void
    {
        $digits = preg_replace('/\D/', '', (string) $this->phoneEntry) ?? '';

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) !== 10) {
            $this->addError('phoneEntry', 'Enter a 10-digit phone number.');

            return;
        }

        $taken = \App\Models\User::query()
            ->where('cell_phone', $digits)
            ->when($this->lead?->user_id, fn ($q) => $q->whereKeyNot($this->lead->user_id))
            ->exists();

        if ($taken) {
            $this->addError('phoneEntry', 'Another contact already uses this number.');

            return;
        }

        $this->resetErrorBag('phoneEntry');

        if ($this->lead?->exists) {
            $data = $this->lead->lead_data;
            $data['phone'] = $digits;
            $this->lead->lead_data = $data;
            $this->lead->saveQuietly();

            // The number is often the missing piece that lets provisioning
            // run at all: a name plus a way to reach them is the bar, so an
            // enquiry with neither phone nor usable email had no contact and
            // no client until now. Runs whether or not a user exists — an
            // existing contact may still be missing its client/address.
            try {
                app(\App\Services\LeadContactProvisioner::class)->provision($this->lead->fresh());
            } catch (\Throwable $e) {
                Log::warning('Lead contact provisioning after manual phone entry failed', [
                    'lead_id' => $this->lead->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Persist the number BEFORE reloading anything for display. The client
        // card renders from $client->users, so refreshing first left it
        // holding the pre-save copy and the new number only appeared once the
        // modal was reopened.
        $contact = $this->lead?->fresh()?->user ?? $this->user;

        if ($contact && ! $contact->hasRoutablePhone()) {
            $contact->forceFill(['cell_phone' => $digits])->save();
        }

        if ($this->lead?->exists) {
            $this->lead = $this->lead->fresh(['user.clients.users', 'last_status']);
            $this->user = $this->lead->user;
            $this->client = $this->resolveClientForLead();
        } else {
            $this->user = $contact?->fresh();
        }

        $this->phone = $digits;
        $this->phoneEntry = null;
        unset($this->needsPhone, $this->missingContactInfo, $this->blockingContactInfo, $this->addressCandidates);
        $this->prepareEmailComposer();

        Flux::toast(
            duration: 3000,
            position: 'top right',
            variant: 'success',
            heading: 'Phone number saved.',
            text: '',
        );
    }

    /**
     * An enquiry can arrive with no phone number (emailed leads often do). We
     * don't invent one, so the contact is incomplete until someone fills it in
     * — and the Message tab stays shut until they have, rather than replying
     * to a contact we can't call.
     */
    /**
     * Real addresses matching this lead's street near the office, nearest
     * first. Shown when the address is short of a city/ZIP and more than one
     * place matches — the lead's own street is the same in two towns often
     * enough that guessing is how the wrong one gets saved.
     *
     * @return array<int, array{address: string, city: string, state: string, zip_code: string, miles: float}>
     */
    #[Computed]
    public function addressCandidates(): array
    {
        if (! $this->lead?->exists || ! in_array('a full address', $this->missingContactInfo, true)) {
            return [];
        }

        $data = $this->lead->lead_data;

        $stored = $data['address_candidates'] ?? null;

        if (is_iterable($stored)) {
            $stored = collect($stored)->map(fn ($c) => (array) $c)->all();

            if ($stored !== []) {
                return $stored;
            }
        }

        // Stored candidates ONLY. This is a computed property read during
        // render; geocoding here put a third-party API call in the path of
        // every lead click. CompleteLeadAddress writes them instead.
        return [];
    }

    /**
     * Fill in an incomplete address when it can be done unambiguously, so the
     * lead opens already resolved rather than asking about the only option.
     * Leads imported before address completion existed arrive here.
     */
    protected function completeAddress(): void
    {
        if (! $this->lead?->exists) {
            return;
        }

        $data = (array) $this->lead->lead_data;

        if ($this->addressIsComplete([
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'zip_code' => $data['zip'] ?? null,
        ])) {
            return;
        }

        // Queued, not inline: completing an address means calling Geoapify,
        // and nobody should wait on a third party to see a lead. The modal
        // shows what's on file now; the next open shows the completion.
        \App\Jobs\CompleteLeadAddress::dispatch($this->lead->id);
    }

    /**
     * Commit one of those candidates as the lead's address, then let
     * provisioning build the client it was waiting on.
     */
    public function selectAddressCandidate(int $index): void
    {
        $candidate = $this->addressCandidates[$index] ?? null;

        if (! $candidate || ! $this->lead?->exists) {
            return;
        }

        $data = $this->lead->lead_data;
        $data['address'] = $candidate['address'];
        $data['city'] = $candidate['city'];
        $data['state'] = $candidate['state'];
        $data['zip'] = $candidate['zip_code'];
        unset($data['address_candidates']);
        $this->lead->lead_data = $data;
        $this->lead->saveQuietly();

        try {
            app(\App\Services\LeadContactProvisioner::class)->provision($this->lead->fresh());
        } catch (\Throwable $e) {
            Log::warning('Lead contact provisioning after address selection failed', [
                'lead_id' => $this->lead->id,
                'error' => $e->getMessage(),
            ]);
        }

        $this->lead = $this->lead->fresh(['user.clients.users', 'last_status']);
        $this->user = $this->lead->user;
        $this->client = $this->resolveClientForLead();
        $this->address = $candidate['address'];

        unset($this->needsPhone, $this->missingContactInfo, $this->blockingContactInfo, $this->addressCandidates);
        $this->prepareEmailComposer();

        Flux::toast(
            duration: 3000,
            position: 'top right',
            variant: 'success',
            heading: 'Address saved.',
            text: '',
        );
    }

    /**
     * What's still missing before this lead can be replied to: a contact we
     * can reach and an address we can put on a client record. Replying to an
     * enquiry we can't call, email or locate just moves the problem later.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function missingContactInfo(): array
    {
        if (! $this->lead?->exists) {
            return [];
        }

        $data = $this->lead->lead_data;
        $contact = $this->lead->user;
        $client = $this->client;

        $missing = [];

        $email = trim((string) ($data['email'] ?? ''));
        if ($email === '' && ! ($contact?->hasRoutableEmail() ?? false)) {
            $missing[] = 'an email address';
        }

        if ($this->needsPhone) {
            $missing[] = 'a phone number';
        }

        // Judge the address on the LEAD, not on the client: a lead with a
        // perfectly good address still has no client while some OTHER field is
        // missing (no phone → no contact → no client), and reporting the
        // address as missing then sent people hunting for a problem that
        // wasn't there. The client is the fallback for older leads whose
        // address only ever lived on the client record.
        $addressComplete = $this->addressIsComplete([
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'zip_code' => $data['zip'] ?? null,
        ]) || ($client && $this->addressIsComplete([
            'address' => $client->address,
            'city' => $client->city,
            'state' => $client->state,
            'zip_code' => $client->zip_code,
        ]));

        if (! $addressComplete) {
            $missing[] = 'a full address';
        }

        return $missing;
    }

    /**
     * The missing pieces that BLOCK the composer — all of them, phone
     * included. Creating the client (and the consult's project) requires a
     * whole contact, so replying before the phone exists just books work the
     * pipeline can't finish.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function blockingContactInfo(): array
    {
        return $this->missingContactInfo;
    }

    /** @param  array<string, mixed>  $parts */
    protected function addressIsComplete(array $parts): bool
    {
        return trim((string) ($parts['address'] ?? '')) !== ''
            && trim((string) ($parts['city'] ?? '')) !== ''
            && trim((string) ($parts['state'] ?? '')) !== ''
            && trim((string) ($parts['zip_code'] ?? '')) !== '';
    }

    #[Computed]
    public function needsPhone(): bool
    {
        if (! $this->lead?->exists) {
            return false;
        }

        // Persisted state ONLY. Reading the bound input here is what let a
        // half-typed or invalid number open the Message tab without anything
        // being saved.
        if (trim((string) ($this->lead->lead_data['phone'] ?? '')) !== '') {
            return false;
        }

        return ! ($this->lead->user?->hasRoutablePhone() ?? false);
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
                // Nothing stated → the client's state → the IL service area.
                'state' => ($m[1] ?? '') ?: (trim((string) $this->client->state) ?: 'IL'),
                'zip_code' => ($m[2] ?? '') ?: (string) ($this->client->zip_code ?? ''),
            ]);
        }

        $title = $this->consultTaskTitle();

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

        $timeSettings = $times
            ? ['use_time' => true, 'start_time' => $times[0], 'end_time' => $times[1]]
            : ['use_time' => false];

        $notes = 'Booked from lead email reply — ' . $this->bookedSlotLabel((array) $slot) . '.';

        // This client's consult, if we already booked one — INCLUDING a
        // soft-deleted one: deleting the consult is how "that date didn't
        // work" is expressed, so booking a new date revives the same task
        // rather than leaving it dead (which silently blocked the booking) or
        // spawning a duplicate. Matching on project+title (not on the date)
        // is what makes a resend idempotent.
        $task = \App\Models\Task::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->where('title', $title)
            ->first();

        if ($task) {
            $wasTrashed = $task->trashed();

            // Resending the same slot changes nothing — don't touch the task or
            // re-notify the client's calendar. A trashed consult is never
            // "unchanged": the point of rebooking is to bring it back.
            $unchanged = ! $wasTrashed
                && optional($task->start_date)->format('Y-m-d') === $date
                && (array) data_get($task->options, 'time_settings.' . $date) == $timeSettings;

            if ($unchanged) {
                return false;
            }

            if ($wasTrashed) {
                $task->restore();
            }

            $options = (array) ($task->options ?? []);
            $options['dates'] = [$date];
            // Drop the previous date's entry, or the task keeps a stale time.
            $options['time_settings'] = [$date => $timeSettings];
            // Rebooking is also when "let's do a video call instead" happens.
            $options['meeting_location_type'] = $this->consultMeetingType;

            // Backfill an empty list only — consults booked before participants
            // were defaulted here have nobody on them, and rebooking is the
            // moment to fix that. A list someone has edited is left alone:
            // removing an attendee is a decision, not a gap.
            if (empty($options['meeting_participants'])) {
                $options['meeting_participants'] = \App\Services\MeetingParticipants::defaults(
                    $project,
                    [auth()->id()],
                );
            }

            $task->update([
                'start_date' => $date,
                'end_date' => $date,
                'notes' => $notes,
                'options' => $options,
            ]);

            // Move the existing calendar event; a task that never got one (the
            // grant was missing at booking) still needs one created.
            data_get($task->options, 'nylas_meet_event.event_id')
                ? \App\Jobs\UpdateMeetTaskCalendarEvent::dispatch($task->id)
                : \App\Jobs\CreateMeetTaskCalendarEvent::dispatch($task->id, auth()->id());

            return true;
        }

        $task = \App\Models\Task::create([
            'title' => $title,
            'project_id' => $project->id,
            'type' => 'Meet',
            'start_date' => $date,
            'end_date' => $date,
            'order' => 0,
            'user_ids' => [auth()->id()],
            'notes' => $notes,
            'options' => [
                'dates' => [$date],
                'checklist' => [],
                'time_settings' => [$date => $timeSettings],
                // The homeowner is the point of a consult — invite them, and
                // whoever is going, exactly as the task form would. This used
                // to be an empty list, so the calendar invite went only to the
                // company mailboxes the calendar service adds on its own and
                // the client never saw their own appointment.
                'meeting_participants' => \App\Services\MeetingParticipants::defaults(
                    $project,
                    [auth()->id()],
                ),
                'meeting_location_type' => $this->consultMeetingType,
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

    /**
     * Someone reachable at this phone/email who already exists — an open
     * lead first (its history should continue, not fork), else a client
     * contact.
     *
     * @return array{kind:string, label:string, lead_id:?int, client_id:?int}|null
     */
    protected function findExistingContact(): ?array
    {
        $email = strtolower(trim((string) $this->email));
        $digits = preg_replace('/\D/', '', (string) $this->phone) ?: null;
        if ($digits && strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        $vendorId = auth()->user()->vendor->id;

        $lead = Lead::withoutGlobalScopes()
            ->where('belongs_to_vendor_id', $vendorId)
            ->where(function ($q) use ($email, $digits) {
                $q->when($email !== '', fn ($inner) => $inner->where('lead_data->email', $email));
                $q->when($digits, fn ($inner) => $inner->orWhere('lead_data->phone', $digits));
            })
            ->latest('id')
            ->first();

        if ($lead) {
            return [
                'kind' => 'lead',
                'label' => trim((string) ($lead->lead_data['name'] ?? 'a lead')).' — '.($lead->last_status?->title ?? 'lead').' since '.$lead->date?->format('M j'),
                'lead_id' => $lead->id,
                'client_id' => null,
            ];
        }

        $user = \App\Models\User::query()
            ->where(function ($q) use ($email, $digits) {
                $q->when($email !== '', fn ($inner) => $inner->whereRaw('LOWER(email) = ?', [$email]));
                $q->when($digits, fn ($inner) => $inner->orWhere('cell_phone', $digits));
            })
            ->first();

        if ($user) {
            $client = $user->clients()->withoutGlobalScopes()->first();

            return [
                'kind' => $client ? 'client' : 'user',
                'label' => trim($user->full_name).($client ? ' — existing client '.$client->name : ' — existing contact'),
                'lead_id' => null,
                'client_id' => $client?->id,
            ];
        }

        return null;
    }

    /** "Create anyway" from the duplicate warning. */
    public function saveDespiteDuplicate(): void
    {
        $this->createAnyway = true;
        $this->duplicateMatch = null;
        $this->save();
        // One override, one lead: left true, a queued second tap would have
        // sailed past the duplicate check and made the very twin the warning
        // exists to prevent.
        $this->createAnyway = false;
    }

    /**
     * The lead's own scheduling page as a short link — the signed pick-times
     * URL is a working booking flow for anyone with a lead record, project or
     * not. Shortened because it goes into emails and texts, and because the
     * signed URL is unwieldy.
     */
    public function scheduleLink(): string
    {
        return app(\App\Services\UrlShortener::class)->shorten($this->lead->availabilityUrl());
    }

    /**
     * Hand the schedule link to the clipboard — for pasting into a text
     * message or any channel the composer doesn't cover.
     */
    public function copyScheduleLink(): void
    {
        if (! $this->lead) {
            return;
        }

        $this->dispatch('lead-schedule-link-copied', url: $this->scheduleLink());
    }

    /**
     * Text the booking link to the lead's phone through the normal SMS
     * pipeline. An opted-in thread gets the link immediately; a fresh number
     * first gets the consent prompt (compliance owns that flow), and the
     * toast says so instead of pretending the link went out.
     */
    public function textScheduleLink(\App\Services\GroupSmsService $sms): void
    {
        if (! $this->lead) {
            return;
        }

        $digits = preg_replace('/\D/', '', (string) ($this->lead->lead_data['phone'] ?? ''));
        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $digits = substr($digits, 1);
        }

        if (strlen($digits) !== 10) {
            Flux::toast(duration: 5000, position: 'top right', variant: 'warning',
                heading: 'No phone number', text: 'Add a phone number to this lead first.');

            return;
        }

        $e164 = \App\Services\GroupSmsService::formatE164($digits);

        $vendor = Vendor::find($this->lead->belongs_to_vendor_id);
        $contractor = trim((string) (data_get($vendor?->options, 'short_name') ?: $vendor?->name)) ?: config('app.name');
        $firstName = strtok(trim((string) ($this->lead->lead_data['name'] ?? '')), ' ');
        // Same shape as the Send Schedule modal's invite: greeting line, then
        // the ask — one voice wherever the link goes out.
        $text = 'Hi'.($firstName ? " {$firstName}" : '').","
            ."\n\nPick a consultation time with {$contractor} here: ".$this->scheduleLink();

        $thread = \App\Models\SmsGroupThread::query()
            ->whereJsonContains('participants', $e164)
            ->whereJsonLength('participants', 1)
            ->latest('last_activity_at')
            ->first();

        if ($thread && $thread->hasPendingOptIn()) {
            Flux::toast(duration: 6000, position: 'top right', variant: 'warning',
                heading: 'Awaiting START reply',
                text: 'This number has not replied START yet — the link can go out once they do.');

            return;
        }

        if ($thread) {
            $sms->sendToThread($thread, $text, [], auth()->id());

            Flux::toast(duration: 4000, position: 'top right', variant: 'success',
                heading: 'Texted', text: 'Schedule link sent to '.phone_display($digits).'.');

            return;
        }

        $clientId = $this->lead->user?->clients()->withoutGlobalScopes()->first()?->id;
        $sms->sendNewGroup([$e164], $text, null, $clientId, auth()->id(), auth()->user()->vendor?->id);

        Flux::toast(duration: 7000, position: 'top right', variant: 'success',
            heading: 'Consent request sent',
            text: 'New number — they get the START prompt first. Text the link from Messages once they opt in.');
    }

    /**
     * One click from the modal: apply the Consult template and send it.
     * Without selected availability the email asks the homeowner to pick
     * consultation times on the signed picker — whose slots are already
     * gated by Greg's and Patryk's calendars — i.e. the "can we meet for a
     * consult?" outreach in a single press. With a usable shared time
     * selected it confirms that time instead, same as the manual flow.
     */
    public function sendConsultInvite(): void
    {
        // Same scoping as the modal's template picker (vendor global scope
        // plus the lead type) — just preselected by name.
        $template = EmailTemplate::query()
            ->where('type', 'lead')
            ->where('name', 'Consult')
            ->first();

        if (! $template) {
            Flux::toast(
                duration: 7000,
                position: 'top right',
                variant: 'danger',
                heading: 'Not sent',
                text: 'No email template named "Consult" exists.',
            );

            return;
        }

        $this->selectedTemplateId = $template->id;
        $this->subject = $this->replacePlaceholders((string) $template->subject);
        $this->emailBody = $this->replacePlaceholders((string) $template->body);

        $this->send_message();
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
        // leads) can't race this into Won — booking sets Won explicitly below.
        if (($this->lead->last_status?->title ?? 'New') === 'New') {
            $this->lead->setStatus('Replied');
            $this->lead->unsetRelation('last_status');
            $this->lead_status = 'Replied';
            unset($this->hasReplied);
        }

        $booked = $this->bookConsult();

        // A booked consult is a converted lead: the client now has a project
        // and a Meet task on the calendar. Move it out of New/Replied so the
        // Replied filter keeps showing only leads still waiting on us. Lost /
        // Not a Fit are someone's decision — never resurrect those here.
        if ($booked && in_array($this->lead->last_status?->title ?? 'New', ['New', 'Replied'], true)) {
            $this->lead->setStatus('Won');
            $this->lead->unsetRelation('last_status');
            $this->lead_status = 'Won';
        }

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
                // Placeholder addresses exist so a phone-only household member
                // can have a user row — never offer them as a recipient.
                if ($u->hasRoutableEmail()) {
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

        $slotTime = trim((string) ($slot['time'] ?? ''));
        $times = $this->parseSlotTimes($slotTime);

        if (! $times && strcasecmp($slotTime, 'Anytime') === 0) {
            // "Anytime" is the whole bookable day, not an unparseable window:
            // offer every start in it so the confirmation names a real time
            // instead of asking the client to read "Anytime" back to us.
            $times = PickTimes::dayBounds();
        }

        if (! $times) {
            return [];
        }

        $cursor = Carbon::createFromFormat('H:i', $times[0]);
        $end = Carbon::createFromFormat('H:i', $times[1]);

        // Starts that collide with what's already on Patryk's or Greg's
        // calendar are withheld — offering them books a clash the office then
        // has to untangle. selectExactTime() validates against this list, so
        // the filter holds server-side too.
        $busyIntervals = app(\App\Services\AdminCalendarBusy::class)
            ->busyIntervalsFor((string) ($slot['date'] ?? ''));

        // Same-day scheduling (an office-proposed date can be today) must not
        // offer starts that have already passed.
        $tz = PickTimes::timezone();
        $minStart = ((string) ($slot['date'] ?? '')) === Carbon::now($tz)->format('Y-m-d')
            ? Carbon::now($tz)->format('H:i')
            : null;

        $options = [];
        while ($cursor < $end) {
            $chipStart = $cursor->format('H:i');
            $chipEnd = $cursor->copy()->addMinutes(self::CONSULT_MINUTES)->format('H:i');

            $clashes = collect($busyIntervals)->contains(
                fn (array $busy) => $chipStart < $busy[1] && $busy[0] < $chipEnd,
            );

            if ($minStart !== null && $chipStart <= $minStart) {
                $clashes = true;
            }

            if (! $clashes) {
                $options[] = [
                    'value' => $chipStart,
                    'label' => $cursor->format('g:i A'),
                ];
            }

            $cursor->addMinutes(30);
        }

        return $options;
    }

    /**
     * The Meet-task title bookConsult() books and rebooks under — the match
     * key that makes rebooking idempotent, and therefore the ONLY task the
     * composer may present as "this lead's consult".
     */
    protected function consultTaskTitle(): ?string
    {
        if (! $this->client || ! $this->lead) {
            return null;
        }

        $vendor = Vendor::find($this->lead->belongs_to_vendor_id);
        $shortVendorName = data_get($vendor?->options, 'short_name') ?: ($vendor?->name ?? config('app.name'));

        $name = preg_replace('/\s+/', ' ', trim((string) $this->full_name));
        $parts = $name !== '' ? explode(' ', $name) : [];
        $lastName = count($parts) > 1 ? array_pop($parts) : $name;
        $clientLastNames = $this->client->last_names ?: $lastName;

        return $shortVendorName . ' | ' . $clientLastNames . ' | Consult';
    }

    /**
     * The consult already on the calendar for this lead, shaped for display:
     * ['label' => 'Thu, Aug 27 · 11:00 AM', 'virtual' => bool, 'task_id' => int].
     *
     * Scoped to the exact task bookConsult() would move — this lead's
     * resolved client, their first project, the consult title — because the
     * callout promises "send and the invite moves": showing ANY Meet task
     * the contact has on some other client/project would promise a move
     * that sending cannot deliver.
     */
    #[Computed]
    public function bookedConsult(): ?array
    {
        $project = $this->client?->projects()->first();
        $title = $this->consultTaskTitle();

        if (! $project || $title === null) {
            return null;
        }

        $task = \App\Models\Task::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('project_id', $project->id)
            ->where('type', 'Meet')
            ->where('title', $title)
            ->first();

        if (! $task || ! $task->start_date) {
            return null;
        }

        $date = Carbon::parse($task->start_date)->format('Y-m-d');
        $settings = (array) data_get($task->options, 'time_settings.' . $date, []);

        $label = Carbon::parse($task->start_date)->format('D, M j');
        if (($settings['use_time'] ?? false) && ! empty($settings['start_time'])) {
            $label .= ' · ' . Carbon::createFromFormat('H:i', $settings['start_time'])->format('g:i A');
        }

        $tz = PickTimes::timezone();

        return [
            'label' => $label,
            'virtual' => data_get($task->options, 'meeting_location_type') === 'virtual',
            'task_id' => $task->id,
            // A consult whose day has passed isn't "booked" to a reader — it
            // either happened or was missed; both mean the email must talk
            // about a NEW time, not confirm the old one.
            'past' => Carbon::parse($task->start_date, $tz)->startOfDay()->lt(Carbon::now($tz)->startOfDay()),
        ];
    }

    /**
     * Office-side (re)scheduling: GS picks any weekday, not just the slots
     * the homeowner shared. The date becomes a synthetic "Anytime" slot, so
     * every existing rail — busy-gated exact-time chips, the awaiting-exact-
     * time send guard, bookConsult's idempotent rebooking, the calendar-event
     * move — carries it with no special cases. Only the email wording knows
     * the difference (see replacePlaceholders: "offer" vs "confirm").
     */
    public function updatedProposeDate(): void
    {
        $hadProposal = collect($this->availability)
            ->contains(fn ($slot) => ! empty(((array) $slot)['office_proposed']));

        $date = trim((string) $this->proposeDate);
        $isCandidate = $date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date);

        // Clearing an already-empty field must not disturb a homeowner-slot
        // selection someone made below.
        if (! $hadProposal && ! $isCandidate) {
            return;
        }

        // Drop any previous proposal along with its selection.
        $this->availability = collect($this->availability)
            ->reject(fn ($slot) => ! empty(((array) $slot)['office_proposed']))
            ->values()
            ->all();
        $this->selectedAvailability = [];
        $this->selectedExactTime = null;
        unset($this->exactTimeOptions, $this->awaitingExactTime, $this->needsProjectName, $this->sendBlockedReason, $this->hasUsableAvailability);

        if (! $isCandidate) {
            $this->rerenderTemplate();

            return;
        }

        $tz = PickTimes::timezone();
        if (Carbon::parse($date, $tz)->lt(Carbon::now($tz)->startOfDay()) || PickTimes::isWeekend($date)) {
            $this->addError('proposeDate', PickTimes::isWeekend($date)
                ? 'Consultations are scheduled Monday through Friday.'
                : 'That date has passed.');
            $this->proposeDate = null;
            $this->rerenderTemplate();

            return;
        }

        $this->resetErrorBag('proposeDate');
        $this->availability[] = ['date' => $date, 'time' => 'Anytime', 'office_proposed' => true];
        $this->selectedAvailability = [array_key_last($this->availability)];

        // A day with zero open starts (fully booked, or today after the last
        // window) must not stand as a selected "Anytime" slot: with no chips
        // to pick, the {{SELECT Time}} guard never arms and sending would
        // book a consult with no time at all.
        if ($this->exactTimeOptions === []) {
            array_pop($this->availability);
            $this->selectedAvailability = [];
            unset($this->exactTimeOptions, $this->awaitingExactTime, $this->needsProjectName, $this->sendBlockedReason, $this->hasUsableAvailability);
            $this->addError('proposeDate', 'No open times that day — pick another day.');
            $this->proposeDate = null;
            $this->rerenderTemplate();

            return;
        }

        $this->rerenderTemplate();
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
                return $this->bookedSlotLabel($slot);
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

    /**
     * How a booked slot reads once a time is settled: the exact time when one
     * was picked, otherwise the slot as the client offered it. The email line
     * and the Meet task's note both use this, so a task can't say "Anytime"
     * while the client was told 10:30 AM.
     */
    protected function bookedSlotLabel(array $slot): string
    {
        if (! $this->selectedExactTime) {
            return $this->formatAvailabilitySlot($slot);
        }

        $date = $slot['date'] ?? null;
        $datePart = $date ? \Carbon\Carbon::parse($date)->format('D, M j') : '';

        return trim($datePart . ' · ' . Carbon::createFromFormat('H:i', $this->selectedExactTime)->format('g:i A'));
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

        // An office-proposed slot was never "availability you shared" — the
        // homeowner sees an offer, not a confirmation of their own words.
        // Resolved before the intro so neither line contradicts the other.
        $selectedSlotIndex = $this->selectedAvailability[0] ?? null;
        $officeProposed = $selectedSlotIndex !== null
            && ! empty(((array) ($this->availability[$selectedSlotIndex] ?? []))['office_proposed']);

        // A consult already on the calendar changes the whole register of the
        // email: greeting a Won client weeks in with "thank you for reaching
        // out!" reads like we forgot who they are.
        $booked = $this->bookedConsult;

        // Opening line: a first reply greets them; a reply after they sent new
        // times thanks them for rescheduling — unless the office is proposing
        // its own time, where thanking them for availability we are about to
        // override would read as a contradiction.
        $intro = match (true) {
            $officeProposed && $rescheduled => 'Thank you for your patience while we line up your consultation.',
            $rescheduled => 'Thank you for sending over your new availability! We appreciate you taking the time to reschedule.',
            $booked !== null && ($booked['past'] ?? false) => 'We&rsquo;d love to get your consultation with '.e($vendorName).' back on the calendar.',
            $booked !== null => 'A quick note about your upcoming consultation with '.e($vendorName).'.',
            default => 'Thank you for reaching out to '.e($vendorName).'! We&rsquo;d love to learn more about your project and set up a consultation.',
        };

        // Say what KIND of meeting this books — a homeowner expecting a
        // doorbell should not get a Teams link, and vice versa.
        $formatLine = $this->consultMeetingType === 'virtual'
            ? ' This will be a video consultation &mdash; the calendar invite includes the Microsoft Teams link to join.'
            : '';

        if ($this->hasUsableAvailability) {
            // A time being confirmed/offered while another sits on the
            // calendar is a MOVE — say so, or the homeowner has two
            // "confirmed" times and no idea which one is real.
            $replacesLine = $booked !== null && ! ($booked['past'] ?? false)
                ? '</p><p></p><p>This replaces the consultation previously scheduled for '
                    .'<strong>'.e($booked['label']).'</strong>.'
                : '';

            $timeBlock = ($officeProposed
                    ? 'We&rsquo;d like to offer this consultation time'
                    : ($rescheduled ? 'Based on your updated availability' : 'Based on the availability you shared')
                        .', we&rsquo;d like to confirm this consultation time')
                .':<br><strong>'
                .$availabilityList.'</strong>'
                .$formatLine
                .$replacesLine
                // Same signed picker as the no-time branch: let them reschedule
                // themselves rather than asking for a reply we'd hand-handle.
                .'</p><p></p><p>If this time no longer works for you, you can '
                .'<a href="'.e($this->lead->availabilityUrl()).'">pick new consultation times</a>'
                .' and we&rsquo;ll confirm the new one ASAP.';
        } elseif ($booked !== null && ! ($booked['past'] ?? false)) {
            // Nothing newly selected, but a consult IS on the books — this
            // email is a nudge about it, not a first contact.
            $timeBlock = 'Your consultation is booked for <strong>'.e($booked['label']).'</strong>.'
                .$formatLine
                .'</p><p></p><p>If that time no longer works for you, you can '
                .'<a href="'.e($this->lead->availabilityUrl()).'">pick new consultation times</a>'
                .' and we&rsquo;ll confirm the change ASAP.';
        } elseif ($booked !== null) {
            // The booked day has passed with nothing new selected: name it,
            // then ask for fresh times.
            $timeBlock = 'We had your consultation scheduled for <strong>'.e($booked['label']).'</strong>'
                .' &mdash; let&rsquo;s find a new time. Please '
                .'<a href="'.e($this->lead->availabilityUrl()).'">select new consultation times</a>'
                .' that suit you and we&rsquo;ll confirm ASAP.';
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
                '{{schedule_link}}',
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
                // The signed pick-times page needs only the LEAD — no project,
                // no account. Any template can carry it.
                $this->scheduleLink(),
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
