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
use App\Services\Leads\LeadReplyComposer;
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

    /**
     * Office override for a hot lead: lets THIS lead's homeowner pick a
     * consult slot as soon as an hour from now on the public picker, instead
     * of the usual three days. Persisted as lead_data.consult_notice_hours
     * (see Lead::consultNoticeHours()/allowsConsultWithinTheHour()), which
     * PickTimes::minLeadHours() reads directly.
     */
    public bool $consultWithinTheHour = false;

    public bool $showLeadDelete = false;

    /** A matching existing contact found while creating a lead by hand. */
    public ?array $duplicateMatch = null;

    /** Set by "Create anyway" after the duplicate warning. */
    public bool $createAnyway = false;

    /** An existing user picked for a lead that is not created yet; linked on save. */
    public ?int $attachUserId = null;

    /** The User dropdown: the linked user's id, or the one just chosen. */
    public $selectedUserId = null;

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
            'duplicateMatch', 'createAnyway', 'consultMeetingType', 'consultWithinTheHour', 'attachUserId', 'selectedUserId', 'user', 'client',
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
        // warning, then "Create anyway" goes through. Someone who picked an
        // existing user from the list already knows.
        if (! $this->createAnyway && $this->attachUserId === null && ($match = $this->findExistingContact())) {
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
                'consult_notice_hours' => $this->consultWithinTheHour ? 1 : null,
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

        // The user picked from the list is the contact; provisioning then
        // only fills in the client at the lead's address.
        if ($this->attachUserId && ($picked = $this->contactCandidates()->whereKey($this->attachUserId)->first())) {
            app(\App\Services\LeadContactProvisioner::class)->link($lead, $picked);
        }

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
        $this->lead = $lead->fresh(['user.clients.users', 'last_status', 'feedback']);

        $this->message = $this->lead->lead_data->message ?? null;
        $this->address = $this->lead->lead_data->address ?? null;
        $this->city = $this->lead->lead_data->city ?? null;
        $this->state = $this->lead->lead_data->state ?? null;
        $this->zip = $this->lead->lead_data->zip ?? null;
        $this->reply_to_email = $this->lead->lead_data->reply_to_email ?? null;
        $this->phone = $this->lead->lead_data->phone ?? null;
        $this->phoneEntry = null;
        $this->email = $this->lead->lead_data->email ?? null;
        $this->attachUserId = null;
        $this->selectedUserId = $this->lead->user_id;
        $this->consultMeetingType = in_array($this->lead->lead_data['meeting_preference'] ?? null, ['in_person', 'virtual'], true)
            ? $this->lead->lead_data['meeting_preference']
            : 'in_person';
        $this->consultWithinTheHour = $this->lead->allowsConsultWithinTheHour();
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

        // The footer status select already saves live via updated()'s
        // setStatus() call; this only guards against writing a duplicate
        // status row when Update is clicked without the status changing.
        if ($this->lead_status) {
            $lead->setStatus($this->lead_status);
        }

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
     * A replied lead is a conversation on record, so it can't be removed —
     * that is what this gates. The Message composer stays open: a consult
     * email is re-sent from it when the first went unanswered, a follow-up is
     * written from it (until 2026-09-15 the composer was locked too, and the
     * only way to send again was flipping the status back to New).
     *
     * A BOUNCED reply doesn't count as one: nobody read it, so the lead is
     * only "replied" on paper — and may still be removed.
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
     * The missing-contact-info tools (phone entry, address candidates,
     * linking/adding a user) — shared with the Hive Contractors app's
     * LeadController so both write through LeadContactProvisioner the same
     * way.
     */
    protected function contactCompletion(): \App\Services\Leads\LeadContactCompletion
    {
        return app(\App\Services\Leads\LeadContactCompletion::class);
    }

    /**
     * Save a hand-entered phone onto the lead AND its contact, so the number
     * we just learned is on the record people actually call from, not only in
     * the lead payload.
     */
    public function saveContactPhone(): void
    {
        $result = $this->contactCompletion()->validatePhoneEntry($this->phoneEntry, $this->lead?->user_id);

        if (! $result['ok']) {
            $this->addError('phoneEntry', $result['error']);

            return;
        }

        $this->resetErrorBag('phoneEntry');
        $digits = $result['digits'];

        if ($this->lead?->exists) {
            // The number is often the missing piece that lets provisioning
            // run at all: a name plus a way to reach them is the bar, so an
            // enquiry with neither phone nor usable email had no contact and
            // no client until now. Runs whether or not a user exists — an
            // existing contact may still be missing its client/address.
            $this->contactCompletion()->completePhone($this->lead, $digits);
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
        if (! $this->lead?->exists) {
            return [];
        }

        // Stored candidates ONLY. This is a computed property read during
        // render; geocoding here put a third-party API call in the path of
        // every lead click. CompleteLeadAddress writes them instead.
        return $this->contactCompletion()->addressCandidates($this->lead);
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
        if (! $this->lead?->exists) {
            return;
        }

        $candidate = $this->contactCompletion()->selectAddressCandidate($this->lead, $index);

        if (! $candidate) {
            return;
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

        // Judged on the LEAD first, not the client: a lead with a perfectly
        // good address still has no client while some OTHER field is
        // missing (no phone → no contact → no client), and reporting the
        // address as missing then sent people hunting for a problem that
        // wasn't there. The client is the fallback for older leads whose
        // address only ever lived on the client record.
        return $this->contactCompletion()->missingContactInfo($this->lead, $this->client);
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
        // Persisted state ONLY. Reading the bound input here is what let a
        // half-typed or invalid number open the Message tab without anything
        // being saved.
        return $this->contactCompletion()->needsPhone($this->lead ?? new Lead);
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
    /**
     * Sending a reply with an availability slot selected books the consult —
     * delegates to App\Services\Leads\LeadReplyComposer::bookConsult(), the
     * service the Hive Contractors app's LeadController shares, so both
     * surfaces book the identical project/Meet task/calendar event.
     */
    protected function bookConsult(): bool
    {
        return app(LeadReplyComposer::class)->bookConsult(
            $this->lead,
            $this->client,
            (string) $this->full_name,
            $this->address,
            $this->consultMeetingType,
            $this->availability,
            $this->selectedAvailability,
            $this->selectedExactTime,
            $this->projectName,
        );
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

    // ---- The lead's user: pick an existing contact, or create one from the lead. ----

    /**
     * The User dropdown: this company's client contacts and the people its
     * other leads are linked to — never its own staff, never another
     * company's customers — with the lead's current user always present.
     *
     * @return list<array{id: int, name: string, contact: string, label: string}>
     */
    #[Computed]
    public function userChoices(): array
    {
        return $this->contactCompletion()->contactChoices((int) auth()->user()->vendor->id, $this->lead?->user);
    }

    /**
     * The users this company may link a lead to.
     */
    protected function contactCandidates(): \Illuminate\Database\Eloquent\Builder
    {
        return $this->contactCompletion()->contactCandidatesQuery((int) auth()->user()->vendor->id);
    }

    /**
     * @return array{id: int, name: string, contact: string, label: string}
     */
    protected function userOption(\App\Models\User $user): array
    {
        return $this->contactCompletion()->contactOption($user);
    }

    /** Choosing a different user in the dropdown links them. */
    public function updatedSelectedUserId($value): void
    {
        if (is_numeric($value) && (int) $value !== (int) ($this->lead?->user_id ?? $this->attachUserId)) {
            $this->attachUser((int) $value);
        }
    }

    /**
     * Link an existing user to the lead — now if the lead exists, on save if
     * it does not — and take their email and phone where the lead has none.
     */
    public function attachUser(int $userId): void
    {
        $user = $this->contactCandidates()->whereKey($userId)->first();

        if ($user === null) {
            $this->selectedUserId = $this->lead?->user_id ?? $this->attachUserId;

            return;
        }

        $this->selectedUserId = $user->id;
        $this->full_name = trim((string) $user->full_name);
        if (blank($this->email) && $user->hasRoutableEmail()) {
            $this->email = $user->email;
        }
        if (blank($this->phone) && $user->hasRoutablePhone()) {
            $this->phone = $user->cell_phone;
        }
        if (! $this->lead?->exists) {
            $this->attachUserId = $user->id;

            return;
        }

        $this->contactCompletion()->attachExistingUser($this->lead, $user, (string) $this->full_name, $this->email, $this->phone);
        $this->reloadLeadContact();

        Flux::toast(
            duration: 4000,
            position: 'top right',
            variant: 'success',
            heading: 'User linked',
            text: trim((string) $user->full_name).' is now the contact on this lead.',
        );
    }

    /**
     * "Add User": a user record from the name, email and phone on the lead,
     * linked to it — for a lead whose contact was never provisioned.
     */
    public function addUser(): void
    {
        if (! $this->lead?->exists || $this->lead->user_id) {
            return;
        }

        $this->validate(['full_name' => ['required', 'string', 'max:120']], [], ['full_name' => 'name']);

        if (blank($this->email) && blank($this->phone)) {
            $this->addError('email', 'An email or phone number is needed to create a user.');

            return;
        }

        $user = $this->contactCompletion()->addUserFromLead($this->lead, (string) $this->full_name, $this->email, $this->phone);

        if ($user === null) {
            $this->addError('full_name', 'A name is needed to create a user.');

            return;
        }

        $this->reloadLeadContact();

        Flux::toast(
            duration: 4000,
            position: 'top right',
            variant: 'success',
            heading: 'User added',
            text: trim((string) $user->full_name).' was created and linked to this lead.',
        );
    }

    protected function reloadLeadContact(): void
    {
        $this->lead = $this->lead->fresh(['user.clients.users', 'last_status', 'feedback']);
        $this->user = $this->lead->user;
        $this->client = $this->resolveClientForLead();
        $this->selectedUserId = $this->lead->user_id;
        if ($this->user) {
            $this->full_name = trim((string) $this->user->full_name);
        }
        unset($this->userChoices, $this->missingContactInfo, $this->blockingContactInfo, $this->needsPhone);
        $this->dispatch('refreshComponent')->to('leads.leads-index');
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
        return app(\App\Services\Leads\LeadDuplicateFinder::class)->find(
            $this->email,
            $this->phone,
            auth()->user()->vendor->id,
        );
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

        // The texting itself is shared with the app (LeadScheduleLinkTexter);
        // the method keeps taking the SMS service so callers and tests that
        // hand one in keep working.
        $texter = new \App\Services\Leads\LeadScheduleLinkTexter($sms, app(\App\Services\UrlShortener::class));
        $result = $texter->text($this->lead, $this->client, auth()->user());

        Flux::toast(
            duration: $result['ok'] ? 4000 : 6000,
            position: 'top right',
            variant: $result['variant'],
            heading: $result['heading'],
            text: $result['text'],
        );
    }

    /**
     * One click from the modal: apply the Consult template and send it.
     * Without selected availability the email asks the homeowner to pick
     * consultation times on the signed picker — whose slots are already
     * gated by Greg's and Patryk's calendars — i.e. the "can we meet for a
     * consult?" outreach in a single press. With a usable shared time
     * selected it confirms that time instead, same as the manual flow.
     */
    /**
     * One click: apply the "Consult" template and send it — delegates to
     * App\Services\Leads\LeadReplyComposer::sendConsultInvite(), the same
     * service the Hive Contractors app's LeadController calls.
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

        $result = app(LeadReplyComposer::class)->send(
            $this->lead,
            $this->client,
            auth()->user(),
            (string) $this->full_name,
            $this->replyInput() + [
                'subject' => $this->subject,
                'body' => $this->emailBody,
            ],
        );

        if (! $result['ok']) {
            Flux::toast(
                duration: 7000,
                position: 'top right',
                variant: 'danger',
                heading: 'Email Not Sent',
                text: (string) $result['error'],
            );

            return;
        }

        $this->finishSend($result);
    }

    /**
     * @param  array{ok: bool, error: ?string, booked: bool, confirmed: bool, texted: ?string, unbooked: bool, new_status: ?string, recipient_count: int}  $result
     */
    protected function finishSend(array $result): void
    {
        if (! $result['ok']) {
            Flux::toast(
                duration: 7000,
                position: 'top right',
                variant: 'danger',
                heading: 'Not sent',
                text: (string) $result['error'],
            );

            return;
        }

        if ($result['new_status'] !== null) {
            $this->lead_status = $result['new_status'];
            unset($this->hasReplied);
        }

        $this->modal('lead_form_modal')->close();
        $this->dispatch('refreshComponent')->to('leads.leads-index');
        $this->dispatch('lead-status-updated');

        if ($result['unbooked']) {
            Flux::toast(
                duration: 9000,
                position: 'top right',
                variant: 'warning',
                heading: 'Email queued — consult NOT booked',
                text: 'This lead has no client record, so the time in the email was not put on the calendar. '
                    . 'Add the contact on Details, then send again.',
            );

            return;
        }

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Email Queued',
            text: 'Sending message to ' . $result['recipient_count'] . ' recipient(s)'
                . ($result['booked'] ? '. Consult booked — project and Meet task created.' : '')
                . ($result['texted'] ? ' '.$result['texted'] : ''),
        );
    }

    /**
     * The composer's current selections, shaped the way
     * App\Services\Leads\LeadReplyComposer expects them — shared by
     * send_message() and sendConsultInvite().
     *
     * @return array{to: array<int, string>, from: string, selected_template_id: ?int, availability: array, selected_availability: array<int>, selected_exact_time: ?string, project_name: string, consult_meeting_type: string, nylas_message_id: ?string, nylas_references: array<int, string>, sender_ip: ?string}
     */
    protected function replyInput(): array
    {
        return [
            'to' => $this->to,
            'from' => $this->from,
            'selected_template_id' => $this->selectedTemplateId,
            'availability' => $this->availability,
            'selected_availability' => $this->selectedAvailability,
            'selected_exact_time' => $this->selectedExactTime,
            'project_name' => $this->projectName,
            'consult_meeting_type' => $this->consultMeetingType,
            'nylas_message_id' => $this->nylasMessageId,
            'nylas_references' => $this->nylasReferences,
            'sender_ip' => request()->ip(),
        ];
    }

    /**
     * Everything the Message tab prefills — delegates to
     * App\Services\Leads\LeadReplyComposer::composerOptions(), the same
     * service the Hive Contractors app's LeadController calls.
     */
    protected function prepareEmailComposer(): void
    {
        $options = app(LeadReplyComposer::class)->composerOptions(
            $this->lead,
            $this->client,
            $this->user,
            (string) $this->full_name,
            $this->origin,
            auth()->user(),
            $this->availability,
            $this->consultMeetingType,
        );

        $this->to = $options['to'];
        $this->availableContacts = $options['available_contacts'];
        $this->availableFromEmails = CompanyEmail::where('vendor_id', $this->lead->belongs_to_vendor_id)
            ->whereNotNull('grant_id')
            ->get();
        $this->from = $options['from'];
        $this->availableTemplates = EmailTemplate::where('type', 'lead')->orderBy('name')->get();
        $this->selectedTemplateId = $options['selected_template_id'];
        $this->subject = $options['subject'];
        $this->emailBody = $options['body'];
        $this->nylasMessageId = $options['nylas_message_id'];
        $this->nylasReferences = $options['nylas_references'];
    }

    public function insertAvailabilitySlot(int $index): void
    {
        if (! isset($this->availability[$index])) {
            return;
        }

        // A slot that can't be confirmed — its date passed, today's window is
        // over, or the calendars leave no free start in it — can't be
        // selected; when none can, the email offers the pick-new-times link
        // instead (see {{lead_time_block}}).
        if ($this->slotProblem((array) $this->availability[$index]) !== null) {
            return;
        }

        if (in_array($index, $this->selectedAvailability, true)) {
            $this->selectedAvailability = [];
        } else {
            $this->selectedAvailability = [$index];
        }

        $this->selectedExactTime = null;
        unset($this->exactTimeOptions, $this->selectedSlotWindowKnown, $this->slotProblems, $this->awaitingExactTime, $this->needsProjectName, $this->sendBlockedReason);
        $this->rerenderTemplate();
    }

    /**
     * Does the selected slot name a window we can lay start times over?
     * Tells "every start is taken" (worth a warning) apart from "the slot has
     * no parseable time" (the email just keeps the range).
     */
    #[Computed]
    public function selectedSlotWindowKnown(): bool
    {
        return app(LeadReplyComposer::class)->selectedSlotWindowKnown($this->availability, $this->selectedAvailability);
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
        return app(LeadReplyComposer::class)->exactTimeOptions($this->availability, $this->selectedAvailability);
    }

    /**
     * Why a preferred slot can't be confirmed — 'past' (its date or window
     * is over) or 'booked' (the calendars leave no free start in it) — or
     * null when it can. A window we can't parse is left usable: the email
     * then keeps the range rather than dropping a time we can't judge.
     */
    public function slotProblem(array $slot): ?string
    {
        return app(LeadReplyComposer::class)->slotProblem($slot);
    }

    /** Which of the lead's preferred slots can still be confirmed, by index. @return array<int, string|null> */
    #[Computed]
    public function slotProblems(): array
    {
        return app(LeadReplyComposer::class)->slotProblems($this->availability);
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
        return app(LeadReplyComposer::class)->bookedConsult($this->client, $this->lead, (string) $this->full_name);
    }

    /**
     * Flipping the switch stores (or clears) the notice override on the lead
     * right away — no separate save step — so the public picker honours it
     * the moment the office turns it on. A lead not yet created (still in the
     * "New Lead" form) has nothing to persist to yet; save() writes the flag
     * into the lead's lead_data the moment the record is first created.
     */
    public function updatedConsultWithinTheHour(bool $value): void
    {
        if (! $this->lead?->exists) {
            return;
        }

        $data = $this->lead->lead_data;

        if ($value) {
            $data['consult_notice_hours'] = 1;
        } else {
            unset($data['consult_notice_hours']);
        }

        $this->lead->lead_data = $data;
        $this->lead->saveQuietly();
        $this->lead = $this->lead->fresh(['user.clients.users', 'last_status', 'feedback']);
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
        unset($this->exactTimeOptions, $this->selectedSlotWindowKnown, $this->slotProblems, $this->awaitingExactTime, $this->needsProjectName, $this->sendBlockedReason, $this->hasUsableAvailability);

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
            unset($this->exactTimeOptions, $this->selectedSlotWindowKnown, $this->slotProblems, $this->awaitingExactTime, $this->needsProjectName, $this->sendBlockedReason, $this->hasUsableAvailability);
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

        // First time picked: propose where the consult goes — the client's
        // newest open project. Every project finished leaves the box empty
        // for a new name.
        if ($this->selectedExactTime !== null && trim($this->projectName) === '' && ($open = $this->openProjectForConsult())) {
            $this->projectName = $this->consultProjectLabel($open);
        }

        unset($this->awaitingExactTime, $this->needsProjectName, $this->sendBlockedReason, $this->consultProjectOptions);
        $this->rerenderTemplate();
    }

    public function updatedProjectName(): void
    {
        unset($this->needsProjectName, $this->sendBlockedReason);
    }

    /** "Primary Bath — Cancelled": how a project reads in the Project box. */
    /** @return array<int, array{id: int, label: string, name: string, stage: string, color: string}> */
    #[Computed]
    public function consultProjectOptions(): array
    {
        return app(LeadReplyComposer::class)->consultProjectOptions($this->client);
    }

    /** "Primary Bath — Cancelled": how a project reads in the Project box. */
    protected function consultProjectLabel(\App\Models\Project $project): string
    {
        return app(LeadReplyComposer::class)->consultProjectLabel($project);
    }

    /**
     * The project pre-filled into the box: the client's newest project that
     * has not started (Invited, Consult, Estimate or Response). A Cancelled one stays on offer in the list but
     * is not proposed; with nothing open the box is left for a name, which
     * is what needsProjectName() then asks for.
     */
    protected function openProjectForConsult(): ?\App\Models\Project
    {
        return app(LeadReplyComposer::class)->openProjectForConsult($this->client);
    }

    /**
     * Any preferred slot still bookable (today or later)? When none are, the
     * consult email swaps its confirm-line for the pick-new-times link and
     * sending is allowed without booking.
     */
    #[Computed]
    public function hasUsableAvailability(): bool
    {
        return app(LeadReplyComposer::class)->hasUsableAvailability($this->availability);
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
        return app(LeadReplyComposer::class)->awaitingExactTime($this->emailBody);
    }

    /**
     * Booking this consult will CREATE a project (client has none yet), so the
     * form needs a project name before sending.
     */
    #[Computed]
    public function needsProjectName(): bool
    {
        return app(LeadReplyComposer::class)->needsProjectName($this->client, $this->selectedExactTime, $this->projectName);
    }

    /** Why Send Email is blocked right now, or null when it isn't. */
    #[Computed]
    public function sendBlockedReason(): ?string
    {
        return app(LeadReplyComposer::class)->sendBlockedReason($this->client, $this->emailBody, $this->selectedExactTime, $this->projectName);
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

    /**
     * Fills a template's placeholders for THIS lead/composer state —
     * delegates to App\Services\Leads\LeadReplyComposer::replacePlaceholders(),
     * the same service the Hive Contractors app's LeadController calls. Kept
     * as a one-argument instance method (tests reach it via Reflection on a
     * live component instance).
     */
    protected function replacePlaceholders(string $text): string
    {
        return app(LeadReplyComposer::class)->replacePlaceholders(
            $text,
            $this->lead,
            $this->client,
            (string) $this->full_name,
            $this->origin,
            $this->message,
            $this->address,
            (string) $this->from,
            $this->availability,
            $this->selectedAvailability,
            $this->selectedExactTime,
            $this->consultMeetingType,
        );
    }

    public function getUserDisplayName(string $email): string
    {
        $contact = collect($this->availableContacts)->firstWhere('email', $email);
        return $contact['name'] ?? $email;
    }

    protected function getFromUserDisplayName(string $email): string
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

    /**
     * The client a reply/consult should be booked against — delegates to
     * App\Services\Leads\LeadReplyComposer::resolveClientForLead(), the same
     * service the Hive Contractors app's LeadController calls.
     */
    protected function resolveClientForLead(): ?\App\Models\Client
    {
        if (! $this->lead) {
            return null;
        }

        return app(LeadReplyComposer::class)->resolveClientForLead($this->lead);
    }
}
