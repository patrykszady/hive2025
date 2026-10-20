<?php

namespace App\Services\ServiceCalls;

use App\Jobs\SendServiceCallScheduleEmail;
use App\Jobs\TextServiceCallScheduleLink;
use App\Models\AppNotification;
use App\Models\Client;
use App\Models\CompanyEmail;
use App\Models\Project;
use App\Models\ProjectStatus;
use App\Models\ServiceCallEmail;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use App\Services\CrewLeadEmailService;
use App\Services\SmsScheduleService;
use App\Services\Tasks\TaskWriter;
use App\Support\AdminAlerts;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Turns a client's email about a problem on finished work into a service
 * call: the project moves to Service Call, a pending task is created for the
 * request, the office gets an in-app alert, and — only when
 * service_calls.email_intake.contact_client is on — the client is emailed
 * and texted the schedule page (the same /s/{token} page whose
 * service-window picker they already use). When the client then shares times, the
 * existing ScheduleIndex flow tells the team to book the visit.
 *
 * Every mail path calls consider(): Hive's personal-inbox sweep and Nylas
 * webhook, the crew@ reader, and the leads API when gs.construction pushes
 * an email it read first. Decisions are keyed by the sha1 of the RFC
 * Message-ID — the identity both readers already share — so one email is
 * judged and answered once, whichever path sees it first.
 */
class ServiceCallEmailIntake
{
    public const SERVICE_CALL_STATUS_CODE = 8;

    public function __construct(
        protected ServiceCallRequestClassifier $classifier,
        protected TaskWriter $tasks,
    ) {
    }

    /**
     * Judge one inbound email. Returns the decision row, or null when the
     * email is not a known client's about an eligible project (nothing to
     * judge), the intake is off, or the model could not be asked (the next
     * read tries again).
     *
     * @param  array{message_key: string, from_email: string, from_name?: ?string, to?: list<string>, cc?: list<string>, subject?: ?string, body?: ?string, nylas_message_id?: ?string, thread_id?: ?string, mailbox?: ?string, rfc_message_id?: ?string, received_at?: ?\DateTimeInterface, vendor_id?: ?int}  $email
     */
    public function consider(array $email): ?ServiceCallEmail
    {
        if (! config('service_calls.email_intake.enabled')) {
            return null;
        }

        $key = trim((string) ($email['message_key'] ?? ''));
        $fromEmail = mb_strtolower(trim((string) ($email['from_email'] ?? '')));

        if ($key === '' || $fromEmail === '') {
            return null;
        }

        if ($existing = ServiceCallEmail::query()->where('message_key', $key)->first()) {
            return $existing;
        }

        $receivedAt = isset($email['received_at']) ? Carbon::instance($email['received_at']) : now();

        if ($receivedAt->lt(now()->subDays((int) config('service_calls.email_intake.max_age_days', 3)))) {
            return null;
        }

        if ($this->isOurAddress($fromEmail)) {
            return null;
        }

        $vendorId = $email['vendor_id'] ?? $this->vendorIdForMailbox($email['mailbox'] ?? null);
        [$sender, $clientIds, $coRecipients] = $this->clientContext($fromEmail, $email);

        if (! $sender || $clientIds->isEmpty()) {
            return null;
        }

        $projects = $this->eligibleProjects($clientIds, $vendorId);

        if ($projects->isEmpty()) {
            return null;
        }

        try {
            return Cache::lock("service-call-email:{$key}", 120)->block(30, fn () => $this->decide(
                $key, $email, $fromEmail, $receivedAt, $sender, $coRecipients, $projects,
            ));
        } catch (LockTimeoutException) {
            return null;
        }
    }

    /** The client schedule page for a project — the same /s/{token} link the Messages composer sends. */
    public static function scheduleUrl(Project $project): string
    {
        $baseUrl = config('app.dev_webhook_url') ?: rtrim((string) config('app.url'), '/');

        return "{$baseUrl}/s/{$project->getOrCreateScheduleToken()}";
    }

