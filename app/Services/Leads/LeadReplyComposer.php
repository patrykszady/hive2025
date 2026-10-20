<?php

namespace App\Services\Leads;

use App\Jobs\CreateMeetTaskCalendarEvent;
use App\Jobs\UpdateMeetTaskCalendarEvent;
use App\Models\Client;
use App\Models\CompanyEmail;
use App\Models\EmailTemplate;
use App\Models\EmailTracking;
use App\Models\Lead;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\SmsGroupThread;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use App\Jobs\SendLeadReplyJob;
use App\Livewire\Leads\PickTimes;
use App\Services\AdminCalendarBusy;
use App\Services\GroupSmsService;
use App\Services\MeetingParticipants;
use App\Services\NylasService;
use App\Services\UrlShortener;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * The lead "Message" composer's business logic — everything
 * App\Livewire\Leads\LeadCreate's Message tab does (template/placeholder
 * resolution, the availability/exact-time picker's rules, booking the
 * consult, and the companion text) — extracted so the Hive Contractors
 * app's LeadController can send the exact same email, book the exact same
 * consult, and send the exact same companion text as the web. LeadCreate
 * delegates to every method here; nothing is duplicated between the two
 * surfaces.
 */
class LeadReplyComposer
{
    /** A booked consult runs half an hour — same constant LeadCreate exposes. */
    public const CONSULT_MINUTES = 30;

    /** Stages a project can be at for a consult to land on it. */
    protected const CONSULT_PROJECT_STAGES = ['Consult', 'Estimate', 'Cancelled'];

    public function __construct(
        protected UrlShortener $urlShortener,
        protected AdminCalendarBusy $busy,
        protected GroupSmsService $sms,
    ) {
    }

    // ---- Composer prefill (mirrors LeadCreate::prepareEmailComposer()) ----

