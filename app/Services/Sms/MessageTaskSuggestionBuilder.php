<?php

namespace App\Services\Sms;

use App\Models\Client;
use App\Models\Project;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\Task;
use App\Models\User;
use App\Services\SmsTaskExtractionService;
use App\Support\Sms\ConversationPresenter;
use Carbon\Carbon;

/**
 * Builds the "Create Task" prefill suggestion for one SMS message — the AI
 * extraction, schedule-reply fallback, and client/project/assignee
 * resolution App\Livewire\Sms\SmsConversation::createTaskFromMessage() used
 * to do inline. Shared as-is by the web component (which only wraps this in
 * its own toasts/dispatch) and the app's task-suggestion endpoint, so both
 * surfaces compute the exact same suggestion for the exact same message.
 */
class MessageTaskSuggestionBuilder
{
    /** How long after a message its follow-up photos still belong to that task. */
    protected const TASK_IMAGE_WINDOW_MINUTES = 5;

    /** How many earlier messages, and how far back, the task extractor sees. */
    public const TASK_CONTEXT_MESSAGES = 6;

    public const TASK_CONTEXT_DAYS = 3;

    /**
     * Build the suggestion for one message, or a failure reason matching one
     * of the web's toasts ('no_text', 'no_task', 'no_client', 'no_project').
     *
     * @return array{ok: bool, reason: ?string, heading: ?string, text: ?string, payload: ?array<string, mixed>}
     */
    public function suggest(SmsMessage $message, ?int $threadId, SmsTaskExtractionService $extractor): array
    {
        $text = trim((string) $message->display_text);

        if ($text === '') {
            return $this->failure('no_text', 'No Text', 'This message has no text to analyze.');
        }

        $sentAt = $message->created_at->copy()->setTimezone(vendor_timezone());

        $isTimeRangeReply = $this->extractTimeRangeFromReply($text) !== null;
        $scheduleReplyFallback = $isTimeRangeReply
            ? $this->fallbackExtractFromScheduleReply($message, $sentAt)
            : null;

        $extracted = $extractor->extract($text, $sentAt, $this->conversationContext($message, $threadId), $this->contextSpeaker($message));

        if ($scheduleReplyFallback && ($scheduleReplyFallback['has_task'] ?? false) && ($scheduleReplyFallback['title'] ?? '') !== '') {
            $extracted = $scheduleReplyFallback;
        }

        if (! $extracted || ! $extracted['has_task'] || $extracted['title'] === '') {
            $extracted = $this->fallbackExtractFromScheduleReply($message, $sentAt);
        }

        if (! $extracted || ! $extracted['has_task'] || $extracted['title'] === '') {
            return $this->failure('no_task', 'No Task Found', 'No schedulable task was found in this message.');
        }

        $checklistItems = collect($extracted['checklist'] ?? [])
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->values();

        // The checklist holds only the to-dos the extractor names. The raw
        // message is never split into it: its sentences ("Hey Patryk, hope
        // things have been going well") showed on the sub's page as tasks
        // (2026-10-15). The message itself is kept as the task's notes.

        $smsImageUrls = $this->imagesForTaskFromMessage($message, $threadId);

        $thread = $threadId ? SmsGroupThread::find($threadId) : null;
        $matchedTask = null;
        $matchedTaskId = isset($extracted['task_id']) && is_numeric($extracted['task_id'])
            ? (int) $extracted['task_id']
            : null;

        if ($matchedTaskId) {
            $matchedTask = Task::query()
                ->with('project.client')
                ->find($matchedTaskId);
        }

        $vendorId = $thread?->subject_vendor_id ?? $matchedTask?->vendor_id;
        $client = $matchedTask?->project?->client ?? $thread?->client;

        $projectId = null;
        if ($matchedTask?->project_id) {
            $projectId = (int) $matchedTask->project_id;
        } elseif ($client) {
            $projectId = $this->resolveClientProjectId($client, $thread, $extracted['project_hint']);
        } elseif ($thread?->project_id) {
            $projectId = (int) $thread->project_id;
        } elseif ($vendorId) {
            $projectId = $this->resolveVendorProjectId($vendorId, $extracted['project_hint']);
        }

        if (! $client && $projectId) {
            $client = Project::query()->with('client')->find($projectId)?->client;
        }

        if (! $client) {
            return $this->failure('no_client', 'No Client', 'Assign a client to this conversation before creating a task.', 4500);
        }

        if (! $projectId) {
            return $this->failure('no_project', 'No Project', 'This client has no project to attach the task to.', 4500);
        }

        if (! $matchedTask && $projectId) {
            $matchedTask = Task::query()
                ->where('project_id', $projectId)
                ->where(function ($titleQuery) use ($extracted) {
                    $title = strtolower(trim((string) ($extracted['title'] ?? '')));
                    if ($title === '') {
                        return;
                    }

                    $titleQuery->whereRaw('LOWER(TRIM(title)) = ?', [$title])
                        ->orWhereRaw('LOWER(title) LIKE ?', ['%' . $title . '%']);
                })
                ->when(! empty($extracted['date']), function ($dateQuery) use ($extracted) {
                    $date = (string) $extracted['date'];

                    $dateQuery->where(function ($innerDateQuery) use ($date) {
                        $innerDateQuery->whereDate('start_date', $date)
                            ->orWhere(function ($rangeQuery) use ($date) {
                                $rangeQuery->whereNotNull('start_date')
                                    ->whereNotNull('end_date')
                                    ->whereDate('start_date', '<=', $date)
                                    ->whereDate('end_date', '>=', $date);
                            });
                    });
                })
                ->latest('id')
                ->first();
        }

        $payload = [
            'task_id' => $matchedTask?->id ?? ($extracted['task_id'] ?? null),
            'title' => $extracted['title'],
            'type' => $extracted['type'],
            'meeting_location_type' => $extracted['meeting_type'] ?? null,
            'project_id' => $projectId,
            'client_id' => $client->id,
            'vendor_id' => $vendorId,
            'notes' => trim((string) ($extracted['notes'] ?? '')) !== '' ? $extracted['notes'] : $text,
            'date' => $extracted['date'],
            'start_time' => $extracted['start_time'],
            'end_time' => $extracted['end_time'],
            'user_ids' => $this->resolveAssigneeUserIds($extracted['assignee_names']),
            'checklist' => $checklistItems
                ->map(fn ($item) => trim((string) $item))
                ->filter()
                ->map(fn (string $text) => ['text' => $text, 'completed' => false])
                ->values()
                ->all(),
            'sms_media_urls' => $smsImageUrls,
            'additional_tasks' => collect($extracted['additional_tasks'] ?? [])
                ->map(fn (array $task) => [
                    'title' => $task['title'],
                    'type' => $task['type'] ?? 'Task',
                    'date' => $task['date'] ?? null,
                    'start_time' => $task['start_time'] ?? null,
                    'end_time' => $task['end_time'] ?? null,
                    'notes' => $task['notes'] ?? null,
                    'user_ids' => $this->resolveAssigneeUserIds($task['assignee_names'] ?? []),
                ])
                ->all(),
            'multi_time' => preg_match_all('/\b\d{1,2}(?::\d{2})?\s*(?:am|pm)\b/i', $text) >= 2,
        ];

        return ['ok' => true, 'reason' => null, 'heading' => null, 'text' => null, 'payload' => $payload];
    }

