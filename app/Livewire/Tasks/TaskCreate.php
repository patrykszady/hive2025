<?php

namespace App\Livewire\Tasks;

use App\Jobs\CreateMeetTaskCalendarEvent;
use App\Jobs\UpdateMeetTaskCalendarEvent;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use App\Models\TaskDependency;
use App\Livewire\Forms\TaskForm;
use App\Livewire\Planner\CardsIndex;
use App\Livewire\Planner\PlannerTaskCard;
use App\Livewire\Projects\UpcomingTasks;
use App\Livewire\Dashboard\UserTasks;
use App\Livewire\Dashboard\VendorTasks;
use App\Livewire\Clients\UpcomingClientTasks;
use App\Livewire\Sms\SendScheduleModal;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Flux;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\Attributes\Computed;

class TaskCreate extends Component
{
    use AuthorizesRequests;

    public TaskForm $form;

    //$projects  come from the Planner Component
    public $projects = [];
    public $selectedPredecessorId = null;
    public $dependencyType = 'finish_to_start';
    public $lagDays = 0;
    public $showCompletedChecklist = false;

    /** @var array<int, array<string, mixed>> */
    public $pendingSmsTasks = [];

    public $view_text = [
        'card_title' => 'Create Task',
        'button_text' => 'Create',
        'form_submit' => 'save',
    ];

    /**
     * The form body (project/vendor/user selects — ~1.5MB rendered) only
     * renders once one of the open events fires; pages that merely HOST this
     * modal don't pay for it. The open event's round trip brings the form.
     */
    public bool $hydrated = false;

    protected $listeners = ['editTask', 'addTask', 'prefillTaskFromSms'];

    /** How long a Meet runs by default — the same half hour a consult books. */
    public const MEET_MINUTES = 30;

    /** Homeowner-slot picker state (mirrors the lead composer's two stages). */
    public ?int $homeownerSlotIndex = null;

    public ?string $homeownerExactTime = null;

    /**
     * Maps a homeowner-selected time frame to concrete arrival start/end times.
     *
     * @var array<string, array{start: string, end: string}>
     */
    private const SERVICE_TIME_FRAMES = [
        '7-9 AM' => ['start' => '07:00', 'end' => '09:00'],
        '9-11 AM' => ['start' => '09:00', 'end' => '11:00'],
        '11-1 PM' => ['start' => '11:00', 'end' => '13:00'],
        '1-3 PM' => ['start' => '13:00', 'end' => '15:00'],
        '3-5 PM' => ['start' => '15:00', 'end' => '17:00'],
    ];

    private function ensureProjectOptionLoaded(?int $projectId): void
    {
        if (! $projectId) {
            return;
        }

        $existingIds = collect($this->projects)
            ->map(fn ($project) => is_object($project) ? ($project->id ?? null) : (is_array($project) ? ($project['id'] ?? null) : null))
            ->filter()
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($existingIds->contains((int) $projectId)) {
            return;
        }

        $project = Project::query()
            ->with('latestStatus')
            ->whereKey($projectId)
            ->first();

        if (! $project) {
            return;
        }

        $this->projects = array_values(array_merge([$project], (array) $this->projects));
    }

    /**
     * Pick the project to pre-select when creating a task for a client.
     *
     * Prefers the most recently created project whose latest status is
     * "Active" (status code 6), then the most recent project that is not
     * Complete (7) or Cancelled (10), and finally the most recent project
     * overall. Expects the collection already ordered latest-first.
     *
     * @param  \Illuminate\Support\Collection<int, Project>  $projects
     */
    private function preferredClientProject($projects): ?Project
    {
        if ($projects->isEmpty()) {
            return null;
        }

        $activeProject = $projects->first(
            fn (Project $project) => (int) ($project->latestStatus->status_code ?? 0) === 6
        );

        if ($activeProject) {
            return $activeProject;
        }

        $openProject = $projects->first(
            fn (Project $project) => ! in_array((int) ($project->latestStatus->status_code ?? 0), [7, 10], true)
        );

        return $openProject ?? $projects->first();
    }

    #[Computed]
    public function taskTypeTextClasses(): array
    {
        return collect(Task::TYPE_UI)
            ->mapWithKeys(fn (array $ui, string $type) => [$type => $ui['text']])
            ->all();
    }

    #[Computed]
    public function taskTypeUi(): array
    {
        return Task::TYPE_UI[$this->form->type ?? 'Task'] ?? Task::TYPE_UI['Task'];
    }

    #[Computed]
    public function taskTypeTabClasses(): array
    {
        return collect(Task::TYPE_UI)
            ->mapWithKeys(fn (array $ui, string $type) => [
                $type => trim(($ui['border'] ?? '').' '.($ui['text'] ?? '')),
            ])
            ->all();
    }

    #[Computed]
    public function vendors()
    {
        // Use Scout search to sort by ytd_expense_sum
        // Must specify take() to override Scout's default limit of 20
        return Vendor::search('*')
            ->orderBy('ytd_expense_sum', 'desc')
            ->take(1000)
            ->get();
    }

    #[Computed]
    public function employees()
    {
        $vendor = auth()->user()?->vendor;

        if (!$vendor) {
            return collect();
        }

        return $vendor->users()->employed()->get();
    }

    /**
     * Build the available meeting contacts list (client users + team members).
     *
     * @return array<int, array{email: string, name: string, group: string}>
     */
    #[Computed]
    public function availableMeetingContacts(): array
    {
        $contacts = collect();
        $taggedEmails = collect();

        // Client users from the selected project
        if ($this->form->project_id) {
            $project = \App\Models\Project::with('client.users')->find($this->form->project_id);
            if ($project?->client) {
                $clientContacts = collect($project->client->users)->map(fn ($user) => [
                    'email' => strtolower(trim($user->email)),
                    'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
                    'group' => 'Client',
                ])->filter(fn ($c) => $c['email'] !== '');

                $contacts = $contacts->merge($clientContacts);
                $taggedEmails = $taggedEmails->merge($clientContacts->pluck('email'));
            }
        }

        // Team members (employees of the current vendor)
        $vendor = auth()->user()?->vendor;
        if ($vendor) {
            $teamContacts = $vendor->users()->employed()->get()->map(fn ($user) => [
                'email' => strtolower(trim($user->email)),
                'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
                'group' => 'Team',
            ])->filter(fn ($c) => $c['email'] !== '');

            $contacts = $contacts->merge($teamContacts);
            $taggedEmails = $taggedEmails->merge($teamContacts->pluck('email'));
        }

        // Selected vendor contact (the sub being scheduled for the meeting)
        if ($this->form->vendor_id && is_numeric($this->form->vendor_id)) {
            $selectedVendor = Vendor::withoutGlobalScopes()->find((int) $this->form->vendor_id);
            $vendorEmail = strtolower(trim((string) ($selectedVendor?->email ?? $selectedVendor?->business_email ?? '')));

            if ($vendorEmail !== '' && ! $taggedEmails->contains($vendorEmail)) {
                $contacts->push([
                    'email' => $vendorEmail,
                    'name' => trim((string) ($selectedVendor?->business_name ?? '')),
                    'group' => 'Vendor',
                ]);
                $taggedEmails->push($vendorEmail);
            }
        }

        // All other users not already tagged
        $otherUsers = User::query()
            ->whereNotIn('email', $taggedEmails->all())
            ->whereNotNull('email')
            ->where('email', '!=', '')
            ->orderBy('first_name')
            ->get()
            ->map(fn ($user) => [
                'email' => strtolower(trim($user->email)),
                'name' => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
                'group' => '',
            ])
            ->filter(fn ($c) => $c['email'] !== '');

        $contacts = $contacts->merge($otherUsers);

        return $contacts->unique('email')->values()->all();
    }

    /**
     * Toggle a contact in/out of the meeting participants list.
     */
    public function toggleMeetingContact(string $email): void
    {
        $email = strtolower(trim($email));

        if (in_array($email, $this->form->meeting_participants, true)) {
            $this->form->meeting_participants = array_values(
                array_filter($this->form->meeting_participants, fn ($e) => $e !== $email)
            );
        } else {
            $this->form->meeting_participants[] = $email;
        }
    }

    #[Computed]
    public function duration()
    {
        if (empty($this->form->dates) || !is_array($this->form->dates)) {
            return 0;
        }

        return count($this->form->dates);
    }

