<?php

namespace App\Livewire\Vendor;

use App\Models\Task;
use App\Models\Vendor;
use Carbon\Carbon;
use Flux;
use Livewire\Attributes\Locked;
use Livewire\Component;

class AvailabilityIndex extends Component
{
    #[Locked]
    public string $token = '';

    #[Locked]
    public ?int $vendorId = null;

    public bool $valid = false;
    public string $message = '';

    /**
     * Task being edited for date proposal.
     */
    public ?int $proposingTaskId = null;
    public array $proposedDates = [];
    public array $proposedTimeSettings = [];

    /**
     * Homeowner-selectable arrival time frames and their start/end times.
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

    public function mount(string $token): void
    {
        $this->token = $token;

        $vendor = Vendor::where('availability_token', $token)->first();

        // Legacy support: old SMS links used per-task tokens.
        if (! $vendor) {
            $task = Task::where('vendor_status_token', $token)
                ->with(['vendor'])
                ->first();

            if ($task?->vendor) {
                $vendor = $task->vendor;
                $canonicalToken = $vendor->getOrCreateAvailabilityToken();

                if ($canonicalToken !== $token) {
                    $this->redirect(route('vendor.availability.index', $canonicalToken));

                    return;
                }
            }
        }

        if (! $vendor) {
            $this->valid = false;
            $this->message = 'This link is no longer valid.';

            return;
        }

        $this->valid = true;
        $this->vendorId = $vendor->id;
    }

    protected function baseTaskQuery()
    {
        return Task::where('vendor_id', $this->vendorId)
            ->where(function ($query) {
                $query
                    ->where('type', '!=', 'Reminder')
                    ->orWhereNull('type');
            })
            ->where(function ($q) {
                $q->whereIn('vendor_status', [
                    Task::VENDOR_STATUS_REQUESTED,
                    Task::VENDOR_STATUS_CONFIRMED,
                    Task::VENDOR_STATUS_REJECTED,
                    Task::VENDOR_STATUS_PROPOSED,
                ])->orWhereNull('vendor_status');
            })
            ->with(['project', 'owner']);
    }

    /**
     * Upcoming: scheduled tasks (has dates) with future/current end date.
     */
    public function getTasks()
    {
        if (! $this->vendorId) {
            return collect();
        }

        return $this->baseTaskQuery()
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('end_date', '>=', Carbon::today())
            ->orderBy('start_date')
            ->get();
    }

    /**
     * Upcoming scheduled tasks grouped by date (matching the hub schedule UI).
     *
     * Tasks with multiple selected dates appear under each of their dates.
     *
     * @return \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, Task>>
     */
    public function getGroupedTasks(): \Illuminate\Support\Collection
    {
        $tasks = $this->getTasks();

        if ($tasks->isEmpty()) {
            return collect();
        }

        $todayStr = Carbon::today()->format('Y-m-d');
        $grouped = collect();

        foreach ($tasks as $task) {
            $selectedDates = (array) data_get($task->options, 'dates', []);

            $dates = ! empty($selectedDates)
                ? array_filter($selectedDates, fn ($date) => $date >= $todayStr)
                : [$task->start_date->format('Y-m-d')];

            foreach ($dates as $dateStr) {
                if (! $grouped->has($dateStr)) {
                    $grouped[$dateStr] = collect();
                }
                $grouped[$dateStr]->push($task);
            }
        }

        return $grouped->sortKeys()->map(function ($dayTasks, $dateStr) {
            return $dayTasks->sortBy(function (Task $task) use ($dateStr) {
                $startTime = (string) data_get($task->options, "time_settings.$dateStr.start_time", '');
                $usesTime = (bool) data_get($task->options, "time_settings.$dateStr.use_time", false);
                $hasTime = $usesTime && $startTime !== '';

                return $hasTime ? '0_' . $startTime : '1';
            })->values();
        });
    }

    /**
     * Past: scheduled tasks whose end date has passed.
     */
    public function getPastTasks()
    {
        if (! $this->vendorId) {
            return collect();
        }

        return $this->baseTaskQuery()
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('end_date', '<', Carbon::today())
            ->orderByDesc('start_date')
            ->get();
    }

    /**
     * Pending: unscheduled tasks (missing start or end date).
     */
    public function getPendingTasks()
    {
        if (! $this->vendorId) {
            return collect();
        }

        return $this->baseTaskQuery()
            ->where(fn ($q) => $q->whereNull('start_date')->orWhereNull('end_date'))
            ->orderBy('id')
            ->get();
    }

    public function getVendor()
    {
        if (! $this->vendorId) {
            return null;
        }

        return Vendor::find($this->vendorId);
    }