    /**
     * @return array{ok: bool, reason: string, heading: string, text: string, payload: null}
     */
    private function failure(string $reason, string $heading, string $text, int $duration = 4000): array
    {
        return ['ok' => false, 'reason' => $reason, 'heading' => $heading, 'text' => $text, 'payload' => null, 'duration' => $duration];
    }

    /**
     * The messages before this one in the thread, oldest first: "afternoon
     * works best" only means a call tomorrow next to the question that asked
     * (thread 393, 2026-10-15).
     *
     * @return list<array{who: string, sent_at: \Carbon\CarbonInterface, text: string}>
     */
    protected function conversationContext(SmsMessage $message, ?int $threadId): array
    {
        return SmsMessage::where('thread_id', $threadId)
            ->where('id', '!=', $message->id)
            ->where('created_at', '<=', $message->created_at)
            ->where('created_at', '>=', $message->created_at->copy()->subDays(self::TASK_CONTEXT_DAYS))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::TASK_CONTEXT_MESSAGES)
            ->get()
            ->reverse()
            ->map(fn (SmsMessage $earlier) => [
                'who' => $this->contextSpeaker($earlier),
                'sent_at' => $earlier->created_at->copy()->setTimezone(vendor_timezone()),
                'text' => trim((string) $earlier->display_text),
            ])
            ->filter(fn (array $earlier) => $earlier['text'] !== '')
            ->values()
            ->all();
    }

    /** "Patryk (our team)" for our side, the contact's first name (or "Client") for theirs. */
    protected function contextSpeaker(SmsMessage $message): string
    {
        if ($message->isOutbound()) {
            return trim(($message->sentByUser?->first_name ?? 'Team').' (our team)');
        }

        $digits = substr((string) preg_replace('/\D/', '', (string) $message->from_number), -10);
        $user = $digits !== ''
            ? User::withoutGlobalScopes()->where('cell_phone', 'like', '%'.$digits)->first()
            : null;

        return $user?->first_name ?: 'Client';
    }

    /**
     * Images to attach to the task: this message's own, plus any that arrive
     * immediately afterwards from the same sender.
     *
     * People announce photos and then send them ("...Sending pictures" followed
     * by three image-only texts a second later). Reading only the clicked
     * message's media_urls attached nothing at all in exactly the case where the
     * photos matter most. A follow-up counts when it is inbound, arrives within
     * a few minutes, and carries images — a later message with its OWN text is
     * a new topic and stops the run.
     *
     * @return array<int, string>
     */
    protected function imagesForTaskFromMessage(SmsMessage $message, ?int $threadId): array
    {
        $urls = collect(is_array($message->media_urls) ? $message->media_urls : []);

        $followUps = SmsMessage::query()
            ->where('thread_id', $threadId)
            ->where('id', '>', $message->id)
            ->where('direction', $message->direction)
            ->where('created_at', '<=', $message->created_at->copy()->addMinutes(self::TASK_IMAGE_WINDOW_MINUTES))
            ->orderBy('id')
            ->limit(10)
            ->get();

        foreach ($followUps as $followUp) {
            $media = collect(is_array($followUp->media_urls) ? $followUp->media_urls : []);

            // Text of its own means the sender moved on — stop collecting.
            if (trim((string) $followUp->display_text) !== '') {
                break;
            }

            if ($media->isEmpty()) {
                break;
            }

            $urls = $urls->merge($media);
        }

        return $urls
            ->filter(fn ($url) => is_string($url) && SmsMessage::isImageUrl($url))
            ->map(fn (string $url) => ConversationPresenter::mediaUrl($url))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Fallback extraction for short time-only replies that reference the
     * previous "Confirm Tasks" blast in the same thread.
     *
     * @return array{
     *     has_task: bool,
    *     task_id: ?int,
     *     title: string,
     *     type: string,
     *     date: ?string,
     *     start_time: ?string,
     *     end_time: ?string,
     *     project_hint: ?string,
     *     assignee_names: array<int, string>,
     *     checklist: array<int, string>
     * }|null
     */
    protected function fallbackExtractFromScheduleReply(SmsMessage $message, Carbon $sentAt): ?array
    {
        $currentText = trim((string) $message->display_text);
        if ($currentText === '') {
            return null;
        }

        $timeRange = $this->extractTimeRangeFromReply($currentText);
        if ($timeRange === null) {
            return null;
        }

        $previous = SmsMessage::query()
            ->where('thread_id', $message->thread_id)
            ->where('id', '<', $message->id)
            ->where('direction', SmsMessage::DIRECTION_OUTBOUND)
            ->whereNotNull('text')
            ->where('text', 'like', '%Confirm Tasks:%')
            ->latest('id')
            ->first();

        if (! $previous) {
            return null;
        }

        $previousText = trim((string) ($previous->display_text ?? ''));
        if ($previousText === '') {
            return null;
        }

        if (! preg_match('/^\s*-\s*(.+?)\s*$/m', $previousText, $taskMatch)) {
            return null;
        }

        $title = trim((string) ($taskMatch[1] ?? ''));
        if ($title === '') {
            return null;
        }

        $date = $this->extractDateFromScheduleBlast($previousText, $previous->created_at?->copy()->setTimezone(vendor_timezone()) ?? $sentAt);
        $matchedTaskId = collect((array) data_get($previous->raw_payload, 'scheduled_task_ids', []))
            ->map(static fn ($id) => is_numeric($id) ? (int) $id : null)
            ->filter(static fn (?int $id) => $id !== null && $id > 0)
            ->first();

        if (! $matchedTaskId) {
            $matchedTaskId = $this->resolveScheduledReplyTaskId($message->thread_id, $title, $date);
        }

        return [
            'has_task' => true,
            'task_id' => $matchedTaskId,
            'title' => $title,
            'type' => 'Task',
            'date' => $date,
            'start_time' => $timeRange['start_time'],
            'end_time' => $timeRange['end_time'],
            'project_hint' => null,
            'assignee_names' => [],
            'checklist' => [],
        ];
    }

    protected function resolveScheduledReplyTaskId(?int $threadId, string $title, ?string $date): ?int
    {
        if (! $threadId) {
            return null;
        }

        $thread = SmsGroupThread::find($threadId);
        if (! $thread) {
            return null;
        }

        $normalizedTitle = strtolower(trim($title));
        if ($normalizedTitle === '') {
            return null;
        }

        $query = Task::query()
            ->where(function ($titleQuery) use ($normalizedTitle) {
                $titleQuery->whereRaw('LOWER(TRIM(title)) = ?', [$normalizedTitle])
                    ->orWhereRaw('LOWER(title) LIKE ?', ['%' . $normalizedTitle . '%']);
            });

        if ($thread->project_id) {
            $query->where('project_id', $thread->project_id);
        } elseif ($thread->client_id) {
            $projectIds = Project::query()
                ->where('client_id', $thread->client_id)
                ->pluck('id')
                ->all();

            if ($projectIds === []) {
                return null;
            }

            $query->whereIn('project_id', $projectIds);
        } elseif ($thread->subject_vendor_id) {
            $query->where('vendor_id', $thread->subject_vendor_id);
        }

        if ($date) {
            $query->where(function ($dateQuery) use ($date) {
                $dateQuery->whereDate('start_date', $date)
                    ->orWhere(function ($rangeQuery) use ($date) {
                        $rangeQuery->whereNotNull('start_date')
                            ->whereNotNull('end_date')
                            ->whereDate('start_date', '<=', $date)
                            ->whereDate('end_date', '>=', $date);
                    });
            });
        }

        return $query->latest('id')->value('id');
    }

    protected function resolveVendorProjectId(int $vendorId, ?string $hint): ?int
    {
        $vendorProjects = Project::query()
            ->where('belongs_to_vendor_id', $vendorId)
            ->with('latestStatus')
            ->orderByDesc('created_at')
            ->get();

        if ($vendorProjects->isEmpty()) {
            return null;
        }

        if ($hint) {
            $best = $vendorProjects
                ->map(fn (Project $project) => [
                    'id' => (int) $project->id,
                    'score' => $this->projectMatchScore($hint, (string) ($project->getRawOriginal('project_name') ?? $project->project_name)),
                ])
                ->sortByDesc('score')
                ->first();

            if ($best && $best['score'] > 0) {
                return $best['id'];
            }
        }

        return (int) $vendorProjects->first()->id;
    }

    /**
     * @return array{start_time: ?string, end_time: ?string}|null
     */
    protected function extractTimeRangeFromReply(string $text): ?array
    {
        $normalized = strtolower(trim($text));

        if (preg_match('/\b(\d{1,2})(?::(\d{2}))?\s*-\s*(\d{1,2})(?::(\d{2}))?\s*(am|pm)\b/i', $normalized, $m) === 1) {
            $startHour = (int) $m[1];
            $startMin = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : 0;
            $endHour = (int) $m[3];
            $endMin = isset($m[4]) && $m[4] !== '' ? (int) $m[4] : 0;
            $meridiem = strtolower((string) $m[5]);

            $start = $this->to24Hour($startHour, $startMin, $meridiem);
            $end = $this->to24Hour($endHour, $endMin, $meridiem);

            if ($start === null || $end === null) {
                return null;
            }

            return [
                'start_time' => $start,
                'end_time' => $end,
            ];
        }

        return null;
    }

    protected function to24Hour(int $hour, int $minute, string $meridiem): ?string
    {
        if ($hour < 1 || $hour > 12 || $minute < 0 || $minute > 59) {
            return null;
        }

        $hour = $hour % 12;
        if ($meridiem === 'pm') {
            $hour += 12;
        }

        return sprintf('%02d:%02d', $hour, $minute);
    }

    protected function extractDateFromScheduleBlast(string $blastText, Carbon $fallbackBaseDate): ?string
    {
        if (preg_match('/\b(\d{1,2})\/(\d{1,2})\b/', $blastText, $md) === 1) {
            $month = (int) ($md[1] ?? 0);
            $day = (int) ($md[2] ?? 0);
            if ($month >= 1 && $month <= 12 && $day >= 1 && $day <= 31) {
                return Carbon::create($fallbackBaseDate->year, $month, $day, 0, 0, 0, $fallbackBaseDate->getTimezone())
                    ->toDateString();
            }
        }

        $lower = strtolower($blastText);
        if (str_contains($lower, 'tomorrow')) {
            return $fallbackBaseDate->copy()->addDay()->toDateString();
        }

        if (str_contains($lower, 'today')) {
            return $fallbackBaseDate->toDateString();
        }

        return null;
    }

    /**
     * Match AI-extracted person names to employed team members of the acting
     * user's vendor, returning their user ids for task assignment.
     *
     * @param  array<int, string>  $names
     * @return array<int, int>
     */
    protected function resolveAssigneeUserIds(array $names): array
    {
        $names = array_values(array_filter(array_map(
            fn ($name) => strtolower(trim((string) $name)),
            $names
        )));

        if ($names === []) {
            return [];
        }

        $vendor = auth()->user()?->vendor;

        if (! $vendor) {
            return [];
        }

        $ids = [];

        foreach ($vendor->users()->employed()->get() as $user) {
            $first = strtolower(trim((string) $user->first_name));
            $nickname = strtolower(trim((string) $user->nickname));
            $full = strtolower(trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')));
            $nicknameFull = $nickname === '' ? '' : strtolower(trim($nickname . ' ' . ($user->last_name ?? '')));

            foreach ($names as $name) {
                if ($name === '') {
                    continue;
                }

                if ($name === $first || $name === $full || $name === $nickname || $name === $nicknameFull) {
                    $ids[] = (int) $user->id;

                    break;
                }
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * Pick the best project for a client when creating a task from a message.
     * Prefers a keyword match against the AI room/area hint, then the thread's
     * project, then the most recent active (non-closed) project.
     */
    protected function resolveClientProjectId(Client $client, ?SmsGroupThread $thread, ?string $hint): ?int
    {
        $projects = $client->projects()
            ->with('latestStatus')
            ->orderByDesc('created_at')
            ->get();

        if ($projects->isEmpty()) {
            return $thread?->project_id;
        }

        if ($hint) {
            $best = $projects
                ->map(fn (Project $project) => [
                    'id' => (int) $project->id,
                    'score' => $this->projectMatchScore($hint, (string) ($project->getRawOriginal('project_name') ?? $project->project_name)),
                ])
                ->sortByDesc('score')
                ->first();

            if ($best && $best['score'] > 0) {
                return $best['id'];
            }
        }

        if ($thread?->project_id && $projects->contains('id', $thread->project_id)) {
            return (int) $thread->project_id;
        }

        $closedStatusCodes = [7, 8, 10, 11]; // Complete, Service Call, Cancelled, VIEW_ONLY

        $active = $projects->first(fn (Project $project) => ! in_array(
            (int) ($project->latestStatus?->status_code ?? 0),
            $closedStatusCodes,
            true
        ));

        return (int) ($active?->id ?? $projects->first()->id);
    }

    /**
     * Score keyword overlap between a hint and a project name. Exact word
     * matches score highest; substring matches (e.g. "bath" in "bathrooms")
     * still count so loose references resolve to the right project.
     */
    protected function projectMatchScore(string $hint, string $name): int
    {
        $hintWords = $this->keywords($hint);
        $nameWords = $this->keywords($name);
        $score = 0;

        foreach ($hintWords as $hintWord) {
            foreach ($nameWords as $nameWord) {
                if ($hintWord === $nameWord) {
                    $score += 2;
                } elseif (strlen($hintWord) >= 4 && (str_contains($nameWord, $hintWord) || str_contains($hintWord, $nameWord))) {
                    $score += 1;
                }
            }
        }

        return $score;
    }

    /**
     * Break text into lowercased significant keywords.
     *
     * @return array<int, string>
     */
    protected function keywords(string $text): array
    {
        $stopWords = ['the', 'and', 'for', 'with', 'room', 'repair', 'project', 'job'];

        return collect(preg_split('/[^a-z0-9]+/i', strtolower($text)) ?: [])
            ->filter(fn (string $word) => strlen($word) >= 3 && ! in_array($word, $stopWords, true))
            ->unique()
            ->values()
            ->all();
    }
}
