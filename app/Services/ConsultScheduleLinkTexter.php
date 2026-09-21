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
     * Compose the text without sending it: the conversation drops it into the
     * message box for the sender to read and send (2026-09-21 — an auto-sent
     * "is booked for Mon 9:00 AM" went out at 3 PM the same day to a client
     * who had already said he could not make it).
     *
     * @return array{ok: bool, variant: string, heading: string, text: string, message: string, lead_id: int|null}
     */
    public function composeForThread(SmsGroupThread $thread, User $actor): array
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
        // A couple's thread carries both their numbers: greet both (thread
        // 180 read "Hi Carri," to Carri and Alan). Anyone on the thread we
        // know by name, in the thread's order; the lead's name as the fallback.
        $firstName = self::greetingNames($thread, $client, (string) ($lead->lead_data['name'] ?? ''));
        $link = $this->shortener->shorten($lead->availabilityUrl());
        $booked = $this->bookedConsult($client);

        // One voice with the lead emails: confirm what's on the books and offer
        // the picker; when the booked time has already passed, say so and ask
        // for a new one; otherwise simply offer the picker.
        $greeting = 'Hi'.($firstName ? " {$firstName}" : '').",\n\n";
        if ($booked && $booked['past']) {
            $text = $greeting."We had your consultation with {$contractor} scheduled for {$booked['label']} — let's find a new time. Pick one here: {$link} and we’ll confirm it ASAP.";
        } elseif ($booked) {
            $text = $greeting."Your consultation with {$contractor} is booked for {$booked['label']}. If this time no longer works for you, you can pick new consultation times here: {$link} and we’ll confirm the new one ASAP.";
        } else {
            $text = $greeting."Pick a consultation time with {$contractor} here: {$link}";
        }

        return $this->result(true, 'success', 'Ready to send', 'The consult text is in the message box — read it over and send.')
            + ['message' => $text, 'lead_id' => $lead->id];
    }

    /**
     * Compose and send in one go (the pre-2026-09-21 behaviour, kept for the
     * callers that want it). Texting the link is our reply: a New lead moves
     * to Replied (waiting on them), exactly as the email composer does —
     * never downgrading a lead that already progressed (Won stays Won). A
     * lead this path made before it recorded a stage gets its New first, so
     * its history reads like everyone else's instead of "Set status".
     *
     * @return array{ok: bool, variant: string, heading: string, text: string}
     */
    public function textToThread(SmsGroupThread $thread, User $actor): array
    {
        $composed = $this->composeForThread($thread, $actor);
        if (! $composed['ok']) {
            return $composed;
        }
        $this->sms->sendToThread($thread, $composed['message'], [], $actor->id);
        $this->markReplied((int) $composed['lead_id']);

        $contact = $this->contactFor($thread, Client::withoutGlobalScopes()->find($thread->client_id));

        return $this->result(true, 'success', 'Texted', 'Consult scheduling link sent to '.($contact?->first_name ?: 'the client').'.');
    }

    /** A New lead moves to Replied once we have texted the link; anything further along stays. */
    public function markReplied(int $leadId): void
    {
        $lead = Lead::withoutGlobalScopes()->find($leadId);
        if (! $lead) {
            return;
        }
        if ($lead->statuses()->doesntExist()) {
            $lead->statuses()->create(['title' => 'New', 'belongs_to_vendor_id' => $lead->belongs_to_vendor_id]);
            $lead->unsetRelation('last_status');
        }
        if (($lead->last_status?->title ?? 'New') === 'New') {
            $lead->setStatus('Replied');
        }
    }

    /** The client contact behind the thread's number, else the client's first contact. */
    protected function contactFor(SmsGroupThread $thread, Client $client): ?User
    {
        return self::contactsFor($thread, $client)->first() ?? $client->users()->withoutGlobalScopes()->first();
    }

    /**
     * "Carri and Alan" — the first names of everyone on the thread we know,
     * in the thread's order, else the first name from $fallbackName. Shared
     * with the consult confirmation text so every text greets the same way.
     */
    public static function greetingNames(SmsGroupThread $thread, Client $client, string $fallbackName = ''): string
    {
        $names = self::contactsFor($thread, $client)
            ->map(fn (User $user) => trim((string) ($user->nickname ?: $user->first_name)))
            ->filter()
            ->unique()
            ->values();

        // Joined the way Client::first_names joins them, so an email and a
        // text to the same couple read the same ("Carri & Alan").
        return $names->isEmpty()
            ? (string) strtok(trim($fallbackName), ' ')
            : $names->join(', ', ' & ');
    }

    /**
     * Every client contact whose number is on the thread, in the thread's
     * participant order — a couple texting together is two of them.
     *
     * @return \Illuminate\Support\Collection<int, User>
     */
    public static function contactsFor(SmsGroupThread $thread, Client $client): \Illuminate\Support\Collection
    {
        $normalize = function (?string $number): string {
            $digits = preg_replace('/\D/', '', (string) $number);

            return strlen($digits) === 11 && str_starts_with($digits, '1') ? substr($digits, 1) : $digits;
        };

        $users = $client->users()->withoutGlobalScopes()->get();

        return collect((array) $thread->participants)
            ->map($normalize)
            ->filter()
            ->map(fn (string $phone) => $users->first(fn (User $user) => $normalize($user->cell_phone) === $phone))
            ->filter()
            ->unique('id')
            ->values();
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

        $lead = Lead::withoutEvents(fn () => Lead::create([
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

        // Parity with every other way a lead is born (form, crew inbox, Angi,
        // the composer): without a status row it has no pipeline stage and
        // the leads table can only offer "Set status".
        $lead->statuses()->create(['title' => 'New', 'belongs_to_vendor_id' => $vendorId]);

        return $lead;
    }

    /**
     * "Wed, Sep 10 · 1:00 PM" for the next upcoming consult Meet on any of the
     * client's projects, or null. Same label the lead email uses.
     */
    /**
     * The consult on the books for this client: the next one ahead, or —
     * when nothing is ahead — the most recent one of the past week, flagged
     * `past` so the text says "we had it scheduled for…" instead of
     * confirming a time that has gone.
     *
     * @return array{label: string, past: bool}|null
     */
    protected function bookedConsult(Client $client): ?array
    {
        $tz = PickTimes::timezone();
        $projectIds = $client->projects()->withoutGlobalScopes()->pluck('id');
        if ($projectIds->isEmpty()) {
            return null;
        }
        $now = Carbon::now($tz);
        $tasks = Task::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereIn('project_id', $projectIds)
            ->where('type', 'Meet')
            ->where('title', 'like', '% Consult')
            ->whereNotNull('start_date')
            ->whereDate('start_date', '>=', $now->copy()->subDays(7)->startOfDay())
            ->orderBy('start_date')
            ->get();
        if ($tasks->isEmpty()) {
            return null;
        }

        $describe = function (Task $task) use ($tz, $now): array {
            $date = Carbon::parse($task->start_date)->format('Y-m-d');
            $settings = (array) data_get($task->options, 'time_settings.'.$date, []);
            $label = Carbon::parse($task->start_date)->format('D, M j');
            $starts = Carbon::parse($date, $tz)->endOfDay();
            if (($settings['use_time'] ?? false) && ! empty($settings['start_time'])) {
                $label .= ' at '.Carbon::createFromFormat('H:i', $settings['start_time'])->format('g:i A');
                $starts = Carbon::parse($date.' '.$settings['start_time'], $tz);
            }

            return ['label' => $label, 'past' => $starts->lt($now)];
        };

        $described = $tasks->map($describe);
        $upcoming = $described->first(fn (array $c) => ! $c['past']);

        return $upcoming ?? $described->last();
    }

    /** @return array{ok: bool, variant: string, heading: string, text: string} */
    protected function result(bool $ok, string $variant, string $heading, string $text): array
    {
        return compact('ok', 'variant', 'heading', 'text');
    }
}
