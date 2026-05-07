<?php

namespace App\Livewire\Sms;

use App\Models\Project;
use App\Models\SmsGroupThread;
use App\Models\Task;
use App\Services\GroupSmsService;
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

    protected int $daysAhead = 3;

    #[On('openScheduleModal')]
    public function open(int $threadId): void
    {
        $this->threadId = $threadId;
        $this->showModal = true;
        $this->editableMessage = $this->previewMessage;
    }

    public function close(): void
    {
        $this->showModal = false;
    }

    #[Computed]
    public function thread(): ?SmsGroupThread
    {
        if (! $this->threadId) {
            return null;
        }

        return SmsGroupThread::with(['project.createdByVendor', 'client.users:id,first_name,last_name'])->find($this->threadId);
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

        return Task::whereIn('project_id', $projectIds)
            ->with(['vendor', 'project.client', 'project.latestStatus'])
            ->when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId))
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', '<=', $endDateStr)
            ->whereDate('end_date', '>=', $todayStr)
            ->orderBy('start_date')
            ->orderBy('order')
            ->get();
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

            if (! empty($selectedDates)) {
                foreach ($selectedDates as $dateStr) {
                    if ($dateStr >= $todayStr && $dateStr <= $endDateStr) {
                        if (! $grouped->has($dateStr)) {
                            $grouped[$dateStr] = collect();
                        }
                        $grouped[$dateStr]->push($task);
                    }
                }
            } else {
                $dateStr = $task->start_date->format('Y-m-d');
                if (! $grouped->has($dateStr)) {
                    $grouped[$dateStr] = collect();
                }
                $grouped[$dateStr]->push($task);
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

        return Task::whereIn('project_id', $projectIds)
            ->with(['vendor', 'project.client', 'project.latestStatus'])
            ->when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId))
            ->where(function ($query) {
                $query->whereNull('start_date')->orWhereNull('end_date');
            })
            ->orderBy('order')
            ->get();
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

        $firstTask = Task::whereIn('project_id', $projectIds)
            ->when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId))
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', '>=', $afterDate)
            ->orderBy('start_date')
            ->orderBy('order')
            ->first();

        if (! $firstTask) {
            return collect();
        }

        return Task::whereIn('project_id', $projectIds)
            ->with(['vendor', 'project.client', 'project.latestStatus'])
            ->when($vendorId, fn ($q) => $q->where('vendor_id', $vendorId))
            ->whereNotNull('start_date')
            ->whereNotNull('end_date')
            ->whereDate('start_date', $firstTask->start_date)
            ->orderBy('start_date')
            ->orderBy('order')
            ->get();
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

        // Build greeting from all client user first names (e.g. "Hi Katie & Jonathan,")
        $names = $this->thread?->client?->users
            ?->pluck('first_name')
            ->filter()
            ->values()
            ->all() ?? [];

        $greeting = count($names) > 0
            ? 'Hi ' . collect($names)->join(', ', ' & ') . ','
            : 'Hi,';

        $taskCount = $allTasks->count();
        $taskWord = $taskCount === 1 ? 'task' : 'tasks';

        $intro = "Upcoming {$taskWord}:";

        // Build task lines grouped by day (matching digest format)
        $today = Carbon::today(browser_timezone());
        $tomorrow = $today->copy()->addDay();
        $daySections = [];
        foreach ($grouped as $dateStr => $dayTasks) {
            if ($dayTasks->isEmpty()) {
                continue;
            }

            $carbonDate = Carbon::parse($dateStr);
            $shortDate = $carbonDate->format('D n/j');

            if ($carbonDate->isSameDay($today)) {
                $dateLabel = "Today {$shortDate}";
            } elseif ($carbonDate->isSameDay($tomorrow)) {
                $dateLabel = "Tomorrow {$shortDate}";
            } else {
                $dateLabel = $shortDate;
            }

            $showProject = (bool) $this->thread?->subject_vendor_id;
            $taskLines = $dayTasks->map(function (Task $task) use ($dateStr, $showProject) {
                $line = '- ' . trim($task->title ?? 'Task');

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
            $nextDate = Carbon::parse($nextTasks->first()->start_date);
            $dateLabel = $nextDate->format('D n/j');

            $showProject = (bool) $this->thread?->subject_vendor_id;
            $taskLines = $nextTasks->map(function (Task $task) use ($nextDate, $showProject) {
                $line = '- ' . trim($task->title ?? 'Task');
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

            $daySections[] = "Next up {$dateLabel}:\n{$taskLines}";
        }

        // Add pending tasks section
        if ($pendingTasks->isNotEmpty()) {
            $showProject = (bool) $this->thread?->subject_vendor_id;
            $pendingLines = $pendingTasks->map(function (Task $task) use ($showProject) {
                $line = '- ' . trim($task->title ?? 'Task');
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

            $daySections[] = "Pending:\n{$pendingLines}";
        }

        $body = implode("\n\n", $daySections);

        // Single schedule link — vendor threads link to vendor availability page, others to project schedule
        $linksText = '';
        $devWebhookUrl = config('app.dev_webhook_url');
        $baseUrl = $devWebhookUrl ?: rtrim((string) config('app.url'), '/');

        $subjectVendor = $this->thread?->subjectVendor;
        if ($subjectVendor) {
            $token = $subjectVendor->getOrCreateAvailabilityToken();
            $linksText = "\nView Schedule: {$baseUrl}/v/{$token}";
        } else {
            $firstProject = $allTasks->first()?->project;
            if ($firstProject) {
                $token = $firstProject->getOrCreateScheduleToken();
                $linksText = "\nView Schedule: {$baseUrl}/s/{$token}";
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
        $names = $this->thread?->client?->users
            ?->pluck('first_name')
            ->filter()
            ->values()
            ->all() ?? [];

        $greeting = count($names) > 0
            ? 'Hi ' . collect($names)->join(', ', ' & ') . ','
            : 'Hi,';

        $linksText = $this->buildScheduleLink();

        if (! $linksText) {
            return '';
        }

        return "{$greeting}\n{$linksText}";
    }

    /**
     * Build the "View Schedule" link text from the first available project.
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

        return "View Schedule: {$baseUrl}/s/{$token}";
    }

    /**
     * Send the schedule message to the thread.
     */
    public function send(GroupSmsService $smsService): void
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

        $text = $this->editableMessage . "\n-GSC";

        $smsService->sendToThread($thread, $text, [], null);

        Flux::toast(variant: 'success', heading: 'Sent', text: 'Schedule message sent.', duration: 4000, position: 'top right');

        $this->showModal = false;
        $this->dispatch('messageSent');
        $this->dispatch('refreshMessages');
    }

    public function render(): \Illuminate\View\View
    {
        return view('livewire.sms.send-schedule-modal');
    }
}