    /**
     * Confirm vendor availability for a task.
     */
    public function confirm(int $taskId): void
    {
        $task = Task::where('id', $taskId)
            ->where('vendor_id', $this->vendorId)
            ->whereIn('vendor_status', [Task::VENDOR_STATUS_REQUESTED, Task::VENDOR_STATUS_PROPOSED])
            ->first();

        if (! $task) {
            Flux::toast(
                text: 'Task not found or already responded.',
                variant: 'danger',
            );

            return;
        }

        $task->update([
            'vendor_status' => Task::VENDOR_STATUS_CONFIRMED,
        ]);

        Flux::toast(
            text: "You confirmed \"{$task->title}\"!",
            variant: 'success',
        );
    }

    /**
     * Reject vendor availability for a task.
     */
    public function reject(int $taskId): void
    {
        $task = Task::where('id', $taskId)
            ->where('vendor_id', $this->vendorId)
            ->whereIn('vendor_status', [Task::VENDOR_STATUS_REQUESTED, Task::VENDOR_STATUS_PROPOSED])
            ->first();

        if (! $task) {
            Flux::toast(
                text: 'Task not found or already responded.',
                variant: 'danger',
            );

            return;
        }

        $task->update([
            'vendor_status' => Task::VENDOR_STATUS_REJECTED,
        ]);

        Flux::toast(
            text: "You declined \"{$task->title}\".",
            variant: 'success',
        );
    }

    /**
     * Revert a confirmed task back to pending/requested for this vendor.
     */
    public function markPending(int $taskId): void
    {
        $task = Task::where('id', $taskId)
            ->where('vendor_id', $this->vendorId)
            ->where('vendor_status', Task::VENDOR_STATUS_CONFIRMED)
            ->first();

        if (! $task) {
            Flux::toast(
                text: 'Task not found or no longer confirmed.',
                variant: 'danger',
            );

            return;
        }

        $task->update([
            'vendor_status' => Task::VENDOR_STATUS_REQUESTED,
        ]);

        Flux::toast(
            text: "You marked \"{$task->title}\" as not available.",
            variant: 'success',
        );
    }

    /**
     * Open the date proposal modal for a task.
     */
    public function openProposeDatesModal(int $taskId): void
    {
        $task = Task::where('id', $taskId)
            ->where('vendor_id', $this->vendorId)
            ->where(function ($query) {
                $query->whereIn('vendor_status', [
                    Task::VENDOR_STATUS_REQUESTED,
                    Task::VENDOR_STATUS_PROPOSED,
                    Task::VENDOR_STATUS_CONFIRMED,
                ])->orWhereNull('vendor_status');
            })
            ->first();

        if (! $task) {
            return;
        }

        $this->proposingTaskId = $taskId;

        // Pre-populate with existing dates from the task
        $existingDates = $task->options->dates ?? [];
        $this->proposedDates = ! empty($existingDates) ? (array) $existingDates : [];

        // Pre-populate time settings (convert nested stdClass to arrays)
        $existingTimeSettings = json_decode(json_encode($task->options->time_settings ?? []), true) ?? [];
        $this->proposedTimeSettings = [];

        foreach ($this->proposedDates as $date) {
            $daySettings = $existingTimeSettings[$date] ?? [];
            $this->proposedTimeSettings[$date] = [
                'use_time' => $daySettings['use_time'] ?? false,
                'start_time' => $daySettings['start_time'] ?? '',
                'end_time' => $daySettings['end_time'] ?? '',
            ];
        }

        $this->modal('vendor_propose_dates_modal')->show();
    }