    /** The decision already made for this message, if it opened a service call. */
    public function openedFor(string $messageKey): ?ServiceCallEmail
    {
        return ServiceCallEmail::query()
            ->where('message_key', $messageKey)
            ->where('outcome', ServiceCallEmail::OUTCOME_OPENED)
            ->first();
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @param  list<string>  $coRecipients
     */
    protected function decide(string $key, array $email, string $fromEmail, Carbon $receivedAt, User $sender, array $coRecipients, Collection $projects): ?ServiceCallEmail
    {
        if ($existing = ServiceCallEmail::query()->where('message_key', $key)->first()) {
            return $existing;
        }

        $base = [
            'message_key' => $key,
            'nylas_message_id' => $email['nylas_message_id'] ?? null,
            'thread_id' => $email['thread_id'] ?? null,
            'mailbox' => isset($email['mailbox']) ? mb_strtolower((string) $email['mailbox']) : null,
            'from_email' => $fromEmail,
            'subject' => Str::limit((string) ($email['subject'] ?? ''), 500, ''),
            'user_id' => $sender->id,
            'client_id' => $projects->first()->client_id,
            'received_at' => $receivedAt,
        ];

        // A reply in a thread that already opened a call ("Thursday works")
        // is part of that call, not a new one.
        if (! empty($email['thread_id']) && ServiceCallEmail::query()
            ->where('thread_id', $email['thread_id'])
            ->where('outcome', ServiceCallEmail::OUTCOME_OPENED)
            ->exists()) {
            return ServiceCallEmail::create($base + ['outcome' => ServiceCallEmail::OUTCOME_FOLLOW_UP]);
        }

        $verdict = $this->classifier->classify(
            $fromEmail,
            $email['from_name'] ?? null,
            (string) ($email['subject'] ?? ''),
            (string) ($email['body'] ?? ''),
            $projects,
        );

        if ($verdict === null) {
            return null;
        }

        $project = $projects->firstWhere('id', $verdict['project_id']);
        $decided = $base + [
            'confidence' => $verdict['confidence'],
            'reason' => $verdict['reason'],
            'verdict' => $verdict + ['reply' => [
                'rfc_message_id' => $email['rfc_message_id'] ?? null,
                'subject' => (string) ($email['subject'] ?? ''),
                'cc' => $coRecipients,
            ]],
        ];

        if (! $verdict['is_service_request']
            || $verdict['confidence'] < (float) config('service_calls.email_intake.min_confidence', 0.8)
            || ! $project
            || ! $verdict['task_title']) {
            return ServiceCallEmail::create($decided + ['outcome' => ServiceCallEmail::OUTCOME_NOT_SERVICE]);
        }

        $decided['client_id'] = $project->client_id;
        $decided['project_id'] = $project->id;

        // Someone moved the project to Service Call after this email arrived:
        // the team already has it, and the client must not hear twice.
        $latest = $project->latestStatus;
        if ((int) $latest?->status_code === self::SERVICE_CALL_STATUS_CODE && $latest->created_at?->gte($receivedAt)) {
            return ServiceCallEmail::create($decided + ['outcome' => ServiceCallEmail::OUTCOME_ALREADY_HANDLED]);
        }

        $actor = $this->actorFor($decided['mailbox'], $project);

        if (! $actor) {
            Log::channel('nylas')->warning('Service call intake: no team member to act as', ['project_id' => $project->id]);

            return null;
        }

        $row = DB::transaction(function () use ($decided, $project, $verdict, $actor, $sender, $email, $receivedAt): ServiceCallEmail {
            $task = $this->actingAs($actor, function () use ($project, $verdict, $sender, $email, $receivedAt): Task {
                if ((int) $project->latestStatus?->status_code !== self::SERVICE_CALL_STATUS_CODE) {
                    ProjectStatus::create([
                        'project_id' => $project->id,
                        'belongs_to_vendor_id' => $project->belongs_to_vendor_id,
                        'status_code' => self::SERVICE_CALL_STATUS_CODE,
                        'start_date' => today()->format('Y-m-d'),
                    ]);
                }

                return $this->tasks->store([
                    'title' => $verdict['task_title'],
                    'project_id' => $project->id,
                    'type' => 'Task',
                    'notes' => $this->taskNotes($sender, $email, $receivedAt),
                ]);
            });

            return ServiceCallEmail::create($decided + [
                'outcome' => ServiceCallEmail::OUTCOME_OPENED,
                'task_id' => $task->id,
                'actor_user_id' => $actor->id,
            ]);
        });

        $contactClient = (bool) config('service_calls.email_intake.contact_client', false);

        if ($contactClient) {
            SendServiceCallScheduleEmail::dispatch($row->id)->afterCommit();

            $sms = app(SmsScheduleService::class);
            TextServiceCallScheduleLink::dispatch($row->id)
                ->delay($sms->isWithinBusinessHours() ? now() : $sms->getNextBusinessHoursStart())
                ->afterCommit();
        }

        $this->notifyTeam($row, $project, $verdict['task_title'], $contactClient);

        Log::channel('nylas')->info('Service call intake: opened a service call from an email', [
            'project_id' => $project->id,
            'task_id' => $row->task_id,
            'confidence' => $verdict['confidence'],
        ]);

        return $row;
    }

    /**
     * Who wrote, which clients they belong to, and the other people on the
     * same client who were on the message (they get the reply too). Someone
     * writing from an address we don't know, with a client user in CC, is
     * that client.
     *
     * @return array{0: ?User, 1: Collection<int, int>, 2: list<string>}
     */
    protected function clientContext(string $fromEmail, array $email): array
    {
        $others = collect(array_merge((array) ($email['to'] ?? []), (array) ($email['cc'] ?? [])))
            ->map(fn ($address) => mb_strtolower(trim((string) $address)))
            ->filter(fn (string $address) => $address !== '' && $address !== $fromEmail && ! $this->isOurAddress($address))
            ->unique()
            ->values();

        $usersByEmail = User::withoutGlobalScopes()
            ->whereIn(DB::raw('LOWER(email)'), $others->prepend($fromEmail)->all())
            ->get()
            ->keyBy(fn (User $user) => mb_strtolower((string) $user->email));

        $sender = $usersByEmail->get($fromEmail)
            ?? $others->map(fn (string $address) => $usersByEmail->get($address))->filter()->first();

        if (! $sender) {
            return [null, collect(), []];
        }

        $clientIds = $sender->clients()->withoutGlobalScopes()->pluck('clients.id')->map(fn ($id) => (int) $id);

        $coRecipients = $others
            ->filter(function (string $address) use ($usersByEmail, $clientIds, $sender): bool {
                $user = $usersByEmail->get($address);

                return $user && $user->id !== $sender->id
                    && $user->clients()->withoutGlobalScopes()->whereIn('clients.id', $clientIds)->exists();
            })
            ->values()
            ->all();

        return [$sender, $clientIds, $coRecipients];
    }

    /**
     * @param  Collection<int, int>  $clientIds
     * @return Collection<int, Project>
     */
    protected function eligibleProjects(Collection $clientIds, ?int $vendorId): Collection
    {
        $codes = array_map('intval', (array) config('service_calls.email_intake.eligible_status_codes', [7, 8]));

        return Project::withoutGlobalScopes()
            ->whereIn('client_id', $clientIds)
            ->when($vendorId, fn ($query) => $query->where('belongs_to_vendor_id', $vendorId))
            ->with(['latestStatus' => fn ($query) => $query->withoutGlobalScopes()])
            ->orderByDesc('id')
            ->get()
            ->filter(fn (Project $project) => in_array((int) $project->latestStatus?->status_code, $codes, true))
            ->values();
    }

    /** The company a mailbox belongs to: a connected inbox, or a vendor's business address (crew@). */
    protected function vendorIdForMailbox(?string $mailbox): ?int
    {
        $mailbox = mb_strtolower(trim((string) $mailbox));

        if ($mailbox === '') {
            return null;
        }

        $vendorId = CompanyEmail::withoutGlobalScopes()->whereRaw('LOWER(email) = ?', [$mailbox])->value('vendor_id')
            ?? Vendor::withoutGlobalScopes()->whereRaw('LOWER(business_email) = ?', [$mailbox])->value('id');

        return $vendorId ? (int) $vendorId : null;
    }

    /**
     * The team member the call is opened as: whoever owns the mailbox the
     * client wrote to (Patryk for patryk@), else the company's first admin.
     */
    protected function actorFor(?string $mailbox, Project $project): ?User
    {
        $team = fn () => User::withoutGlobalScopes()->whereHas(
            'vendors',
            fn ($query) => $query->withoutGlobalScopes()->where('vendors.id', $project->belongs_to_vendor_id)
        );

        if ($mailbox) {
            $owner = $team()->whereRaw('LOWER(email) = ?', [$mailbox])->first();

            if ($owner) {
                return $owner;
            }
        }

        return $team()
            ->whereHas('vendors', fn ($query) => $query->withoutGlobalScopes()
                ->where('vendors.id', $project->belongs_to_vendor_id)
                ->where('user_vendor.role_id', 1))
            ->orderBy('users.id')
            ->first();
    }

    /**
     * Tasks are stamped with the signed-in user (TaskObserver::creating), and
     * this runs from the scheduler, a queue worker or the site's API token.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    protected function actingAs(User $actor, callable $callback): mixed
    {
        $guard = Auth::guard();
        $previous = $guard->user();
        $guard->setUser($actor);

        try {
            return $callback();
        } finally {
            if ($previous) {
                $guard->setUser($previous);
            } elseif (method_exists($guard, 'forgetUser')) {
                $guard->forgetUser();
            }
        }
    }

    protected function taskNotes(User $sender, array $email, Carbon $receivedAt): string
    {
        $name = trim($sender->first_name.' '.$sender->last_name) ?: $sender->email;
        $body = Str::limit(Str::squish((string) ($email['body'] ?? '')), 900);

        return "Service request emailed by {$name} on {$receivedAt->copy()->setTimezone('America/Chicago')->format('M j, Y g:i A')}:\n\n\"{$body}\"";
    }

    protected function notifyTeam(ServiceCallEmail $row, Project $project, string $taskTitle, bool $clientContacted): void
    {
        $admins = User::withoutGlobalScopes()
            ->whereHas('vendors', fn ($query) => $query->withoutGlobalScopes()
                ->where('vendors.id', $project->belongs_to_vendor_id)
                ->where('user_vendor.role_id', 1))
            ->pluck('users.id');

        if ($admins->isEmpty()) {
            return;
        }

        $client = Client::withoutGlobalScopes()->find($project->client_id);
        $who = trim((string) ($client?->name ?: $client?->last_names ?: 'A client'));
        $title = "Service call opened: {$who}";
        $body = $clientContacted
            ? "{$taskTitle} — from their email. They were sent the schedule link to pick times."
            : "{$taskTitle} — from their email. They have not been contacted; send the schedule link when ready.";
        $path = '/projects/'.$project->id;

        foreach ($admins as $userId) {
            AppNotification::create([
                'user_id' => $userId,
                'type' => 'service_call_opened',
                'title' => $title,
                'body' => $body,
                'action_url' => $path,
                'data' => ['project_id' => $project->id, 'task_id' => $row->task_id, 'service_call_email_id' => $row->id],
            ]);
        }

        AdminAlerts::push($admins, 'service_call_opened', $title, $body, url($path));
    }

    protected function isOurAddress(string $email): bool
    {
        return app(CrewLeadEmailService::class)->isOurAddress($email);
    }
}