    /**
     * The homeowner's submitted preferred service times for the current project,
     * grouped by day, with per-time applied state for the schedule picker.
     *
     * @return array<int, array{date: string, label: string, times: array<int, array{time: string, applied: bool}>}>
     */
    #[Computed]
    public function servicePreferredSlots(): array
    {
        if (! $this->form->project_id) {
            return [];
        }

        // Homeowner preferred times only apply to unscheduled/pending tasks. Once a
        // task has a persisted start date it's already scheduled, so hide the picker.
        if ($this->form->task?->start_date) {
            return [];
        }

        $project = Project::find($this->form->project_id);
        if (! $project || ! $this->currentTaskCoveredByPreferredTimes($project)) {
            return [];
        }

        $slots = (array) ($project?->service_availability['slots'] ?? []);

        if (empty($slots)) {
            return [];
        }

        return collect($slots)
            ->filter(fn ($slot) => is_array($slot) && isset($slot['date'], $slot['time']))
            ->groupBy('date')
            ->sortKeys()
            ->map(fn ($group, string $date) => [
                'date' => $date,
                'label' => Carbon::parse($date)->format('D, M j'),
                'times' => $group
                    ->pluck('time')
                    ->unique()
                    ->values()
                    ->map(fn (string $time) => [
                        'time' => $time,
                        'applied' => $this->servicePreferredSlotIsApplied($date, $time),
                    ])
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Show the pending availability cue for service-call tasks when there are
     * no currently-applicable homeowner-submitted slots to apply.
     */
    #[Computed]
    public function showAwaitingClientAvailabilityCard(): bool
    {
        if (! $this->form->project_id) {
            return false;
        }

        if ($this->form->task?->start_date) {
            return false;
        }

        if ($this->servicePreferredSlots() !== []) {
            return false;
        }

        $project = Project::query()
            ->with('latestStatus')
            ->find($this->form->project_id);

        if (! $project) {
            return false;
        }

        return (int) ($project->latestStatus->status_code ?? 0) === 8;
    }

    /**
     * Whether the homeowner's submitted preferred times cover the task currently
     * being edited. Tasks recorded in the saved task_ids are covered; when no
     * task ids were recorded (legacy submissions) every pending task is treated
     * as covered.
     */
    protected function currentTaskCoveredByPreferredTimes(Project $project): bool
    {
        if ((array) data_get($project->service_availability, 'slots', []) === []) {
            return false;
        }

        $savedTaskIds = collect((array) data_get($project->service_availability, 'task_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->all();

        if ($savedTaskIds === []) {
            return true;
        }

        $taskId = (int) ($this->form->task?->id ?? 0);

        return $taskId > 0 && in_array($taskId, $savedTaskIds, true);
    }

    /**
     * When the homeowner submitted their availability (ISO string), if any.
     */
    #[Computed]
    public function servicePreferredSubmittedAt(): ?string
    {
        if (! $this->form->project_id) {
            return null;
        }

        $project = Project::find($this->form->project_id);

        return $project?->service_availability['submitted_at'] ?? null;
    }

    /**
     * Whether the given preferred day + time frame is currently applied to the task.
     */
    protected function servicePreferredSlotIsApplied(string $date, string $time): bool
    {
        if (! in_array($date, (array) $this->form->dates, true)) {
            return false;
        }

        $setting = $this->form->time_settings[$date] ?? [];
        $frame = self::SERVICE_TIME_FRAMES[$time] ?? null;

        if ($frame === null) {
            // "Anytime" — applied when the date is selected without a specific arrival time.
            return empty($setting['use_time']);
        }

        return ! empty($setting['use_time'])
            && ($setting['start_time'] ?? null) === $frame['start'];
    }

    /**
     * Remove any homeowner-preferred slots currently applied to the task schedule.
     */
    protected function clearAppliedServicePreferredSlots(): void
    {
        if (! $this->form->project_id) {
            return;
        }

        $project = Project::find($this->form->project_id);
        $slots = (array) ($project?->service_availability['slots'] ?? []);

        foreach ($slots as $slot) {
            if (! is_array($slot) || ! isset($slot['date'], $slot['time'])) {
                continue;
            }

            if (! $this->servicePreferredSlotIsApplied($slot['date'], $slot['time'])) {
                continue;
            }

            $this->form->dates = array_values(
                array_filter((array) $this->form->dates, fn ($d) => $d !== $slot['date'])
            );
            unset($this->form->time_settings[$slot['date']]);
        }
    }

    /**
     * Apply a homeowner-preferred day + time frame to the task schedule.
     *
     * Only one homeowner-preferred slot can be applied at a time; selecting a new
     * slot clears any previously applied one, and re-selecting the active slot
     * removes it.
     */
    public function applyServicePreferredSlot(string $date, string $time): void
    {
        $alreadyApplied = $this->servicePreferredSlotIsApplied($date, $time);

        $this->clearAppliedServicePreferredSlots();

        if ($alreadyApplied) {
            return;
        }

        if (! in_array($date, (array) $this->form->dates, true)) {
            $this->form->dates[] = $date;
            sort($this->form->dates);
        }

        $frame = self::SERVICE_TIME_FRAMES[$time] ?? null;

        if ($frame === null) {
            $this->form->time_settings[$date] = array_merge(
                $this->form->time_settings[$date] ?? [],
                ['use_time' => false],
            );

            return;
        }

        $this->form->time_settings[$date] = array_merge(
            $this->form->time_settings[$date] ?? [],
            [
                'use_time' => true,
                'start_time' => $frame['start'],
                'end_time' => $frame['end'],
            ],
        );
    }

    #[Computed]
    public function taskHistory(): \Illuminate\Support\Collection
    {
        if (! $this->form->task) {
            return collect();
        }

        $task = $this->form->task;

        $history = $task->activities()
            ->with('causer')
            ->latest()
            ->limit(50)
            ->get()
            ->map(function ($activity) {
                return [
                    'id' => $activity->id,
                    'event' => $activity->event,
                    'causer' => $activity->causer?->first_name ?? 'System',
                    'created_at' => $activity->created_at,
                    'changes' => $this->formatActivityChanges($activity),
                ];
            });

        // Add synthetic "created" entry if no logged created event exists
        $hasCreatedEvent = $history->contains('event', 'created');
        if (! $hasCreatedEvent) {
            $creator = $task->created_by_user_id ? User::find($task->created_by_user_id) : null;
            $history->push([
                'id' => 'created',
                'event' => 'created',
                'causer' => $creator?->first_name ?? 'System',
                'created_at' => $task->created_at,
                'changes' => $this->formatSyntheticCreated($task),
            ]);
        }

        return $history;
    }

    /**
     * Format activity log changes into human-readable items.
     *
     * Each item is ['label' => string, 'old' => ?string, 'new' => ?string].
     *
     * @return array<array{label: string, old: ?string, new: ?string}>
     */
    private function formatActivityChanges(\Spatie\Activitylog\Models\Activity $activity): array
    {
        if ($activity->event === 'deleted') {
            return [['label' => 'Deleted this task', 'old' => null, 'new' => null]];
        }

        if ($activity->event === 'created') {
            return $this->formatCreatedChanges($activity);
        }

        $changes = [];
        $new = $activity->properties['attributes'] ?? [];
        $old = $activity->properties['old'] ?? [];

        $labels = [
            'title' => 'Title',
            'type' => 'Type',
            'start_date' => 'Start Date',
            'end_date' => 'End Date',
            'progress' => 'Progress',
            'vendor_id' => 'Vendor',
            'vendor_status' => 'Vendor Status',
            'order' => 'Order',
            'parent_task_id' => 'Parent Task',
        ];

        foreach ($new as $field => $newValue) {
            $oldValue = $old[$field] ?? null;

            if ($field === 'options') {
                $optionChanges = $this->formatOptionsChanges($oldValue, $newValue);
                $changes = array_merge($changes, $optionChanges);
                continue;
            }

            if ($field === 'notes') {
                continue;
            }

            if ($field === 'user_ids') {
                $changes[] = ['label' => 'Team members updated', 'old' => null, 'new' => null];
                continue;
            }

            if ($field === 'vendor_id') {
                $oldVendor = $oldValue ? Vendor::find($oldValue)?->name : null;
                $newVendor = $newValue ? Vendor::find($newValue)?->name : null;
                $changes[] = ['label' => 'Vendor', 'old' => $oldVendor, 'new' => $newVendor];
                continue;
            }

            if (in_array($field, ['start_date', 'end_date'])) {
                continue;
            }

            if ($field === 'progress') {
                $changes[] = ['label' => 'Progress', 'old' => $oldValue !== null ? "{$oldValue}%" : null, 'new' => "{$newValue}%"];
                continue;
            }

            $label = $labels[$field] ?? ucfirst(str_replace('_', ' ', $field));
            $changes[] = ['label' => $label, 'old' => $oldValue, 'new' => $newValue];
        }

        if (empty($changes)) {
            return [['label' => 'Task updated', 'old' => null, 'new' => null]];
        }

        return $changes;
    }

    /**
     * Format changes to the options JSON field.
     *
     * @return array<array{label: string, old: ?string, new: ?string}>
     */
    private function formatOptionsChanges(mixed $old, mixed $new): array
    {
        $changes = [];
        $old = is_string($old) ? json_decode($old, true) : (array) ($old ?? []);
        $new = is_string($new) ? json_decode($new, true) : (array) ($new ?? []);

        // Schedule dates + times
        $oldDates = (array) ($old['dates'] ?? []);
        $newDates = (array) ($new['dates'] ?? []);
        $oldTimes = (array) ($old['time_settings'] ?? []);
        $newTimes = (array) ($new['time_settings'] ?? []);
        $datesChanged = $oldDates != $newDates;

        $formatDateWithTime = function (string $date, array $timeSettings): string {
            $formatted = Carbon::parse($date)->format('D, M j, Y');
            $setting = (array) ($timeSettings[$date] ?? []);
            $time = $this->formatTimeSetting($setting);

            return $time ? "{$formatted} · {$time}" : $formatted;
        };

        if ($datesChanged) {
            // Only show dates that were actually removed or added
            $removed = array_diff($oldDates, $newDates);
            $added = array_diff($newDates, $oldDates);

            $oldLines = collect($removed)->map(fn ($d) => $formatDateWithTime($d, (array) $oldTimes))->values()->all();
            $newLines = collect($added)->map(fn ($d) => $formatDateWithTime($d, (array) $newTimes))->values()->all();

            $changes[] = [
                'label' => 'Date',
                'old' => $oldLines ?: null,
                'new' => $newLines ?: null,
            ];
        } elseif ($oldTimes != $newTimes) {
            // Only times changed — show per-date time diffs
            $allDateKeys = array_unique(array_merge(array_keys((array) $oldTimes), array_keys((array) $newTimes)));
            sort($allDateKeys);

            foreach ($allDateKeys as $dateKey) {
                $oldSetting = (array) (((array) $oldTimes)[$dateKey] ?? []);
                $newSetting = (array) (((array) $newTimes)[$dateKey] ?? []);

                if ($oldSetting == $newSetting) {
                    continue;
                }

                $dateLabel = Carbon::parse($dateKey)->format('D, M j');
                $oldTime = $this->formatTimeSetting($oldSetting);
                $newTime = $this->formatTimeSetting($newSetting);

                $changes[] = ['label' => "Time ({$dateLabel})", 'old' => $oldTime, 'new' => $newTime];
            }
        }

        // Checklist changes
        $oldChecklist = $old['checklist'] ?? [];
        $newChecklist = $new['checklist'] ?? [];
        if ($oldChecklist != $newChecklist) {
            $changes[] = ['label' => 'Checklist updated', 'old' => null, 'new' => null];
        }

        return $changes;
    }

    private function formatCreatedChanges(\Spatie\Activitylog\Models\Activity $activity): array
    {
        return [['label' => 'Task created', 'old' => null, 'new' => null]];
    }

    private function formatSyntheticCreated(Task $task): array
    {
        return [['label' => 'Task created', 'old' => null, 'new' => null]];
    }

    private function formatTimeSetting(array $setting): ?string
    {
        $start = $setting['start_time'] ?? null;
        $end = $setting['end_time'] ?? null;

        if (! $start && ! $end) {
            return null;
        }

        $format = fn ($t) => Carbon::parse($t)->minute === 0
            ? Carbon::parse($t)->format('gA')
            : Carbon::parse($t)->format('g:iA');

        if ($start && $end) {
            if ($start === $end) {
                return $format($start);
            }

            return $format($start) . '–' . $format($end);
        }

        return $start ? $format($start) : $format($end);
    }

    /**
     * Add a participant email to the meeting.
     */
    public function addMeetingParticipant(string $email): void
    {
        $email = strtolower(trim($email));

        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->addError('form.meeting_participants', 'Please enter a valid email address.');
            return;
        }

        if (in_array($email, $this->form->meeting_participants, true)) {
            $this->addError('form.meeting_participants', 'This participant has already been added.');
            return;
        }

        $this->form->meeting_participants[] = $email;
        $this->resetErrorBag('form.meeting_participants');
    }

    /**
     * Remove a participant email from the meeting. When the email belongs to
     * a team member, they come off the Team Members select too — the two
     * lists mirror each other for the team, in both directions.
     */
    public function removeMeetingParticipant(int $index): void
    {
        $removed = strtolower(trim((string) ($this->form->meeting_participants[$index] ?? '')));

        unset($this->form->meeting_participants[$index]);
        $this->form->meeting_participants = array_values($this->form->meeting_participants);

        $teamUserId = array_search($removed, $this->teamMemberEmails(), true);

        if ($teamUserId !== false) {
            $this->form->user_ids = array_values(array_filter(
                (array) ($this->form->user_ids ?? []),
                fn ($id) => (int) $id !== $teamUserId,
            ));
        }
    }

    /**
     * Reset form and dependency fields to initial state
     */
    private function resetFormFields()
    {
        $this->form->reset();
        $this->resetErrorBag();
        $this->pendingSmsTasks = [];
        $this->selectedPredecessorId = null;
        $this->dependencyType = 'finish_to_start';
        $this->homeownerSlotIndex = null;
        $this->homeownerExactTime = null;
        $this->lagDays = 0;
    }

    /**
     * Clear all time settings for all selected dates
     */
    public function clearAllTimes()
    {
        $this->form->time_settings = [];
    }

    /**
     * When the task type changes to Meet, auto-populate meeting participants.
     */
    public function updatedFormType(): void
    {
        if ($this->form->type === 'Meet') {
            $this->syncMeetingParticipants();

            // Meets are single-day: a task drafted with several days keeps
            // only its last one when it becomes a Meet.
            if (count($this->form->dates) > 1) {
                $keep = collect($this->form->dates)->sort()->last();
                $this->form->dates = [$keep];
                $this->form->time_settings = array_intersect_key(
                    (array) $this->form->time_settings,
                    [$keep => true],
                );
            }
            $this->previousDates = $this->form->dates;

            // Times set while the task was still a plain Task mirrored the
            // start — becoming a Meet upgrades them to the 30-minute block.
            foreach ((array) $this->form->time_settings as $date => $settings) {
                $start = trim((string) ($settings['start_time'] ?? ''));
                $end = trim((string) ($settings['end_time'] ?? ''));

                if (! empty($settings['use_time']) && $start !== '' && ($end === '' || $end <= $start)) {
                    $this->form->time_settings[$date]['end_time'] = $this->defaultEndTime($start);
                }
            }
        }
    }

    /**
     * When the project changes and type is Meet, refresh meeting participants.
     */
    public function updatedFormProjectId(): void
    {
        if ($this->form->type === 'Meet') {
            $this->syncMeetingParticipants();
        }
    }

    /**
     * When team members change and type is Meet, refresh meeting participants.
     */
    public function updatedFormUserIds(): void
    {
        if ($this->form->type === 'Meet') {
            $this->syncMeetingParticipants();
        }
    }

    /**
     * When vendor changes and type is Meet, refresh meeting participants.
     */
    public function updatedFormVendorId(): void
    {
        if ($this->form->type === 'Meet') {
            $this->syncMeetingParticipants();
        }
    }

    /**
     * Ensure end_time follows whenever a start_time changes.
     *
     * @param  mixed  $value
     */
    public function updated($property, $value): void
    {
        if (! is_string($property) || ! str_starts_with($property, 'form.time_settings.')) {
            return;
        }

        // The update arrives in one of two shapes: the start_time LEAF
        // ("…2026-08-11.start_time" => "12:00"), or — what the time-picker
        // actually sends — the whole DAY OBJECT ("…2026-08-11" =>
        // ['use_time' => true, 'start_time' => '12:00']).
        if (str_ends_with($property, '.start_time')) {
            $date = substr($property, strlen('form.time_settings.'), -strlen('.start_time'));
            $start = is_string($value) ? trim($value) : '';
            $endMissing = true; // an explicit start move always re-derives the end
        } else {
            $date = substr($property, strlen('form.time_settings.'));

            if (str_contains($date, '.')) {
                return; // some other leaf (end_time, use_time) — nothing to derive
            }

            $start = trim((string) (is_array($value) ? ($value['start_time'] ?? '') : ''));
            $end = trim((string) (is_array($value) ? ($value['end_time'] ?? '') : ''));
            // Whole-object updates also fire when the END is edited — only
            // fill when the end is absent or no longer after the start.
            $endMissing = $end === '' || $end <= $start;
        }

        if ($date === '' || $start === '' || ! $endMissing || ! isset($this->form->time_settings[$date])) {
            return;
        }

        $this->form->time_settings[$date]['end_time'] = $this->defaultEndTime($start);
    }

    /**
     * The times the homeowner picked on the public scheduling page, read
     * LIVE off their lead — never a stamped copy that can go stale. Resolves
     * project → client → its users' latest lead, matching by user link first
     * and by email as the fallback (some older leads lost their user link).
     * Only future, still-bookable slots are offered.
     *
     * @return array{times: array<int, array{date: string, time: string}>, updated: ?string, preference: ?string}|null
     */
    #[Computed]
    public function homeownerAvailability(): ?array
    {
        if ($this->form->type !== 'Meet' || ! $this->form->project_id) {
            return null;
        }

        // Straight by client_id — Project::client() is a vendor-scoped
        // HasOneThrough over the pivot, which is the wrong question here:
        // we want THE project's client, not "the client as seen through the
        // current vendor's pivot rows".
        $clientId = \App\Models\Project::withoutGlobalScopes()
            ->find($this->form->project_id)
            ?->client_id;

        $client = $clientId ? \App\Models\Client::withoutGlobalScopes()->find($clientId) : null;

        if (! $client) {
            return null;
        }

        // Shared resolver: excludes trashed leads (a deleted stub must not
        // shadow the real one) and prefers freshest availability.
        $lead = \App\Models\Lead::latestForClient($client);

        $times = collect((array) ($lead?->lead_data['availability'] ?? []))
            ->filter(fn ($slot) => is_array($slot) && \App\Models\Lead::slotIsBookable($slot))
            ->values()
            ->all();

        if ($times === []) {
            return null;
        }

        return [
            'times' => $times,
            'updated' => $lead->lead_data['availability_updated_at'] ?? null,
            'preference' => $lead->lead_data['meeting_preference'] ?? null,
        ];
    }

    /**
     * Reflect an already-booked Meet in the two-stage picker on modal open:
     * check the offered slot the booking sits on and highlight its exact
     * time, so the confirmed appointment reads back instead of a blank picker.
     */
    protected function seedHomeownerSelection(): void
    {
        $this->homeownerSlotIndex = null;
        $this->homeownerExactTime = null;

        $date = $this->form->dates[0] ?? null;
        $slots = $this->homeownerAvailability['times'] ?? [];

        if (! $date || $slots === []) {
            return;
        }

        foreach ($slots as $index => $slot) {
            if (($slot['date'] ?? null) === $date) {
                $this->homeownerSlotIndex = $index;
                break;
            }
        }

        $start = data_get($this->form->time_settings, $date . '.start_time');
        if ($this->homeownerSlotIndex !== null && is_string($start) && $start !== '') {
            $this->homeownerExactTime = $start;
        }
    }

    /**
     * Selecting a homeowner slot books its day into the form (arrival at the
     * window start), then offers the exact half-hour starts — the same
     * two-stage flow the lead composer's Availability section uses, so the
     * chips read identically everywhere.
     */
    public function applyHomeownerTime(int $index): void
    {
        $slot = $this->homeownerAvailability['times'][$index] ?? null;

        if (! $slot) {
            return;
        }

        $this->homeownerSlotIndex = $index;
        $this->homeownerExactTime = null;

        $date = $slot['date'];
        $window = \App\Models\Lead::parseSlotTimes((string) $slot['time']);

        $this->form->dates = [$date];
        $this->previousDates = [$date];
        $this->form->time_settings = [
            $date => $window
                ? ['use_time' => true, 'start_time' => $window[0], 'end_time' => $this->defaultEndTime($window[0])]
                : ['use_time' => false],
        ];
    }

    /**
     * Exact start-time choices inside the selected homeowner slot, on the
     * half hour — "4-6 PM" offers 4:00, 4:30, 5:00, 5:30.
     *
     * @return array<int, array{value: string, label: string}>
     */
    #[Computed]
    public function homeownerExactOptions(): array
    {
        $slot = $this->homeownerSlotIndex !== null
            ? ($this->homeownerAvailability['times'][$this->homeownerSlotIndex] ?? null)
            : null;

        $window = $slot ? \App\Models\Lead::parseSlotTimes((string) $slot['time']) : null;

        if (! $window && $slot && strcasecmp(trim((string) $slot['time']), 'Anytime') === 0) {
            // "Anytime" is the whole bookable day, not an unparseable window —
            // same treatment as the lead composer, so both pickers offer the
            // identical exact-time chips.
            $window = \App\Livewire\Leads\PickTimes::dayBounds();
        }

        if (! $window) {
            return [];
        }

        $cursor = Carbon::createFromFormat('H:i', $window[0]);
        $end = Carbon::createFromFormat('H:i', $window[1]);
        $options = [];

        while ($cursor < $end) {
            $options[] = ['value' => $cursor->format('H:i'), 'label' => $cursor->format('g:i A')];
            $cursor->addMinutes(self::MEET_MINUTES);
        }

        return $options;
    }

    /** Narrow the selected slot to an exact start; the Meet runs 30min from it. */
    public function selectHomeownerExactTime(string $value): void
    {
        $slot = $this->homeownerSlotIndex !== null
            ? ($this->homeownerAvailability['times'][$this->homeownerSlotIndex] ?? null)
            : null;

        if (! $slot || ! collect($this->homeownerExactOptions)->contains(fn ($o) => $o['value'] === $value)) {
            return;
        }

        $this->homeownerExactTime = $value;
        $this->form->dates = [$slot['date']];
        $this->previousDates = [$slot['date']];
        $this->form->time_settings = [
            $slot['date'] => ['use_time' => true, 'start_time' => $value, 'end_time' => $this->defaultEndTime($value)],
        ];
    }

    /**
     * Meets saved before the 30-minute rule carry a start with no end —
     * reading one back into the form fills the default, so the modal never
     * shows an open-ended meeting and Update can't re-save one.
     */
    protected function fillMissingMeetEndTimes(): void
    {
        foreach ((array) $this->form->time_settings as $date => $settings) {
            $start = trim((string) ($settings['start_time'] ?? ''));

            if (! empty($settings['use_time']) && $start !== ''
                && trim((string) ($settings['end_time'] ?? '')) === '') {
                $this->form->time_settings[$date]['end_time'] = $this->defaultEndTime($start);
            }
        }
    }

    /**
     * Where a day's end time lands when the start moves.
     *
     * A Meet is a meeting someone has to be at, so it gets a real duration:
     * end follows start by MEET_MINUTES. Everything else keeps the plain
     * mirror it always had.
     */
    private function defaultEndTime(string $startTime): string
    {
        if ($this->form->type !== 'Meet') {
            return $startTime;
        }

        return $this->shiftTime($startTime, self::MEET_MINUTES) ?? $startTime;
    }

    /**
     * $time plus $minutes as H:i, or null when it can't be parsed or would
     * roll into the next day — a 23:45 start keeps the plain mirror rather
     * than proposing tomorrow morning.
     */
    private function shiftTime(string $time, int $minutes): ?string
    {
        try {
            $start = Carbon::createFromFormat('H:i', substr(trim($time), 0, 5));
        } catch (\Exception $e) {
            return null;
        }

        $end = $start->copy()->addMinutes($minutes);

        return $end->isSameDay($start) ? $end->format('H:i') : null;
    }

    /**
     * The earliest end the picker offers for a day. A Meet can't end when it
     * starts, so its options open at start + MEET_MINUTES; anything else may
     * end on its start. Falls back to the general floor with no start picked.
     */
    public function minimumEndTime(string $date): string
    {
        $start = data_get($this->form->time_settings, "$date.start_time");

        if (! is_string($start) || trim($start) === '') {
            return '06:00';
        }

        return $this->defaultEndTime($start);
    }

    /**
     * Sync meeting participants: keep any manually-added emails and merge in resolved defaults.
     */
    private function syncMeetingParticipants(): void
    {
        $defaults = $this->resolveDefaultMeetingParticipants();
        $current = $this->form->meeting_participants;
        $excluded = $this->resolveExcludedMeetingParticipants();

        // Team members' participation FOLLOWS the Team Members select, both
        // ways: deselecting Greg up top must take him off the invite too —
        // merging defaults only ever added people, so a deselected teammate
        // used to linger as a participant forever.
        $teamPool = $this->teamMemberEmails();
        $selectedIds = array_map('intval', (array) ($this->form->user_ids ?? []));
        $selectedTeamEmails = array_values(array_intersect_key(
            $teamPool,
            array_flip($selectedIds),
        ));

        $this->form->meeting_participants = collect($defaults)
            ->merge($current)
            ->map(fn (string $email) => strtolower(trim($email)))
            ->reject(fn (string $email) => in_array($email, $excluded, true))
            ->reject(fn (string $email) => in_array($email, $teamPool, true)
                && ! in_array($email, $selectedTeamEmails, true))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * The vendor team's emails keyed by user id — the pool the Team Members
     * select draws from, lowercased to match participant normalization.
     *
     * @return array<int, string>
     */
    private function teamMemberEmails(): array
    {
        return $this->employees
            ->filter(fn ($user) => filled($user->email))
            ->mapWithKeys(fn ($user) => [(int) $user->id => strtolower(trim((string) $user->email))])
            ->all();
    }

    /**
     * Resolve emails that must never be auto-kept in Participants.
     *
     * The owning company's direct business email (e.g. a generic "crew@" inbox)
     * should not appear as a meeting attendee.
     *
     * @return string[]
     */
    private function resolveExcludedMeetingParticipants(): array
    {
        return \App\Services\MeetingParticipants::excluded($this->meetingProject());
    }

    private function meetingProject(): ?\App\Models\Project
    {
        return $this->form->project_id
            ? \App\Models\Project::with('client.users')->find($this->form->project_id)
            : null;
    }

    /**
     * Resolve default meeting participant emails from team members, client, and selected vendor.
     *
     * @return string[]
     */
    private function resolveDefaultMeetingParticipants(): array
    {
        return \App\Services\MeetingParticipants::defaults(
            $this->meetingProject(),
            (array) ($this->form->user_ids ?? []),
            is_numeric($this->form->vendor_id) ? (int) $this->form->vendor_id : null,
        );
    }

    /**
     * When dates change, auto-enable arrival time for newly added dates
     * if the main arrival time toggle is already on.
     */
    /**
     * The dates as of the previous calendar interaction — what lets the Meet
     * rule below tell WHICH day a click just added.
     *
     * @var array<int, string>
     */
    public array $previousDates = [];

    public function updatedFormDates(): void
    {
        sort($this->form->dates);

        // A Meet is one appointment on one day. The calendar stays the shared
        // multi-select control, but picking another day MOVES the meet there
        // instead of growing the selection.
        if ($this->form->type === 'Meet' && count($this->form->dates) > 1) {
            $added = array_values(array_diff($this->form->dates, $this->previousDates));
            $keep = $added !== [] ? end($added) : end($this->form->dates);

            $this->form->dates = [$keep];
            $this->form->time_settings = array_intersect_key(
                (array) $this->form->time_settings,
                [$keep => true],
            );
        }

        $this->previousDates = $this->form->dates;

        $hasArrivalTimeOn = collect($this->form->time_settings)->contains('use_time', true);

        if (! $hasArrivalTimeOn) {
            return;
        }

        // Find source time settings from the last configured date
        $sourceSettings = null;
        foreach (array_reverse($this->form->dates) as $date) {
            $s = $this->form->time_settings[$date] ?? [];
            if (! empty($s['use_time']) && ! empty($s['start_time'])) {
                $sourceSettings = $s;
                break;
            }
        }

        foreach ($this->form->dates as $date) {
            if (! isset($this->form->time_settings[$date])) {
                $this->form->time_settings[$date] = [
                    'use_time' => true,
                    'start_time' => $sourceSettings['start_time'] ?? null,
                    'end_time' => $sourceSettings['end_time'] ?? null,
                ];
            }
        }
    }

    /**
     * Toggle arrival time on/off for ALL selected dates at once.
     */
    public function toggleAllArrivalTimes(bool $enabled): void
    {
        // Find the first date that already has times configured (for copying)
        $sourceSettings = null;

        if ($enabled) {
            foreach ($this->form->dates as $date) {
                $s = $this->form->time_settings[$date] ?? [];
                if (! empty($s['start_time'])) {
                    $sourceSettings = $s;
                    break;
                }
            }
        }

        foreach ($this->form->dates as $date) {
            $this->form->time_settings[$date] = array_merge(
                $this->form->time_settings[$date] ?? [],
                [
                    'use_time' => $enabled,
                    'start_time' => $enabled ? ($this->form->time_settings[$date]['start_time'] ?? $sourceSettings['start_time'] ?? null) : ($this->form->time_settings[$date]['start_time'] ?? null),
                    'end_time' => $enabled ? ($this->form->time_settings[$date]['end_time'] ?? $sourceSettings['end_time'] ?? null) : ($this->form->time_settings[$date]['end_time'] ?? null),
                ],
            );
        }
    }

    /**
     * Copy times from the first date that has them to the given date.
     * Called via x-on:change when use_time toggle fires.
     */
    public function copyTimesToDate(string $targetDate): void
    {
        // Only act when use_time is ON for this date
        if (! ($this->form->time_settings[$targetDate]['use_time'] ?? false)) {
            return;
        }

        // Already has times set — nothing to copy
        if (! empty($this->form->time_settings[$targetDate]['start_time'])) {
            return;
        }

        // Find the first other date that has times configured
        foreach ($this->form->dates as $date) {
            if ($date === $targetDate) {
                continue;
            }

            $settings = $this->form->time_settings[$date] ?? [];

            if (! empty($settings['start_time'])) {
                $this->form->time_settings[$targetDate]['start_time'] = $settings['start_time'];
                $this->form->time_settings[$targetDate]['end_time'] = $settings['end_time'] ?? null;
                break;
            }
        }
    }

    public function toggleArrivalTime(string $date): void
    {
        $current = data_get($this->form->time_settings, "$date.use_time", false);

        data_set($this->form->time_settings, "$date.use_time", ! $current);
    }

    /**
     * Fill in an end time from the start, unless one is already set.
     */
    public function updateEndTime($date)
    {
        if (!isset($this->form->time_settings[$date]['start_time'])) {
            return;
        }

        $startTime = $this->form->time_settings[$date]['start_time'];
        $existingEnd = $this->form->time_settings[$date]['end_time'] ?? null;

        if (is_string($existingEnd) && trim($existingEnd) !== '') {
            // End time is already set; preserve it.
            return;
        }

        if (! is_string($startTime) || trim($startTime) === '') {
            return;
        }

        $this->form->time_settings[$date]['end_time'] = $this->defaultEndTime($startTime);
    }

    /**
     * Apply time settings from one date to all other dates
     * Only copies start/end times if this is the only date with use_time enabled
     * Does not toggle use_time on for other dates
     */
    public function applyTimeToAllDates($sourceDate)
    {
        if (!isset($this->form->time_settings[$sourceDate])) {
            return;
        }

        // Count how many dates have use_time enabled
        $enabledCount = 0;
        foreach ($this->form->dates as $date) {
            if ($this->form->time_settings[$date]['use_time'] ?? false) {
                $enabledCount++;
            }
        }

        // Only propagate if this is the only date with use_time enabled
        if ($enabledCount > 1) {
            return;
        }

        $sourceSettings = $this->form->time_settings[$sourceDate];

        foreach ($this->form->dates as $date) {
            if ($date !== $sourceDate) {
                $this->form->time_settings[$date] = array_merge(
                    $this->form->time_settings[$date] ?? [],
                    [
                        // Preserve existing use_time state, default to false
                        'use_time' => $this->form->time_settings[$date]['use_time'] ?? false,
                        'start_time' => $sourceSettings['start_time'] ?? null,
                        'end_time' => $sourceSettings['end_time'] ?? null,
                    ]
                );
            }
        }
    }

    /**
     * Set the view text configuration based on mode
     */
    private function setupViewText(string $mode)
    {
        $config = [
            'create' => [
                'card_title' => 'Create Task',
                'button_text' => 'Create',
                'form_submit' => 'save',
            ],
            'edit' => [
                'card_title' => 'Edit Task',
                'button_text' => 'Update',
                'form_submit' => 'edit',
            ],
            'duplicate' => [
                'card_title' => 'Duplicate Task',
                'button_text' => 'Create',
                'form_submit' => 'save',
            ],
        ];

        $this->view_text = $config[$mode] ?? $config['create'];
    }

    /**
     * Handle common task operations (show modal, dispatch events)
     */
    private function handleTaskOperation(string $operation, ?Task $task = null)
    {
        if ($operation === 'start' && $task) {
            $this->dispatch('task-operation-started', taskId: $task->id)->to(CardsIndex::class);
        } elseif ($operation === 'complete') {
            $this->refreshPlannerComponents();
            $this->modal('task_create_form_modal')->close();
            $this->dispatch('task-operation-completed')->to(CardsIndex::class);
        }
    }

    /**
     * Show a standardized toast notification
     */
    private function showNotification(string $action)
    {
        $messages = [
            'created' => 'Task Created',
            'updated' => 'Task Updated',
            'removed' => 'Task Removed',
            'restored' => 'Task Restored',
            'dependency_added' => 'Dependency Added',
            'dependency_removed' => 'Dependency Removed',
        ];

        $descriptions = [
            'dependency_added' => 'Task dependency has been created.',
            'dependency_removed' => 'Task dependency has been removed.',
        ];

        Flux::toast(
            duration: 3000,
            position: 'top right',
            variant: 'success',
            heading: $messages[$action] ?? 'Action Completed',
            text: $descriptions[$action] ?? '',
        );
    }

    /**
     * Helper method to refresh all planner components
     */
    private function refreshPlannerComponents()
    {
        $this->dispatch('refreshComponent')->to(CardsIndex::class);
        $this->dispatch('refreshComponent')->to(PlannerTaskCard::class);
        $this->dispatch('refreshComponent')->to(UpcomingTasks::class);
        $this->dispatch('refreshComponent')->to(UserTasks::class);
        $this->dispatch('refreshComponent')->to(VendorTasks::class);
        $this->dispatch('refreshComponent')->to(UpcomingClientTasks::class);
        $this->dispatch('refreshSchedulePreview')->to(SendScheduleModal::class);
    }

    /**
     * Lightweight refresh for dependency-only changes. Only the gantt arrows
     * depend on dependency data, so skip the heavy full-component refresh chain.
     */
    private function refreshDependencyComponents(): void
    {
        $this->dispatch('dependenciesUpdated')->to(CardsIndex::class);
    }

    /**
     * Copy task data for duplication
     */
    private function copyTaskData(Task $task)
    {
        $this->form->title = $task->title;
        $this->form->type = $task->type;
        $this->form->project_id = $task->project_id;
        $this->form->vendor_id = $task->vendor_id;
        $this->form->user_ids = $task->user_ids;
        $this->form->notes = $task->notes;
        $this->form->meeting_location_type = $task->options->meeting_location_type ?? 'virtual';
        $meetingParticipants = $task->options->meeting_participants ?? [];
        $this->form->meeting_participants = is_array($meetingParticipants) ? $meetingParticipants : (array) $meetingParticipants;
        
        // Set up parent-child relationship
        if ($task->parent_task_id) {
            // If current task is already a child, make duplicate a sibling
            $this->form->parent_task_id = $task->parent_task_id;
        } else {
            // If current task is standalone/parent, make duplicate its child
            $this->form->parent_task_id = $task->id;
        }
        
        // Leave dates empty as requested
        $this->form->dates = [];
    }

    public function addTask($project_id = null, $date = null, $vendor_id = null, $user_ids = [], $client_id = null)
    {
        $this->hydrated = true;
        $this->resetFormFields();
        $this->setupViewText('create');
        
        $this->form->dates = $date ? [Carbon::parse($date)->format('Y-m-d')] : [];
        $this->previousDates = $this->form->dates;

        // Set the appropriate fields based on what was passed
        if ($project_id) {
            $this->form->project_id = $project_id;
            $this->ensureProjectOptionLoaded((int) $project_id);
        }

        if ($client_id) {
            $clientProjects = Project::query()
                ->where('client_id', $client_id)
                ->with('latestStatus')
                ->orderByDesc('created_at')
                ->get();

            $this->projects = $clientProjects->all();

            if (! $this->form->project_id) {
                $preferredProject = $this->preferredClientProject($clientProjects);

                if ($preferredProject) {
                    $this->form->project_id = $preferredProject->id;
                }
            }
        }

        if (!$project_id && !$client_id && auth()->user()?->primary_vendor_id) {
            $this->projects = Project::query()
                ->where('belongs_to_vendor_id', auth()->user()->primary_vendor_id)
                ->with('latestStatus')
                ->orderByDesc('created_at')
                ->get()
                ->all();
        }

        if ($vendor_id) {
            $this->form->vendor_id = $vendor_id;
        }

        if (!empty($user_ids)) {
            $this->form->user_ids = $user_ids;
        }

        $this->modal('task_create_form_modal')->show();
    }

    /**
     * Create a task from an AI extraction of an SMS message and open it in the
     * full editor (edit mode) so every option — Dates, Notes/Checklist,
     * Dependencies, History — is available for review and refinement.
     *
        * @param  array{task_id?: ?int, title?: ?string, type?: ?string, project_id?: ?int, client_id?: ?int, vendor_id?: ?int, date?: ?string, start_time?: ?string, end_time?: ?string, user_ids?: array<int, int>, checklist?: array<int, array{text: string, completed: bool}>, sms_media_urls?: array<int, string>}  $payload
     */
    public function prefillTaskFromSms(array $payload): void
    {
        $this->hydrated = true;
        $this->resetFormFields();

        $clientId = isset($payload['client_id']) ? (int) $payload['client_id'] : null;
        $projectId = isset($payload['project_id']) ? (int) $payload['project_id'] : null;
        $vendorId = ! empty($payload['vendor_id']) ? (int) $payload['vendor_id'] : null;

        $this->pendingSmsTasks = collect($payload['additional_tasks'] ?? [])
            ->filter(fn ($row) => is_array($row) && trim((string) ($row['title'] ?? '')) !== '')
            ->map(fn (array $row) => [
                'title' => $row['title'],
                'type' => $row['type'] ?? 'Task',
                'project_id' => $projectId,
                'client_id' => $clientId,
                'vendor_id' => $vendorId,
                'notes' => $row['notes'] ?? null,
                'date' => $row['date'] ?? null,
                'start_time' => $row['start_time'] ?? null,
                'end_time' => $row['end_time'] ?? null,
                'user_ids' => $row['user_ids'] ?? [],
                'checklist' => [],
                'multi_time' => true,
            ])
            ->values()
            ->all();

        $existingTaskId = isset($payload['task_id']) ? (int) $payload['task_id'] : null;
        $existingTask = $existingTaskId ? Task::query()->find($existingTaskId) : null;

        if (! $existingTask && $projectId) {
            $existingTask = $this->findSimilarSmsTask($projectId, (string) ($payload['title'] ?? ''), $payload['date'] ?? null);
        }

        if ($existingTask) {
            $this->ensureProjectOptionLoaded((int) $existingTask->project_id);
            $this->form->setTask($existingTask);

            if ($this->form->type === 'Meet') {
                $this->syncMeetingParticipants();
            }
        }

        $this->setupViewText($existingTask ? 'edit' : 'create');

        if (! $existingTask && $clientId) {
            $this->projects = Project::query()
                ->where('client_id', $clientId)
                ->with('latestStatus')
                ->orderByDesc('created_at')
                ->get()
                ->all();
        }

        if (! $existingTask && $projectId) {
            $this->form->project_id = $projectId;
            $this->ensureProjectOptionLoaded($projectId);
        }

        if (! empty($payload['title'])) {
            $this->form->title = $payload['title'];
        }

        $type = $payload['type'] ?? 'Task';
        if (in_array($type, ['Task', 'Milestone', 'Meet', 'Reminder'], true)) {
            $this->form->type = $type;
        }

        if (! $existingTask && ! empty($payload['notes']) && trim((string) $this->form->notes) === '') {
            $this->form->notes = trim((string) $payload['notes']);
        }

        if (! empty($payload['vendor_id'])) {
            $this->form->vendor_id = (int) $payload['vendor_id'];
        }

        if (! empty($payload['user_ids']) && is_array($payload['user_ids'])) {
            $this->form->user_ids = array_values(array_unique(array_map('intval', $payload['user_ids'])));
        }

        if (! empty($payload['checklist']) && is_array($payload['checklist'])) {
            $this->form->checklist = array_values(array_filter(
                array_map(function ($item): ?array {
                    $text = is_array($item) ? trim((string) ($item['text'] ?? '')) : trim((string) $item);

                    return $text === '' ? null : ['text' => $text, 'completed' => false];
                }, $payload['checklist'])
            ));
        }

        $date = ! empty($payload['date']) ? Carbon::parse($payload['date'])->format('Y-m-d') : null;

        if ($date) {
            $dates = $existingTask
                ? collect($this->form->dates)->push($date)->filter()->unique()->values()->all()
                : [$date];

            $this->form->dates = $dates;

            $startTime = $payload['start_time'] ?? null;

            if (! empty($startTime)) {
                $timeSettings = (array) $this->form->time_settings;
                $timeSettings[$date] = [
                    'use_time' => true,
                    'start_time' => $startTime,
                    'end_time' => $payload['end_time'] ?? $this->defaultEndTime($startTime),
                ];
                $this->form->time_settings = $timeSettings;
            }
        }

        if ($existingTask) {
            $this->attachSmsMediaToTask($existingTask, (array) ($payload['sms_media_urls'] ?? []));

            $this->modal('task_create_form_modal')->show();

            if (! empty($this->pendingSmsTasks) || ! empty($payload['multi_time'])) {
                $this->dispatch('reset-tabs');
            } else {
                $this->dispatch('task-modal-focus-arrival-times');
            }

            return;
        }

        // Persist immediately and reopen in edit mode so the full task editor
        // (Dates, Notes/Checklist, Dependencies, History) is available for the
        // user to review and refine. Dependencies and history require a saved
        // task, so creation must happen before those tabs can be shown.
        $task = $this->form->store();

        if (! $task instanceof Task) {
            $this->modal('task_create_form_modal')->show();

            return;
        }

        $this->ensureProjectOptionLoaded((int) $task->project_id);
        $this->attachSmsMediaToTask($task, (array) ($payload['sms_media_urls'] ?? []));
        $this->form->setTask($task);

        if ($this->form->type === 'Meet') {
            $this->syncMeetingParticipants();
        }

        $this->setupViewText('edit');
        $this->refreshPlannerComponents();

        $this->modal('task_create_form_modal')->show();

        if (! empty($this->pendingSmsTasks) || ! empty($payload['multi_time'])) {
            $this->dispatch('reset-tabs');
        } else {
            $this->dispatch('task-modal-focus-arrival-times');
        }
    }

    /**
     * Merge SMS image URLs into task options for follow-up context.
     *
     * @param  array<int, string>  $urls
     */
    protected function attachSmsMediaToTask(Task $task, array $urls): void
    {
        $cleanUrls = collect($urls)
            ->filter(fn ($url) => is_string($url) && trim($url) !== '')
            ->map(fn ($url) => trim((string) $url))
            ->unique()
            ->values()
            ->all();

        if ($cleanUrls === []) {
            return;
        }

        $options = (array) ($task->options ?? []);
        $existingUrls = collect((array) ($options['sms_media_urls'] ?? []))
            ->filter(fn ($url) => is_string($url) && trim($url) !== '')
            ->map(fn ($url) => trim((string) $url));

        $options['sms_media_urls'] = $existingUrls
            ->merge($cleanUrls)
            ->unique()
            ->values()
            ->all();

        $task->update(['options' => $options]);
        $task->refresh();
    }

    /**
     * SMS images attached to this task via Create Task from Message flow.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function taskSmsMediaUrls(): array
    {
        if (! $this->form->task) {
            return [];
        }

        return collect((array) data_get($this->form->task->options, 'sms_media_urls', []))
            ->filter(fn ($url) => is_string($url) && trim($url) !== '')
            ->map(fn ($url) => trim((string) $url))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Open the next secondary task parsed from the same SMS, if any remain.
     * Returns true when another task was opened so callers can skip closing.
     */
    protected function openNextPendingSmsTask(): bool
    {
        if (empty($this->pendingSmsTasks)) {
            return false;
        }

        $next = array_shift($this->pendingSmsTasks);
        $next['additional_tasks'] = $this->pendingSmsTasks;
        $this->pendingSmsTasks = [];

        $this->prefillTaskFromSms($next);

        return true;
    }

    /**
     * Find an existing task on the project with the same (or very similar) title
     * so re-running the SMS task action edits it instead of creating a duplicate.
     */
    protected function findSimilarSmsTask(int $projectId, string $title, ?string $date): ?Task
    {
        $title = strtolower(trim($title));

        if ($title === '') {
            return null;
        }

        return Task::query()
            ->where('project_id', $projectId)
            ->where(function ($query) use ($title) {
                $query->whereRaw('LOWER(TRIM(title)) = ?', [$title])
                    ->orWhereRaw('LOWER(title) LIKE ?', ['%' . $title . '%'])
                    ->orWhereRaw('? LIKE CONCAT(\'%\', LOWER(title), \'%\')', [$title]);
            })
            ->when(! empty($date), function ($query) use ($date) {
                $query->where(function ($inner) use ($date) {
                    $inner->whereDate('start_date', $date)
                        ->orWhere(function ($range) use ($date) {
                            $range->whereNotNull('start_date')
                                ->whereNotNull('end_date')
                                ->whereDate('start_date', '<=', $date)
                                ->whereDate('end_date', '>=', $date);
                        });
                });
            })
            ->latest('id')
            ->first();
    }

    public function editTask(int $task)
    {
        $this->hydrated = true;
        $task = Task::withTrashed()->findOrFail($task);

        $this->handleTaskOperation('start', $task);
        $this->resetFormFields();
        $this->setupViewText('edit');

        $this->ensureProjectOptionLoaded((int) $task->project_id);
        
        // Simply use the task as-is without reloading
        $this->form->setTask($task);
        $this->previousDates = (array) $this->form->dates;

        if ($this->form->type === 'Meet') {
            $this->syncMeetingParticipants();
            $this->seedHomeownerSelection();
            $this->fillMissingMeetEndTimes();
        }
        
        $this->modal('task_create_form_modal')->show();
        $this->dispatch('task-modal-opened');
    }

    public function duplicateTask()
    {
        // Get the current task data
        $currentTask = $this->form->task;
        $this->modal('task_create_form_modal')->close();
        
        $this->resetFormFields();
        $this->setupViewText('duplicate');
        
        // Copy relevant data from current task
        $this->copyTaskData($currentTask);
        
        // Open the modal again with the duplicated data
        $this->modal('task_create_form_modal')->show();
        $this->dispatch('task-modal-opened');
    }

    public function removeTask()
    {
        $task = $this->form->task ?? Task::withTrashed()->find($this->form->task_id);

        if (!$task) {
            return;
        }

        if ($task->trashed()) {
            $task->forceDelete();
        } else {
            $task->delete();
        }

        $this->handleTaskOperation('complete');
        $this->showNotification('removed');
    }

    public function restoreTask()
    {
        $task = $this->form->task ?? Task::onlyTrashed()->find($this->form->task_id);

        if (!$task || !$task->trashed()) {
            return;
        }

        $task->restore();

        $this->handleTaskOperation('complete');
        $this->showNotification('restored');
    }

    public function edit()
    {
        $existingTask = $this->form->task;
        $previousType = $existingTask?->type;
        $existingMeetEventId = data_get($existingTask?->options, 'nylas_meet_event.event_id');

        $this->authorize('update', $this->form->task);
        $result = $this->form->update();

        if ($result === false) {
            // The form's errors need to be copied to the component's error bag
            $formErrors = $this->form->getErrorBag();
            
            // Add each form error to the component's error bag with 'form.' prefix
            foreach ($formErrors->messages() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError("form.{$field}", $message);
                }
            }
            return; // Don't close modal
        }

        if ($this->form->task instanceof Task && $this->form->task->type === 'Meet') {
            $wasConvertedToMeet = $previousType !== 'Meet';
            $isMissingMeetEvent = ! is_string($existingMeetEventId) || trim($existingMeetEventId) === '';

            if (($wasConvertedToMeet || $isMissingMeetEvent) && $this->form->task->start_date) {
                CreateMeetTaskCalendarEvent::dispatch($this->form->task->id, auth()->id());
            } elseif (! $isMissingMeetEvent && $this->form->task->start_date) {
                UpdateMeetTaskCalendarEvent::dispatch($this->form->task->id);
            }
        }

        $this->handleTaskOperation('complete');
        $this->showNotification('updated');

        $this->openNextPendingSmsTask();
    }

    public function save()
    {
        $task = $this->form->store();

        if ($task instanceof Task && $task->type === 'Meet' && $task->start_date) {
            CreateMeetTaskCalendarEvent::dispatch($task->id, auth()->id());
        }

        $this->handleTaskOperation('complete');
        $this->showNotification('created');

        $this->openNextPendingSmsTask();
    }

    public function addDependency()
    {
        $this->validate([
            'selectedPredecessorId' => [
                'required',
                'exists:tasks,id',
                function ($attribute, $value, $fail) {
                    if ($value == $this->form->task->id) {
                        $fail('A task cannot depend on itself.');
                    }
                }
            ],
            'dependencyType' => 'required|in:finish_to_start,start_to_start,finish_to_finish,start_to_finish',
            'lagDays' => 'integer',
        ]);

        // Check for circular dependencies
        if (TaskDependency::wouldCreateCircularDependency($this->selectedPredecessorId, $this->form->task->id)) {
            $this->addError('selectedPredecessorId', 'This would create a circular dependency.');
            return;
        }

        // Check if dependency already exists
        $existingDependency = TaskDependency::where('predecessor_task_id', $this->selectedPredecessorId)
            ->where('successor_task_id', $this->form->task->id)
            ->first();

        if ($existingDependency) {
            $this->addError('selectedPredecessorId', 'This dependency already exists.');
            return;
        }

        // Create the dependency
        TaskDependency::create([
            'predecessor_task_id' => $this->selectedPredecessorId,
            'successor_task_id' => $this->form->task->id,
            'type' => $this->dependencyType,
            'lag_days' => $this->lagDays,
        ]);
        
        // Reset form fields
        $this->selectedPredecessorId = null;
        $this->lagDays = 0;

        // Refresh task data with eager loading
        $this->form->refreshTaskWithDependencies($this->form->task->id);
        
        // Lightweight refresh: only gantt arrows depend on deps
        $this->refreshDependencyComponents();
        $this->dispatch('gantt-links-changed');
        
        $this->showNotification('dependency_added');
    }

    public function removeDependency($dependencyId)
    {
        TaskDependency::find($dependencyId)->delete();
        
        // Refresh task data with eager loading
        $this->form->refreshTaskWithDependencies($this->form->task->id);
        
        // Lightweight refresh: only gantt arrows depend on deps
        $this->refreshDependencyComponents();
        $this->dispatch('gantt-links-changed');
        
        $this->showNotification('dependency_removed');
    }

    #[Computed]
    public function availableTasks()
    {
        if (!$this->form->task || !$this->form->task->project_id) {
            return collect();
        }

        $excludeIds = [$this->form->task->id];

        // Exclude tasks that are already predecessors
        $existingPredecessorIds = $this->form->task->predecessorTasks->pluck('id')->toArray();
        $excludeIds = array_merge($excludeIds, $existingPredecessorIds);

        return Task::where('project_id', $this->form->task->project_id)
                ->whereNotIn('id', $excludeIds)
                ->whereNotNull('start_date')
                ->whereNotNull('end_date')
                ->orderBy('start_date')
                ->get();
    }

    /**
     * View a dependent task by opening its edit modal
     */
    public function viewDependentTask($taskId)
    {
        // Close current task modal
        $this->modal('task_create_form_modal')->close();
        
        // Get fresh task data with no query cache
        $task = Task::withoutGlobalScopes()->findOrFail($taskId);
    
        // Dispatch the editTask event to open the task
        $this->dispatch('editTask', task: $taskId)->to('tasks.task-create');
    }

    /**
     * Check if any dependency is blocking
     */
    #[Computed]
    public function hasBlockingDependency()
    {
        if (!isset($this->form->task)) {
            return false;
        }
        
        // Check predecessor dependencies first
        foreach($this->form->task->predecessorDependencies as $dependency) {
            if($dependency->isBlocking()) {
                return true;
            }
        }
        
        // Then check successor dependencies
        foreach($this->form->task->successorDependencies as $dependency) {
            if($dependency->isBlocking()) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Add a new checklist item
     */
    public function addChecklistItem($text = '')
    {
        $this->form->checklist[] = [
            'uid' => 'c'.substr(md5(uniqid('', true)), 0, 10),
            'text' => $text,
            'completed' => false,
        ];

        // Auto-save the checklist
        $this->saveChecklistOnly();
    }

    /**
     * Remove a checklist item
     */
    public function removeChecklistItem($index)
    {
        unset($this->form->checklist[$index]);
        $this->form->checklist = array_values($this->form->checklist); // Re-index array
    }

    /**
     * Toggle visibility of completed checklist items
     */
    public function toggleCompletedChecklist()
    {
        $this->showCompletedChecklist = !$this->showCompletedChecklist;
    }

    /**
     * Toggle checklist item completion status
     */
    public function toggleChecklistItem($index)
    {
        if (isset($this->form->checklist[$index])) {
            $item = $this->form->checklist[$index];
            $isCompleted = is_array($item) ? ($item['completed'] ?? false) : ($item->completed ?? false);
            
            if (is_object($item)) {
                $item = (array) $item;
            }
            
            $item['completed'] = !$isCompleted;
            $this->form->checklist[$index] = $item;
            
            // Auto-save the checklist without closing modal
            $this->saveChecklistOnly();
        }
    }

    /**
     * Sort checklist items via drag-and-drop
     */
    public function sortChecklistItems($key, $position)
    {
        $items = $this->form->checklist;
        
        // Find the item by its original index
        $fromIndex = (int) $key;
        
        if (!isset($items[$fromIndex])) {
            return;
        }
        
        // Remove item from original position
        $item = $items[$fromIndex];
        array_splice($items, $fromIndex, 1);
        
        // Insert at new position
        array_splice($items, $position, 0, [$item]);
        
        // Re-index and update
        $this->form->checklist = array_values($items);
        
        // Auto-save
        $this->saveChecklistOnly();
    }

    /**
     * Save only the checklist without closing the modal
     */
    private function saveChecklistOnly()
    {
        $task = $this->form->task;

        if (!$task) {
            return;
        }

        // Checklist is stored in options JSON column. Re-read it and merge:
        // boxes ticked from a task card (possibly on another device) while this
        // modal sat open must not be reverted by this save.
        $fresh = $task->fresh();
        $options = json_decode(json_encode($fresh?->options ?? $task->options ?? []), true) ?: [];

        $merged = \App\Models\Task::mergeChecklist(
            $options['checklist'] ?? [],
            $this->form->checklistOriginal,
            $this->form->checklist,
        );

        $options['checklist'] = $merged;

        $task->update([
            'options' => $options,
        ]);

        // The merge result becomes the new base for the next save in this session.
        $this->form->checklist = $merged;
        $this->form->checklistOriginal = $merged;
    }

    /**
     * Save only the notes without closing the modal
     */
    public function saveNotes()
    {
        $task = $this->form->task;

        if (!$task) {
            return;
        }

        $task->update([
            'notes' => $this->form->notes,
        ]);
    }

    public function render()
    {
        return view('livewire.tasks.create');
    }

    public function confirmVendorAvailability(): void
    {
        if (! $this->form->task) {
            return;
        }

        $task = Task::find($this->form->task->id);

        if (! $task || ! $task->vendor_id) {
            Flux::toast(text: 'Vendor not assigned.', variant: 'danger');
            return;
        }

        $this->authorize('update', $task);

        if (! in_array($task->vendor_status, [Task::VENDOR_STATUS_REQUESTED, Task::VENDOR_STATUS_PROPOSED], true)) {
            Flux::toast(text: 'Task is not awaiting vendor response.', variant: 'danger');
            return;
        }

        $task->update([
            'vendor_status' => Task::VENDOR_STATUS_CONFIRMED,
        ]);

        $this->form->task->refresh();
        $this->refreshPlannerComponents();

        Flux::toast(text: 'Vendor confirmed for this task.', variant: 'success');
    }

    public function rejectVendorAvailability(): void
    {
        if (! $this->form->task) {
            return;
        }

        $task = Task::find($this->form->task->id);

        if (! $task || ! $task->vendor_id) {
            Flux::toast(text: 'Vendor not assigned.', variant: 'danger');
            return;
        }

        $this->authorize('update', $task);

        if (! in_array($task->vendor_status, [Task::VENDOR_STATUS_REQUESTED, Task::VENDOR_STATUS_PROPOSED], true)) {
            Flux::toast(text: 'Task is not awaiting vendor response.', variant: 'danger');
            return;
        }

        $task->update([
            'vendor_status' => Task::VENDOR_STATUS_REJECTED,
        ]);

        $this->form->task->refresh();
        $this->refreshPlannerComponents();

        Flux::toast(text: 'Vendor rejected for this task.', variant: 'success');
    }

    public function resetVendorAvailability(): void
    {
        if (! $this->form->task) {
            return;
        }

        $task = Task::find($this->form->task->id);

        if (! $task || ! $task->vendor_id) {
            Flux::toast(text: 'Vendor not assigned.', variant: 'danger');
            return;
        }

        $this->authorize('update', $task);

        if ($task->vendor_status !== Task::VENDOR_STATUS_CONFIRMED) {
            Flux::toast(text: 'Task is not confirmed.', variant: 'danger');
            return;
        }

        $task->update([
            'vendor_status' => Task::VENDOR_STATUS_REQUESTED,
        ]);

        $this->form->task->refresh();
        $this->refreshPlannerComponents();

        Flux::toast(text: 'Vendor status reset to requested.', variant: 'success');
    }
}