    /**
     * Called when dates are updated to sync time settings.
     */
    public function updatedProposedDates(): void
    {
        // With homeowner-submitted time frames on the table, the calendar is
        // hidden and those frames are the ONLY valid days — a hand-posted
        // date outside them is dropped so the UI rule holds server-side too.
        $slots = $this->proposedPreferredSlots();

        if ($slots !== []) {
            $allowed = array_column($slots, 'date');
            $this->proposedDates = array_values(array_intersect($this->proposedDates, $allowed));
        }

        // Add new dates to time settings
        foreach ($this->proposedDates as $date) {
            if (! isset($this->proposedTimeSettings[$date])) {
                $this->proposedTimeSettings[$date] = [
                    'use_time' => false,
                    'start_time' => '',
                    'end_time' => '',
                ];
            }
        }

        // Remove dates that are no longer selected
        $this->proposedTimeSettings = array_filter(
            $this->proposedTimeSettings,
            fn ($key) => in_array($key, $this->proposedDates),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * Auto-set end time when start time changes.
     */
    public function updateEndTime(string $date): void
    {
        $settings = $this->proposedTimeSettings[$date] ?? null;

        if (! $settings || empty($settings['start_time'])) {
            return;
        }

        try {
            $startTime = Carbon::createFromFormat('H:i', $settings['start_time']);
            $this->proposedTimeSettings[$date]['end_time'] = $startTime->addMinutes(30)->format('H:i');
        } catch (\Exception $e) {
            // Ignore
        }
    }

    /**
     * Save proposed dates.
     */
    public function saveProposedDates(): void
    {
        if (empty($this->proposedDates)) {
            Flux::toast(
                text: 'Please select at least one date.',
                variant: 'warning',
            );

            return;
        }

        $task = Task::where('id', $this->proposingTaskId)
            ->where('vendor_id', $this->vendorId)
            ->where(function ($query) {
                $query->whereIn('vendor_status', [
                    Task::VENDOR_STATUS_REQUESTED,
                    Task::VENDOR_STATUS_PROPOSED,
                    Task::VENDOR_STATUS_CONFIRMED,
                ])->orWhereNull('vendor_status');
            })
            ->first();

        if (! $task) {
            Flux::toast(
                text: 'Task not found or already responded.',
                variant: 'danger',
            );

            return;
        }

        // Sort dates
        $sortedDates = collect($this->proposedDates)->sort()->values()->all();

        // Build new options with proposed dates
        $options = (array) ($task->options ?? []);
        $options['dates'] = $sortedDates;
        $options['time_settings'] = $this->proposedTimeSettings;
        $options['vendor_proposed_dates'] = $sortedDates;
        $options['vendor_proposed_at'] = now()->toISOString();

        // Update start_date and end_date based on proposed dates
        $firstDate = Carbon::parse($sortedDates[0]);
        $lastDate = Carbon::parse(end($sortedDates));

        $task->update([
            'vendor_status' => Task::VENDOR_STATUS_CONFIRMED,
            'options' => (object) $options,
            'start_date' => $firstDate,
            'end_date' => count($sortedDates) > 1 ? $lastDate : $firstDate,
        ]);

        $this->notifyTeamOfSelection($task, $sortedDates);

        $this->modal('vendor_propose_dates_modal')->close();

        Flux::toast(
            text: "You confirmed \"{$task->title}\" for the selected dates!",
            variant: 'success',
        );

        // Reset state
        $this->proposingTaskId = null;
        $this->proposedDates = [];
        $this->proposedTimeSettings = [];
    }

    public function cancelProposal(): void
    {
        $this->modal('vendor_propose_dates_modal')->close();
        $this->proposingTaskId = null;
        $this->proposedDates = [];
        $this->proposedTimeSettings = [];
    }

    /**
     * Homeowner submitted preferred times for the task being scheduled, grouped
     * by day with per-time applied state. Empty unless the project has submitted
     * times covering this task.
     *
     * @return array<int, array{date: string, label: string, times: array<int, array{time: string, applied: bool}>}>
     */
    /**
     * The sub picked dates — the GC's team finds out from the bell, not from
     * stumbling onto the planner. Mirrors the lead_times_picked pattern.
     */
    protected function notifyTeamOfSelection(Task $task, array $dates): void
    {
        $owningVendorId = $task->project?->belongs_to_vendor_id ?? $task->belongs_to_vendor_id;
        $owningVendor = \App\Models\Vendor::withoutGlobalScopes()->find($owningVendorId);
        $subVendor = \App\Models\Vendor::withoutGlobalScopes()->find($this->vendorId);

        if (! $owningVendor || ! $subVendor) {
            return;
        }

        $summary = collect($dates)
            ->map(function (string $date) {
                $window = data_get($this->proposedTimeSettings, $date);
                $times = ! empty($window['use_time']) && ! empty($window['start_time'])
                    ? ' · '.trim($window['start_time'].'–'.($window['end_time'] ?? ''), '–')
                    : '';

                return Carbon::parse($date)->format('D, M j').$times;
            })
            ->implode(', ');

        foreach ($owningVendor->users()->wherePivot('role_id', 1)->get() as $admin) {
            \App\Models\AppNotification::create([
                'user_id' => $admin->id,
                'type' => 'vendor_times_selected',
                'title' => "{$subVendor->name} scheduled \"{$task->title}\"",
                'body' => trim($summary),
                'action_url' => $task->project
                    ? route('projects.show', $task->project)
                    : route('planner.cards'),
                'data' => [
                    'task_id' => $task->id,
                    'project_id' => $task->project_id,
                ],
            ]);
        }
    }

    /**
     * The sub ticks a checklist item off (or back on) right from their
     * schedule page. Scoped hard to this token's vendor — the task id comes
     * from the wire and proves nothing by itself.
     */
    public function toggleChecklistItem(int $taskId, int $index): void
    {
        if (! $this->valid || ! $this->vendorId) {
            return;
        }

        $task = Task::where('vendor_id', $this->vendorId)->find($taskId);

        if (! $task) {
            return;
        }

        // Work gets checked off on site — the boxes go live on the task's
        // scheduled day. Mirrors the blade gate so a crafted request can't
        // tick early either.
        if (! $task->start_date || $task->start_date->copy()->startOfDay()->gt(today(browser_timezone()))) {
            return;
        }

        $options = json_decode(json_encode($task->options ?? []), true) ?: [];

        if (! isset($options['checklist'][$index])) {
            return;
        }

        $item = (array) $options['checklist'][$index];
        $item['completed'] = ! (bool) ($item['completed'] ?? false);
        $options['checklist'][$index] = $item;

        $task->options = $options;
        $task->saveQuietly();
    }

    public function proposedPreferredSlots(): array
    {
        if (! $this->proposingTaskId) {
            return [];
        }

        $task = Task::with('project')->find($this->proposingTaskId);
        $project = $task?->project;

        if (! $project) {
            return [];
        }

        $slots = (array) data_get($project->service_availability, 'slots', []);

        if ($slots === []) {
            return [];
        }

        $savedTaskIds = collect((array) data_get($project->service_availability, 'task_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->all();

        if ($savedTaskIds !== [] && ! in_array((int) $task->id, $savedTaskIds, true)) {
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
                        'applied' => $this->preferredSlotIsApplied($date, $time),
                    ])
                    ->all(),
            ])
            ->values()
            ->all();
    }

    /**
     * Whether the given preferred day + time frame is currently applied to the
     * proposed schedule.
     */
    protected function preferredSlotIsApplied(string $date, string $time): bool
    {
        if (! in_array($date, (array) $this->proposedDates, true)) {
            return false;
        }

        $setting = $this->proposedTimeSettings[$date] ?? [];
        $frame = self::SERVICE_TIME_FRAMES[$time] ?? null;

        if ($frame === null) {
            return empty($setting['use_time']);
        }

        return ! empty($setting['use_time'])
            && ($setting['start_time'] ?? null) === $frame['start'];
    }

    /**
     * Remove any homeowner-preferred slots currently applied to the proposal.
     */
    protected function clearAppliedPreferredSlots(): void
    {
        if (! $this->proposingTaskId) {
            return;
        }

        $task = Task::with('project')->find($this->proposingTaskId);
        $slots = (array) data_get($task?->project?->service_availability, 'slots', []);

        foreach ($slots as $slot) {
            if (! is_array($slot) || ! isset($slot['date'], $slot['time'])) {
                continue;
            }

            if (! $this->preferredSlotIsApplied($slot['date'], $slot['time'])) {
                continue;
            }

            $this->proposedDates = array_values(
                array_filter((array) $this->proposedDates, fn ($d) => $d !== $slot['date'])
            );
            unset($this->proposedTimeSettings[$slot['date']]);
        }
    }

    /**
     * Apply a homeowner-preferred day + time frame to the proposed schedule.
     *
     * Only one homeowner-preferred slot can be applied at a time; selecting a new
     * slot clears any previously applied one, and re-selecting the active slot
     * removes it.
     */
    public function applyPreferredSlot(string $date, string $time): void
    {
        $alreadyApplied = $this->preferredSlotIsApplied($date, $time);

        $this->clearAppliedPreferredSlots();

        if ($alreadyApplied) {
            return;
        }

        if (! in_array($date, (array) $this->proposedDates, true)) {
            $this->proposedDates[] = $date;
            sort($this->proposedDates);
        }

        $frame = self::SERVICE_TIME_FRAMES[$time] ?? null;

        if ($frame === null) {
            $this->proposedTimeSettings[$date] = array_merge(
                $this->proposedTimeSettings[$date] ?? [],
                ['use_time' => false],
            );

            return;
        }

        $this->proposedTimeSettings[$date] = array_merge(
            $this->proposedTimeSettings[$date] ?? [],
            [
                'use_time' => true,
                'start_time' => $frame['start'],
                'end_time' => $frame['end'],
            ],
        );
    }

    public function render()
    {
        return view('livewire.vendor.availability-index', [
            'tasks' => $this->getTasks(),
            'groupedTasks' => $this->getGroupedTasks(),
            'pastTasks' => $this->getPastTasks(),
            'pendingTasks' => $this->getPendingTasks(),
            'vendor' => $this->getVendor(),
        ])->layout('components.layouts.guest', [
            'title' => 'Tasks',
        ]);
    }
}
