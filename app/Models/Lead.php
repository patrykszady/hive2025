<?php

namespace App\Models;

use App\Scopes\LeadScope;
use Illuminate\Database\Eloquent\Casts\AsArrayObject;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Lead extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = ['date', 'origin', 'external_source', 'external_id', 'notes', 'user_id', 'lead_data', 'belongs_to_vendor_id', 'created_by_user_id', 'created_at', 'updated_at', 'deleted_at'];

    protected function casts(): array
    {
        return [
            'date' => 'date:Y-m-d H:i:s',
            'deleted_at' => 'date:Y-m-d',
            'lead_data' => AsArrayObject::class,
        ];
    }

    protected static function booted(): void
    {
        static::addGlobalScope(new LeadScope);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function statuses(): HasMany
    {
        return $this->hasMany(LeadStatus::class);
    }

    public function last_status(): HasOne
    {
        // id desc breaks created_at ties: two statuses written in the same
        // second (lead created then immediately converted, bulk actions) would
        // otherwise resolve to whichever row the driver returned first, showing
        // a stale status. Matches scopeWhereLatestStatus' ordering.
        return $this->hasOne(LeadStatus::class)
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    public function feedback(): HasMany
    {
        // Newest first everywhere this is read: the details panel, and any
        // future notifyTeam-style summary.
        return $this->hasMany(LeadFeedback::class)->latest();
    }

    /**
     * Canonical lead statuses with badge colors — single source of truth for
     * the row dropdown, bulk actions and the edit form. Shaped like
     * ProjectStatus::selectableStatuses() so both feed <x-status-select />.
     *
     * @return array<int, array{code: string, label: string, color: string}>
     */
    public static function selectableStatuses(): array
    {
        return collect([
            'New' => 'yellow',
            'Replied' => 'indigo',
            'Won' => 'green',
            'Lost' => 'red',
            'Not a Fit' => 'zinc',
        ])
            ->map(fn (string $color, string $title) => [
                'code' => $title,
                'label' => $title,
                'color' => $color,
            ])
            ->values()
            ->all();
    }

    /**
     * Filter by the lead's CURRENT status. `whereHas('last_status')` can't do
     * this — it matches any row in the history (every lead has a "New" row).
     * Leads with no status rows yet count as "New".
     */
    public function scopeWhereLatestStatus($query, string|array $titles)
    {
        $titles = array_values((array) $titles);
        $placeholders = implode(',', array_fill(0, count($titles), '?'));

        return $query->whereRaw("
            COALESCE((
                select title from lead_statuses
                where lead_statuses.lead_id = leads.id
                order by created_at desc, id desc
                limit 1
            ), 'New') in ({$placeholders})
        ", $titles);
    }

    /**
     * The client this lead turned into, if any — single source of truth for
     * the leads table, the "Won" backfill command and the project-created job.
     *
     * Two signals: the lead's contact belongs to a client, or (for leads with
     * no user account) the lead address matches a client of the same vendor.
     * Runs unscoped so queue jobs and console commands resolve the same way a
     * logged-in request does.
     */
    /** Per-instance memo — index tables resolve the same lead each render. */
    protected bool $resolvedClientMemoSet = false;

    protected ?Client $resolvedClientMemo = null;

    public function resolveClient(): ?Client
    {
        if ($this->resolvedClientMemoSet) {
            return $this->resolvedClientMemo;
        }

        $this->resolvedClientMemoSet = true;

        return $this->resolvedClientMemo = $this->resolveClientUncached();
    }

    protected function resolveClientUncached(): ?Client
    {
        // Prefer an already eager-loaded clients relation (LeadsIndex loads
        // user.clients) over a fresh unscoped query per row.
        if ($this->relationLoaded('user') && $this->user?->relationLoaded('clients')) {
            $loaded = $this->user->clients->first();

            if ($loaded) {
                return $loaded;
            }
        }

        $client = $this->user?->clients()->withoutGlobalScopes()->first();

        if ($client) {
            return $client;
        }

        $address = $this->lead_data['address'] ?? null;
        $vendorId = $this->belongs_to_vendor_id;

        if (! $address || ! $vendorId) {
            return null;
        }

        $street = trim(explode(',', (string) $address)[0] ?? '');

        if ($street === '') {
            return null;
        }

        return Client::withoutGlobalScopes()
            ->whereHas('vendors', fn ($q) => $q->where('vendors.id', $vendorId))
            ->where('address', 'like', $street.'%')
            ->first();
    }

    /**
     * Record a status change, skipping no-op writes so the history stays
     * meaningful. Returns true when a new status row was actually created.
     */
    public function setStatus(string $title): bool
    {
        if ($this->last_status?->title === $title) {
            return false;
        }

        $this->statuses()->create([
            'title' => $title,
            'belongs_to_vendor_id' => $this->belongs_to_vendor_id,
        ]);

        $this->unsetRelation('last_status');

        return true;
    }

    /**
     * Address split for the leads table: city + street only — no state, zip,
     * country or unit/suite line. Leads arrive from webhooks and hand-typed
     * forms, so the address is one free-text blob; the explicit `city` field
     * wins when present, otherwise it's parsed out of the address.
     *
     * @return array{city: string, street: string}
     */
    public function shortAddressParts(): array
    {
        $raw = trim((string) ($this->lead_data['address'] ?? ''));

        $segments = array_values(array_filter(
            array_map('trim', explode(',', $raw)),
            fn (string $segment): bool => $segment !== ''
        ));

        $street = $segments[0] ?? '';

        $isUnit = fn (string $s): bool => (bool) preg_match('/^(?:#|ste\.?|suite|apt\.?|apartment|unit|floor|fl\.?|bldg|building|rm\.?|room)\b/i', $s);
        $isCountry = fn (string $s): bool => (bool) preg_match('/^(?:usa|u\.?s\.?a?\.?|united states(?: of america)?)$/i', $s);
        // "IL", "IL 60047", "Illinois 60047", "60047"
        $isStateOrZip = fn (string $s): bool => (bool) preg_match('/^(?:[A-Za-z]{2}\.?|[A-Za-z]{4,})?\s*\d{5}(?:-\d{4})?$/', $s)
            || (bool) preg_match('/^[A-Za-z]{2}\.?$/', $s);

        $city = trim((string) ($this->lead_data['city'] ?? ''));

        if ($city === '') {
            foreach (array_slice($segments, 1) as $segment) {
                if ($isUnit($segment) || $isCountry($segment) || $isStateOrZip($segment)) {
                    continue;
                }

                $city = $segment;
                break;
            }
        }

        // Read as an address, not as typed: "6 drake terrace" shows as
        // "6 Drake Terrace"; capitals the sender used are kept.
        return ['city' => $city, 'street' => $street !== '' ? \App\Support\StreetAddress::tidyCase($street) : ''];
    }

    /**
     * IDs of this client's leads (via the contact user on the client) — used
     * to attach lead emails to client/project pages.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    public static function idsForClient(?int $clientId)
    {
        if (! $clientId) {
            return collect();
        }

        return static::withoutGlobalScopes()
            ->whereHas('user.clients', fn ($q) => $q->where('clients.id', $clientId))
            ->pluck('id');
    }

    /**
     * Availability slots that are still bookable (today or later). Slots come
     * from the website form as ['date' => 'Y-m-d', 'time' => '2-4 PM'].
     *
     * @return array<int, array{date: string, time: string}>  keyed by original index
     */
    public function usableAvailability(): array
    {
        $raw = $this->lead_data['availability'] ?? [];

        return collect(is_array($raw) || $raw instanceof \Traversable ? $raw : [])
            ->map(fn ($slot) => (array) $slot)
            ->filter(fn ($slot) => static::slotIsBookable($slot))
            ->all();
    }

    /**
     * Public signed link where the lead picks new consultation times — used by
     * the consult email when every preferred slot has passed (or none were
     * given). Signed, so no auth and no token column; valid for 30 days.
     */
    public function availabilityUrl(): string
    {
        return \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'lead.availability',
            now()->addDays(30),
            ['lead' => $this->id],
        );
    }

    /**
     * ONE bookability rule for an availability slot: a future date is
     * bookable; today's slot only while its window's END is still ahead
     * (in the browser timezone) — "Mon 2-4 PM" stops being offered at 4 PM.
     * Used by the consult composer, the modal chips and usableAvailability().
     *
     * @param  array{date?: string, time?: string}  $slot
     */
    public static function slotIsBookable(array $slot): bool
    {
        $date = $slot['date'] ?? null;

        if (! $date) {
            return false;
        }

        $today = browser_today()->format('Y-m-d');

        if ($date > $today) {
            return true;
        }

        if ($date < $today) {
            return false;
        }

        // Today: unparseable windows stay bookable rather than vanishing early.
        $times = static::parseSlotTimes((string) ($slot['time'] ?? ''));

        if (! $times) {
            return true;
        }

        $end = \Illuminate\Support\Carbon::parse($date.' '.$times[1], browser_timezone());

        return \Illuminate\Support\Carbon::now(browser_timezone())->lt($end);
    }

    public static function parseSlotTimes(string $time): ?array
    {
        $pattern = '/^\s*(\d{1,2})(?::(\d{2}))?\s*(am|pm)?\s*(?:-|–|to)\s*(\d{1,2})(?::(\d{2}))?\s*(am|pm)?\s*$/i';

        if (! preg_match($pattern, $time, $m)) {
            return null;
        }

        $endMeridiem = strtolower($m[6] ?? '') ?: strtolower($m[3] ?? '');
        $startMeridiem = strtolower($m[3] ?? '') ?: $endMeridiem;

        $to24 = function (int $hour, string $meridiem): int {
            if ($meridiem === 'pm' && $hour < 12) {
                return $hour + 12;
            }
            if ($meridiem === 'am' && $hour === 12) {
                return 0;
            }

            return $hour;
        };

        $start = $to24((int) $m[1], $startMeridiem);
        $end = $to24((int) $m[4], $endMeridiem);

        // "11-1 PM": applying PM to both puts the start after the end — the
        // start was actually AM.
        if ($start >= $end && $startMeridiem === 'pm' && $start >= 12) {
            $start -= 12;
        }

        if ($start >= $end || $end > 23) {
            return null;
        }

        return [
            sprintf('%02d:%s', $start, ($m[2] ?? '') !== '' ? $m[2] : '00'),
            sprintf('%02d:%s', $end, ($m[5] ?? '') !== '' ? $m[5] : '00'),
        ];
    }

    /**
     * Has the homeowner sent availability on top of availability they had
     * already given (or of a consult already booked)? Set by the picker at
     * that moment. `availability_updated_at` alone is not it: that stamps
     * every pick, including the very first one through the link.
     */
    public function hasRescheduled(): bool
    {
        return filled($this->lead_data['availability_rescheduled_at'] ?? null);
    }

    /**
     * The lead that speaks for a client — for showing the times its
     * homeowner picked on the scheduling page.
     *
     * Matches the client's users by user link first, email as fallback
     * (older leads lost their user link), never a trashed lead (a deleted
     * lead must not shadow a live one), and when several match, the one
     * whose availability is freshest wins — "the lead they most recently
     * scheduled through", not "the newest row".
     */
    public static function latestForClient(Client $client): ?self
    {
        $users = $client->users()->withoutGlobalScopes()->get(['users.id', 'users.email']);

        if ($users->isEmpty()) {
            return null;
        }

        $emails = $users->pluck('email')
            ->filter()
            ->map(fn ($email) => mb_strtolower(trim((string) $email)))
            ->values();

        return static::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where(function ($q) use ($users, $emails) {
                $q->whereIn('user_id', $users->pluck('id'));
                if ($emails->isNotEmpty()) {
                    $q->orWhereIn('lead_data->email', $emails);
                }
            })
            ->get()
            ->sortByDesc(fn (self $lead) => [
                (string) ($lead->lead_data['availability_updated_at'] ?? ''),
                $lead->id,
            ])
            ->first();
    }

    /**
     * Does a scheduling link for this lead still live in someone's inbox or
     * messages? Consult emails, SMS invites and calendar invites all carry a
     * shortened pick-times URL; while one exists, deleting the lead breaks a
     * link a homeowner may be about to tap. (Soft deletion keeps the page
     * working — this warning exists for the human deciding to delete.)
     */
    public function hasActiveScheduleLink(): bool
    {
        // The '?' terminator keeps lead 8 from matching lead 80 — the signed
        // URL always carries a query string.
        return \App\Models\ShortLink::query()
            ->where('destination', 'like', '%/lead/times/'.$this->id.'?%')
            ->exists();
    }

    /**
     * Is there a consult on the books for this lead already?
     *
     * The "Need a different time?" link in a calendar invite lands on the same
     * picker a brand-new lead uses, and a homeowner who never went through the
     * picker before was being held to the first-contact notice — three days out
     * to move a meeting they already have. Having a consult IS the difference
     * between booking and rescheduling.
     */
    public function hasBookedConsult(): bool
    {
        if (! $this->user_id) {
            return false;
        }

        return $this->consultTasksQuery()->exists();
    }

    /**
     * The consultations still ahead for this lead's contact: Meet tasks
     * titled "… Consult" on their client's projects, from today on. These
     * are what removing the lead cancels — a consult already held stays in
     * the history, and a task that is not a consult is not the lead's to
     * take down.
     *
     * @return \Illuminate\Support\Collection<int, \App\Models\Task>
     */
    public function bookedConsultTasks(): \Illuminate\Support\Collection
    {
        if (! $this->user_id) {
            return collect();
        }

        return $this->consultTasksQuery()
            ->where('title', 'like', '% Consult')
            ->whereNotNull('start_date')
            ->whereDate('start_date', '>=', \Carbon\Carbon::now(\App\Livewire\Leads\PickTimes::timezone())->startOfDay())
            ->orderBy('start_date')
            ->get();
    }

    /**
     * Cancel this lead's upcoming consultations. Deleting the Meet task is
     * what withdraws the calendar invite (TaskObserver dispatches the Nylas
     * event deletion) — lead 170 was removed with its consult left on
     * everyone's calendar (2026-09-15). A project the booking made just for
     * that consult, still at the Consult stage with nothing else on it,
     * goes with it, so the client it created can be tidied as an orphan.
     *
     * @return array{consults: array<int, string>, projects: array<int, string>}
     */
    public function cancelBookedConsults(): array
    {
        $cancelled = ['consults' => [], 'projects' => []];

        foreach ($this->bookedConsultTasks() as $task) {
            $project = $task->project()->withoutGlobalScopes()->first();
            // Task's Sortable trait re-orders siblings on delete through
            // $task->project, which is vendor-scoped: hand it the project
            // so a console run (no session) or another vendor's admin
            // still deletes cleanly.
            $task->setRelation('project', $project);
            $cancelled['consults'][] = self::consultLabel($task);
            $task->delete();

            if ($project && self::projectExistsOnlyForConsult($project)) {
                $cancelled['projects'][] = (string) $project->project_name;
                $project->delete();
            }
        }

        return $cancelled;
    }

    /** "Sep 21, 10:00 AM" (or just the date) for a consult task. */
    public static function consultLabel(\App\Models\Task $task): string
    {
        $date = \Carbon\Carbon::parse($task->start_date);
        $start = (string) data_get($task->options, 'time_settings.'.$date->format('Y-m-d').'.start_time', '');

        return $start !== ''
            ? $date->format('M j').', '.\Carbon\Carbon::parse($start)->format('g:i A')
            : $date->format('M j');
    }

    /** A project at the Consult stage with no tasks left on it. */
    protected static function projectExistsOnlyForConsult(\App\Models\Project $project): bool
    {
        $stage = self::projectStage($project);

        return (int) $stage === 9
            && ! \App\Models\Task::withoutGlobalScopes()->whereNull('deleted_at')->where('project_id', $project->id)->exists();
    }

    /** The project's latest stage code, read past the session's vendor scope. */
    protected static function projectStage(\App\Models\Project $project): ?int
    {
        $code = \App\Models\ProjectStatus::withoutGlobalScopes()
            ->where('project_id', $project->id)
            ->orderByDesc('id')
            ->value('status_code');

        return $code === null ? null : (int) $code;
    }

    /**
     * Meet tasks on the contact's client's projects, however titled.
     *
     * Walks the pivots itself (client_user → project_vendor → projects →
     * tasks) under the LEAD's vendor rather than through Project::client(),
     * which is scoped to whoever is signed in: on the console nobody is, and
     * in a vendor's session another vendor's consult must not appear.
     * withoutGlobalScopes() drops soft-deletion too, so it is spelled out —
     * or a cancelled consult on a removed project still counts as booked.
     */
    protected function consultTasksQuery(): \Illuminate\Database\Eloquent\Builder
    {
        $clientIds = \Illuminate\Support\Facades\DB::table('client_user')
            ->where('user_id', $this->user_id)
            ->select('client_id');

        $projectIds = \Illuminate\Support\Facades\DB::table('project_vendor')
            ->whereIn('client_id', $clientIds)
            ->when($this->belongs_to_vendor_id, fn ($q) => $q->where('vendor_id', $this->belongs_to_vendor_id))
            ->select('project_id');

        return \App\Models\Task::withoutGlobalScopes()
            ->whereNull('tasks.deleted_at')
            ->where('type', 'Meet')
            ->whereIn('project_id', \App\Models\Project::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->whereIn('id', $projectIds)
                ->select('id'));
    }


    public static function statusColor(?string $title): string
    {
        return collect(self::selectableStatuses())->firstWhere('code', $title)['color'] ?? 'zinc';
    }

    /**
     * What removing this lead takes with it: the contact person and any client
     * record that exist only because of this lead. Shared by the single-lead
     * delete (lead form) and the bulk delete on /leads.
     *
     * @return array{clients: array<int, string>, user: string|null}
     */
    public function deleteImpact(): array
    {
        $consultTasks = $this->bookedConsultTasks();

        $impact = [
            'clients' => [],
            'user' => null,
            // Deleting still works (the pick-times page resolves trashed
            // leads), but the human deciding should know a homeowner may be
            // holding a link or an appointment tied to this lead.
            'schedule_link' => $this->hasActiveScheduleLink(),
            'booked_consult' => $this->hasBookedConsult(),
            // What the delete cancels: the upcoming consults (invites
            // withdrawn) and any project that existed only for one.
            'consults' => $consultTasks->map(fn ($task) => self::consultLabel($task))->values()->all(),
            'consult_projects' => $consultTasks
                ->map(fn ($task) => $task->project()->withoutGlobalScopes()->first())
                ->filter(fn ($project) => $project && self::projectWouldBeOrphanedByConsults($project, $consultTasks))
                ->map(fn ($project) => (string) $project->project_name)
                ->unique()
                ->values()
                ->all(),
        ];

        $user = $this->user;

        if (! $user) {
            return $impact;
        }

        // Judged as if the consults were already cancelled: a project made
        // for the consult goes, and the client it left behind is an orphan.
        $goingProjects = $impact['consult_projects'];
        $clients = $user->clients()->get();
        $orphanedClients = $clients->filter(fn ($client) => $this->clientIsOrphaned($client, $user, $goingProjects));

        $impact['clients'] = $orphanedClients->map(fn ($client) => $client->name)->values()->all();

        // The contact keeps their account if anything survives: another lead,
        // a company, or a client record we're not removing.
        if ($this->userIsOrphaned($user, $orphanedClients->pluck('id')->all())) {
            $impact['user'] = $user->full_name;
        }

        return $impact;
    }

    /**
     * Delete the lead and clean up anything that existed only for it:
     * orphaned client records (no projects, no other contacts) and the contact
     * user when nothing else refers to them.
     */
    public function deleteWithOrphans(): void
    {
        $user = $this->user;
        $impact = $this->deleteImpact();

        // First, so the invite is withdrawn and a consult-only project is
        // gone before the orphan check below looks at the client.
        $this->cancelBookedConsults();

        $this->delete();

        if (! $user) {
            return;
        }

        $orphanedClientNames = $impact['clients'];

        foreach ($user->clients()->get() as $client) {
            if (! in_array($client->name, $orphanedClientNames, true) || ! $this->clientIsOrphaned($client, $user)) {
                continue;
            }

            $client->users()->detach();
            $client->vendors()->detach();
            $client->unsearchable();
            $client->delete();
        }

        if ($impact['user'] && $this->userIsOrphaned($user->fresh(), [])) {
            $user->delete();
        }
    }

    /**
     * A client record exists only for this lead when it has no projects and no
     * contact other than the lead's own.
     */
    /**
     * @param  array<int, string>  $goingProjects  names of projects the consult cancellation removes first
     */
    protected function clientIsOrphaned(Client $client, User $user, array $goingProjects = []): bool
    {
        return ! $client->projects()->whereNotIn('project_name', $goingProjects ?: [''])->exists()
            && ! $client->users()->where('users.id', '!=', $user->id)->exists();
    }

    /**
     * Would cancelling these consults leave the project with nothing on it,
     * at the Consult stage — i.e. it existed only for the consult?
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\Task>  $consultTasks
     */
    protected static function projectWouldBeOrphanedByConsults(\App\Models\Project $project, \Illuminate\Support\Collection $consultTasks): bool
    {
        $stage = self::projectStage($project);

        return (int) $stage === 9
            && ! \App\Models\Task::withoutGlobalScopes()
                ->whereNull('deleted_at')
                ->where('project_id', $project->id)
                ->whereNotIn('id', $consultTasks->pluck('id')->all())
                ->exists();
    }

    /**
     * The lead's contact is orphaned once this lead is gone and nothing else
     * (another lead, a company, a surviving client, or their own work records)
     * still refers to them.
     *
     * @param  array<int, int>  $ignoreClientIds  clients being removed alongside the lead
     */
    protected function userIsOrphaned(User $user, array $ignoreClientIds): bool
    {
        // Tasks are queried directly: User::task() maps to a `user_id` column
        // the tasks table doesn't have (assignees live in `user_ids`).
        $hasTasks = Task::withoutGlobalScopes()
            ->where(fn ($q) => $q->where('created_by_user_id', $user->id)
                ->orWhereJsonContains('user_ids', $user->id)
                ->orWhereJsonContains('user_ids', (string) $user->id))
            ->exists();

        return ! $user->leads()->where('leads.id', '!=', $this->id)->exists()
            && ! $user->vendors()->exists()
            && ! $user->clients()->whereNotIn('clients.id', $ignoreClientIds ?: [0])->exists()
            && ! $user->timesheets()->exists()
            && ! $hasTasks
            && ! $user->distributions()->exists();
    }
}
