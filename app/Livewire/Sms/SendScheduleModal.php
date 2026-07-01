<?php

namespace App\Livewire\Sms;

use App\Models\Project;
use App\Models\Client;
use App\Models\SmsGroupThread;
use App\Models\Task;
use App\Models\User;
use App\Services\GroupSmsService;
use App\Services\SmsTranslationService;
use Carbon\Carbon;
use Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

class SendScheduleModal extends Component
{
    public bool $showModal = false;

    public ?int $threadId = null;

    public string $editableMessage = '';

    public bool $scheduleWithoutDate = false;

    protected int $daysAhead = 3;

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
    #[Computed]
    public function upcomingTasks(): \Illuminate\Support\Collection
    {
        $projectIds = $this->clientProjectIds;

        if (empty($projectIds)) {
            return collect();
        }

        $today = Carbon::today(browser_timezone());
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
        $today = Carbon::today(browser_timezone());
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
            ->with(['vendor', 'project.client', 'project.latestStatus'])
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

        $intro = $this->scheduleIntroLabel() . ':';

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
                if ($projectName !== '') {
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
                if ($projectName !== '') {
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

        // Add pending tasks section
        if ($pendingTasks->isNotEmpty()) {
            $showProject = (bool) $this->thread?->subject_vendor_id;
            $pendingLines = $pendingTasks->map(function (Task $task) use ($showProject) {
                $taskTitle = trim($task->title ?? 'Task');
                $projectName = trim((string) ($task->project?->project_name ?? ''));
                if ($projectName !== '') {
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
            })->implode("\n");

            $daySections[] = $this->scheduleDayLabel('pending') . ":\n{$pendingLines}";
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

        return "{$greeting}\n{$intro}\n\n{$body}\n{$linksText}";
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

        return "{$greeting}\n{$linksText}";
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
                ? 'Hello' . " {$shortName},"
                : ($this->vendorUserGreeting() ?: 'Hello,');
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

        return 'Hello ' . collect($names)->join(', ', ' & ') . ',';
    }

    protected function scheduleDayLabel(string $key): string
    {
        if ($this->thread?->subject_vendor_id) {
            return match ($key) {
                'today' => 'Today',
                'tomorrow' => 'Tomorrow',
                'next_up' => 'Next up',
                'pending' => 'Pending',
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
            ],
            'pl' => [
                'hi' => 'Czesc',
                'confirm_tasks' => 'Potwierdz zadania',
                'confirm_schedule' => 'Potwierdz plan',
                'today' => 'Dzisiaj',
                'tomorrow' => 'Jutro',
                'next_up' => 'Nastepnie',
                'pending' => 'Oczekujace',
            ],
            'es' => [
                'hi' => 'Hola',
                'confirm_tasks' => 'Confirma tareas',
                'confirm_schedule' => 'Confirmar horario',
                'today' => 'Hoy',
                'tomorrow' => 'Manana',
                'next_up' => 'Proximo',
                'pending' => 'Pendientes',
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
