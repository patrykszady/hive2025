<?php

namespace App\Services;

use App\Livewire\Leads\PickTimes;
use App\Models\Client;
use App\Models\Lead;
use App\Models\SmsGroupThread;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use Carbon\Carbon;

/**
 * Text a client the signed "pick consultation times" link from a Messages
 * thread — the same link the lead emails carry.
 *
 * The link is bound to a Lead (the pick-times page reads and writes the
 * lead's availability, and the office books from the lead form). A client
 * texting from a project that never came through the leads pipeline has no
 * lead, so one is created quietly for the thread's contact — no "new lead"
 * notification, since nothing new arrived — and every existing rail then
 * carries it: the homeowner picks times, the team is notified, the office
 * confirms from the lead, and the consult lands on the client's project.
 */
class ConsultScheduleLinkTexter
{
    public function __construct(
        protected GroupSmsService $sms,
        protected UrlShortener $shortener,
    ) {
    }

    /**
     * @return array{ok: bool, variant: string, heading: string, text: string}
     */
    public function textToThread(SmsGroupThread $thread, User $actor): array
    {
        $client = $thread->client_id ? Client::withoutGlobalScopes()->find($thread->client_id) : null;
        if (! $client) {
            return $this->result(false, 'warning', 'No client on this thread', 'Assign the client first, then text the scheduling link.');
        }

        $contact = $this->contactFor($thread, $client);
        if (! $contact) {
            return $this->result(false, 'warning', 'No contact on this client', 'The client record has no contact person to attach the link to.');
        }

        if ($thread->hasPendingOptIn()) {
            return $this->result(false, 'warning', 'Awaiting START reply', 'This number has not replied START yet — the link can go out once they do.');
        }

        $vendorId = (int) ($actor->vendor?->id ?? $client->vendors()->withoutGlobalScopes()->value('vendors.id') ?? 0);
        $lead = $this->leadFor($contact, $client, $vendorId, $actor);

        $vendor = Vendor::withoutGlobalScopes()->find($lead->belongs_to_vendor_id);
        $contractor = trim((string) (data_get($vendor?->options, 'short_name') ?: $vendor?->name)) ?: config('app.name');
        $firstName = trim((string) $contact->first_name) ?: strtok(trim((string) ($lead->lead_data['name'] ?? '')), ' ');
        $link = $this->shortener->shorten($lead->availabilityUrl());
        $booked = $this->bookedConsult($client);

        // One voice with the lead emails: confirm what's on the books and offer
        // the picker, or simply offer the picker.
        $text = 'Hi'.($firstName ? " {$firstName}" : '').",\n\n"
            .($booked
                ? "Your consultation with {$contractor} is booked for {$booked}. If this time no longer works for you, you can pick new consultation times here: {$link} and we’ll confirm the new one ASAP."
                : "Pick a consultation time with {$contractor} here: {$link}");

        $this->sms->sendToThread($thread, $text, [], $actor->id);

        return $this->result(true, 'success', 'Texted', 'Consult scheduling link sent to '.($contact->first_name ?: 'the client').'.');
    }

    /** The client contact behind the thread's number, else the client's first contact. */
    protected function contactFor(SmsGroupThread $thread, Client $client): ?User
    {
        $users = $client->users()->withoutGlobalScopes()->get();
        $phones = collect((array) $thread->participants)
            ->map(fn ($p) => preg_replace('/\D/', '', (string) $p))
            ->map(fn ($d) => strlen($d) === 11 && str_starts_with($d, '1') ? substr($d, 1) : $d)
            ->filter()
            ->all();

        return $users->first(function (User $user) use ($phones) {
            $digits = preg_replace('/\D/', '', (string) $user->cell_phone);
            $digits = strlen($digits) === 11 && str_starts_with($digits, '1') ? substr($digits, 1) : $digits;

            return $digits !== '' && in_array($digits, $phones, true);
        }) ?? $users->first();
    }

    /** The contact's open lead, or a quiet one made for this purpose. */
    protected function leadFor(User $contact, Client $client, int $vendorId, User $actor): Lead
    {
        $lead = Lead::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('user_id', $contact->id)
            ->latest('id')
            ->first();

        if ($lead) {
            return $lead;
        }

        return Lead::withoutEvents(fn () => Lead::create([
            'date' => now(),
            'origin' => 'Messages',
            'user_id' => $contact->id,
            'belongs_to_vendor_id' => $vendorId,
            'created_by_user_id' => $actor->id,
            'lead_data' => array_filter([
                'name' => trim($contact->first_name.' '.$contact->last_name),
                'email' => $contact->email,
                'phone' => $contact->cell_phone,
                'address' => $client->address,
                'city' => $client->city,
                'state' => $client->state,
                'zip' => $client->zip_code !== null ? (string) $client->zip_code : null,
                'source' => 'Consult scheduling link texted from Messages',
            ], fn ($v) => $v !== null && $v !== ''),
        ]));
    }

    /**
     * "Wed, Sep 10 · 1:00 PM" for the next upcoming consult Meet on any of the
     * client's projects, or null. Same label the lead email uses.
     */
    protected function bookedConsult(Client $client): ?string
    {
        $tz = PickTimes::timezone();
        $projectIds = $client->projects()->withoutGlobalScopes()->pluck('id');
        if ($projectIds->isEmpty()) {
            return null;
        }

        $task = Task::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereIn('project_id', $projectIds)
            ->where('type', 'Meet')
            ->where('title', 'like', '% Consult')
            ->whereNotNull('start_date')
            ->whereDate('start_date', '>=', Carbon::now($tz)->startOfDay())
            ->orderBy('start_date')
            ->first();

        if (! $task) {
            return null;
        }

        $date = Carbon::parse($task->start_date)->format('Y-m-d');
        $settings = (array) data_get($task->options, 'time_settings.'.$date, []);
        $label = Carbon::parse($task->start_date)->format('D, M j');
        if (($settings['use_time'] ?? false) && ! empty($settings['start_time'])) {
            $label .= ' at '.Carbon::createFromFormat('H:i', $settings['start_time'])->format('g:i A');
        }

        return $label;
    }

    /** @return array{ok: bool, variant: string, heading: string, text: string} */
    protected function result(bool $ok, string $variant, string $heading, string $text): array
    {
        return compact('ok', 'variant', 'heading', 'text');
    }
}
