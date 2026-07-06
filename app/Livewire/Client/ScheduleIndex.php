<?php

namespace App\Livewire\Client;

use App\Livewire\Concerns\HasLaterTasks;
use App\Models\AppNotification;
use App\Models\Project;
use App\Models\Task;
use App\Notifications\ClientServiceAvailabilityNotification;
use App\Notifications\VendorClientTimesRequestNotification;
use App\Services\SmsScheduleService;
use Carbon\Carbon;
use Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

class ScheduleIndex extends Component
{
    use HasLaterTasks;
    protected $listeners = ['refreshComponent' => '$refresh'];

    /**
     * Service Call project status code.
     */
    private const SERVICE_CALL_STATUS_CODE = 8;

    /**
     * Selectable service-window time frames.
     *
     * @var array<int, string>
     */
    public const SERVICE_TIME_SLOTS = ['Anytime', '7-9 AM', '9-11 AM', '11-1 PM', '1-3 PM', '3-5 PM'];

    /**
     * Number of selectable days shown, and how many days ahead selection starts.
     */
    private const SERVICE_DAYS_COUNT = 14;
    private const SERVICE_DAYS_LEAD = 4;

    /**
     * Minimum number of time frames and distinct days required to submit.
     */
    private const SERVICE_MIN_SLOTS = 3;
    private const SERVICE_MIN_DAYS = 3;

    #[Locked]
    public string $token = '';

    #[Locked]
    public ?int $projectId = null;

    #[Locked]
    public ?int $clientId = null;

    public bool $valid = false;
    public string $message = '';

    /**
     * Selected service-availability slots as "Y-m-d|time" keys.
     *
     * @var array<int, string>
     */
    public array $selectedServiceSlots = [];

    /**
     * The day currently expanded for time-frame selection (Y-m-d).
     */
    public ?string $focusedServiceDay = null;

    /**
     * Whether the client has already submitted their preferred times.
     */
    public bool $serviceAvailabilitySubmitted = false;

    public function mount(string $token): void
    {
        // Logged-in users go straight to the dashboard
        if (auth()->check()) {
            $this->redirect(url('/'));
            return;
        }

        $this->token = $token;

        $project = Project::where('schedule_token', $token)->first();

        if (! $project) {
            $this->valid = false;
            $this->message = 'This link is no longer valid.';

            return;
        }

        $this->valid = true;
        $this->projectId = $project->id;
        $this->clientId = $project->client_id;

        if ($this->serviceAvailabilityStillApplies($project)) {
            $saved = (array) ($project->service_availability['slots'] ?? []);
            $this->selectedServiceSlots = collect($saved)
                ->filter(fn ($slot) => is_array($slot) && isset($slot['date'], $slot['time']))
                ->map(fn ($slot) => $slot['date'] . '|' . $slot['time'])
                ->values()
                ->all();
            $this->serviceAvailabilitySubmitted = ! empty($this->selectedServiceSlots);

            return;
        }

        $this->selectedServiceSlots = [];
        $this->serviceAvailabilitySubmitted = false;
    }