    /**
     * Everything the Message tab prefills: recipients, available contacts,
     * from addresses, templates (default subject/body already placeholder-
     * filled), and the inbound Nylas message to thread the reply under.
     *
     * @return array{to: list<string>, available_contacts: list<array{email: string, name: string, group: string}>, available_from_emails: list<array{id: int, email: string}>, available_templates: list<array{id: int, name: string}>, selected_template_id: ?int, subject: string, body: string, nylas_message_id: ?string, nylas_references: list<string>}
     */
    public function composerOptions(Lead $lead, ?Client $client, ?User $user, string $fullName, ?string $origin, User $currentUser, array $availability = [], string $consultMeetingType = 'in_person'): array
    {
        $vendorId = $lead->belongs_to_vendor_id;

        $companyEmails = CompanyEmail::where('vendor_id', $vendorId)
            ->whereNotNull('grant_id')
            ->get();

        $currentUserEmail = $currentUser->email;
        $current = $companyEmails->firstWhere('email', $currentUserEmail);
        $from = $current?->email ?? ($companyEmails->first()?->email ?? '');

        $recipients = collect();
        $leadEmail = $lead->lead_data['email'] ?? null;
        $replyToEmail = $lead->lead_data['reply_to_email'] ?? null;

        if ($client) {
            foreach ($client->users as $u) {
                if ($u->hasRoutableEmail()) {
                    $recipients->push($u->email);
                }
            }
        } elseif ($user && ! empty($user->email)) {
            $recipients->push($user->email);
        }

        if ($leadEmail) {
            $recipients->push($leadEmail);
        }
        if ($replyToEmail) {
            $recipients->push($replyToEmail);
        }

        $to = $recipients->filter()->unique()->values()->all();

        $contacts = collect();
        if ($client) {
            foreach ($client->users as $u) {
                if (! empty($u->email)) {
                    $contacts->push([
                        'email' => $u->email,
                        'name' => trim(($u->first_name ?? '').' '.($u->last_name ?? '')) ?: $u->email,
                        'group' => 'Client',
                    ]);
                }
            }
        }
        if ($leadEmail) {
            $contacts->push([
                'email' => $leadEmail,
                'name' => $fullName ?: $leadEmail,
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
        $availableContacts = $contacts->unique('email')->values()->all();

        $templates = EmailTemplate::where('type', 'lead')->orderBy('name')->get();
        $defaultTemplate = $templates->first();
        $selectedTemplateId = $defaultTemplate?->id;

        $placeholderArgs = [$lead, $client, $fullName, $origin, $lead->lead_data->message ?? null, $lead->lead_data->address ?? null, $from];

        $subject = $defaultTemplate
            ? $this->replacePlaceholders((string) $defaultTemplate->subject, ...$placeholderArgs, availability: $availability, selectedAvailability: [], selectedExactTime: null, consultMeetingType: $consultMeetingType)
            : 'Re: '.($origin ? ucfirst($origin).' inquiry' : 'your inquiry');
        $body = $defaultTemplate
            ? $this->replacePlaceholders((string) $defaultTemplate->body, ...$placeholderArgs, availability: $availability, selectedAvailability: [], selectedExactTime: null, consultMeetingType: $consultMeetingType)
            : '';

        [$nylasMessageId, $nylasReferences] = $this->lookupNylasInboundMessage($lead, $current ?? $companyEmails->first(), $leadEmail ?: $replyToEmail);

        return [
            'to' => $to,
            'available_contacts' => $availableContacts,
            'available_from_emails' => $companyEmails->map(fn (CompanyEmail $e) => ['id' => $e->id, 'email' => $e->email])->values()->all(),
            'available_templates' => $templates->map(fn (EmailTemplate $t) => ['id' => $t->id, 'name' => $t->name])->values()->all(),
            'selected_template_id' => $selectedTemplateId,
            'from' => $from,
            'subject' => $subject,
            'body' => $body,
            'nylas_message_id' => $nylasMessageId,
            'nylas_references' => $nylasReferences,
        ];
    }

    /**
     * @return array{0: ?string, 1: list<string>}
     */
    protected function lookupNylasInboundMessage(Lead $lead, ?CompanyEmail $companyEmail, ?string $leadEmail): array
    {
        if (! $companyEmail || ! $companyEmail->grant_id || empty($leadEmail)) {
            return [null, []];
        }

        try {
            $nylas = app(NylasService::class);

            $receivedAfter = $lead->date
                ? Carbon::parse($lead->date)->subDays(2)->timestamp
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
                $references = (array) data_get($match, 'references', []);

                return [
                    data_get($match, 'id') ?: null,
                    array_values(array_filter(array_map('strval', $references))),
                ];
            }
        } catch (\Throwable $exception) {
            Log::warning('Lead Nylas inbound lookup failed', [
                'lead_id' => $lead->id,
                'error' => $exception->getMessage(),
            ]);
        }

        return [null, []];
    }

    /**
     * The lead's offered availability, shaped exactly as
     * LeadCreate::editLead() sets $this->availability — every slot the lead
     * gave (NOT filtered to only the bookable ones; a past slot still
     * occupies its index so slotProblem()/insertAvailabilitySlot() agree
     * with what the composer shows). Unlike Lead::usableAvailability() (used
     * for read-only display elsewhere), this never drops or renumbers a
     * slot, because the composer's selected_availability indices must stay
     * stable across requests.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rawAvailability(Lead $lead): array
    {
        $raw = $lead->lead_data['availability'] ?? [];

        return is_array($raw) || $raw instanceof \Traversable
            ? collect($raw)->map(fn ($slot) => (array) $slot)->values()->all()
            : [];
    }

    /**
     * The client a reply/consult should be booked against — mirrors
     * LeadCreate::resolveClientForLead(): the lead's own resolveClient()
     * when its linked user has no client yet, otherwise whichever of that
     * user's clients the lead's own address matches (a contact with more
     * than one address on file), falling back to their first.
     */
    public function resolveClientForLead(Lead $lead): ?Client
    {
        if (! $lead->user || $lead->user->clients->isEmpty()) {
            return $lead->resolveClient();
        }

        $clients = $lead->user->clients;

        $address = $lead->lead_data->address ?? null;

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

    // ---- Availability / exact-time rules (mirrors the Computed properties) ----

    /**
     * Why a preferred slot can't be confirmed, or null when it can — mirrors
     * LeadCreate::slotProblem().
     */
    public function slotProblem(array $slot): ?string
    {
        if (! Lead::slotIsBookable($slot)) {
            return 'past';
        }

        $slotTime = trim((string) ($slot['time'] ?? ''));
        $windowKnown = Lead::parseSlotTimes($slotTime) || strcasecmp($slotTime, 'Anytime') === 0;

        return $windowKnown && $this->startTimesFor($slot) === [] ? 'booked' : null;
    }

    /** @return array<int, string|null> */
    public function slotProblems(array $availability): array
    {
        return collect($availability)->map(fn ($slot) => $this->slotProblem((array) $slot))->all();
    }

    public function hasUsableAvailability(array $availability): bool
    {
        return collect($this->slotProblems($availability))->contains(null);
    }

    /** @return array<int, array{value: string, label: string}> */
    public function startTimesFor(array $slot): array
    {
        $slotTime = trim((string) ($slot['time'] ?? ''));
        $times = Lead::parseSlotTimes($slotTime);

        if (! $times && strcasecmp($slotTime, 'Anytime') === 0) {
            $times = PickTimes::dayBounds();
        }

        if (! $times) {
            return [];
        }

        $cursor = Carbon::createFromFormat('H:i', $times[0]);
        $end = Carbon::createFromFormat('H:i', $times[1]);

        $date = (string) ($slot['date'] ?? '');

        $tz = PickTimes::timezone();
        $minStart = ((string) ($slot['date'] ?? '')) === Carbon::now($tz)->format('Y-m-d')
            ? Carbon::now($tz)->format('H:i')
            : null;

        $options = [];
        while ($cursor < $end) {
            $chipStart = $cursor->format('H:i');

            $clashes = $this->busy->startIsBusy($date, $chipStart, self::CONSULT_MINUTES);

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

    /** @return array<int, array{value: string, label: string}> */
    public function exactTimeOptions(array $availability, array $selectedAvailability): array
    {
        $slotIndex = $selectedAvailability[0] ?? null;
        $slot = $slotIndex !== null ? ($availability[$slotIndex] ?? null) : null;

        return $slot ? $this->startTimesFor((array) $slot) : [];
    }

    public function selectedSlotWindowKnown(array $availability, array $selectedAvailability): bool
    {
        $slotIndex = $selectedAvailability[0] ?? null;
        $slot = $slotIndex !== null ? ($availability[$slotIndex] ?? null) : null;
        $slotTime = trim((string) ($slot['time'] ?? ''));

        return (bool) $slot && (Lead::parseSlotTimes($slotTime) !== null || strcasecmp($slotTime, 'Anytime') === 0);
    }

    protected function formatAvailabilitySlot(array $slot): string
    {
        $date = $slot['date'] ?? null;
        $time = $slot['time'] ?? null;

        $datePart = $date ? Carbon::parse($date)->format('D, M j') : '';

        return trim($datePart.($time ? ' · '.$time : ''));
    }

    public function bookedSlotLabel(array $slot, ?string $selectedExactTime): string
    {
        if (! $selectedExactTime) {
            return $this->formatAvailabilitySlot($slot);
        }

        $date = $slot['date'] ?? null;
        $datePart = $date ? Carbon::parse($date)->format('D, M j') : '';

        return trim($datePart.' · '.Carbon::createFromFormat('H:i', $selectedExactTime)->format('g:i A'));
    }

    public function formatAvailabilityList(array $availability, array $selectedAvailability, ?string $selectedExactTime): string
    {
        if (empty($availability)) {
            return '';
        }

        if (empty($selectedAvailability)) {
            return '{{SELECT Availability}}';
        }

        $slots = collect($selectedAvailability)
            ->map(fn ($i) => $availability[$i] ?? null)
            ->filter()
            ->values();

        if ($slots->count() === 1) {
            $slot = (array) $slots->first();

            if ($selectedExactTime) {
                return $this->bookedSlotLabel($slot, $selectedExactTime);
            }

            if ($this->exactTimeOptions($availability, $selectedAvailability) !== []) {
                return '{{SELECT Time}}';
            }

            return $this->formatAvailabilitySlot($slot);
        }

        return $slots
            ->map(fn ($slot) => '• '.$this->formatAvailabilitySlot((array) $slot))
            ->implode("\n");
    }

    /**
     * Office-side (re)scheduling: add $date as a synthetic "Anytime" slot —
     * mirrors LeadCreate::updatedProposeDate()'s validation and array
     * splicing, minus the Livewire error-bag/property wiring (the
     * controller translates this result into its own 422/response shape).
     *
     * @param  array<int, array<string, mixed>>  $availability
     * @return array{ok: bool, error: ?string, availability: array, selected_availability: array<int>, exact_time_options: array}
     */
    public function proposeDate(array $availability, string $date): array
    {
        $date = trim($date);

        if ($date === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return ['ok' => false, 'error' => 'Enter a valid date.', 'availability' => $availability, 'selected_availability' => [], 'exact_time_options' => []];
        }

        // Drop any previous proposal along with its selection.
        $availability = collect($availability)
            ->reject(fn ($slot) => ! empty(((array) $slot)['office_proposed']))
            ->values()
            ->all();

        $tz = PickTimes::timezone();
        if (Carbon::parse($date, $tz)->lt(Carbon::now($tz)->startOfDay()) || PickTimes::isWeekend($date)) {
            return [
                'ok' => false,
                'error' => PickTimes::isWeekend($date) ? 'Consultations are scheduled Monday through Friday.' : 'That date has passed.',
                'availability' => $availability,
                'selected_availability' => [],
                'exact_time_options' => [],
            ];
        }

        $availability[] = ['date' => $date, 'time' => 'Anytime', 'office_proposed' => true];
        $selectedAvailability = [array_key_last($availability)];

        $exactTimeOptions = $this->exactTimeOptions($availability, $selectedAvailability);

        if ($exactTimeOptions === []) {
            array_pop($availability);

            return [
                'ok' => false,
                'error' => 'No open times that day — pick another day.',
                'availability' => $availability,
                'selected_availability' => [],
                'exact_time_options' => [],
            ];
        }

        return [
            'ok' => true,
            'error' => null,
            'availability' => $availability,
            'selected_availability' => $selectedAvailability,
            'exact_time_options' => $exactTimeOptions,
        ];
    }

    // ---- Consult project resolution (mirrors consultProjects()/etc) ----

    /** @return \Illuminate\Support\Collection<int, Project> */
    public function consultProjects(?Client $client): \Illuminate\Support\Collection
    {
        if (! $client) {
            return collect();
        }

        return $client->projects()
            ->with('latestStatus')
            ->whereDoesntHave('statuses', fn ($q) => $q->where('status_code', 7))
            ->orderByDesc('id')
            ->get()
            ->filter(fn (Project $project) => in_array($project->latestStatus?->title, self::CONSULT_PROJECT_STAGES, true))
            ->values();
    }

    public function consultProjectLabel(Project $project): string
    {
        return $project->project_name.' — '.($project->latestStatus?->title ?? 'No status');
    }

    /** @return array<int, array{id: int, label: string, name: string, stage: string, color: string}> */
    public function consultProjectOptions(?Client $client): array
    {
        return $this->consultProjects($client)
            ->map(fn (Project $project) => [
                'id' => $project->id,
                'label' => $this->consultProjectLabel($project),
                'name' => (string) $project->project_name,
                'stage' => $project->latestStatus?->title ?? 'No status',
                'color' => $project->latestStatus?->badge_color ?? 'zinc',
            ])
            ->values()
            ->all();
    }

    public function resolvedConsultProject(?Client $client, string $projectName): ?Project
    {
        $typed = mb_strtolower(trim($projectName));

        if ($typed === '') {
            return null;
        }

        return $this->consultProjects($client)
            ->first(fn (Project $project) => in_array($typed, [
                mb_strtolower($this->consultProjectLabel($project)),
                mb_strtolower(trim((string) $project->project_name)),
            ], true));
    }

    public function newProjectName(string $projectName): string
    {
        return trim((string) preg_replace('/\s+—\s+[^—]*$/u', '', trim($projectName)));
    }

    public function openProjectForConsult(?Client $client): ?Project
    {
        return $this->consultProjects($client)
            ->first(fn (Project $project) => $project->latestStatus?->title !== 'Cancelled');
    }

    public function needsProjectName(?Client $client, ?string $selectedExactTime, string $projectName): bool
    {
        return $selectedExactTime !== null
            && $client !== null
            && $this->resolvedConsultProject($client, $projectName) === null
            && $this->newProjectName($projectName) === '';
    }

    public function awaitingExactTime(string $emailBody): bool
    {
        return str_contains($emailBody, '{{SELECT');
    }

    /** Why Send Email is blocked right now, or null when it isn't — mirrors LeadCreate::sendBlockedReason(). */
    public function sendBlockedReason(?Client $client, string $emailBody, ?string $selectedExactTime, string $projectName): ?string
    {
        if ($this->awaitingExactTime($emailBody)) {
            return 'Pick the exact consult time first';
        }

        if ($this->needsProjectName($client, $selectedExactTime, $projectName)) {
            return 'Name the project for this consult first';
        }

        return null;
    }

    // ---- The consult already on the calendar (mirrors bookedConsult()) ----

    public function consultTaskTitle(?Client $client, ?Lead $lead, string $fullName): ?string
    {
        if (! $client || ! $lead) {
            return null;
        }

        $vendor = Vendor::find($lead->belongs_to_vendor_id);
        $shortVendorName = data_get($vendor?->options, 'short_name') ?: ($vendor?->name ?? config('app.name'));

        $name = preg_replace('/\s+/', ' ', trim($fullName));
        $parts = $name !== '' ? explode(' ', $name) : [];
        $lastName = count($parts) > 1 ? array_pop($parts) : $name;
        $clientLastNames = $client->last_names ?: $lastName;

        return $shortVendorName.' | '.$clientLastNames.' | Consult';
    }

    /**
     * @return array{label: string, virtual: bool, task_id: int, past: bool}|null
     */
    public function bookedConsult(?Client $client, ?Lead $lead, string $fullName): ?array
    {
        $project = $client?->projects()->first();
        $title = $this->consultTaskTitle($client, $lead, $fullName);

        if (! $project || $title === null) {
            return null;
        }

        $task = Task::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where('project_id', $project->id)
            ->where('type', 'Meet')
            ->where('title', $title)
            ->first();

        if (! $task || ! $task->start_date) {
            return null;
        }

        $date = Carbon::parse($task->start_date)->format('Y-m-d');
        $settings = (array) data_get($task->options, 'time_settings.'.$date, []);

        $label = Carbon::parse($task->start_date)->format('D, M j');
        if (($settings['use_time'] ?? false) && ! empty($settings['start_time'])) {
            $label .= ' · '.Carbon::createFromFormat('H:i', $settings['start_time'])->format('g:i A');
        }

        $tz = PickTimes::timezone();

        return [
            'label' => $label,
            'virtual' => data_get($task->options, 'meeting_location_type') === 'virtual',
            'task_id' => $task->id,
            'past' => Carbon::parse($task->start_date, $tz)->startOfDay()->lt(Carbon::now($tz)->startOfDay()),
        ];
    }

    // ---- Placeholder replacement (mirrors replacePlaceholders()) ----

    public function replacePlaceholders(
        string $text,
        Lead $lead,
        ?Client $client,
        string $fullName,
        ?string $origin,
        ?string $message,
        ?string $address,
        string $from,
        array $availability,
        array $selectedAvailability,
        ?string $selectedExactTime,
        string $consultMeetingType,
    ): string {
        $name = preg_replace('/\s+/', ' ', trim($fullName));
        $parts = $name !== '' ? explode(' ', $name) : [];
        $lastName = count($parts) > 1 ? array_pop($parts) : '';
        $firstNames = $parts ? implode(' ', $parts) : ($lastName !== '' ? '' : $name);

        $clientName = $client?->name ?: $name;
        $clientFirstNames = $client?->first_names ?: $firstNames;
        $clientLastNames = $client?->last_names ?: $lastName;

        $clientFirstName = $client && empty($client->business_name) && $client->users->isNotEmpty()
            ? $clientFirstNames
            : (strtok(trim((string) $clientFirstNames), ' ') ?: $clientFirstNames);

        $vendor = Vendor::find($lead->belongs_to_vendor_id);
        $vendorName = $vendor?->name ?? config('app.name');
        $shortVendorName = data_get($vendor?->options, 'short_name') ?: $vendorName;

        $sender = null;
        if ($from) {
            $sender = User::where('email', $from)->first();
        }
        $sender = $sender ?: auth()->user();
        $senderFirstName = $sender?->first_name ?? '';
        $senderLastName = $sender?->last_name ?? '';

        $availabilityList = $this->formatAvailabilityList($availability, $selectedAvailability, $selectedExactTime);

        $rescheduled = $lead->hasRescheduled();

        $selectedSlotIndex = $selectedAvailability[0] ?? null;
        $officeProposed = $selectedSlotIndex !== null
            && ! empty(((array) ($availability[$selectedSlotIndex] ?? []))['office_proposed']);

        $booked = $this->bookedConsult($client, $lead, $fullName);

        $intro = match (true) {
            $officeProposed && $rescheduled => 'Thank you for your patience while we line up your consultation.',
            $rescheduled => 'Thank you for sending over your new availability! We appreciate you taking the time to reschedule.',
            $booked !== null && ($booked['past'] ?? false) => 'We&rsquo;d love to get your consultation with '.e($vendorName).' back on the calendar.',
            $booked !== null => 'A quick note about your upcoming consultation with '.e($vendorName).'.',
            default => 'Thank you for reaching out to '.e($vendorName).'! We&rsquo;d love to learn more about your project and set up a consultation.',
        };

        $formatLine = $consultMeetingType === 'virtual'
            ? ' This will be a video consultation &mdash; the calendar invite includes the Microsoft Teams link to join.'
            : '';

        $hasUsableAvailability = $this->hasUsableAvailability($availability);
        $slotProblems = $this->slotProblems($availability);

        if ($hasUsableAvailability) {
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
                .'</p><p></p><p>If this time no longer works for you, you can '
                .'<a href="'.e($lead->availabilityUrl()).'">pick new consultation times</a>'
                .' and we&rsquo;ll confirm the new one ASAP.';
        } elseif ($booked !== null && ! ($booked['past'] ?? false)) {
            $timeBlock = 'Your consultation is booked for <strong>'.e($booked['label']).'</strong>.'
                .$formatLine
                .'</p><p></p><p>If that time no longer works for you, you can '
                .'<a href="'.e($lead->availabilityUrl()).'">pick new consultation times</a>'
                .' and we&rsquo;ll confirm the change ASAP.';
        } elseif ($booked !== null) {
            $timeBlock = 'We had your consultation scheduled for <strong>'.e($booked['label']).'</strong>'
                .' &mdash; let&rsquo;s find a new time. Please '
                .'<a href="'.e($lead->availabilityUrl()).'">select new consultation times</a>'
                .' that suit you and we&rsquo;ll confirm ASAP.';
        } else {
            $timeBlock = (in_array('booked', $slotProblems, true)
                    ? 'The times you shared are no longer open on our calendar &mdash; please '
                    : 'Let&rsquo;s find a time that works for you &mdash; please ')
                .'<a href="'.e($lead->availabilityUrl()).'">'
                .($availability === [] ? 'select consultation times' : 'select new consultation times')
                .'</a> that suit you and we&rsquo;ll confirm ASAP.';
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
                $clientFirstName,
                $clientFirstNames,
                $clientLastNames,
                (string) ($message ?? ''),
                (string) ($address ?? ''),
                (string) ($origin ?? ''),
                $availabilityList,
                $intro,
                $timeBlock,
                $vendorName,
                $shortVendorName,
                $senderFirstName,
                $senderLastName,
                $this->scheduleLink($lead),
            ],
            $text
        );
    }

    public function scheduleLink(Lead $lead): string
    {
        return $this->urlShortener->shorten($lead->availabilityUrl());
    }

    // ---- Booking the consult (mirrors bookConsult()) ----

    /**
     * @return bool whether a consult was booked/rebooked by this call
     */
    public function bookConsult(
        Lead $lead,
        ?Client $client,
        string $fullName,
        ?string $address,
        string $consultMeetingType,
        array $availability,
        array $selectedAvailability,
        ?string $selectedExactTime,
        string $projectName,
    ): bool {
        $slotIndex = $selectedAvailability[0] ?? null;
        $slot = $slotIndex !== null ? ($availability[$slotIndex] ?? null) : null;

        if (! $slot || ! ($slot['date'] ?? null) || ! $client) {
            return false;
        }

        $project = $this->resolvedConsultProject($client, $projectName);

        if (! $project) {
            $addressParts = $lead->shortAddressParts();
            preg_match('/\b([A-Z]{2})\b[,\s]*(\d{5})?/', (string) $address, $m);

            $project = Project::create([
                'project_name' => $this->newProjectName($projectName) !== '' ? $this->newProjectName($projectName) : 'Consult',
                'client_id' => $client->id,
                'address' => ($addressParts['street'] ?: $address) ?? '',
                'city' => $addressParts['city'] ?: '',
                'state' => ($m[1] ?? '') ?: (trim((string) $client->state) ?: 'IL'),
                'zip_code' => ($m[2] ?? '') ?: (string) ($client->zip_code ?? ''),
            ]);
        }

        $title = $this->consultTaskTitle($client, $lead, $fullName);

        $date = $slot['date'];

        $startTime = $selectedExactTime
            ?: (($window = Lead::parseSlotTimes((string) ($slot['time'] ?? ''))) ? $window[0] : null);

        $times = $startTime
            ? [$startTime, Carbon::createFromFormat('H:i', $startTime)->addMinutes(self::CONSULT_MINUTES)->format('H:i')]
            : null;

        $timeSettings = $times
            ? ['use_time' => true, 'start_time' => $times[0], 'end_time' => $times[1]]
            : ['use_time' => false];

        $notes = 'Booked from lead email reply — '.$this->bookedSlotLabel((array) $slot, $selectedExactTime).'.';

        $task = Task::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->where('title', $title)
            ->first();

        if ($task) {
            $wasTrashed = $task->trashed();

            $unchanged = ! $wasTrashed
                && optional($task->start_date)->format('Y-m-d') === $date
                && (array) data_get($task->options, 'time_settings.'.$date) == $timeSettings;

            if ($unchanged) {
                return false;
            }

            if ($wasTrashed) {
                $task->restore();
            }

            $options = (array) ($task->options ?? []);
            $options['dates'] = [$date];
            $options['time_settings'] = [$date => $timeSettings];
            $options['meeting_location_type'] = $consultMeetingType;

            if (empty($options['meeting_participants'])) {
                $options['meeting_participants'] = MeetingParticipants::defaults(
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

            data_get($task->options, 'nylas_meet_event.event_id')
                ? UpdateMeetTaskCalendarEvent::dispatch($task->id)
                : CreateMeetTaskCalendarEvent::dispatch($task->id, auth()->id());

            $this->parkProjectInConsult($project, $task);

            return true;
        }

        $task = Task::create([
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
                'meeting_participants' => MeetingParticipants::defaults(
                    $project,
                    [auth()->id()],
                ),
                'meeting_location_type' => $consultMeetingType,
            ],
        ]);

        EmailTracking::withoutGlobalScopes()
            ->where('lead_id', $lead->id)
            ->whereNull('project_id')
            ->update(['project_id' => $project->id]);

        CreateMeetTaskCalendarEvent::dispatch($task->id, auth()->id());

        $this->parkProjectInConsult($project, $task);

        return true;
    }

    protected function parkProjectInConsult(Project $project, Task $task): void
    {
        if ($task->meetHasPassed(PickTimes::timezone())) {
            return;
        }

        $latestCode = ProjectStatus::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->orderByDesc('start_date')
            ->orderByDesc('id')
            ->value('status_code');

        if ((int) $latestCode === 9) {
            return;
        }

        ProjectStatus::create([
            'project_id' => $project->id,
            'belongs_to_vendor_id' => $project->belongs_to_vendor_id,
            'status_code' => 9, // Consult
            'start_date' => today()->format('Y-m-d'),
        ]);
    }

    // ---- The companion text (mirrors textAlongsideEmail()/textForEmail()) ----

    public function textAlongsideEmail(bool $booked, ?Client $client, Lead $lead, string $subject, string $emailBody, string $fullName): ?string
    {
        $numbers = $this->clientTextNumbers($client, $lead);
        if ($numbers === []) {
            return null;
        }

        $thread = $this->threadFor($client, $numbers);

        try {
            if (! $thread) {
                $this->sms->sendNewGroup(
                    $numbers,
                    '',
                    null,
                    $client?->id,
                    auth()->id(),
                    auth()->user()?->vendor?->id ?? $lead->belongs_to_vendor_id,
                );

                return 'Consent text sent — they get the START prompt first; this message went by email only.';
            }

            if ($thread->hasPendingOptIn()) {
                return 'Not texted — they have not replied START yet.';
            }

            $this->sms->sendToThread($thread, $this->textForEmail($booked, $thread, $client, $lead, $subject, $emailBody, $fullName), [], auth()->id());
        } catch (\Throwable $e) {
            Log::warning('Lead email: companion text failed', ['lead_id' => $lead->id, 'thread_id' => $thread?->id, 'error' => $e->getMessage()]);

            return null;
        }

        return $booked ? 'Confirmation texted too.' : 'Texted too.';
    }

    /** @return array<int, string> */
    public function clientTextNumbers(?Client $client, Lead $lead): array
    {
        $tenDigits = function (?string $phone): ?string {
            $digits = preg_replace('/\D/', '', (string) $phone);
            if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
                $digits = substr($digits, 1);
            }

            return strlen($digits) === 10 ? $digits : null;
        };

        $numbers = collect($client?->users ?? [])
            ->map(fn ($user) => $tenDigits($user->cell_phone))
            ->filter();

        if ($numbers->isEmpty()) {
            $numbers = collect([$tenDigits($lead->lead_data['phone'] ?? null)])->filter();
        }

        return $numbers
            ->map(fn (string $digits) => GroupSmsService::formatE164($digits))
            ->unique()
            ->values()
            ->all();
    }

    /** @param  array<int, string>  $numbers  E.164 */
    public function threadFor(?Client $client, array $numbers): ?SmsGroupThread
    {
        if ($client) {
            $threads = SmsGroupThread::query()
                ->accessibleTo(auth()->user())
                ->where('client_id', $client->id)
                ->latest('last_activity_at')
                ->get();

            $covering = $threads->first(fn ($thread) => collect($numbers)->every(
                fn ($number) => in_array($number, (array) $thread->participants, true),
            ));

            if ($covering ?? $threads->first()) {
                return $covering ?? $threads->first();
            }
        }

        $query = SmsGroupThread::query()
            ->accessibleTo(auth()->user())
            ->whereJsonLength('participants', count($numbers));
        foreach ($numbers as $number) {
            $query->whereJsonContains('participants', $number);
        }

        return $query->latest('last_activity_at')->first();
    }

    public function textForEmail(bool $booked, SmsGroupThread $thread, ?Client $client, Lead $lead, string $subject, string $emailBody, string $fullName): string
    {
        $vendor = Vendor::find($lead->belongs_to_vendor_id);
        $contractor = trim((string) (data_get($vendor?->options, 'short_name') ?: $vendor?->name)) ?: config('app.name');
        $names = $client
            ? \App\Services\ConsultScheduleLinkTexter::greetingNames($thread, $client, (string) ($lead->lead_data['name'] ?? ''))
            : (string) strtok(trim((string) ($lead->lead_data['name'] ?? '')), ' ');
        $greeting = 'Hi'.($names !== '' ? " {$names}" : '').",\n\n";

        $consult = $booked ? $this->bookedConsult($client, $lead, $fullName) : null;
        if ($consult) {
            $task = Task::withoutGlobalScopes()->find($consult['task_id']);
            $project = $task?->project()->withoutGlobalScopes()->first();
            $where = trim(implode(', ', array_filter([trim((string) $project?->address), trim((string) $project?->city)])));
            $place = $consult['virtual']
                ? ' as a video call — the calendar invite has the Microsoft Teams link'
                : ($where !== '' ? ' at '.$where : '');

            return $greeting
                ."Your consultation with {$contractor} is confirmed for {$consult['label']}{$place}. "
                ."If this time no longer works for you, you can pick new consultation times here: ".$this->scheduleLink($lead).' and we’ll confirm the new one ASAP.';
        }

        if (str_contains($emailBody, '/lead/times/')) {
            return $greeting."Pick a consultation time with {$contractor} here: ".$this->scheduleLink($lead);
        }

        $subject = trim($subject);

        return $greeting
            ."We just emailed you".($subject !== '' ? " about \"{$subject}\"" : '')
            ." — please check your inbox. If texting is easier, just reply here.";
    }

    /**
     * Recomputes the template-filled subject/body and the picker's
     * exact-time chips/blocking state for the composer's CURRENT selection —
     * mirrors what LeadCreate::rerenderTemplate() plus its recomputed
     * Computed properties give the Livewire page on every interaction
     * (insertAvailabilitySlot/selectExactTime/updatedProjectName/etc), in
     * one call for a discrete HTTP round trip.
     *
     * @param  array{template_id: ?int, from: string, subject: ?string, body: ?string, availability: array, selected_availability: array<int>, selected_exact_time: ?string, project_name: string, consult_meeting_type: string}  $input
     * @return array{subject: string, body: string, exact_time_options: array, selected_slot_window_known: bool, has_usable_availability: bool, consult_project_options: array, needs_project_name: bool, send_blocked_reason: ?string}
     */
    public function preview(Lead $lead, ?Client $client, string $fullName, array $input): array
    {
        $availability = (array) ($input['availability'] ?? []);
        $selectedAvailability = (array) ($input['selected_availability'] ?? []);
        $selectedExactTime = $input['selected_exact_time'] ?? null;
        $consultMeetingType = (string) ($input['consult_meeting_type'] ?? 'in_person');
        $projectName = (string) ($input['project_name'] ?? '');

        $placeholderArgs = [
            $lead, $client, $fullName,
            $lead->origin, $lead->lead_data['message'] ?? null, $lead->lead_data['address'] ?? null,
            (string) ($input['from'] ?? ''),
        ];

        if (! empty($input['template_id'])) {
            $template = EmailTemplate::find($input['template_id']);
            $subject = $template ? $this->replacePlaceholders((string) $template->subject, ...$placeholderArgs, availability: $availability, selectedAvailability: $selectedAvailability, selectedExactTime: $selectedExactTime, consultMeetingType: $consultMeetingType) : (string) ($input['subject'] ?? '');
            $body = $template ? $this->replacePlaceholders((string) $template->body, ...$placeholderArgs, availability: $availability, selectedAvailability: $selectedAvailability, selectedExactTime: $selectedExactTime, consultMeetingType: $consultMeetingType) : (string) ($input['body'] ?? '');
        } else {
            // No template selected: re-render the FREE-TEXT body/subject
            // already on screen, same as rerenderTemplate()'s no-op when
            // selectedTemplateId is empty — any {{placeholder}} left in a
            // hand-written message is left alone.
            $subject = (string) ($input['subject'] ?? '');
            $body = (string) ($input['body'] ?? '');
        }

        return [
            'subject' => $subject,
            'body' => $body,
            'exact_time_options' => $this->exactTimeOptions($availability, $selectedAvailability),
            'selected_slot_window_known' => $this->selectedSlotWindowKnown($availability, $selectedAvailability),
            'has_usable_availability' => $this->hasUsableAvailability($availability),
            'consult_project_options' => $this->consultProjectOptions($client),
            'needs_project_name' => $this->needsProjectName($client, $selectedExactTime, $projectName),
            'send_blocked_reason' => $this->sendBlockedReason($client, $body, $selectedExactTime, $projectName),
        ];
    }

    // ---- Send orchestration (mirrors send_message()/sendConsultInvite()) ----

    /**
     * @param  array{to: list<string>, from: string, subject: string, body: string, selected_template_id: ?int, availability: array, selected_availability: array<int>, selected_exact_time: ?string, project_name: string, consult_meeting_type: string, nylas_message_id: ?string, nylas_references: list<string>}  $input
     * @return array{ok: bool, error: ?string, booked: bool, confirmed: bool, texted: ?string, unbooked: bool, new_status: ?string, recipient_count: int}
     */
    public function send(Lead $lead, ?Client $client, User $sender, string $fullName, array $input): array
    {
        $blocked = $this->sendBlockedReason($client, (string) $input['body'], $input['selected_exact_time'] ?? null, (string) ($input['project_name'] ?? ''));

        if ($blocked !== null) {
            return $this->errorResult($blocked.'.');
        }

        $vendorId = $lead->belongs_to_vendor_id;
        $trackingProvider = (string) config('email_tracking.provider', 'nylas');

        $companyEmailQuery = CompanyEmail::query()
            ->where('email', $input['from'])
            ->where('vendor_id', $vendorId);

        if ($trackingProvider !== 'mailtrap') {
            $companyEmailQuery->whereNotNull('grant_id');
        }

        $companyEmail = $companyEmailQuery->first();

        if (! $companyEmail) {
            return $this->errorResult($trackingProvider === 'mailtrap'
                ? 'The selected sender email is not available.'
                : 'The selected sender email is not connected to Nylas.');
        }

        $templateName = null;
        if (! empty($input['selected_template_id'])) {
            $template = EmailTemplate::find($input['selected_template_id']);
            $templateName = $template?->name;
        }

        SendLeadReplyJob::dispatch(
            leadId: $lead->id,
            companyEmailId: $companyEmail->id,
            userId: $sender->id,
            recipients: $input['to'],
            fromEmail: $input['from'],
            subject: $input['subject'],
            body: $input['body'],
            emailTemplateName: $templateName,
            senderIp: $input['sender_ip'] ?? null,
            inReplyToMessageId: $input['nylas_message_id'] ?? null,
            references: $input['nylas_references'] ?? [],
        );

        $newStatus = null;

        if (($lead->last_status?->title ?? 'New') === 'New') {
            $lead->setStatus('Replied');
            $lead->unsetRelation('last_status');
            $newStatus = 'Replied';
        }

        $availability = (array) ($input['availability'] ?? []);
        $selectedAvailability = (array) ($input['selected_availability'] ?? []);
        $selectedExactTime = $input['selected_exact_time'] ?? null;
        $consultMeetingType = (string) ($input['consult_meeting_type'] ?? 'in_person');
        $projectName = (string) ($input['project_name'] ?? '');

        $booked = $this->bookConsult(
            $lead,
            $client,
            $fullName,
            $lead->lead_data['address'] ?? null,
            $consultMeetingType,
            $availability,
            $selectedAvailability,
            $selectedExactTime,
            $projectName,
        );

        $lead->unsetRelation('last_status');

        $confirmed = $booked || ($selectedExactTime !== null
            && ($consult = $this->bookedConsult($client, $lead, $fullName)) !== null
            && ! ($consult['past'] ?? true));

        $texted = $this->textAlongsideEmail($confirmed, $client, $lead, (string) $input['subject'], (string) $input['body'], $fullName);

        $unbooked = ! $booked && ($selectedAvailability[0] ?? null) !== null && ! $client;

        if ($confirmed && in_array($lead->last_status?->title ?? 'New', ['New', 'Replied'], true)) {
            $lead->setStatus('Won');
            $lead->unsetRelation('last_status');
            $newStatus = 'Won';
        }

        if ($unbooked) {
            Log::warning('Lead consult not booked: no client for the lead', ['lead_id' => $lead->id]);
        }

        return [
            'ok' => true,
            'error' => null,
            'booked' => $booked,
            'confirmed' => $confirmed,
            'texted' => $texted,
            'unbooked' => $unbooked,
            'new_status' => $newStatus,
            'recipient_count' => count($input['to']),
        ];
    }

    /**
     * One click: apply the "Consult" lead template and send — mirrors
     * LeadCreate::sendConsultInvite().
     *
     * @param  array{to: list<string>, from: string, availability: array, selected_availability: array<int>, selected_exact_time: ?string, project_name: string, consult_meeting_type: string, nylas_message_id: ?string, nylas_references: list<string>}  $input
     * @return array{ok: bool, error: ?string, booked: bool, confirmed: bool, texted: ?string, unbooked: bool, new_status: ?string, recipient_count: int}
     */
    public function sendConsultInvite(Lead $lead, ?Client $client, User $sender, string $fullName, array $input): array
    {
        $template = EmailTemplate::query()
            ->where('type', 'lead')
            ->where('name', 'Consult')
            ->first();

        if (! $template) {
            return $this->errorResult('No email template named "Consult" exists.');
        }

        $availability = (array) ($input['availability'] ?? []);
        $selectedAvailability = (array) ($input['selected_availability'] ?? []);
        $selectedExactTime = $input['selected_exact_time'] ?? null;
        $consultMeetingType = (string) ($input['consult_meeting_type'] ?? 'in_person');

        $placeholderArgs = [
            $lead, $client, $fullName,
            $lead->origin, $lead->lead_data['message'] ?? null, $lead->lead_data['address'] ?? null,
            $input['from'],
        ];

        $subject = $this->replacePlaceholders((string) $template->subject, ...$placeholderArgs, availability: $availability, selectedAvailability: $selectedAvailability, selectedExactTime: $selectedExactTime, consultMeetingType: $consultMeetingType);
        $body = $this->replacePlaceholders((string) $template->body, ...$placeholderArgs, availability: $availability, selectedAvailability: $selectedAvailability, selectedExactTime: $selectedExactTime, consultMeetingType: $consultMeetingType);

        return $this->send($lead, $client, $sender, $fullName, $input + [
            'subject' => $subject,
            'body' => $body,
            'selected_template_id' => $template->id,
        ]);
    }

    /**
     * @return array{ok: bool, error: string, booked: bool, confirmed: bool, texted: ?string, unbooked: bool, new_status: ?string, recipient_count: int}
     */
    protected function errorResult(string $message): array
    {
        return [
            'ok' => false,
            'error' => $message,
            'booked' => false,
            'confirmed' => false,
            'texted' => null,
            'unbooked' => false,
            'new_status' => null,
            'recipient_count' => 0,
        ];
    }
}
