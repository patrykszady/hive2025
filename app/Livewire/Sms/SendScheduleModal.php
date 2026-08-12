<?php

namespace App\Livewire\Sms;

use App\Livewire\Concerns\HasLaterTasks;
use App\Models\Project;
use App\Models\Client;
use App\Models\SmsGroupThread;
use App\Models\Task;
use App\Models\User;
use App\Services\GroupSmsService;
use App\Services\SmsTranslationService;
use Carbon\Carbon;
use Flux;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class SendScheduleModal extends Component
{
    use HasLaterTasks;

    public bool $showModal = false;

    public ?int $threadId = null;

    public string $editableMessage = '';

    public bool $scheduleWithoutDate = false;

    protected int $daysAhead = 3;

    /**
     * Service Call project status code.
     */
    private const SERVICE_CALL_STATUS_CODE = 8;

    /** Complete — leftover unscheduled work on one is a service call in practice. */
    private const COMPLETE_STATUS_CODE = 7;

    /** Estimate — a pending Meet on such a project is an unscheduled consult. */
    private const ESTIMATE_STATUS_CODE = 2;

    /** Response — estimate delivered, homeowner deciding; consults still welcome. */
    private const RESPONSE_STATUS_CODE = 3;

    #[On('openScheduleModal')]
    public function open(int $threadId): void
    {
        $this->threadId = $threadId;
        $this->showModal = true;
        $this->scheduleWithoutDate = false;
        $this->editableMessage = $this->previewMessage;
    }

    #[On('refreshSchedulePreview')]
    public function refreshSchedulePreview(): void
    {
        if (! $this->showModal || ! $this->threadId) {
            return;
        }

        unset(
            $this->thread,
            $this->clientProjectIds,
            $this->upcomingTasks,
            $this->groupedUpcomingTasks,
            $this->pendingTasks,
            $this->nextUpcomingTasks,
            $this->laterTasks,
            $this->selectedTaskIds,
            $this->previewMessage,
        );

        $this->editableMessage = $this->previewMessage;
    }

    public function openCreateTaskForDate(string $date): void
    {
        $thread = $this->thread;

        $this->dispatch(
            'addTask',
            date: $date,
            vendor_id: $thread?->subject_vendor_id,
            client_id: $thread?->client_id,
        )->to('tasks.task-create');
    }

    public function openCreateTask(): void
    {
        $thread = $this->thread;

        $this->dispatch(
            'addTask',
            vendor_id: $thread?->subject_vendor_id,
            client_id: $thread?->client_id,
        )->to('tasks.task-create');
    }

    public function close(): void
    {
        $this->showModal = false;
        $this->scheduleWithoutDate = false;
    }

    public function useNoDateSchedule(): void
    {
        $this->scheduleWithoutDate = true;
        $this->editableMessage = $this->buildScheduleLinkMessage();
    }

    public function useDatedSchedule(): void
    {
        $this->scheduleWithoutDate = false;
        $this->editableMessage = $this->previewMessage;
    }

    #[Computed]
    public function thread(): ?SmsGroupThread
    {
        if (! $this->threadId) {
            return null;
        }

        return SmsGroupThread::with([
            'project.createdByVendor',
            'client.users:id,first_name,last_name,nickname,preferred_language',
            'subjectVendor.users:id,first_name,last_name,nickname,preferred_language',
        ])->find($this->threadId);
    }

    #[Computed]
    public function recipientLanguage(): string
    {
        $thread = $this->thread;

        if (! $thread) {
            return 'English';
        }

        $language = null;

        if ($thread->subject_vendor_id) {
            $language = $thread->subjectVendor?->users
                ?->pluck('preferred_language')
                ->filter(fn ($value) => is_string($value) && trim($value) !== '')
                ->first();
        } else {
            $language = $thread->client?->users
                ?->pluck('preferred_language')
                ->filter(fn ($value) => is_string($value) && trim($value) !== '')
                ->first();
        }

        return is_string($language) && trim($language) !== '' ? $language : 'English';
    }

    #[Computed]
    public function viewerLanguage(): string
    {
        return app(SmsTranslationService::class)
            ->normalizeLanguage((string) (auth()->user()?->preferred_language ?: 'English'));
    }

    #[Computed]
    public function clientProjectIds(): array
    {
        $thread = $this->thread;

        if (! $thread) {
            return [];
        }

        if ($thread->project_id) {
            return [$thread->project_id];
        }

        if ($thread->client_id) {
            return Project::where('client_id', $thread->client_id)->pluck('id')->all();
        }

        if ($thread->subject_vendor_id) {
            return Task::withoutGlobalScopes()
                ->where('vendor_id', $thread->subject_vendor_id)
                ->whereNotNull('project_id')
                ->whereNull('deleted_at')
                ->distinct()
                ->pluck('project_id')
                ->all();
        }

        return [];
    }

    /**
     * Get upcoming tasks across all client projects.
     *
     * @return \Illuminate\Support\Collection<int, Task>
     */
    /**
     * Where the upcoming window opens: today — or tomorrow once the vendor's
     * working day is over. A schedule texted after closing that still lists
     * "Today" promises a crew that is not coming.
     */
    protected function scheduleWindowStart(): Carbon
    {
        $today = Carbon::today(browser_timezone());

        $vendor = $this->thread?->ownerVendor ?? $this->thread?->subjectVendor;

        if (! $vendor) {
            return $today;
        }

        $tz = $vendor->timezone ?: 'America/Chicago';
        $closing = Carbon::today($tz)->setTimeFromTimeString($vendor->businessHours()['end']);

        return now($tz)->gt($closing) ? $today->addDay() : $today;
    }

    #[Computed]
    public function upcomingTasks(): \Illuminate\Support\Collection
    {
        $projectIds = $this->clientProjectIds;

        if (empty($projectIds)) {
            return collect();
        }

        $today = $this->scheduleWindowStart();
        $endDate = $today->copy()->addDays($this->daysAhead - 1);
        $todayStr = $today->format('Y-m-d');
        $endDateStr = $endDate->format('Y-m-d');

        $vendorId = $this->thread?->subject_vendor_id;

        $tasks = Task::whereIn('project_id', $projectIds)
            ->with(['vendor', 'project.client', 'project.latestStatus'])
            ->when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId))
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', '<=', $endDateStr)
            ->whereDate('end_date', '>=', $todayStr)
            ->orderBy('start_date')
            ->orderBy('order')
            ->get();

        return $this->filterVendorReminderVisibility($tasks);
    }

    /**
     * Get upcoming tasks grouped by date.
     *
     * @return \Illuminate\Support\Collection<string, \Illuminate\Support\Collection<int, Task>>
     */
    #[Computed]
    public function groupedUpcomingTasks(): \Illuminate\Support\Collection
    {
        $tasks = $this->upcomingTasks;
        $today = $this->scheduleWindowStart();
        $endDate = $today->copy()->addDays($this->daysAhead - 1);
        $todayStr = $today->format('Y-m-d');
        $endDateStr = $endDate->format('Y-m-d');

        $grouped = collect();

        foreach ($tasks as $task) {
            $selectedDates = (array) data_get($task->options, 'dates', []);
            $addedDateKeys = [];

            foreach ($selectedDates as $rawDate) {
                $normalizedDate = $this->normalizeTaskDateKey($rawDate);

                if (! $normalizedDate || isset($addedDateKeys[$normalizedDate])) {
                    continue;
                }

                if ($normalizedDate >= $todayStr && $normalizedDate <= $endDateStr) {
                    // Today's tasks drop off once their time window has passed
                    // (e.g. don't list a 7-7:30AM roofer arrival at 2PM).
                    if ($normalizedDate === $todayStr && $task->timeHasPassedOn($normalizedDate, browser_timezone())) {
                        $addedDateKeys[$normalizedDate] = true;

                        continue;
                    }

                    if (! $grouped->has($normalizedDate)) {
                        $grouped[$normalizedDate] = collect();
                    }

                    $grouped[$normalizedDate]->push($task);
                    $addedDateKeys[$normalizedDate] = true;
                }
            }

            // Fallback for tasks without usable options.dates: include each day in
            // the stored start/end range, constrained to the current preview window.
            if ($addedDateKeys === []) {
                $start = $task->start_date?->copy()->startOfDay();
                $end = $task->end_date?->copy()->startOfDay();

                if (! $start && ! $end) {
                    continue;
                }

                if (! $start) {
                    $start = $end?->copy();
                }

                if (! $end) {
                    $end = $start?->copy();
                }

                if (! $start || ! $end) {
                    continue;
                }

                if ($end->lt($start)) {
                    [$start, $end] = [$end, $start];
                }

                $cursor = $start->copy();

                while ($cursor->lte($end)) {
                    $dateStr = $cursor->format('Y-m-d');

                    if ($dateStr >= $todayStr && $dateStr <= $endDateStr) {
                        // Today's tasks drop off once their time window has passed.
                        if ($dateStr === $todayStr && $task->timeHasPassedOn($dateStr, browser_timezone())) {
                            $cursor->addDay();

                            continue;
                        }

                        if (! $grouped->has($dateStr)) {
                            $grouped[$dateStr] = collect();
                        }

                        $grouped[$dateStr]->push($task);
                    }

                    $cursor->addDay();
                }
            }
        }

        $grouped = $grouped->sortKeys()->map(function ($tasks, $dateStr) {
            return $tasks->sortBy(function ($task) use ($dateStr) {
                $startTime = (string) data_get($task->options, "time_settings.$dateStr.start_time", '');
                $usesTime = (bool) data_get($task->options, "time_settings.$dateStr.use_time", false);
                $hasTime = $usesTime && $startTime !== '';

                return $hasTime ? '0_' . $startTime : '1';
            })->values();
        });

        // Fill in all consecutive days (including empty ones) so the UI shows every day
        for ($i = 0; $i < $this->daysAhead; $i++) {
            $dateStr = $today->copy()->addDays($i)->format('Y-m-d');
            if (! $grouped->has($dateStr)) {
                $grouped[$dateStr] = collect();
            }
        }

        return $grouped->sortKeys();
    }

    protected function normalizeTaskDateKey(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse(trim($value), browser_timezone())->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Get tasks without dates (pending/unscheduled).
     *
     * @return \Illuminate\Support\Collection<int, Task>
     */
    #[Computed]
    public function pendingTasks(): \Illuminate\Support\Collection
    {
        $projectIds = $this->clientProjectIds;

        if (empty($projectIds)) {
            return collect();
        }

        $vendorId = $this->thread?->subject_vendor_id;

        $tasks = Task::whereIn('project_id', $projectIds)
            ->with(['vendor', 'project.client', 'project.latestStatus', 'project.createdByVendor'])
            ->when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId))
            ->where(function ($query) {
                $query->whereNull('start_date')->orWhereNull('end_date');
            })
            ->orderBy('order')
            ->get();

        return $this->filterVendorReminderVisibility($tasks);
    }

    /**
     * All task IDs from upcoming + pending tasks.
     *
     * @return array<int>
     */
    #[Computed]
    public function selectedTaskIds(): array
    {
        $ids = $this->upcomingTasks
            ->merge($this->pendingTasks)
            ->pluck('id')
            ->unique()
            ->values();

        $nextTasks = $this->nextUpcomingTasks;
        if ($nextTasks->isNotEmpty()) {
            $ids = $ids->merge($nextTasks->pluck('id'));
        }

        return $ids->unique()->values()->all();
    }

    /**
     * When no tasks exist in the upcoming window, find all tasks on the next future day.
     */
    #[Computed]
    public function nextUpcomingTasks(): \Illuminate\Support\Collection
    {
        $projectIds = $this->clientProjectIds;

        if (empty($projectIds)) {
            return collect();
        }

        $today = Carbon::today(browser_timezone());
        $afterDate = $today->copy()->addDays($this->daysAhead)->format('Y-m-d');

        $vendorId = $this->thread?->subject_vendor_id;

        $candidates = Task::whereIn('project_id', $projectIds)
            ->with(['vendor', 'project.client', 'project.latestStatus'])
            ->when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId))
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('end_date', '>=', $afterDate)
            ->orderBy('start_date')
            ->orderBy('order')
            ->get();

        $candidates = $this->filterVendorReminderVisibility($candidates);

        if ($candidates->isEmpty()) {
            return collect();
        }

        $firstDate = $this->firstFutureScheduledDate($candidates, $afterDate);

        if (! $firstDate) {
            return collect();
        }

        return $candidates
            ->filter(fn (Task $task) => $this->taskScheduledOnDate($task, $firstDate))
            ->sortBy(function (Task $task) use ($firstDate) {
                $startTime = (string) data_get($task->options, "time_settings.$firstDate.start_time", '');
                $usesTime = (bool) data_get($task->options, "time_settings.$firstDate.use_time", false);
                $hasTime = $usesTime && $startTime !== '';

                return [
                    $hasTime ? 0 : 1,
                    $hasTime ? $startTime : '99:99',
                    (int) ($task->order ?? 0),
                    (int) $task->id,
                ];
            })
            ->values();
    }

    #[Computed]
    public function nextUpcomingDate(): ?string
    {
        $tasks = $this->nextUpcomingTasks;

        if ($tasks->isEmpty()) {
            return null;
        }

        $today = Carbon::today(browser_timezone());
        $afterDate = $today->copy()->addDays($this->daysAhead)->format('Y-m-d');

        return $this->firstFutureScheduledDate($tasks, $afterDate);
    }

    /**
     * Base query for "Later" tasks: scheduled tasks across the thread's projects,
     * honoring the same vendor-reminder visibility rule as the other windows.
     */
    protected function laterTasksBaseQuery(): Builder
    {
        $projectIds = $this->clientProjectIds;
        $vendorId = $this->thread?->subject_vendor_id;

        return Task::query()
            ->whereIn('project_id', $projectIds ?: [0])
            ->when($vendorId, fn (Builder $query) => $query
                ->where('vendor_id', $vendorId)
                ->whereRaw('LOWER(type) != ?', ['reminder']))
            ->whereNotNull('start_date')
            ->whereNotNull('end_date');
    }

    /**
     * "Later" tasks begin the day after the "Next Up" day (or the preview window
     * end when there is no next-up day), so they never duplicate shown tasks.
     */
    protected function laterTasksWindowEnd(): string
    {
        $today = Carbon::today(browser_timezone());
        $windowEnd = $today->copy()->addDays($this->daysAhead - 1)->format('Y-m-d');

        $nextDate = $this->nextUpcomingDate;

        return $nextDate && $nextDate > $windowEnd ? $nextDate : $windowEnd;
    }

    /**
     * Build the schedule message text matching the morning/night digest format.
     */
    #[Computed]
    public function previewMessage(): string
    {
        $grouped = $this->groupedUpcomingTasks;
        $pendingTasks = $this->pendingTasks;

        // Flatten all tasks to check if there are any
        $allTasks = $grouped->flatten(1)->merge($pendingTasks)->unique('id');

        // Include next upcoming tasks if the window is empty
        $nextTasks = $this->nextUpcomingTasks;
        if ($nextTasks->isNotEmpty()) {
            $allTasks = $allTasks->merge($nextTasks)->unique('id');
        }

        if ($allTasks->isEmpty()) {
            return $this->buildScheduleLinkMessage();
        }

        $greeting = $this->buildRecipientGreeting();

        $invite = $this->serviceCallInviteLine();

        $hasUpcomingWindowTasks = $grouped->contains(fn ($tasks) => $tasks->isNotEmpty());
        $intro = $hasUpcomingWindowTasks ? $this->scheduleIntroLabel() . ':' : '';

        // Build task lines grouped by day (matching digest format)
        $today = Carbon::today(browser_timezone());
        $tomorrow = $today->copy()->addDay();
        $daySections = [];
        foreach ($grouped as $dateStr => $dayTasks) {
            if ($dayTasks->isEmpty()) {
                continue;
            }

            $carbonDate = Carbon::parse($dateStr);
            $isVendorSchedule = (bool) $this->thread?->subject_vendor_id;
            $shortDate = $isVendorSchedule
                ? $carbonDate->format('D m/d')
                : $carbonDate->format('D n/j');

            if ($carbonDate->isSameDay($today)) {
                $dateLabel = $this->scheduleDayLabel('today') . " {$shortDate}";
            } elseif ($carbonDate->isSameDay($tomorrow)) {
                $dateLabel = $this->scheduleDayLabel('tomorrow') . " {$shortDate}";
            } else {
                $dateLabel = $shortDate;
            }

            $showProject = (bool) $this->thread?->subject_vendor_id;
            $taskLines = $dayTasks->map(function (Task $task) use ($dateStr, $showProject) {
                $taskTitle = trim($task->title ?? 'Task');
                $projectName = trim((string) ($task->project?->project_name ?? ''));
                if ($showProject && $projectName !== '') {
                    $taskTitle .= " ({$projectName})";
                }

                $line = '- ' . $taskTitle;

                // Use the model's getArrivalTimeLabel for consistent time formatting (e.g. 11AM-2PM)
                $arrivalTime = $task->getArrivalTimeLabel($dateStr);
                if ($arrivalTime) {
                    $line .= " @ {$arrivalTime}";
                }

                if ($showProject && $task->project) {
                    $p = $task->project;
                    $street = trim((string) $p->address);
                    $cityLine = collect([$p->city, $p->state ? trim($p->state . ' ' . $p->zip_code) : null])
                        ->filter()->implode(', ');
                    if ($street) {
                        $line .= "\n  {$street}";
                    }
                    if ($cityLine) {
                        $line .= "\n  {$cityLine}";
                    }
                }

                return $line;
            })->implode("\n");

            $daySections[] = "{$dateLabel}:\n{$taskLines}";
        }

        // Add next upcoming tasks beyond the 3-day window
        if ($nextTasks->isNotEmpty()) {
            $nextDateKey = $this->nextUpcomingDate;
            $nextDate = $nextDateKey ? Carbon::parse($nextDateKey) : Carbon::parse($nextTasks->first()->start_date);
            $dateLabel = $this->thread?->subject_vendor_id
                ? $nextDate->format('D m/d')
                : $nextDate->format('D n/j');

            $showProject = (bool) $this->thread?->subject_vendor_id;
            $taskLines = $nextTasks->map(function (Task $task) use ($nextDate, $showProject) {
                $taskTitle = trim($task->title ?? 'Task');
                $projectName = trim((string) ($task->project?->project_name ?? ''));
                if ($showProject && $projectName !== '') {
                    $taskTitle .= " ({$projectName})";
                }

                $line = '- ' . $taskTitle;
                $arrivalTime = $task->getArrivalTimeLabel($nextDate->format('Y-m-d'));
                if ($arrivalTime) {
                    $line .= " @ {$arrivalTime}";
                }
                if ($showProject && $task->project) {
                    $p = $task->project;
                    $street = trim((string) $p->address);
                    $cityLine = collect([$p->city, $p->state ? trim($p->state . ' ' . $p->zip_code) : null])
                        ->filter()->implode(', ');
                    if ($street) {
                        $line .= "\n  {$street}";
                    }
                    if ($cityLine) {
                        $line .= "\n  {$cityLine}";
                    }
                }
                return $line;
            })->implode("\n");

            $daySections[] = $this->scheduleDayLabel('next_up') . " {$dateLabel}:\n{$taskLines}";
        }

        // Add pending tasks section. For client threads, Service Call pending items
        // are shown in the top availability block and omitted here.
        if ($pendingTasks->isNotEmpty()) {
            $showProject = (bool) $this->thread?->subject_vendor_id;
            $pendingForSection = $showProject
                ? $pendingTasks
                : $pendingTasks->reject(
                    fn (Task $task): bool => $this->needsServiceAvailability($task)
                        || $this->isConsultInviteTask($task)
                )->values();

            // Sub-contractor threads: a task whose homeowner already shared
            // service-call times is not merely "Pending" — the ask is theirs.
            // Call it out, and keep genuinely dateless work under Pending.
            $requestedForSection = collect();
            if ($showProject) {
                [$requestedForSection, $pendingForSection] = $pendingForSection->partition(
                    fn (Task $task): bool => $task->preferredTimeIndicator() === 'schedule'
                );
                $requestedForSection = $requestedForSection->values();
                $pendingForSection = $pendingForSection->values();
            }

            $formatPendingLine = function (Task $task) use ($showProject) {
                $taskTitle = trim($task->title ?? 'Task');
                $projectName = trim((string) ($task->project?->project_name ?? ''));
                if ($showProject && $projectName !== '') {
                    $taskTitle .= " ({$projectName})";
                }

                $line = '- ' . $taskTitle;
                if ($showProject && $task->project) {
                    $p = $task->project;
                    $street = trim((string) $p->address);
                    $cityLine = collect([$p->city, $p->state ? trim($p->state . ' ' . $p->zip_code) : null])
                        ->filter()->implode(', ');
                    if ($street) {
                        $line .= "\n  {$street}";
                    }
                    if ($cityLine) {
                        $line .= "\n  {$cityLine}";
                    }
                }
                return $line;
            };

            if ($requestedForSection->isNotEmpty()) {
                $heading = match ($this->languageKey()) {
                    'pl' => $requestedForSection->count() === 1
                        ? 'Klient poprosil o termin serwisu dla tego zadania'
                        : 'Klient poprosil o termin serwisu dla tych zadan',
                    'es' => $requestedForSection->count() === 1
                        ? 'El propietario ha solicitado una visita de servicio para esta tarea'
                        : 'El propietario ha solicitado una visita de servicio para estas tareas',
                    default => $requestedForSection->count() === 1
                        ? 'Homeowner has requested a service call for this task'
                        : 'Homeowner has requested a service call for these tasks',
                };

                $daySections[] = $heading . ":\n" . $requestedForSection->map($formatPendingLine)->implode("\n");
            }

            if ($pendingForSection->isNotEmpty()) {
                $pendingLines = $pendingForSection->map($formatPendingLine)->implode("\n");

                $daySections[] = $this->scheduleDayLabel('pending') . ":\n{$pendingLines}";
            }
        }

        // Compact "Later" summary — count only; details live on the schedule link.
        $laterCount = $this->laterTasks->flatten()->unique('id')->count();
        if ($laterCount > 0) {
            $laterWord = $laterCount === 1 ? 'task' : 'tasks';
            $daySections[] = $this->scheduleDayLabel('later') . " ({$laterCount} {$laterWord})";
        }

        $body = implode("\n\n", $daySections);

        // Single schedule link — vendor threads link to vendor availability page, others to project schedule
        $linksText = '';
        $devWebhookUrl = config('app.dev_webhook_url');
        $baseUrl = $devWebhookUrl ?: rtrim((string) config('app.url'), '/');

        $subjectVendor = $this->thread?->subjectVendor;
        if ($subjectVendor) {
            $token = $subjectVendor->getOrCreateAvailabilityToken();
            $scheduleUrl = app(\App\Services\UrlShortener::class)->shorten("{$baseUrl}/v/{$token}");
            $linksText = "\n" . $this->scheduleLinkLabel() . ": {$scheduleUrl}";
        } else {
            $firstProject = $allTasks->first()?->project;
            if ($firstProject) {
                $token = $firstProject->getOrCreateScheduleToken();
                $scheduleUrl = app(\App\Services\UrlShortener::class)->shorten("{$baseUrl}/s/{$token}");
                $linksText = "\n" . $this->scheduleLinkLabel() . ": {$scheduleUrl}";
            } else {
                $link = $this->buildScheduleLink();
                if ($link) {
                    $linksText = "\n{$link}";
                }
            }
        }

        // One availability ask per message: when the service-call invite is
        // present, its Schedule link already opens the page where the
        // homeowner shares times — stacking the consult invite under it
        // doubled the ask with a second link to the same conversation.
        $invite = trim($invite !== '' ? $invite : $this->consultInviteLine());

        // Blank line after the greeting: "Hi Amy & Andy," reads as its own
        // line, then the invite starts a fresh paragraph.
        if ($invite !== '' && $intro !== '') {
            $header = "{$greeting}\n\n{$invite}\n\n{$intro}";
        } elseif ($invite !== '') {
            $header = "{$greeting}\n\n{$invite}";
        } elseif ($intro !== '') {
            // Greeting flows straight into the intro line — no blank line.
            $header = "{$greeting}\n{$intro}";
        } else {
            $header = $greeting;
        }

        if ($body === '') {
            // No scheduled/pending task sections to show. When a service-call
            // invite is present it already includes an inline Schedule link, so
            // the header is the entire message — avoid a duplicate bottom link.
            if ($invite !== '') {
                return $header;
            }

            return trim("{$header}\n{$linksText}");
        }

        return "{$header}\n\n{$body}\n{$linksText}";
    }

    /**
     * Whether this pending task should ask the homeowner for availability:
     * a Service Call project, or a Complete project's leftover work — the
     * same rule the client schedule page uses to show its service-window
     * picker, so the text promises exactly what the page delivers.
     */
    protected function needsServiceAvailability(Task $task): bool
    {
        $code = (int) ($task->project?->latestStatus?->status_code ?? 0);

        return $code === self::SERVICE_CALL_STATUS_CODE
            || $code === self::COMPLETE_STATUS_CODE;
    }

    /**
     * Client-facing invite shown before the task list when the client has a
     * pending Service Call task, asking them to share availability.
     */
    protected function serviceCallInviteLine(): string
    {
        if ($this->thread?->subject_vendor_id) {
            return '';
        }

        $serviceCallTasks = $this->pendingTasks->filter(
            fn (Task $task): bool => $this->needsServiceAvailability($task)
        );

        if ($serviceCallTasks->isEmpty()) {
            return '';
        }

        $vendor = $serviceCallTasks->first()->project?->createdByVendor;
        $contractor = trim((string) ($vendor?->short_name ?: $vendor?->name ?: ''));

        if ($contractor === '') {
            $contractor = 'your contractor';
        }

        $serviceCallCount = $serviceCallTasks->count();
        $serviceCallLabel = $serviceCallCount === 1
            ? 'for this service call'
            : 'for these service calls';

        // The homeowner already gave times for every project here — this text
        // is a nudge for fresh ones, not a first ask that ignores their reply.
        $projects = $serviceCallTasks->pluck('project')->filter()->unique('id');
        $alreadyShared = $projects->isNotEmpty()
            && $projects->every(fn ($project) => ! empty(data_get($project->service_availability, 'slots')));

        $inviteText = match ($this->languageKey()) {
            'pl' => $alreadyShared
                ? "Podaj nowa lub dodatkowa dostepnosc dla {$contractor}:"
                : "Podaj swoja dostepnosc dla {$contractor}:",
            'es' => $alreadyShared
                ? "Comparte nueva o mas disponibilidad para {$contractor}:"
                : "Comparte tu disponibilidad para {$contractor}:",
            default => $alreadyShared
                ? "Share new or more availability with {$contractor} {$serviceCallLabel}:"
                : "Share availability with {$contractor} {$serviceCallLabel}:",
        };

        $scheduleLinkLine = $this->buildScheduleLink();
        $inlineScheduleLine = '';
        if ($scheduleLinkLine !== '') {
            $parts = explode(':', $scheduleLinkLine, 2);
            $scheduleUrl = trim((string) ($parts[1] ?? ''));
            if ($scheduleUrl !== '') {
                $inlineScheduleLine = "Schedule: {$scheduleUrl}";
            }
        }

        $itemLines = $serviceCallTasks
            ->map(fn (Task $task): string => '- ' . trim((string) ($task->title ?? 'Task')))
            ->implode("\n");

        if ($inlineScheduleLine !== '') {
            return "{$inviteText}\n{$itemLines}\n{$inlineScheduleLine}";
        }

        return "{$inviteText}\n{$itemLines}";
    }

    /**
     * A pending Meet on an Estimate-status project is a consult nobody has
     * scheduled yet — the client picks the time, not us. So instead of listing
     * it as a dead "Pending" line, invite them to the lead's pick-times page
     * (the same signed page the consult EMAIL links to), which writes their
     * choices back onto the lead for the composer to confirm.
     *
     * Empty when the thread has no such task, or when no lead backs the
     * client — the page is lead-scoped, so there'd be nowhere to save to.
     */
    protected function consultInviteLine(): string
    {
        $tasks = $this->consultInviteTasks();
        $projects = $this->consultInviteProjects();

        // Nothing consult-shaped on this thread: no pending consult Meet AND
        // no project still in the pre-consult stages.
        if ($tasks->isEmpty() && $projects->isEmpty()) {
            return '';
        }

        // A consult already on the calendar answers the question — don't ask
        // the homeowner to pick times for a meeting that exists.
        if ($tasks->isEmpty() && $this->hasUpcomingConsult($projects)) {
            return '';
        }

        $lead = $this->threadLead();

        // No lead to anchor the pick-times page — never invent one. The
        // schedule page hosts the availability picker for Estimate/Response
        // projects, so the SAME pick-times ask points there instead.
        if (! $lead) {
            return $projects->isEmpty() ? '' : $this->schedulePickInviteLine($projects);
        }

        $vendor = $tasks->first()?->project?->createdByVendor
            ?? $projects->first()?->createdByVendor
            ?? $this->thread?->ownerVendor;
        $contractor = trim((string) ($vendor?->short_name ?: $vendor?->name ?: '')) ?: 'your contractor';

        $inviteText = match ($this->languageKey()) {
            'pl' => "Wybierz termin konsultacji z {$contractor}:",
            'es' => "Elige un horario de consulta con {$contractor}:",
            default => "Pick a consultation time with {$contractor}:",
        };

        $itemLines = $tasks
            ->map(fn (Task $task): string => '- ' . trim((string) ($task->title ?? 'Consult')))
            ->implode("\n");

        $url = app(\App\Services\UrlShortener::class)->shorten($lead->availabilityUrl());

        $label = match ($this->languageKey()) {
            'pl' => 'Terminy',
            'es' => 'Horarios',
            default => 'Times',
        };

        // With no pending Meet to list (an Estimate/Response project alone),
        // the invite is just the ask + link.
        return $itemLines === ''
            ? "{$inviteText}\n{$label}: {$url}"
            : "{$inviteText}\n{$itemLines}\n{$label}: {$url}";
    }

    /**
     * The pick-a-time ask for a lead-LESS client: same wording as the consult
     * invite, but the link is the project schedule page — which shows the
     * availability picker for Estimate/Response projects.
     */
    protected function schedulePickInviteLine(\Illuminate\Support\Collection $projects): string
    {
        $linkLine = $this->buildScheduleLink();
        $url = trim((string) \Illuminate\Support\Str::after($linkLine, ': '));

        if ($url === '') {
            return '';
        }

        $vendor = $projects->first()?->createdByVendor ?? $this->thread?->ownerVendor;
        $contractor = trim((string) ($vendor?->short_name ?: $vendor?->name ?: '')) ?: 'your contractor';

        $inviteText = match ($this->languageKey()) {
            'pl' => "Wybierz termin konsultacji z {$contractor}:",
            'es' => "Elige un horario de consulta con {$contractor}:",
            default => "Pick a consultation time with {$contractor}:",
        };

        $label = match ($this->languageKey()) {
            'pl' => 'Terminy',
            'es' => 'Horarios',
            default => 'Times',
        };

        return "{$inviteText}\n{$label}: {$url}";
    }

    /**
     * The thread client's projects still in the pre-consult stages (Estimate
     * / Response) — a schedule text to them should offer the pick-times link
     * even before anyone drafted a consult Meet.
     */
    protected function consultInviteProjects(): \Illuminate\Support\Collection
    {
        if ($this->thread?->subject_vendor_id || ! $this->thread?->client_id) {
            return collect();
        }

        return Project::withoutGlobalScopes()
            ->with(['latestStatus', 'createdByVendor'])
            ->where('client_id', $this->thread->client_id)
            ->whereNull('deleted_at')
            ->get()
            ->filter(fn (Project $project): bool => in_array(
                (int) ($project->latestStatus?->status_code ?? 0),
                [self::ESTIMATE_STATUS_CODE, self::RESPONSE_STATUS_CODE],
                true,
            ))
            ->values();
    }

    /** A consult Meet already scheduled (today or later) on any of these projects. */
    protected function hasUpcomingConsult(\Illuminate\Support\Collection $projects): bool
    {
        return Task::whereIn('project_id', $projects->pluck('id'))
            ->where('type', 'Meet')
            ->whereNotNull('start_date')
            ->whereDate('end_date', '>=', Carbon::today(browser_timezone())->format('Y-m-d'))
            ->exists();
    }

    /** Pending Meet tasks whose project is still at Estimate. */
    protected function consultInviteTasks(): \Illuminate\Support\Collection
    {
        if ($this->thread?->subject_vendor_id) {
            return collect();
        }

        return $this->pendingTasks->filter(fn (Task $task): bool => $this->isConsultInviteTask($task))->values();
    }

    protected function isConsultInviteTask(Task $task): bool
    {
        return $task->type === 'Meet'
            && in_array(
                (int) ($task->project?->latestStatus?->status_code ?? 0),
                [self::ESTIMATE_STATUS_CODE, self::RESPONSE_STATUS_CODE],
                true,
            );
    }

    /**
     * The lead behind this client thread, if any — the pick-times page is
     * lead-scoped. Newest first: a client who came back for a second project
     * has more than one.
     */
    protected function threadLead(): ?\App\Models\Lead
    {
        $clientId = $this->thread?->client_id;

        if (! $clientId) {
            return null;
        }

        $users = \App\Models\Client::withoutGlobalScopes()
            ->find($clientId)?->users()->withoutGlobalScopes()->get(['users.id', 'users.email']) ?? collect();

        if ($users->isEmpty()) {
            return null;
        }

        $byUser = \App\Models\Lead::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->whereIn('user_id', $users->pluck('id'))
            ->latest('id')
            ->first();

        if ($byUser) {
            return $byUser;
        }

        // Older leads lost their user link — connect by email before ever
        // concluding the client has none. Matched as-stored and lowercased:
        // the JSON column compares case-sensitively and user emails arrive
        // in whatever casing the homeowner typed.
        $emails = $users->pluck('email')
            ->filter(fn ($email) => is_string($email) && trim($email) !== '')
            ->flatMap(fn (string $email) => [trim($email), mb_strtolower(trim($email))])
            ->unique()
            ->values();

        if ($emails->isEmpty()) {
            return null;
        }

        return \App\Models\Lead::withoutGlobalScopes()
            ->whereNull('deleted_at')
            ->where(function ($query) use ($emails) {
                foreach ($emails as $email) {
                    $query->orWhere('lead_data->email', $email);
                }
            })
            ->latest('id')
            ->first();
    }

    /**
     * Build a message with just the greeting and schedule link (no tasks).
     */
    protected function buildScheduleLinkMessage(): string
    {
        $greeting = $this->buildRecipientGreeting();

        $linksText = $this->buildScheduleLink();

        if (! $linksText) {
            return '';
        }

        // A no-tasks thread can still be a homeowner mid-decision — an
        // Estimate/Response project earns the pick-a-consult invite even
        // when there is no schedule to show.
        $invite = $this->consultInviteLine();

        if ($invite === '') {
            return "{$greeting}\n\n{$linksText}";
        }

        // A lead-less invite already carries the schedule URL as its
        // pick-times link — repeating it as "View schedule" reads twice.
        $scheduleUrl = trim((string) \Illuminate\Support\Str::after($linksText, ': '));

        return $scheduleUrl !== '' && str_contains($invite, $scheduleUrl)
            ? "{$greeting}\n\n{$invite}"
            : "{$greeting}\n\n{$invite}\n\n{$linksText}";
    }

    /**
     * Build greeting for the active thread.
     * Client threads use client users; vendor threads use vendor users.
     */
    protected function buildRecipientGreeting(): string
    {
        $thread = $this->thread;

        if ($thread?->subject_vendor_id) {
            $shortName = trim((string) ($thread->subjectVendor?->short_name ?? ''));

            return $shortName !== ''
                ? 'Hi' . " {$shortName},"
                : ($this->vendorUserGreeting() ?: 'Hi,');
        }

        $clientName = trim($this->clientFirstNamesForGreeting($thread?->client_id));

        return $clientName !== ''
            ? $this->translateText('hi') . ' ' . $clientName . ','
            : $this->translateText('hi') . ',';
    }

    protected function clientFirstNamesForGreeting(?int $clientId): string
    {
        if (! $clientId) {
            return '';
        }

        $client = Client::withoutGlobalScopes()->with('users:id,first_name,nickname')->find($clientId);

        if (! $client) {
            return '';
        }

        if (trim((string) $client->business_name) !== '') {
            return trim((string) $client->first_names);
        }

        $firstNames = $client->users
            ->map(fn (User $user) => trim((string) ($user->nickname ?: $user->first_name)))
            ->filter()
            ->values()
            ->all();

        if ($firstNames === []) {
            return trim((string) $client->first_names);
        }

        return collect($firstNames)->join(', ', ' & ');
    }

    /**
     * Build the "Confirm Schedule" link text from the first available project.
     */
    protected function buildScheduleLink(): string
    {
        $projectIds = $this->clientProjectIds;

        if (empty($projectIds)) {
            return '';
        }

        $project = Project::find($projectIds[0]);

        if (! $project) {
            return '';
        }

        $devWebhookUrl = config('app.dev_webhook_url');
        $baseUrl = $devWebhookUrl ?: rtrim((string) config('app.url'), '/');
        $token = $project->getOrCreateScheduleToken();

        $scheduleUrl = app(\App\Services\UrlShortener::class)->shorten("{$baseUrl}/s/{$token}");

        return $this->scheduleLinkLabel() . ": {$scheduleUrl}";
    }

    protected function scheduleIntroLabel(): string
    {
        return $this->thread?->subject_vendor_id
            ? 'Upcoming tasks'
            : 'Upcoming tasks';
    }

    protected function scheduleLinkLabel(): string
    {
        return $this->thread?->subject_vendor_id
            ? 'Confirm Schedule'
            : 'View schedule';
    }

    protected function vendorUserGreeting(): ?string
    {
        $names = $this->thread?->subjectVendor?->users
            ?->map(fn (User $user) => $this->displayName($user))
            ->filter()
            ->values()
            ->all() ?? [];

        if ($names === []) {
            return null;
        }

        return 'Hi ' . collect($names)->join(', ', ' & ') . ',';
    }

    protected function scheduleDayLabel(string $key): string
    {
        if ($this->thread?->subject_vendor_id) {
            return match ($key) {
                'today' => 'Today',
                'tomorrow' => 'Tomorrow',
                'next_up' => 'Next up',
                'pending' => 'Pending',
                'later' => 'Later',
                default => $key,
            };
        }

        return $this->translateText($key);
    }

    protected function displayFirstName(User $user): string
    {
        $nickname = trim((string) ($user->nickname ?? ''));

        if ($nickname !== '') {
            return $nickname;
        }

        return trim((string) ($user->first_name ?? ''));
    }

    protected function displayName(User $user): string
    {
        $first = $this->displayFirstName($user);
        $last = trim((string) ($user->last_name ?? ''));

        return trim($first . ' ' . $last);
    }

    protected function languageKey(): string
    {
        $language = strtolower(trim((string) $this->viewerLanguage));

        return match (true) {
            str_contains($language, 'polish'), str_contains($language, 'polski') => 'pl',
            str_contains($language, 'spanish'), str_contains($language, 'espanol') => 'es',
            default => 'en',
        };
    }

    protected function translateText(string $key): string
    {
        $translations = [
            'en' => [
                'hi' => 'Hi',
                'confirm_tasks' => 'Confirm Tasks',
                'confirm_schedule' => 'Confirm Schedule',
                'today' => 'Today',
                'tomorrow' => 'Tomorrow',
                'next_up' => 'Next up',
                'pending' => 'Pending',
                'later' => 'Later',
            ],
            'pl' => [
                'hi' => 'Czesc',
                'confirm_tasks' => 'Potwierdz zadania',
                'confirm_schedule' => 'Potwierdz plan',
                'today' => 'Dzisiaj',
                'tomorrow' => 'Jutro',
                'next_up' => 'Nastepnie',
                'pending' => 'Oczekujace',
                'later' => 'Pozniej',
            ],
            'es' => [
                'hi' => 'Hola',
                'confirm_tasks' => 'Confirma tareas',
                'confirm_schedule' => 'Confirmar horario',
                'today' => 'Hoy',
                'tomorrow' => 'Manana',
                'next_up' => 'Proximo',
                'pending' => 'Pendientes',
                'later' => 'Mas tarde',
            ],
        ];

        $language = $this->languageKey();

        return $translations[$language][$key] ?? $translations['en'][$key] ?? $key;
    }

    protected function firstFutureScheduledDate(\Illuminate\Support\Collection $tasks, string $minDate): ?string
    {
        $dates = collect();

        foreach ($tasks as $task) {
            foreach ($this->taskScheduledDateKeys($task) as $dateKey) {
                if ($dateKey >= $minDate) {
                    $dates->push($dateKey);
                }
            }
        }

        return $dates->filter()->sort()->values()->first();
    }

    /**
     * @return array<int, string>
     */
    protected function taskScheduledDateKeys(Task $task): array
    {
        $dateKeys = collect();

        foreach ((array) data_get($task->options, 'dates', []) as $rawDate) {
            $normalizedDate = $this->normalizeTaskDateKey($rawDate);
            if ($normalizedDate) {
                $dateKeys->push($normalizedDate);
            }
        }

        if ($dateKeys->isNotEmpty()) {
            return $dateKeys->unique()->sort()->values()->all();
        }

        $start = $task->start_date?->copy()->startOfDay();
        $end = $task->end_date?->copy()->startOfDay();

        if (! $start && ! $end) {
            return [];
        }

        if (! $start) {
            $start = $end?->copy();
        }

        if (! $end) {
            $end = $start?->copy();
        }

        if (! $start || ! $end) {
            return [];
        }

        if ($end->lt($start)) {
            [$start, $end] = [$end, $start];
        }

        $cursor = $start->copy();

        while ($cursor->lte($end)) {
            $dateKeys->push($cursor->format('Y-m-d'));
            $cursor->addDay();
        }

        return $dateKeys->unique()->sort()->values()->all();
    }

    protected function taskScheduledOnDate(Task $task, string $dateKey): bool
    {
        return in_array($dateKey, $this->taskScheduledDateKeys($task), true);
    }

    /**
     * In vendor schedule threads, only show Reminder tasks created by the project owner vendor.
     * This keeps vendor-to-vendor reminders out of the outbound schedule message.
     *
     * @param  \Illuminate\Support\Collection<int, Task>  $tasks
     * @return \Illuminate\Support\Collection<int, Task>
     */
    protected function filterVendorReminderVisibility(\Illuminate\Support\Collection $tasks): \Illuminate\Support\Collection
    {
        if (! $this->thread?->subject_vendor_id) {
            return $tasks;
        }

        return $tasks->filter(function (Task $task): bool {
            return strcasecmp((string) $task->type, 'Reminder') !== 0;
        })->values();
    }

    /**
     * Send the schedule message to the thread.
     */
    public function send(GroupSmsService $smsService, SmsTranslationService $translator): void
    {
        if (empty($this->editableMessage)) {
            Flux::toast(variant: 'warning', heading: 'No Message', text: 'No message to send.', duration: 4000, position: 'top right');
            return;
        }

        $thread = $this->thread;

        if (! $thread) {
            Flux::toast(variant: 'danger', heading: 'Error', text: 'Thread not found.', duration: 4000, position: 'top right');
            return;
        }

        if ($thread->hasPendingOptIn()) {
            Flux::toast(variant: 'warning', heading: 'Awaiting START Replies', text: 'Each recipient must reply START before sending.', duration: 5000, position: 'top right');
            return;
        }

        $viewerLanguage = $translator->normalizeLanguage((string) (auth()->user()?->preferred_language ?: 'English'));
        $recipientLanguage = $translator->normalizeLanguage($this->recipientLanguage);

        // Keep schedule text exactly as drafted in the modal (no auto-translation)
        // so date headings, labels, and project details stay consistent.
        $outboundBody = $this->editableMessage;

        $text = $outboundBody . "\n-GSC";

        $rawPayload = [
            'original_text' => $this->editableMessage,
            'sender_language' => $viewerLanguage,
            'recipient_language' => $recipientLanguage,
            'source' => 'send_schedule_modal',
            'scheduled_task_ids' => $this->selectedTaskIds,
        ];

        $smsService->sendToThread(
            $thread,
            $text,
            [],
            null,
            null,
            $rawPayload,
            $this->scheduleWithoutDate,
        );

        // Mark vendor tasks as requested only after the SMS is sent.
        if ($thread->subject_vendor_id && ! $this->scheduleWithoutDate) {
            Task::whereIn('id', $this->selectedTaskIds)
                ->where('vendor_id', $thread->subject_vendor_id)
                ->whereNull('vendor_status')
                ->update([
                    'vendor_status' => Task::VENDOR_STATUS_REQUESTED,
                ]);
        }

        if ($this->scheduleWithoutDate) {
            Flux::toast(variant: 'success', heading: 'Scheduled', text: 'Message scheduled without date. Use send now to send it.', duration: 4000, position: 'top right');
        } else {
            Flux::toast(variant: 'success', heading: 'Sent', text: 'Schedule message sent.', duration: 4000, position: 'top right');
        }

        $this->showModal = false;
        $this->scheduleWithoutDate = false;
        $this->dispatch('messageSent');
        $this->dispatch('refreshMessages');
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.sms.send-schedule-modal');
    }
}
