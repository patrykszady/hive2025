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

        [$startAt, $endAt, $timezone] = $this->resolveDateRange($task);

        $meetConfig = (array) config('nylas.meet', []);
        $conferencingProvider = (string) ($meetConfig['conferencing_provider'] ?? 'Microsoft Teams');

        $payload = [
            'calendar_id' => $calendarId,
            'title' => $task->title ?: 'Meet',
            'description' => $this->buildDescription($task, $recipientEmails),
            'location' => $this->resolveProjectLocation($task),
            'conferencing' => [
                'provider' => $conferencingProvider,
                'autocreate' => (object) [],
            ],
            'participants' => $recipientEmails
                ->map(fn (string $email) => ['email' => $email])
                ->values()
                ->all(),
            'when' => [
                'start_time' => $startAt->timestamp,
                'end_time' => $endAt->timestamp,
                'start_timezone' => $timezone,
                'end_timezone' => $timezone,
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

    private function resolveGrantId(Task $task, ?int $actorUserId = null): ?string
    {
        $vendorId = $task->project?->belongs_to_vendor_id;
        $resolvedUserId = is_int($actorUserId) && $actorUserId > 0
            ? $actorUserId
            : (is_numeric($task->created_by_user_id) ? (int) $task->created_by_user_id : null);

        if (! $vendorId) {
            return null;
        }

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

        $selectedTeamMemberIds = collect($task->user_ids ?? [])
            ->map(fn ($id) => is_numeric($id) ? (int) $id : null)
            ->filter(fn (?int $id) => is_int($id) && $id > 0)
            ->unique()
            ->values();

        $selectedTeamMemberEmails = $selectedTeamMemberIds->isEmpty()
            ? collect()
            : User::query()
                ->whereIn('id', $selectedTeamMemberIds->all())
                ->pluck('email');

        $clientUserEmails = collect($task->project?->client?->users ?? [])->pluck('email');

        return $selectedTeamMemberEmails
            ->merge($clientUserEmails)
            ->filter(fn (?string $email) => is_string($email) && $email !== '')
            ->map(fn (string $email) => strtolower(trim($email)))
            ->unique()
            ->values();
    }

    private function resolveDateRange(Task $task): array
    {
        $timezone = (string) ($task->project?->createdByVendor?->timezone ?: config('app.timezone'));
        $startDate = $task->start_date?->copy() ?: now($timezone);
        $dateKey = $startDate->format('Y-m-d');

        $daySettings = (array) data_get($task->options, "time_settings.$dateKey", []);
        $startTime = $daySettings['start_time'] ?? null;
        $endTime = $daySettings['end_time'] ?? null;

        if (is_string($startTime) && $startTime !== '') {
            $startAt = Carbon::parse("{$dateKey} {$startTime}", $timezone);
            $endAt = is_string($endTime) && $endTime !== ''
                ? Carbon::parse("{$dateKey} {$endTime}", $timezone)
                : $startAt->copy()->addHour();
        } else {
            $startAt = Carbon::parse("{$dateKey} 09:00", $timezone);
            $endAt = Carbon::parse("{$dateKey} 10:00", $timezone);
        }

        if ($endAt->lessThanOrEqualTo($startAt)) {
            $endAt = $startAt->copy()->addHour();
        }

        return [$startAt, $endAt, $timezone];
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

        $headerLabel = $vendorHeaderName !== '' ? "{$vendorHeaderName} Meeting" : 'Meeting';
        if ($vendorBusinessWebsite !== '' && $vendorHeaderName !== '') {
            $headerLabel = "<a href=\"{$vendorBusinessWebsite}\">{$vendorHeaderName}</a> Meeting";
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
            $lines[] = '';
            $lines[] = 'Attendees:';
            foreach ($recipientEmails as $email) {
                $lines[] = "  {$email}";
            }
        }

        $lines[] = '';
        $lines[] = 'If any changes, please contact us to let us know.';
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
}
