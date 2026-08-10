<?php

namespace App\Services;

use App\Models\CompanyEmail;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class MeetTaskCalendarService
{
    /** Estimate — a Meet on such a project is a consult the client books. */
    private const ESTIMATE_STATUS_CODE = 2;

    public function __construct(private readonly NylasService $nylasService)
    {
    }

    public function createMeetEvent(Task $task, ?int $actorUserId = null): void
    {
        if (! config('nylas.meet.enabled', true)) {
            return;
        }

        if ($task->type !== 'Meet') {
            return;
        }

        $task->loadMissing(['project.client.users', 'project.createdByVendor', 'vendor']);

        $grantId = $this->resolveGrantId($task, $actorUserId);
        $organizerEmail = $grantId ? $this->resolveOrganizerEmail($grantId) : null;

        if (! $grantId) {
            Log::channel('nylas')->warning('Skipping Meet calendar event: no grant_id available', [
                'task_id' => $task->id,
                'project_id' => $task->project_id,
            ]);

            return;
        }

        $calendarId = $this->resolveCalendarId($grantId);

        if (! $calendarId) {
            Log::channel('nylas')->warning('Skipping Meet calendar event: no calendar_id available', [
                'task_id' => $task->id,
                'grant_id' => $grantId,
            ]);

            return;
        }

        $recipientEmails = $this->resolveRecipientEmails($task);

        if ($recipientEmails->isEmpty()) {
            Log::channel('nylas')->warning('Skipping Meet calendar event: no recipients resolved', [
                'task_id' => $task->id,
                'project_id' => $task->project_id,
            ]);

            return;
        }

        [$when, $startAt, $endAt, $timezone, $allDay] = $this->resolveDateRange($task);

        $meetConfig = (array) config('nylas.meet', []);
        $conferencingProvider = (string) ($meetConfig['conferencing_provider'] ?? 'Microsoft Teams');

        $isMicrosoft = str_contains($conferencingProvider, 'Microsoft');

        $payload = [
            'calendar_id' => $calendarId,
            'title' => $task->title ?: 'Meet',
            'description' => $this->buildDescription($task, $recipientEmails),
            'location' => $this->resolveMeetingLocation($task),
            'participants' => $recipientEmails
                ->map(fn (string $email) => ['email' => $email])
                ->values()
                ->all(),
            'when' => $when,
            'conferencing' => [
                'provider' => $conferencingProvider,
                'autocreate' => (object) [],
            ],
        ];

        $payload['reminders'] = $isMicrosoft
            ? [
                'use_default' => false,
                'overrides' => [
                    ['reminder_minutes' => 60],
                ],
            ]
            : [
                'use_default' => false,
                'overrides' => [
                    ['reminder_minutes' => 60, 'reminder_method' => 'popup'],
                ],
            ];

        $response = $this->nylasService->createEvent($grantId, $payload);

        if (! ($response['success'] ?? false)) {
            $fallbackPayload = $payload;
            unset($fallbackPayload['conferencing']);

            $fallbackResponse = $this->nylasService->createEvent($grantId, $fallbackPayload);

            if ($fallbackResponse['success'] ?? false) {
                Log::channel('nylas')->warning('Meet calendar event created without Teams conferencing after conferencing request failed', [
                    'task_id' => $task->id,
                    'grant_id' => $grantId,
                    'calendar_id' => $calendarId,
                    'status' => $response['status'] ?? null,
                    'error' => $response['error'] ?? $response['body'] ?? null,
                ]);

                $response = $fallbackResponse;
            }
        }

        if ($response['success'] ?? false) {
            $responseData = (array) ($response['data'] ?? []);
            $eventId = data_get($responseData, 'data.id')
                ?? data_get($responseData, 'id');

            if (is_string($eventId) && $eventId !== '') {
                $this->persistCalendarMetadata($task, [
                    'event_id' => $eventId,
                    'grant_id' => $grantId,
                    'calendar_id' => $calendarId,
                    'organizer_email' => $organizerEmail,
                ]);
            }

            // Archive copy to the company mailbox (crew@) — the same "we have
            // a copy" treatment payment and estimate emails get. A plain
            // email, deliberately NOT a participant: a mailbox must never
            // appear as an attendee on the homeowner's invite.
            $this->sendArchiveCopy($task, $grantId, $payload, 'booked');

            Log::channel('nylas')->info('Meet calendar event created', [
                'task_id' => $task->id,
                'grant_id' => $grantId,
                'organizer_email' => $organizerEmail,
                'calendar_id' => $calendarId,
                'event_id' => $eventId,
                'recipients' => $recipientEmails->values()->all(),
                'location' => $payload['location'] ?? null,
                'start_time' => $startAt->toIso8601String(),
                'end_time' => $endAt->toIso8601String(),
                'all_day' => $allDay,
                'dev_override_recipient' => app()->environment(['local', 'development', 'testing'])
                    ? config('nylas.meet.dev_recipient', 'patryk.szady@live.com')
                    : null,
            ]);

            return;
        }

        if (! ($response['success'] ?? false)) {
            Log::channel('nylas')->error('Meet calendar event creation failed', [
                'task_id' => $task->id,
                'grant_id' => $grantId,
                'organizer_email' => $organizerEmail,
                'calendar_id' => $calendarId,
                'status' => $response['status'] ?? null,
                'error' => $response['error'] ?? $response['body'] ?? null,
            ]);
        }
    }

    public function deleteMeetEventByMeta(int $taskId, array $eventMeta): void
    {
        $eventId = (string) ($eventMeta['event_id'] ?? '');
        $grantId = (string) ($eventMeta['grant_id'] ?? '');
        $calendarId = (string) ($eventMeta['calendar_id'] ?? '');

        $response = $this->nylasService->deleteEvent($grantId, $eventId, $calendarId !== '' ? $calendarId : null);

        if ($response['success'] ?? false) {
            Log::channel('nylas')->info('Meet calendar event deleted', [
                'task_id' => $taskId,
                'grant_id' => $grantId,
                'calendar_id' => $calendarId !== '' ? $calendarId : null,
                'event_id' => $eventId,
            ]);

            return;
        }

        $status = (int) ($response['status'] ?? 0);

        if (in_array($status, [404, 412], true)) {
            return;
        }

        Log::channel('nylas')->error('Meet calendar event deletion failed', [
            'task_id' => $taskId,
            'grant_id' => $grantId,
            'calendar_id' => $calendarId !== '' ? $calendarId : null,
            'event_id' => $eventId,
            'status' => $status,
            'error' => $response['error'] ?? $response['body'] ?? null,
        ]);
    }

    /**
     * Update an existing Meet calendar event with new date/time and details.
     */
    public function updateMeetEvent(Task $task): void
    {
        if (! config('nylas.meet.enabled', true)) {
            return;
        }

        if ($task->type !== 'Meet') {
            return;
        }

        $eventMeta = $this->resolveEventMetadata($task);
        $eventId = (string) ($eventMeta['event_id'] ?? '');
        $grantId = (string) ($eventMeta['grant_id'] ?? '');
        $calendarId = (string) ($eventMeta['calendar_id'] ?? '');

        if ($eventId === '' || $grantId === '' || $calendarId === '') {
            Log::channel('nylas')->warning('Cannot update Meet event: missing metadata', [
                'task_id' => $task->id,
                'event_meta' => $eventMeta,
            ]);

            return;
        }

        $task->loadMissing(['project.client.users', 'project.createdByVendor', 'vendor']);

        $recipientEmails = $this->resolveRecipientEmails($task);
        [$when, $startAt, $endAt, $timezone, $allDay] = $this->resolveDateRange($task);

        $payload = [
            'calendar_id' => $calendarId,
            'title' => $task->title ?: 'Meet',
            'description' => $this->buildDescription($task, $recipientEmails),
            'location' => $this->resolveMeetingLocation($task),
            'participants' => $recipientEmails
                ->map(fn (string $email) => ['email' => $email])
                ->values()
                ->all(),
            'when' => $when,
        ];

        $response = $this->nylasService->updateEvent($grantId, $eventId, $payload);

        if ($response['success'] ?? false) {
            // No archive copy here, deliberately: the booked copy (create)
            // gives crew@ its record, and every date/participant tweak after
            // that was one more "rescheduled" email nobody needed.

            Log::channel('nylas')->info('Meet calendar event updated', [
                'task_id' => $task->id,
                'event_id' => $eventId,
                'grant_id' => $grantId,
                'start_time' => $startAt->toIso8601String(),
                'end_time' => $endAt->toIso8601String(),
                'all_day' => $allDay,
            ]);

            return;
        }

        $status = (int) ($response['status'] ?? 0);

        // If event no longer exists, create a new one instead
        if (in_array($status, [404, 410], true)) {
            Log::channel('nylas')->warning('Meet event not found for update, creating new event', [
                'task_id' => $task->id,
                'event_id' => $eventId,
            ]);
            $this->createMeetEvent($task);

            return;
        }

        Log::channel('nylas')->error('Meet calendar event update failed', [
            'task_id' => $task->id,
            'event_id' => $eventId,
            'grant_id' => $grantId,
            'status' => $status,
            'error' => $response['error'] ?? $response['body'] ?? null,
        ]);
    }

    /**
     * A plain-email copy of the invite to the owning company's mailbox
     * (crew@) so the consult booking is archived like payment and estimate
     * emails are. Never a participant — an archive, not an attendee. Failure
     * is logged and swallowed: the invite itself already succeeded.
     */
    private function sendArchiveCopy(Task $task, string $grantId, array $payload, string $verb): void
    {
        $archiveEmail = trim((string) ($this->resolveSignatureVendor($task)?->business_email ?? ''));

        if ($archiveEmail === '') {
            return;
        }

        $body = nl2br((string) ($payload['description'] ?? ''));
        $location = trim((string) ($payload['location'] ?? ''));

        $response = $this->nylasService->sendEmail($grantId, [
            'to' => [['email' => $archiveEmail]],
            'subject' => 'Consult '.$verb.': '.($payload['title'] ?? 'Meet'),
            'body' => '<p>Calendar invite '.$verb.' for this consult.</p>'
                .($location !== '' ? '<p>Location: '.e($location).'</p>' : '')
                .'<hr><p>'.$body.'</p>',
        ]);

        if (! ($response['success'] ?? false)) {
            Log::channel('nylas')->warning('Meet archive copy to company mailbox failed', [
                'task_id' => $task->id,
                'archive_email' => $archiveEmail,
            ]);
        }
    }

    private function resolveGrantId(Task $task, ?int $actorUserId = null): ?string
    {
        $vendorId = $task->project?->belongs_to_vendor_id;

        if (! $vendorId) {
            return null;
        }

        // Use the first selected team member as the organizer, not the person creating the event
        $firstTeamMemberId = collect($task->user_ids ?? [])
            ->map(fn ($id) => is_numeric($id) ? (int) $id : null)
            ->filter(fn (?int $id) => is_int($id) && $id > 0)
            ->first();

        $resolvedUserId = $firstTeamMemberId
            ?? (is_int($actorUserId) && $actorUserId > 0 ? $actorUserId : null)
            ?? (is_numeric($task->created_by_user_id) ? (int) $task->created_by_user_id : null);

        if (is_int($resolvedUserId) && $resolvedUserId > 0) {
            $actor = User::query()->find($resolvedUserId);
            $actorEmail = strtolower(trim((string) ($actor?->email ?? '')));

            if ($actorEmail !== '') {
                $actorCompanyEmail = CompanyEmail::withoutGlobalScopes()
                    ->where('vendor_id', $vendorId)
                    ->whereNotNull('grant_id')
                    ->whereRaw('LOWER(email) = ?', [$actorEmail])
                    ->orderBy('id')
                    ->first();

                if (is_string($actorCompanyEmail?->grant_id) && $actorCompanyEmail->grant_id !== '') {
                    return $actorCompanyEmail->grant_id;
                }
            }
        }

        $companyEmail = CompanyEmail::withoutGlobalScopes()
            ->where('vendor_id', $vendorId)
            ->whereNotNull('grant_id')
            ->orderBy('id')
            ->first();

        return $companyEmail?->grant_id;
    }

    private function resolveOrganizerEmail(string $grantId): ?string
    {
        $companyEmail = CompanyEmail::withoutGlobalScopes()
            ->where('grant_id', $grantId)
            ->first();

        return is_string($companyEmail?->email) && $companyEmail->email !== ''
            ? $companyEmail->email
            : null;
    }

    private function resolveCalendarId(string $grantId): ?string
    {
        $calendarsResponse = $this->nylasService->getCalendars($grantId);

        if (! ($calendarsResponse['success'] ?? false)) {
            return null;
        }

        $calendars = collect($calendarsResponse['data']['data'] ?? $calendarsResponse['data'] ?? []);

        if ($calendars->isEmpty()) {
            return null;
        }

        $primary = $calendars->first(fn (array $calendar) => (bool) ($calendar['is_primary'] ?? false));

        return $primary['id'] ?? $calendars->first()['id'] ?? null;
    }

    private function resolveRecipientEmails(Task $task): Collection
    {
        if (app()->environment(['local', 'development', 'testing'])) {
            $devRecipient = trim((string) config('nylas.meet.dev_recipient', 'patryk.szady@live.com'));

            return collect([$devRecipient])->filter();
        }

        $meetingParticipants = collect((array) ($task->options->meeting_participants ?? []))
            ->filter(fn ($email) => is_string($email) && trim($email) !== '')
            ->map(fn (string $email) => strtolower(trim($email)))
            ->unique()
            ->values();

        // The task's Participants list IS the guest list — whoever is on it,
        // and nobody else. Every company mailbox on the vendor used to be
        // merged in on top, so a second company address landed on invites it
        // was never added to. That merge is now only what a task with an empty
        // list falls back to.
        if ($meetingParticipants->isNotEmpty()) {
            return $meetingParticipants;
        }

        return $this->resolveOwnerAndVendorContactEmails($task);
    }

    private function resolveDateRange(Task $task): array
    {
        $timezone = (string) ($task->project?->createdByVendor?->timezone ?: config('app.timezone'));
        $startDate = $task->start_date?->copy() ?: now($timezone);
        $dateKey = $startDate->format('Y-m-d');

        $daySettings = (array) data_get($task->options, "time_settings.$dateKey", []);
        $usesTime = (bool) ($daySettings['use_time'] ?? false);
        $startTime = is_string($daySettings['start_time'] ?? null)
            ? trim((string) $daySettings['start_time'])
            : null;
        $endTime = is_string($daySettings['end_time'] ?? null)
            ? trim((string) $daySettings['end_time'])
            : null;

        if ($usesTime && is_string($startTime) && $startTime !== '') {
            $startAt = Carbon::parse("{$dateKey} {$startTime}", $timezone);
            $endAt = is_string($endTime) && $endTime !== ''
                ? Carbon::parse("{$dateKey} {$endTime}", $timezone)
                : $startAt->copy()->addHour();

            if ($endAt->lessThanOrEqualTo($startAt)) {
                $endAt = $startAt->copy()->addHour();
            }

            return [[
                'start_time' => $startAt->timestamp,
                'end_time' => $endAt->timestamp,
                'start_timezone' => $timezone,
                'end_timezone' => $timezone,
            ], $startAt, $endAt, $timezone, false];
        }

        $allDayDate = $startDate->copy()->setTimezone($timezone)->format('Y-m-d');
        $startAt = Carbon::parse("{$allDayDate} 00:00", $timezone);
        $endAt = Carbon::parse("{$allDayDate} 23:59", $timezone);

        return [[
            'date' => $allDayDate,
        ], $startAt, $endAt, $timezone, true];
    }

    private function resolveOwnerAndVendorContactEmails(Task $task): Collection
    {
        $emails = collect();

        $addEmail = static function ($value) use (&$emails): void {
            $email = strtolower(trim((string) $value));
            if ($email !== '') {
                $emails->push($email);
            }
        };

        // Direct contact for the selected vendor (the sub being scheduled).
        $selectedVendor = $task->vendor;
        if ($selectedVendor) {
            $addEmail($selectedVendor->email ?? null);
            $addEmail($selectedVendor->business_email ?? null);
        }

        // Company mailboxes for the owning company and the selected vendor.
        // The owning company's direct business email is intentionally excluded.
        $vendorIds = collect([
            $task->project?->belongs_to_vendor_id,
            $task->belongs_to_vendor_id,
            $task->vendor_id,
        ])
            ->filter(fn ($id) => is_numeric($id) && (int) $id > 0)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($vendorIds->isNotEmpty()) {
            $emails = $emails->merge(
                CompanyEmail::withoutGlobalScopes()
                    ->whereIn('vendor_id', $vendorIds->all())
                    ->whereNotNull('email')
                    ->pluck('email')
            );
        }

        $ownerBusinessEmails = collect([
            $task->project?->createdByVendor?->email ?? null,
            $task->project?->createdByVendor?->business_email ?? null,
            $task->owner?->email ?? null,
            $task->owner?->business_email ?? null,
        ])
            ->filter(fn ($email) => is_string($email) && trim($email) !== '')
            ->map(fn (string $email) => strtolower(trim($email)))
            ->all();

        return $emails
            ->filter(fn (?string $email) => is_string($email) && trim($email) !== '')
            ->map(fn (string $email) => strtolower(trim($email)))
            ->reject(fn (string $email) => in_array($email, $ownerBusinessEmails, true))
            ->unique()
            ->values();
    }

    private function buildDescription(Task $task, Collection $recipientEmails): string
    {
        $projectName = $task->project?->project_name;
        $clientName = $task->project?->client?->name;
        $taskCreator = $task->created_by_user_id ? User::find($task->created_by_user_id) : null;
        $creatorDisplayName = trim((string) ($taskCreator?->first_name ?? $taskCreator?->email ?? 'Team'));

        $vendor = $this->resolveSignatureVendor($task);
        $vendorBusinessPhone = trim((string) ($vendor?->business_phone ?? ''));
        $vendorShortName = trim((string) ($vendor?->short_name ?? ''));
        $vendorBusinessName = trim((string) ($vendor?->business_name ?? ''));
        $vendorBusinessWebsite = trim((string) ($vendor?->business_website ?? ''));
        $vendorHeaderName = $vendorShortName !== '' ? $vendorShortName : $vendorBusinessName;
        $vendorFooterName = $vendorShortName !== '' ? $vendorShortName : $vendorBusinessName;

        $meetingLocationType = $task->options->meeting_location_type ?? 'in_person';
        $meetingTypeLabel = $meetingLocationType === 'in_person' ? 'ONSITE' : 'VIRTUAL';

        $headerLabel = $vendorHeaderName !== '' ? "{$vendorHeaderName} ({$meetingTypeLabel}) Meeting" : '(' . $meetingTypeLabel . ') Meeting';
        if ($vendorBusinessWebsite !== '' && $vendorHeaderName !== '') {
            $headerLabel = "<a href=\"{$vendorBusinessWebsite}\">{$vendorHeaderName}</a> ({$meetingTypeLabel}) Meeting";
        }

        $lines = [
            $headerLabel,
            $projectName ? "Project: {$projectName}" : null,
            $clientName ? "Client: {$clientName}" : null,
            $task->notes ? "Notes: {$task->notes}" : null,
        ];

        $clientPhones = $this->resolveClientPhones($task);
        if ($clientPhones->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Client Contact:';
            foreach ($clientPhones as $entry) {
                $lines[] = "  {$entry}";
            }
        }

        if ($recipientEmails->isNotEmpty()) {
            $namesByEmail = $this->resolveAttendeeNames($recipientEmails);
            $lines[] = '';
            $lines[] = 'Attendees:';
            foreach ($recipientEmails as $email) {
                $name = $namesByEmail[$email] ?? null;
                $lines[] = $name ? "{$name} ({$email})" : $email;
            }
        }

        $lines[] = '';
        $lines[] = $this->rescheduleLine($task);
        $lines[] = '';
        $lines[] = 'Thank you,';
        $lines[] = $vendorBusinessPhone !== ''
            ? "{$creatorDisplayName} | {$vendorBusinessPhone}"
            : $creatorDisplayName;
        if ($vendorFooterName !== '') {
            $footerLabel = $vendorBusinessWebsite !== ''
                ? "<a href=\"{$vendorBusinessWebsite}\">{$vendorFooterName}</a>"
                : $vendorFooterName;
            $lines[] = $footerLabel;
        }

        return trim(implode("\n", array_filter($lines, fn ($line) => $line !== null)));
    }

    /**
     * "Reschedule here: <link>" instead of asking people to reach out — an
     * invite is where someone realises the time doesn't work, so the way to
     * change it belongs in the invite.
     *
     * Two kinds of Meet get two different links:
     *   - a consult on an Estimate project → the LEAD's pick-times page (the
     *     same page the consult email and the SMS invite link to; it applies
     *     the shorter reschedule notice once times were already given);
     *   - anything else → the project's client schedule page.
     * With neither available the original ask-us wording stands.
     */
    private function rescheduleLine(Task $task): string
    {
        $url = $this->rescheduleUrl($task);

        if ($url === null) {
            return 'Should anything change, please reach out to reschedule.';
        }

        // The label IS the link — a bare URL pasted after it reads as clutter
        // in the calendar body.
        return "Need a different time? <a href=\"{$url}\">Reschedule here</a>";
    }

    private function rescheduleUrl(Task $task): ?string
    {
        $shortener = app(\App\Services\UrlShortener::class);

        if ($lead = $this->resolveLead($task)) {
            return $shortener->shorten($lead->availabilityUrl());
        }

        $project = $task->project;

        if (! $project) {
            return null;
        }

        $baseUrl = config('app.dev_webhook_url') ?: rtrim((string) config('app.url'), '/');

        return $shortener->shorten("{$baseUrl}/s/{$project->getOrCreateScheduleToken()}");
    }

    /**
     * The lead behind this consult, if this Meet is one: an unscheduled-or-not
     * Meet on a project still at Estimate, whose client came from a lead.
     */
    private function resolveLead(Task $task): ?\App\Models\Lead
    {
        if ((int) ($task->project?->latestStatus?->status_code ?? 0) !== self::ESTIMATE_STATUS_CODE) {
            return null;
        }

        $clientId = $task->project?->client_id;

        if (! $clientId) {
            return null;
        }

        $userIds = \App\Models\Client::withoutGlobalScopes()
            ->find($clientId)?->users()->withoutGlobalScopes()->pluck('users.id') ?? collect();

        if ($userIds->isEmpty()) {
            return null;
        }

        return \App\Models\Lead::withoutGlobalScopes()
            ->whereIn('user_id', $userIds)
            ->latest('id')
            ->first();
    }

    private function resolveSignatureVendor(Task $task): ?Vendor
    {
        $vendorId = $task->belongs_to_vendor_id
            ?? $task->project?->belongs_to_vendor_id
            ?? null;

        if (is_numeric($vendorId) && (int) $vendorId > 0) {
            return Vendor::query()->find((int) $vendorId);
        }

        if ($task->relationLoaded('owner') && $task->owner) {
            return $task->owner;
        }

        return $task->project?->createdByVendor
            ?? $task->vendor;
    }

    private function resolveClientPhones(Task $task): Collection
    {
        $entries = collect();

        $client = $task->project?->client;

        if (! $client) {
            return $entries;
        }

        $homePhone = trim((string) ($client->home_phone ?? ''));
        if ($homePhone !== '') {
            $entries->push("{$client->business_name} - {$homePhone}");
        }

        foreach ($client->users ?? [] as $user) {
            $cellPhone = trim((string) ($user->cell_phone ?? ''));
            if ($cellPhone !== '') {
                $name = trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''));
                $label = $name !== '' ? $name : $user->email;
                $entries->push("{$label} - {$cellPhone}");
            }
        }

        return $entries;
    }

    /**
     * The event's location line. A virtual meet's venue is the call itself —
     * putting the jobsite address there sends somebody driving to a Teams
     * meeting. The join link is attached by Nylas's autocreated conferencing.
     */
    private function resolveMeetingLocation(Task $task): ?string
    {
        if (($task->options->meeting_location_type ?? 'in_person') !== 'in_person') {
            return 'Microsoft Teams';
        }

        return $this->resolveProjectLocation($task);
    }

    private function resolveProjectLocation(Task $task): ?string
    {
        $project = $task->project;

        if (! $project) {
            return null;
        }

        $fullAddress = trim((string) ($project->full_address ?? ''));
        $fullAddress = preg_replace('/<br\s*\/?>/i', ', ', $fullAddress) ?? $fullAddress;
        $fullAddress = trim(strip_tags($fullAddress));
        $fullAddress = preg_replace('/\s+/', ' ', $fullAddress) ?? $fullAddress;

        if ($fullAddress !== '') {
            return $fullAddress;
        }

        $fallback = trim(implode(', ', array_filter([
            trim((string) ($project->address ?? '')),
            trim((string) ($project->city ?? '')),
            trim((string) ($project->state ?? '')),
            trim((string) ($project->zip_code ?? '')),
        ])));

        return $fallback !== '' ? $fallback : null;
    }

    private function persistCalendarMetadata(Task $task, array $metadata): void
    {
        $options = (array) ($task->options ?? []);
        $options['nylas_meet_event'] = $metadata;

        $task->updateQuietly([
            'options' => $options,
        ]);
    }

    private function resolveEventMetadata(Task $task): array
    {
        $meta = data_get($task->options, 'nylas_meet_event', []);

        if (is_object($meta)) {
            $meta = (array) $meta;
        }

        return is_array($meta) ? $meta : [];
    }

    /**
     * Resolve full names for attendee emails by looking up the users table.
     *
     * @return array<string, string>  email => full name
     */
    private function resolveAttendeeNames(Collection $emails): array
    {
        if ($emails->isEmpty()) {
            return [];
        }

        return User::query()
            ->whereIn('email', $emails->all())
            ->get(['email', 'first_name', 'last_name'])
            ->mapWithKeys(function (User $user): array {
                $name = trim($user->full_name);
                $email = strtolower(trim((string) $user->email));

                return $name !== '' ? [$email => $name] : [];
            })
            ->all();
    }
}