    /**
     * Whether the saved preferred service times still apply to the current
     * pending-task context.
     */
    protected function serviceAvailabilityStillApplies(Project $project): bool
    {
        $slots = (array) data_get($project->service_availability, 'slots', []);

        if ($slots === []) {
            return false;
        }

        $savedTaskIds = collect((array) data_get($project->service_availability, 'task_ids', []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->sort()
            ->values()
            ->all();

        $currentTaskIds = $this->pendingServiceTaskIds($project->id);

        if ($savedTaskIds !== []) {
            return $savedTaskIds === $currentTaskIds;
        }

        $submittedAt = data_get($project->service_availability, 'submitted_at');
        if (! is_string($submittedAt) || $submittedAt === '') {
            return $currentTaskIds === [];
        }

        $submittedAtCarbon = Carbon::parse($submittedAt);

        $latestPendingTaskCreatedAt = Task::query()
            ->where('project_id', $project->id)
            ->whereNull('start_date')
            ->max('created_at');

        if (! $latestPendingTaskCreatedAt) {
            return true;
        }

        return ! Carbon::parse($latestPendingTaskCreatedAt)->greaterThan($submittedAtCarbon);
    }

    /**
     * Current pending-task IDs for the service-availability context.
     *
     * @return array<int>
     */
    protected function pendingServiceTaskIds(int $projectId): array
    {
        return Task::query()
            ->where('project_id', $projectId)
            ->whereNull('start_date')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Get all project IDs for this client.
     *
     * @return array<int>
     */
    #[Computed]
    public function clientProjectIds(): array
    {
        if ($this->clientId) {
            return Project::where('client_id', $this->clientId)->pluck('id')->all();
        }

        return $this->projectId ? [$this->projectId] : [];
    }

    public function getProject()
    {
        if (! $this->projectId) {
            return null;
        }

        return Project::with(['client', 'createdByVendor', 'latestStatus'])->find($this->projectId);
    }

    /**
     * Get the timezone for this project's vendor.
     */
    protected function getProjectTimezone(): string
    {
        $project = $this->getProject();
        $vendor = $project?->createdByVendor;

        if ($vendor && is_string($vendor->timezone) && $vendor->timezone !== '') {
            return $vendor->timezone;
        }

        return (string) config('app.timezone');
    }

    /**
     * Get today's date in the project's vendor timezone.
     */
    protected function getToday(): Carbon
    {
        return Carbon::today($this->getProjectTimezone());
    }

    /**
     * Get today's date for the view (for Today/Tomorrow badges).
     * Uses browser date if available, otherwise falls back to project vendor timezone.
     */
    protected function getBrowserToday(): Carbon
    {
        $browserDate = browser_date();
        
        if ($browserDate) {
            return Carbon::createFromFormat('Y-m-d', $browserDate)->startOfDay();
        }

        // Fallback to project's vendor timezone (not UTC)
        return Carbon::today($this->getProjectTimezone());
    }

    /**
     * Get today's date string (Y-m-d) for the view.
     * Uses browser timezone so "Today" badge reflects the user's local time.
     */
    #[Computed]
    public function todayDate(): string
    {
        return $this->getBrowserToday()->format('Y-m-d');
    }

    /**
     * Get tomorrow's date string (Y-m-d) for the view.
     * Uses browser timezone so "Tomorrow" badge reflects the user's local time.
     */
    #[Computed]
    public function tomorrowDate(): string
    {
        return $this->getBrowserToday()->addDay()->format('Y-m-d');
    }

    /**
     * Get the start date string (Y-m-d) for the view (2 days before today).
     */
    #[Computed]
    public function startOfWeekDate(): string
    {
        return $this->getToday()->subDays(2)->format('Y-m-d');
    }

    /**
     * Get tasks grouped by date, with multi-day tasks appearing on each day.
     * 
     * @return Collection<string, Collection<int, Task>>
     */
    #[Computed]
    public function groupedTasks(): Collection
    {
        $projectIds = $this->clientProjectIds;

        if (empty($projectIds)) {
            return collect();
        }

        $today = $this->getToday();

        // Build grouped tasks with all days in range (including empty days)
        $grouped = collect();
        $todayCarbon = Carbon::parse($today);

        // Show from 2 days before today through end of current week (Sunday)
        $startDate = $todayCarbon->copy()->subDays(2);
        $endOfWeek = $todayCarbon->copy()->endOfWeek(Carbon::SUNDAY);
        // Ensure at least 5 days shown after today
        $endDate = $endOfWeek->max($todayCarbon->copy()->addDays(5));
        $startDateStr = $startDate->format('Y-m-d');
        $endDateStr = $endDate->format('Y-m-d');

        // Get tasks that have any date within the range
        // Don't filter by end_date >= today, as tasks earlier in the range should still appear
        $tasks = Task::withTrashed()
            ->whereIn('project_id', $projectIds)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->where(function ($query) use ($startDateStr, $endDateStr) {
                // Task overlaps with the display range
                $query->whereDate('start_date', '<=', $endDateStr)
                      ->whereDate('end_date', '>=', $startDateStr);
            })
            ->orderBy('start_date')
            ->orderBy('end_date')
            ->get();

        // Create all days in the range
        $currentDate = $startDate->copy();
        while ($currentDate->lte($endDate)) {
            $grouped[$currentDate->format('Y-m-d')] = collect();
            $currentDate->addDay();
        }

        // Add tasks to their specifically selected dates (only within the range)
        foreach ($tasks as $task) {
            $selectedDates = (array) data_get($task->options, 'dates', []);
            
            if (! empty($selectedDates)) {
                foreach ($selectedDates as $dateStr) {
                    if ($dateStr >= $startDateStr && $dateStr <= $endDateStr && $grouped->has($dateStr)) {
                        $grouped[$dateStr]->push($task);
                    }
                }
            } else {
                // Fallback: single-day task using start_date
                $dateStr = $task->start_date->format('Y-m-d');
                if ($dateStr >= $startDateStr && $dateStr <= $endDateStr && $grouped->has($dateStr)) {
                    $grouped[$dateStr]->push($task);
                }
            }
        }

        // Sort by date
        return $grouped->sortKeys();
    }

    protected function laterTasksBaseQuery(): Builder
    {
        return Task::withTrashed()
            ->whereIn('project_id', $this->clientProjectIds)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date');
    }

    protected function laterTasksWindowEnd(): string
    {
        $today = $this->getToday();
        $endOfWeek = $today->copy()->endOfWeek(Carbon::SUNDAY);
        $displayEnd = $endOfWeek->max($today->copy()->addDays(5));

        return $displayEnd->format('Y-m-d');
    }

    /**
     * Get total task count for the badge.
     */
    #[Computed]
    public function taskCount(): int
    {
        $projectIds = $this->clientProjectIds;

        if (empty($projectIds)) {
            return 0;
        }

        $today = $this->getToday();

        return Task::withTrashed()
            ->whereIn('project_id', $projectIds)
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('end_date', '>=', $today)
            ->count();
    }

    /**
     * Whether the displayed tasks span multiple projects.
     */
    #[Computed]
    public function hasMultipleProjects(): bool
    {
        $projectIds = $this->groupedTasks
            ->flatten(1)
            ->pluck('project_id')
            ->merge($this->unscheduledTasks->pluck('project_id'))
            ->unique();

        return $projectIds->count() > 1;
    }

    /**
     * Get unscheduled tasks (tasks without dates selected).
     */
    #[Computed]
    public function unscheduledTasks(): Collection
    {
        $projectIds = $this->clientProjectIds;

        if (empty($projectIds)) {
            return collect();
        }

        return Task::withTrashed()
            ->with('project')
            ->whereIn('project_id', $projectIds)
            ->whereNull('start_date')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * Unscheduled tasks the homeowner still needs to pick times for.
     *
     * Excludes tasks already covered by the project's submitted preferred times
     * (recorded in service_availability.task_ids) — those are awaiting the
     * contractor to confirm, not the client to pick times.
     */
    #[Computed]
    public function pickerPendingTasks(): Collection
    {
        return $this->unscheduledTasks
            ->reject(function (Task $task): bool {
                $savedTaskIds = collect((array) data_get($task->project?->service_availability, 'task_ids', []))
                    ->map(fn ($id) => (int) $id)
                    ->all();

                return $savedTaskIds !== [] && in_array((int) $task->id, $savedTaskIds, true);
            })
            ->values();
    }

    /**
     * Whether the project is currently in the Service Call status.
     */
    #[Computed]
    public function isServiceCall(): bool
    {
        return $this->getProject()?->latestStatus?->status_code === self::SERVICE_CALL_STATUS_CODE;
    }

    /**
     * Selectable service days (Y-m-d) starting a few days out.
     *
     * Weekends remain in the calendar flow for continuity, but stay disabled in the UI.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function serviceDays(): array
    {
        $start = $this->getBrowserToday()->copy()->addDays(self::SERVICE_DAYS_LEAD);

        return collect(range(0, self::SERVICE_DAYS_COUNT - 1))
            ->map(fn (int $offset) => $start->copy()->addDays($offset)->format('Y-m-d'))
            ->all();
    }

    /**
     * Whether the given service day can be selected.
     */
    protected function isSelectableServiceDay(string $date): bool
    {
        return ! Carbon::parse($date)->isWeekend();
    }

    /**
     * Pending tasks for this project shown alongside the availability picker.
     *
     * @return Collection<int, Task>
     */
    #[Computed]
    public function serviceCallTasks(): Collection
    {
        if (! $this->projectId) {
            return collect();
        }

        return Task::where('project_id', $this->projectId)
            ->orderBy('order')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * The available time-frame options.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function serviceTimeSlots(): array
    {
        return self::SERVICE_TIME_SLOTS;
    }

    /**
     * Selected slots grouped by date for the summary display.
     *
     * @return array<string, array<int, string>>
     */
    #[Computed]
    public function selectedServiceByDay(): array
    {
        return collect($this->selectedServiceSlots)
            ->map(fn (string $key) => explode('|', $key, 2))
            ->filter(fn (array $parts) => count($parts) === 2)
            ->groupBy(fn (array $parts) => $parts[0])
            ->map(fn ($group) => $group->map(fn (array $parts) => $parts[1])->all())
            ->sortKeys()
            ->all();
    }

    /**
     * Number of distinct days selected.
     */
    #[Computed]
    public function selectedServiceDayCount(): int
    {
        return count($this->selectedServiceByDay);
    }

    /**
     * Whether the current selection meets the minimum requirements.
     */
    #[Computed]
    public function serviceSelectionMeetsMinimum(): bool
    {
        return count($this->selectedServiceSlots) >= self::SERVICE_MIN_SLOTS
            && $this->selectedServiceDayCount >= self::SERVICE_MIN_DAYS;
    }

    /**
     * Toggle a service time-frame slot for a given day.
     */
    public function toggleServiceSlot(string $date, string $time): void
    {
        if (! $this->isSelectableServiceDay($date) || ! in_array($time, self::SERVICE_TIME_SLOTS, true)) {
            return;
        }

        $key = $date . '|' . $time;
        $anytimeKey = $date . '|Anytime';
        $dayPrefix = $date . '|';

        if ($time === 'Anytime') {
            if (in_array($key, $this->selectedServiceSlots, true)) {
                $this->selectedServiceSlots = array_values(
                    array_filter($this->selectedServiceSlots, fn ($slot) => $slot !== $key)
                );

                unset($this->selectedServiceByDay, $this->selectedServiceDayCount, $this->serviceSelectionMeetsMinimum);

                return;
            }

            $this->selectedServiceSlots = array_values(
                array_filter($this->selectedServiceSlots, fn ($slot) => ! str_starts_with($slot, $dayPrefix))
            );
            $this->selectedServiceSlots[] = $key;

            unset($this->selectedServiceByDay, $this->selectedServiceDayCount, $this->serviceSelectionMeetsMinimum);

            return;
        }

        if (in_array($anytimeKey, $this->selectedServiceSlots, true)) {
            $this->selectedServiceSlots = array_values(
                array_filter($this->selectedServiceSlots, fn ($slot) => $slot !== $anytimeKey)
            );
        }

        if (in_array($key, $this->selectedServiceSlots, true)) {
            $this->selectedServiceSlots = array_values(
                array_filter($this->selectedServiceSlots, fn ($slot) => $slot !== $key)
            );
        } else {
            $this->selectedServiceSlots[] = $key;
        }

        unset($this->selectedServiceByDay, $this->selectedServiceDayCount, $this->serviceSelectionMeetsMinimum);
    }

    /**
     * Focus a day to show its time-frame options.
     */
    public function focusServiceDay(string $date): void
    {
        if (in_array($date, $this->serviceDays, true)) {
            $this->focusedServiceDay = $date;
        }
    }

    /**
     * Persist the client's preferred service times and notify the vendor.
     */
    public function submitServiceAvailability(): void
    {
        if (! $this->isServiceCall || ! $this->serviceSelectionMeetsMinimum) {
            return;
        }

        $project = $this->getProject();

        if (! $project) {
            return;
        }

        $slots = collect($this->selectedServiceSlots)
            ->map(fn (string $key) => explode('|', $key, 2))
            ->filter(fn (array $parts) => count($parts) === 2)
            ->map(fn (array $parts) => ['date' => $parts[0], 'time' => $parts[1]])
            ->sortBy([['date', 'asc'], ['time', 'asc']])
            ->values()
            ->all();

        $project->forceFill([
            'service_availability' => [
                'slots' => $slots,
                'task_ids' => $this->pendingServiceTaskIds($project->id),
                'submitted_at' => now()->toIso8601String(),
            ],
        ])->save();

        $this->serviceAvailabilitySubmitted = true;

        $this->notifyVendorOfServiceAvailability($project, $slots);
        $this->notifyTaskVendorsOfServiceAvailability($project);

        Flux::toast(
            duration: 5000,
            position: 'top right',
            variant: 'success',
            heading: 'Thank you!',
            text: 'Your preferred times were sent to your contractor.',
        );
    }

    /**
     * Re-open the picker to change a submitted selection.
     */
    public function editServiceAvailability(): void
    {
        $this->serviceAvailabilitySubmitted = false;
    }

    /**
     * Notify the owning vendor's admins about the submitted availability.
     *
     * @param  array<int, array{date: string, time: string}>  $slots
     */
    protected function notifyVendorOfServiceAvailability(Project $project, array $slots): void
    {
        $vendor = $project->createdByVendor;

        if (! $vendor) {
            return;
        }

        $sendAt = $this->serviceAvailabilitySmsSendAt($project);

        $notification = new ClientServiceAvailabilityNotification($project, $slots);

        if ($sendAt !== null) {
            $notification->delay($sendAt);
        }

        foreach ($vendor->getAdminUsersWithCellPhones() as $adminUser) {
            $adminUser->notify($notification);
        }

        $this->createServiceAvailabilityHubNotifications(
            $project,
            $vendor->users()->wherePivot('role_id', 1)->get()
        );
    }

    /**
     * Add Hive Hub (in-app) notifications for the given users letting the
     * contractor know the homeowner submitted preferred service times.
     *
     * @param  \Illuminate\Support\Collection<int, \App\Models\User>  $users
     */
    protected function createServiceAvailabilityHubNotifications(Project $project, Collection $users): void
    {
        if ($users->isEmpty()) {
            return;
        }

        $clientName = trim((string) ($project->client?->name ?? 'A homeowner'));

        foreach ($users as $user) {
            AppNotification::create([
                'user_id' => $user->id,
                'type' => 'service_availability_submitted',
                'title' => "{$clientName} shared preferred times",
                'body' => 'Preferred service times submitted. Schedule the service call.',
                'action_url' => '/planner',
                'data' => ['project_id' => $project->id],
            ]);
        }
    }

    /**
     * Text each sub-vendor assigned to a pending service-call task so they can
     * select their availability in their /v/ schedule.
     */
    protected function notifyTaskVendorsOfServiceAvailability(Project $project): void
    {
        $tasks = Task::query()
            ->where('project_id', $project->id)
            ->whereNull('start_date')
            ->whereNotNull('vendor_id')
            ->with(['vendor', 'project', 'owner'])
            ->get();

        $sendAt = $this->serviceAvailabilitySmsSendAt($project);

        $tasks
            ->groupBy('vendor_id')
            ->each(function ($vendorTasks) use ($project, $sendAt): void {
                $vendor = $vendorTasks->first()?->vendor;

                if (! $vendor) {
                    return;
                }

                $this->markTasksRequested($vendorTasks);

                $notification = new VendorClientTimesRequestNotification($vendorTasks->values(), $project);

                if ($sendAt !== null) {
                    $notification->delay($sendAt);
                }

                $vendor->notify($notification);
            });
    }

    /**
     * When service-availability SMS should be delivered, deferring to the next
     * business-hours window so vendors are never texted overnight. Returns null
     * to send immediately.
     */
    protected function serviceAvailabilitySmsSendAt(Project $project): ?\Carbon\Carbon
    {
        $smsService = app(SmsScheduleService::class);
        $vendorTimezone = $project->createdByVendor?->timezone;

        return $smsService->isWithinBusinessHours($vendorTimezone)
            ? null
            : $smsService->getNextBusinessHoursStart($vendorTimezone);
    }

    /**
     * Flag pending vendor tasks as requested so they surface in the vendor's
     * "needs response" bucket on their /v/ schedule.
     *
     * @param  \Illuminate\Support\Collection<int, Task>  $vendorTasks
     */
    protected function markTasksRequested($vendorTasks): void
    {
        foreach ($vendorTasks as $task) {
            if (in_array($task->vendor_status, [
                Task::VENDOR_STATUS_CONFIRMED,
                Task::VENDOR_STATUS_REJECTED,
                Task::VENDOR_STATUS_REQUESTED,
            ], true)) {
                continue;
            }

            $task->update(['vendor_status' => Task::VENDOR_STATUS_REQUESTED]);
        }
    }

    public function render()
    {
        return view('livewire.client.schedule-index')
            ->layout('components.layouts.guest', [
                'title' => 'Schedule',
                'bodyClass' => 'bg-zinc-100',
            ]);
    }
}
