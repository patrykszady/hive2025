<?php

namespace App\Livewire\Sms;

use App\Livewire\Sms\SmsIndex;
use App\Livewire\Sms\SmsNewThread;
use App\Livewire\Tasks\TaskCreate;
use App\Models\BlockedCaller;
use App\Models\CallLog;
use App\Models\Client;
use App\Models\Project;
use App\Models\SmsGroupThread;
use App\Models\SmsMessage;
use App\Models\SmsThreadParticipant;
use App\Models\SmsThreadRead;
use App\Models\Task;
use App\Models\User;
use App\Models\Vendor;
use App\Services\GroupSmsService;
use App\Services\SmsTranslationService;
use App\Services\SmsTaskExtractionService;
use Carbon\Carbon;
use Flux;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Isolate;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use Livewire\WithFileUploads;

#[Isolate]
class SmsConversation extends Component
{
    use WithFileUploads;

    private const ATTACHMENT_VALIDATION_RULE = 'nullable|file|mimes:jpg,jpeg,png,gif,webp,mp4,mov,webm,m4v,3gp,avi';

    /**
     * Messages loaded when a thread is first opened. Kept small so switching
     * threads paints quickly; older messages stream in on scroll.
     */
    private const INITIAL_MESSAGE_LIMIT = 10;

    public ?int $threadId = null;

    public string $newMessage = '';

    public $attachment;

    public bool $showImageLightbox = false;

    public ?string $lightboxImageUrl = null;

    public bool $isClientUser = false;

    public bool $showOptInModal = false;

    public bool $showDeleteConfirm = false;

    public bool $showAssignClientModal = false;

    /** 'client' or 'vendor' */
    public string $assignSubjectType = 'client';

    public ?int $assignClientId = null;

    public ?int $assignVendorId = null;

    public ?int $cancelScheduledId = null;

    public bool $showCancelModal = false;

    public ?int $editScheduledId = null;

    public string $manualOptInReason = '';

    public ?int $manualOptInParticipantId = null;

    protected ?int $lastMarkedMessageId = null;

    public int $messageLimit = self::INITIAL_MESSAGE_LIMIT;

    public bool $selectionMode = false;

    /** @var array<int, int> */
    public array $selectedMessageIds = [];

    public bool $showForwardModal = false;

    public ?int $forwardTargetThreadId = null;

    public string $forwardThreadSearch = '';

    public function mount(): void
    {
        $this->isClientUser = (bool) auth()->user()->is_browsing_as_client;
        $this->authorizeThread();
        // Opening a thread does NOT mark it read — threads stay unread until
        // the user exits them (switches thread, closes it, or leaves the page).
    }

    /**
     * Swap to a different thread without destroying/recreating the component.
     * Called from SmsIndex when user selects a thread or navigates back.
     */
    #[On('loadThread')]
    public function loadThread(?int $threadId): void
    {
        if ($threadId === $this->threadId) {
            // Same thread — refresh messages (e.g., after notification click)
            unset($this->smsMessages, $this->processedMessages, $this->phoneNameMap);
            $this->dispatch('thread-ready');
            return;
        }

        // Exiting the current thread — mark IT read before swapping.
        $this->markThreadAsRead();

        $this->threadId = $threadId;
        $this->authorizeThread();
        $this->messageLimit = self::INITIAL_MESSAGE_LIMIT;
        $this->newMessage = '';
        $this->attachment = null;
        $this->showImageLightbox = false;
        $this->lightboxImageUrl = null;
        $this->showOptInModal = false;
        $this->manualOptInReason = '';
        $this->manualOptInParticipantId = null;
        $this->lastMarkedMessageId = null;
        $this->selectionMode = false;
        $this->selectedMessageIds = [];
        $this->showForwardModal = false;
        $this->forwardTargetThreadId = null;
        $this->forwardThreadSearch = '';

        $this->dispatch('thread-ready');
    }

    /** @return array<string, string> */
    public function getListeners(): array
    {
        return [
            'echo-private:sms.notifications,SmsMessageReceived' => 'handleIncomingMessage',
        ];
    }

    /**
     * Ensure the current user is allowed to view the selected thread.
     * Client users may only access threads belonging to their client(s).
     * Vendor users may only access threads belonging to their vendor.
     */
    private function authorizeThread(): void
    {
        if (! $this->threadId) {
            return;
        }

        $user = auth()->user();

        if ($this->isClientUser) {
            $clientIds = $user->clients()->pluck('clients.id');
            $participantPhones = $this->clientUserParticipantPhones($user);
            $allowed = SmsGroupThread::where('id', $this->threadId)
                ->whereIn('client_id', $clientIds)
                ->whereHas('threadParticipants', fn ($participantQuery) => $participantQuery->whereIn('phone_number', $participantPhones))
                ->exists();
        } else {
            $vendorId = $user->vendor?->id;
            $allowed = $vendorId && SmsGroupThread::where('id', $this->threadId)
                ->visibleToVendor($vendorId)
                ->exists();
        }

        if (! $allowed) {
            $this->threadId = null;
        }
    }

    /**
     * @return array<int, string>
     */
    protected function clientUserParticipantPhones(User $user): array
    {
        $rawPhone = $user->routeNotificationForTelnyx();

        if (! is_string($rawPhone) || $rawPhone === '') {
            return [];
        }

        $digits = preg_replace('/\D/', '', $rawPhone);
        if (! is_string($digits) || $digits === '') {
            return [];
        }

        if (strlen($digits) === 10) {
            return array_values(array_unique([$rawPhone, '+1' . $digits, '1' . $digits, $digits]));
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            $tenDigit = substr($digits, 1);

            return array_values(array_unique([$rawPhone, '+' . $digits, $digits, '+1' . $tenDigit, $tenDigit]));
        }

        return array_values(array_unique([$rawPhone, '+' . $digits, $digits]));
    }

    public function handleIncomingMessage($payload = null): void
    {
        $incomingThreadId = is_array($payload)
            ? ($payload['threadId'] ?? null)
            : $payload;

        if ($incomingThreadId !== null && (int) $incomingThreadId !== (int) $this->threadId) {
            // Message belongs to a different thread — don't repaint this pane.
            $this->skipRender();

            return;
        }

        unset($this->smsMessages, $this->processedMessages, $this->phoneNameMap, $this->threadMedia, $this->threadImages);
        $this->dispatch('sms-new-message-received');
    }

    #[On('refreshMessages')]
    #[On('sms-schedule-changed')]
    public function refreshMessages(): void
    {
        unset($this->smsMessages, $this->processedMessages, $this->phoneNameMap, $this->threadMedia, $this->threadImages, $this->threadHasMixedNumbers);
    }

    /**
     * Fingerprint of the rendered thread's messages, refreshed on every
     * render. Lets {@see pollForUpdates()} detect changes with one cheap
     * aggregate query instead of repainting the conversation blindly.
     */
    public ?string $pollFingerprint = null;

    /**
     * Safety-net poll: Echo websockets are the primary realtime path, but
     * broadcasts can be missed (dropped connection, background tab, failed
     * dispatch). Only repaints when a message was added, removed, or updated
     * (e.g. a scheduled message transitioning to sent).
     */
    public function pollForUpdates(): void
    {
        if (! $this->threadId) {
            $this->skipRender();

            return;
        }

        if ($this->messagesFingerprint() === $this->pollFingerprint) {
            $this->skipRender();

            return;
        }

        $this->refreshMessages();
    }

    protected function messagesFingerprint(): string
    {
        $stats = SmsMessage::query()
            ->where('thread_id', $this->threadId)
            ->selectRaw('COUNT(*) as total, COALESCE(MAX(id), 0) as max_id, COALESCE(MAX(updated_at), "") as last_update')
            ->first();

        return $stats->total . ':' . $stats->max_id . ':' . $stats->last_update;
    }

    public function updatedAttachment(): void
    {
        $this->validate([
            'attachment' => self::attachmentValidationRule(),
        ]);
    }

    public static function attachmentValidationRule(): string
    {
        return self::ATTACHMENT_VALIDATION_RULE;
    }

    public function removeAttachment(): void
    {
        $this->attachment = null;
    }

    public function openImageLightbox(string $url): void
    {
        $this->openMediaLightbox($url);
    }

    public function openVideoLightbox(string $url): void
    {
        $this->openMediaLightbox($url);
    }

    public function openMediaLightbox(string $url): void
    {
        $this->lightboxImageUrl = $url;
        $this->showImageLightbox = true;
        $this->dispatch('lightbox-images-updated', images: $this->threadMedia);
    }

    /**
     * Flat list of image+video URLs in this thread for lightbox navigation.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function threadMedia(): array
    {
        return $this->smsMessages
            ->flatMap(fn (SmsMessage $msg) => $msg->media_urls ?? [])
            ->filter(fn (string $url) => SmsMessage::isImageUrl($url) || SmsMessage::isVideoUrl($url))
            ->values()
            ->all();
    }

    /**
     * Backward-compatible alias used by existing lightbox event naming.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function threadImages(): array
    {
        return $this->threadMedia;
    }

    /**
     * Convert a media URL to the proper public streaming URL.
     * Handles both old /storage/... paths and new relative paths.
     */
    public function mediaUrl(string $url): string
    {
        // If it's already an absolute HTTP URL, return as-is
        if (str_starts_with($url, 'http')) {
            return $url;
        }

        // If it's an old /storage/sms-media/... path, extract just the path after the prefix
        if (str_starts_with($url, '/storage/sms-media/')) {
            $path = substr($url, strlen('/storage/sms-media/'));
            return route('sms.media', ['filename' => $path]);
        }

        if (str_starts_with($url, '/storage/sms-attachments/')) {
            $path = substr($url, strlen('/storage/sms-attachments/'));
            return route('sms.media', ['filename' => 'sms-attachments/' . $path]);
        }

        // If it's a relative path starting with sms-media/ or sms-attachments/, use as-is
        if (str_starts_with($url, 'sms-media/') || str_starts_with($url, 'sms-attachments/')) {
            return route('sms.media', ['filename' => $url]);
        }

        // Otherwise assume it's a bare filename that goes in sms-media/
        return route('sms.media', ['filename' => 'sms-media/' . $url]);
    }

    public function loadMoreMessages(): void
    {
        $this->messageLimit += 30;
    }

    /**
     * Whether a message is substantial enough to attempt task extraction from.
     * Hides the "Create Task" action for automated schedule blasts and for very
     * short messages (1-3 words) that never contain a schedulable task.
     */
    public function messageAllowsTaskCreation(SmsMessage $message): bool
    {
        $text = trim((string) ($message->translated_display_text ?? $message->display_text));

        if ($text === '') {
            return false;
        }

        if (Str::contains($text, 'View Schedule:') || preg_match('#/s/[A-Za-z0-9]+#', $text)) {
            return false;
        }

        return str_word_count($text) > 3;
    }

    /**
     * Use AI to extract a schedulable Hive task from a single message, then open
     * the create-task modal pre-filled for review. Runs only on explicit user
     * action (the per-message 3-dot menu) — never automatically.
     */
    public function createTaskFromMessage(int $messageId, SmsTaskExtractionService $extractor): void
    {
        if ($this->isClientUser) {
            abort(403);
        }

        $message = SmsMessage::where('thread_id', $this->threadId)->find($messageId);
        $text = trim((string) $message?->display_text);

        if (! $message || $text === '') {
            Flux::toast(variant: 'warning', heading: 'No Text', text: 'This message has no text to analyze.', duration: 4000, position: 'top right');

            return;
        }

        $sentAt = $message->created_at->copy()->setTimezone(vendor_timezone());

        $isTimeRangeReply = $this->extractTimeRangeFromReply($text) !== null;
        $scheduleReplyFallback = $isTimeRangeReply
            ? $this->fallbackExtractFromScheduleReply($message, $sentAt)
            : null;

        $extracted = $extractor->extract($text, $sentAt);

        if ($scheduleReplyFallback && ($scheduleReplyFallback['has_task'] ?? false) && ($scheduleReplyFallback['title'] ?? '') !== '') {
            $extracted = $scheduleReplyFallback;
        }

        if (! $extracted || ! $extracted['has_task'] || $extracted['title'] === '') {
            $extracted = $this->fallbackExtractFromScheduleReply($message, $sentAt);
        }

        if (! $extracted || ! $extracted['has_task'] || $extracted['title'] === '') {
            Flux::toast(variant: 'warning', heading: 'No Task Found', text: 'No schedulable task was found in this message.', duration: 4000, position: 'top right');

            return;
        }

        $checklistItems = collect($extracted['checklist'] ?? [])
            ->map(fn ($item) => trim((string) $item))
            ->filter()
            ->values();

        // Only synthesize a checklist from the raw message for single-task
        // messages. When the message was split into multiple tasks, the extra
        // "sentences" are the other tasks and greetings, not checklist items.
        if ($checklistItems->isEmpty() && empty($extracted['additional_tasks'])) {
            $checklistItems = collect($this->fallbackChecklistFromMessage($text, (string) ($extracted['title'] ?? '')));
        }

        $smsImageUrls = collect(is_array($message->media_urls) ? $message->media_urls : [])
            ->filter(fn ($url) => is_string($url) && SmsMessage::isImageUrl($url))
            ->map(fn (string $url) => $this->mediaUrl($url))
            ->unique()
            ->values()
            ->all();

        $thread = SmsGroupThread::find($this->threadId);
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
            Flux::toast(variant: 'warning', heading: 'No Client', text: 'Assign a client to this conversation before creating a task.', duration: 4500, position: 'top right');

            return;
        }

        if (! $projectId) {
            Flux::toast(variant: 'warning', heading: 'No Project', text: 'This client has no project to attach the task to.', duration: 4500, position: 'top right');

            return;
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

        $this->dispatch('prefillTaskFromSms', payload: [
            'task_id' => $matchedTask?->id ?? ($extracted['task_id'] ?? null),
            'title' => $extracted['title'],
            'type' => $extracted['type'],
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
        ])->to(TaskCreate::class);
    }

    /**
     * Build simple checklist rows from multi-sentence issue reports when AI
     * does not return checklist items.
     *
     * @return array<int, string>
     */
    protected function fallbackChecklistFromMessage(string $text, string $title): array
    {
        $sentences = preg_split('/[\.!?\n]+/', $text) ?: [];
        $titleNeedle = Str::lower(trim($title));

        $items = collect($sentences)
            ->map(fn (string $sentence) => trim($sentence))
            ->filter(fn (string $sentence) => mb_strlen($sentence) >= 12)
            ->map(function (string $sentence): string {
                $sentence = preg_replace('/^\s*(also|and|plus)\b[\s,:-]*/i', '', $sentence) ?? $sentence;
                $sentence = preg_replace('/^\s*as\s+i\s+indicated\b[\s,:-]*/i', '', $sentence) ?? $sentence;

                return ucfirst(trim($sentence));
            })
            ->filter(function (string $sentence) use ($titleNeedle): bool {
                if ($titleNeedle === '') {
                    return true;
                }

                return ! Str::contains(Str::lower($sentence), $titleNeedle);
            })
            ->unique()
            ->values();

        return $items->take(5)->all();
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

    public function openAssignClientModal(): void
    {
        $thread = SmsGroupThread::find($this->threadId);
        $this->assignClientId = $thread?->client_id;
        $this->assignVendorId = $thread?->subject_vendor_id;
        $this->assignSubjectType = $thread?->subject_vendor_id ? 'vendor' : 'client';
        $this->showAssignClientModal = true;
    }

    public function assignClient(): void
    {
        if ($this->isClientUser) {
            abort(403);
        }

        $this->validate([
            'assignSubjectType' => 'required|in:client,vendor',
            'assignClientId' => 'nullable|exists:clients,id',
            'assignVendorId' => 'nullable|exists:vendors,id',
        ]);

        $thread = SmsGroupThread::findOrFail($this->threadId);

        if ($this->assignSubjectType === 'client') {
            $thread->update([
                'client_id' => $this->assignClientId ?: null,
                'subject_vendor_id' => null,
            ]);
        } else {
            $thread->update([
                'client_id' => null,
                'subject_vendor_id' => $this->assignVendorId ?: null,
            ]);
        }

        $this->showAssignClientModal = false;

        unset($this->thread);

        Flux::toast('Thread updated.');
    }

    /**
     * Mark the current thread's external participant numbers as spam.
     */
    public function markThreadAsSpam(): void
    {
        if ($this->isClientUser) {
            abort(403);
        }

        $targets = $this->threadSpamTargetPhones();

        if ($targets === []) {
            Flux::toast(variant: 'warning', heading: 'No Number', text: 'No external participant number found for this thread.', duration: 4000, position: 'top right');
            return;
        }

        foreach ($targets as $phone) {
            BlockedCaller::firstOrCreate(
                ['phone_number' => $phone],
                ['reason' => 'Manually marked as spam from messages', 'blocked_by_user_id' => auth()->id(), 'auto_blocked' => false]
            );
        }

        $count = count($targets);

        // Repaint the thread list so its spam indicator updates immediately.
        $this->dispatch('sms-spam-changed');

        Flux::toast(
            variant: 'success',
            heading: 'Marked as Spam',
            text: $count === 1
                ? ($this->resolvePhoneDisplay($targets[0]) . ' has been blocked.')
                : ($count . ' participant numbers have been blocked.'),
            duration: 5000,
            position: 'top right'
        );
    }

    /**
     * Remove the current thread's external participant numbers from spam block list.
     */
    public function unblockThreadSpam(): void
    {
        if ($this->isClientUser) {
            abort(403);
        }

        $targets = $this->threadSpamTargetPhones();

        if ($targets === []) {
            Flux::toast(variant: 'warning', heading: 'No Number', text: 'No external participant number found for this thread.', duration: 4000, position: 'top right');
            return;
        }

        $deleted = BlockedCaller::query()->whereIn('phone_number', $targets)->delete();

        if ($deleted === 0) {
            Flux::toast(variant: 'warning', heading: 'Not Blocked', text: 'Thread participant numbers are not currently blocked.', duration: 4000, position: 'top right');
            return;
        }

        // Repaint the thread list so its spam indicator updates immediately.
        $this->dispatch('sms-spam-changed');

        Flux::toast(variant: 'success', heading: 'Unblocked', text: 'Thread participant numbers were removed from blocked list.', duration: 5000, position: 'top right');
    }

    public function hasBlockedThreadSpamTargets(): bool
    {
        $targets = $this->threadSpamTargetPhones();

        if ($targets === []) {
            return false;
        }

        return BlockedCaller::query()->whereIn('phone_number', $targets)->exists();
    }

    /**
     * @return array<int, string>
     */
    protected function threadSpamTargetPhones(): array
    {
        if (! $this->thread) {
            return [];
        }

        $targets = collect()
            ->merge($this->thread->threadParticipants?->pluck('phone_number')->all() ?? [])
            ->merge(is_array($this->thread->participants) ? $this->thread->participants : [])
            ->filter(fn ($phone) => is_string($phone) && trim($phone) !== '')
            ->map(fn ($phone) => $this->normalizeDialTarget($phone))
            ->filter(fn ($phone) => is_string($phone) && $phone !== '')
            ->reject(fn ($phone) => GroupSmsService::isOurNumber($phone))
            ->unique()
            ->values();

        return $targets->all();
    }

    #[Computed]
    public function allClients()
    {
        return Client::with('users')->orderBy('created_at', 'desc')->get(['id', 'business_name']);
    }

    #[Computed]
    public function allVendors()
    {
        return Vendor::orderBy('business_name')->get();
    }

    public function deleteThread(): void
    {
        if ($this->isClientUser) {
            abort(403, 'Client users cannot delete threads.');
        }

        if (! $this->threadId) {
            return;
        }

        $thread = SmsGroupThread::findOrFail($this->threadId);

        $thread->messages()->delete();
        $thread->reads()->delete();
        $thread->threadParticipants()->delete();
        $thread->delete();

        $this->showDeleteConfirm = false;
        $this->threadId = null;

        $this->dispatch('threadDeleted')->to(SmsIndex::class);
    }

    public function updatingThreadId(): void
    {
        // Property still holds the departing thread here — mark it read on exit.
        $this->markThreadAsRead();
    }

    public function enterSelectionMode(): void
    {
        if ($this->isClientUser) {
            abort(403);
        }

        $this->selectionMode = true;
        $this->selectedMessageIds = [];
        $this->dispatch('sms-selection-started');
    }

    public function exitSelectionMode(): void
    {
        $this->selectionMode = false;
        $this->selectedMessageIds = [];
        $this->dispatch('sms-selection-cleared');
    }

    public function toggleSelectionMode(): void
    {
        if ($this->selectionMode) {
            $this->exitSelectionMode();
        } else {
            $this->enterSelectionMode();
        }
    }

    public function toggleSelectMessage(int $messageId): void
    {
        if ($this->isClientUser) {
            abort(403);
        }

        if (in_array($messageId, $this->selectedMessageIds, true)) {
            $this->selectedMessageIds = array_values(array_diff($this->selectedMessageIds, [$messageId]));
        } else {
            $this->selectedMessageIds[] = $messageId;
        }
    }

    public function openForwardModal(): void
    {
        if ($this->isClientUser) {
            abort(403);
        }

        if (empty($this->selectedMessageIds)) {
            Flux::toast(variant: 'warning', text: 'Select at least one message to forward.', duration: 4000, position: 'top right');
            return;
        }

        $this->forwardTargetThreadId = null;
        $this->forwardThreadSearch = '';
        $this->showForwardModal = true;
        Flux::modal('forward-messages')->show();
    }

    /**
     * Accept selected IDs from Alpine and open the forward modal in one
     * Livewire request to avoid intermediate reflows.
     *
     * @param  array<int, int|string>  $messageIds
     */
    public function openForwardModalWithSelection(array $messageIds): void
    {
        // Ignore stale/delayed client clicks once selection mode has ended.
        if (! $this->selectionMode) {
            return;
        }

        $this->selectedMessageIds = collect($messageIds)
            ->map(static fn ($id) => (int) $id)
            ->filter(static fn ($id) => $id > 0)
            ->unique()
            ->values()
            ->all();

        $this->openForwardModal();
    }

    /**
     * Forward a single message from its per-message menu. Mirrors the thread
     * "Forward messages" flow by entering selection mode, but pre-selects the
     * clicked message so the user can add more and forward from the same bar.
     */
    public function forwardSingleMessage(int $messageId): void
    {
        if ($this->isClientUser) {
            abort(403);
        }

        $exists = SmsMessage::where('thread_id', $this->threadId)
            ->whereKey($messageId)
            ->exists();

        if (! $exists) {
            Flux::toast(variant: 'warning', heading: 'Not Found', text: 'That message could not be found.', duration: 4000, position: 'top right');
            return;
        }

        $this->selectionMode = true;
        $this->selectedMessageIds = [$messageId];
        $this->dispatch('sms-selection-started', ids: [$messageId]);
    }

    /**
     * Threads the current user can forward messages to (excludes the current thread).
     */
    #[Computed]
    public function forwardableThreads(): \Illuminate\Support\Collection
    {
        $user = auth()->user();

        if ($this->isClientUser) {
            $clientIds = $user->clients()->pluck('clients.id');
            $query = SmsGroupThread::query()->whereIn('client_id', $clientIds);
        } else {
            $vendorId = $user->vendor?->id;
            if (! $vendorId) {
                return collect();
            }
            $query = SmsGroupThread::query()->visibleToVendor($vendorId);
        }

        return $query
            ->when($this->threadId, fn ($q) => $q->where('id', '!=', $this->threadId))
            ->when($this->forwardThreadSearch !== '', function ($q) {
                $term = '%' . trim($this->forwardThreadSearch) . '%';
                $q->where(function ($inner) use ($term) {
                    $inner->where('name', 'like', $term)
                        ->orWhereHas('client', fn ($c) => $c->where('business_name', 'like', $term))
                        ->orWhereHas('subjectVendor', fn ($v) => $v->where('business_name', 'like', $term))
                        ->orWhereHas('project', fn ($p) => $p->where('address', 'like', $term));
                });
            })
            ->with(['client:id,business_name', 'subjectVendor:id,business_name,options', 'project:id,address'])
            ->orderByDesc('last_activity_at')
            ->limit(100)
            ->get();
    }

    public function forwardThreadLabel(SmsGroupThread $thread): string
    {
        if ($thread->name) {
            return $this->decodeEntities($thread->name);
        }
        if ($thread->client) {
            $clientLabel = $thread->client->name ?: ($thread->client->business_name ?: ('Client #' . $thread->client_id));

            return $this->decodeEntities((string) $clientLabel);
        }
        if ($thread->subjectVendor) {
            $vendorLabel = $thread->subjectVendor->short_name ?: $thread->subjectVendor->business_name;

            return $this->decodeEntities((string) $vendorLabel);
        }
        if ($thread->project) {
            return $this->decodeEntities((string) $thread->project->address);
        }

        $participants = is_array($thread->participants) ? $thread->participants : [];

        return collect($participants)
            ->map(fn (string $p) => $this->resolvePhoneDisplay($p))
            ->take(3)
            ->join(', ', ' & ') ?: ('Thread #' . $thread->id);
    }

    /**
     * Repeatedly decode HTML entities until the string stops changing so that
     * double-encoded values (e.g. "&amp;amp;") collapse all the way to "&".
     */
    private function decodeEntities(string $value): string
    {
        for ($i = 0; $i < 3; $i++) {
            $decoded = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $value) {
                return $value;
            }
            $value = $decoded;
        }

        return $value;
    }

    public function forwardMessages(GroupSmsService $smsService): void
    {
        if ($this->isClientUser) {
            abort(403, 'Client users cannot forward messages.');
        }

        $this->validate([
            'forwardTargetThreadId' => 'required|integer|exists:sms_group_threads,id',
            'selectedMessageIds' => 'required|array|min:1',
            'selectedMessageIds.*' => 'integer',
        ]);

        if ((int) $this->forwardTargetThreadId === (int) $this->threadId) {
            $this->addError('forwardTargetThreadId', 'Pick a different conversation than the current one.');
            return;
        }

        $allowedThreadIds = $this->forwardableThreads->pluck('id')->all();
        if (! in_array((int) $this->forwardTargetThreadId, $allowedThreadIds, true)) {
            abort(403, 'You do not have access to the selected conversation.');
        }

        $targetThread = SmsGroupThread::findOrFail($this->forwardTargetThreadId);

        if ($targetThread->hasPendingOptIn()) {
            Flux::toast(
                variant: 'warning',
                heading: 'Awaiting START Replies',
                text: 'The target conversation has participants who have not replied START yet.',
                duration: 5000,
                position: 'top right'
            );
            return;
        }

        $messages = SmsMessage::where('thread_id', $this->threadId)
            ->whereIn('id', $this->selectedMessageIds)
            ->where('status', '!=', 'scheduled')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($messages->isEmpty()) {
            $this->addError('selectedMessageIds', 'The selected messages could not be found.');
            return;
        }

        $forwardedCount = $messages->count();
        $forwardedSections = [];
        $forwardedMediaUrls = [];

        foreach ($messages as $message) {
            $body = trim((string) $message->display_text);
            if ($body !== '') {
                $forwardedSections[] = $body;
            }

            $mediaUrls = collect(is_array($message->media_urls) ? $message->media_urls : [])
                ->merge(collect(data_get($message->raw_payload, 'media', []))->pluck('url')->all())
                ->filter(static fn ($url) => is_string($url) && trim($url) !== '')
                ->map(static fn (string $url) => trim($url))
                ->values()
                ->all();

            if (! empty($mediaUrls)) {
                $forwardedMediaUrls = array_merge($forwardedMediaUrls, $mediaUrls);
            }
        }

        $forwardedMediaUrls = array_values(array_unique($forwardedMediaUrls));
        $forwardedBody = implode("\n\n", $forwardedSections);
        $forwardedText = $forwardedBody === ''
            ? 'Forwarded'
            : "Forwarded\n\n{$forwardedBody}";

        $smsService->sendToThread($targetThread, $forwardedText, $forwardedMediaUrls, auth()->id());

        $this->showForwardModal = false;
        $this->forwardTargetThreadId = null;
        $this->forwardThreadSearch = '';

        // Close the Flux modal and clear selection state via the single source
        // of truth so the message checkboxes/toolbar visually reset.
        Flux::modal('forward-messages')->close();
        $this->exitSelectionMode();

        Flux::toast(
            variant: 'success',
            heading: 'Forwarded',
            text: $forwardedCount === 1
                ? '1 message forwarded.'
                : "{$forwardedCount} messages forwarded.",
            duration: 4000,
            position: 'top right'
        );
    }

    public function sendMessage(GroupSmsService $smsService, SmsTranslationService $translator): void
    {
        if ($this->isClientUser) {
            abort(403, 'Client users cannot send messages.');
        }

        if ($this->editScheduledId) {
            $this->saveEditedScheduledMessage($translator);

            return;
        }

        $this->validate([
            'newMessage' => 'required_without:attachment|nullable|string|max:1600',
            'attachment' => self::attachmentValidationRule(),
            'threadId' => 'required|exists:sms_group_threads,id',
        ]);

        $thread = SmsGroupThread::findOrFail($this->threadId);

        if ($thread->hasPendingOptIn()) {
            Flux::toast(
                variant: 'warning',
                heading: 'Awaiting START Replies',
                text: 'Each recipient must reply START before sending project messages.',
                duration: 5000,
                position: 'top right'
            );

            return;
        }

        $text = trim($this->newMessage);

        $senderLanguage = $this->preferredLanguageForUser(auth()->user());
        $recipientLanguage = $this->threadRecipientLanguage($thread);
        $outboundText = $text;

        if ($text !== '' && strcasecmp($senderLanguage, $recipientLanguage) !== 0) {
            $outboundText = $translator->translate($text, $recipientLanguage, $senderLanguage);
        }

        $messageWithSig = $outboundText !== ''
            ? $outboundText . "\n" . SmsNewThread::getSignature()
            : SmsNewThread::getSignature();

        $mediaUrls = [];
        if ($this->attachment) {
            $path = $this->attachment->store('sms-attachments', 'public');
            // Store a relative path so images display correctly regardless of domain.
            // The SendGroupMms job converts to an absolute URL when calling Telnyx.
            $mediaUrls[] = '/storage/' . $path;
        }

        $rawPayload = [
            'original_text' => $text,
            'sender_language' => $senderLanguage,
            'recipient_language' => $recipientLanguage,
        ];

        $smsService->sendToThread($thread, $messageWithSig, $mediaUrls, auth()->id(), null, $rawPayload);

        $this->newMessage = '';
        $this->attachment = null;

        // Replying counts as reading — clear the unread state immediately.
        $this->markThreadAsRead();

        // Clear memoized computed properties so the re-render fetches fresh data
        $this->refreshMessages();

        $this->js("(function(){ localStorage.removeItem('sms-draft-' + {$this->threadId}); const ta = \$wire.\$el.querySelector('ui-composer textarea'); if (ta) { ta.value = ''; ta.dispatchEvent(new Event('input', { bubbles: true })); } })()");
        $this->dispatch('messageSent');
    }

    public function scheduleMessage(string $preset, GroupSmsService $smsService, SmsTranslationService $translator): void
    {
        if ($this->isClientUser) {
            abort(403, 'Client users cannot send messages.');
        }

        $this->validate([
            'newMessage' => 'required_without:attachment|nullable|string|max:1600',
            'attachment' => self::attachmentValidationRule(),
            'threadId' => 'required|exists:sms_group_threads,id',
        ]);

        $thread = SmsGroupThread::findOrFail($this->threadId);

        $now = now('America/Chicago');
        $scheduleOnly = $preset === 'schedule_only';
        $scheduledAt = match ($preset) {
            '1hr' => $now->copy()->addHour()->utc(),
            '2hr' => $now->copy()->addHours(2)->utc(),
            'tomorrow_8am' => $now->copy()->addDay()->setTime(8, 0)->utc(),
            'tomorrow_12pm' => $now->copy()->addDay()->setTime(12, 0)->utc(),
            default => null,
        };

        if (! $scheduleOnly && ! $scheduledAt) {
            return;
        }

        $text = trim($this->newMessage);

        $senderLanguage = $this->preferredLanguageForUser(auth()->user());
        $recipientLanguage = $this->threadRecipientLanguage($thread);
        $outboundText = $text;

        if ($text !== '' && strcasecmp($senderLanguage, $recipientLanguage) !== 0) {
            $outboundText = $translator->translate($text, $recipientLanguage, $senderLanguage);
        }

        $messageWithSig = $outboundText !== ''
            ? $outboundText . "\n" . SmsNewThread::getSignature()
            : SmsNewThread::getSignature();

        $mediaUrls = [];
        if ($this->attachment) {
            $path = $this->attachment->store('sms-attachments', 'public');
            $mediaUrls[] = '/storage/' . $path;
        }

        $rawPayload = [
            'original_text' => $text,
            'sender_language' => $senderLanguage,
            'recipient_language' => $recipientLanguage,
            'source' => 'conversation_schedule_menu',
            'schedule_only' => $scheduleOnly,
        ];

        $smsService->sendToThread(
            $thread,
            $messageWithSig,
            $mediaUrls,
            auth()->id(),
            $scheduledAt,
            $rawPayload,
            $scheduleOnly,
        );

        $this->newMessage = '';
        $this->attachment = null;

        unset($this->smsMessages, $this->processedMessages, $this->phoneNameMap);

        if ($scheduleOnly) {
            Flux::toast(
                variant: 'success',
                heading: 'Draft Scheduled',
                text: 'Saved as Schedule Only. It will send only when manually sent.',
                duration: 4000,
                position: 'top right'
            );
        } else {
            Flux::toast(
                variant: 'success',
                heading: 'Message Scheduled',
                text: 'Will send ' . $scheduledAt->timezone('America/Chicago')->format('M j, g:i A'),
                duration: 4000,
                position: 'top right'
            );
        }

        $this->js("(function(){ localStorage.removeItem('sms-draft-' + {$this->threadId}); const ta = \$wire.\$el.querySelector('ui-composer textarea'); if (ta) { ta.value = ''; ta.dispatchEvent(new Event('input', { bubbles: true })); } })()");
        $this->dispatch('messageSent');
        $this->dispatch('sms-schedule-changed');
    }

    public function cancelScheduledMessage(): void
    {
        if ($this->isClientUser) {
            abort(403);
        }

        $message = SmsMessage::where('id', $this->cancelScheduledId)
            ->where('thread_id', $this->threadId)
            ->where('status', 'scheduled')
            ->firstOrFail();

        $message->delete();

        $this->cancelScheduledId = null;
        $this->showCancelModal = false;

        unset($this->smsMessages, $this->processedMessages, $this->phoneNameMap);

        Flux::toast(
            text: 'Scheduled message cancelled.',
            variant: 'success',
            duration: 3000,
            position: 'top right'
        );

        $this->dispatch('sms-schedule-changed');
    }

    public function sendScheduledNow(int $messageId): void
    {
        if ($this->isClientUser) {
            abort(403);
        }

        $message = SmsMessage::where('id', $messageId)
            ->where('thread_id', $this->threadId)
            ->where('status', 'scheduled')
            ->firstOrFail();

        $rawPayload = is_array($message->raw_payload) ? $message->raw_payload : [];

        $taskIds = collect((array) ($rawPayload['scheduled_task_ids'] ?? []))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->values()
            ->all();

        if ($taskIds !== [] && $message->thread?->subject_vendor_id) {
            Task::whereIn('id', $taskIds)
                ->where('vendor_id', $message->thread->subject_vendor_id)
                ->whereNull('vendor_status')
                ->update([
                    'vendor_status' => Task::VENDOR_STATUS_REQUESTED,
                ]);
        }

        $updates = ['status' => 'sending', 'scheduled_at' => null];

        $currentUserId = auth()->id();
        $newSignature = SmsNewThread::getSignature($currentUserId);
        $existingSignature = $this->extractSignatureLine((string) ($message->text ?? ''));

        if ($existingSignature !== null && $existingSignature !== $newSignature) {
            $body = rtrim(preg_replace('/\n?-(?:PS|GS|GSC)$/', '', rtrim((string) $message->text)));
            $updates['text'] = $body . "\n" . $newSignature;
        }

        $updates['sent_by_user_id'] = $currentUserId;

        $message->update($updates);

        \App\Jobs\SendGroupMms::dispatch($message->id);

        $message->thread?->update(['last_activity_at' => now()]);

        try {
            \App\Events\SmsMessageReceived::dispatch($message->thread_id);
        } catch (\Throwable $e) {
            Log::warning('SMS broadcast failed', ['message_id' => $message->id, 'error' => $e->getMessage()]);
        }

        if ($message->sent_by_user_id) {
            \App\Jobs\SendOutboundSmsBrowserNotifications::dispatch($message->id, $message->sent_by_user_id);
        }

        unset($this->smsMessages, $this->processedMessages, $this->phoneNameMap);

        Flux::toast(
            text: 'Message sent',
            variant: 'success',
            duration: 3000,
            position: 'top right'
        );

        $this->dispatch('sms-schedule-changed');
    }

    public function openEditScheduledMessage(int $messageId): void
    {
        if ($this->isClientUser) {
            abort(403);
        }

        $message = SmsMessage::where('id', $messageId)
            ->where('thread_id', $this->threadId)
            ->where('status', 'scheduled')
            ->firstOrFail();

        $this->editScheduledId = $message->id;
        $this->newMessage = trim((string) $message->display_text);
        $this->attachment = null;
        $this->js("(function(){ const ta = \$wire.\$el.querySelector('ui-composer textarea'); if (ta) { ta.focus(); ta.setSelectionRange(ta.value.length, ta.value.length); } })()");
    }

    public function saveEditedScheduledMessage(SmsTranslationService $translator): void
    {
        if ($this->isClientUser) {
            abort(403);
        }

        $this->validate([
            'newMessage' => 'required|string|max:1600',
        ]);

        $message = SmsMessage::where('id', $this->editScheduledId)
            ->where('thread_id', $this->threadId)
            ->where('status', 'scheduled')
            ->firstOrFail();

        $newOriginalText = trim($this->newMessage);
        $rawPayload = is_array($message->raw_payload) ? $message->raw_payload : [];

        $senderLanguage = $this->normalizeLanguage((string) ($rawPayload['sender_language'] ?? $this->preferredLanguageForUser(auth()->user())));
        $recipientLanguage = $this->normalizeLanguage((string) ($rawPayload['recipient_language'] ?? $this->threadRecipientLanguage($message->thread)));

        $outboundText = $newOriginalText;
        if ($newOriginalText !== '' && strcasecmp($senderLanguage, $recipientLanguage) !== 0) {
            $outboundText = $translator->translate($newOriginalText, $recipientLanguage, $senderLanguage);
        }

        $signatureLine = $this->extractSignatureLine((string) ($message->text ?? ''));
        if ($signatureLine !== null) {
            $outboundText .= "\n{$signatureLine}";
        }

        $rawPayload['original_text'] = $newOriginalText;
        $rawPayload['sender_language'] = $senderLanguage;
        $rawPayload['recipient_language'] = $recipientLanguage;

        $message->update([
            'text' => $outboundText,
            'raw_payload' => $rawPayload,
        ]);

        $this->editScheduledId = null;
        $this->newMessage = '';
        $this->attachment = null;

        $this->refreshMessages();

        Flux::toast(
            text: 'Scheduled message updated.',
            variant: 'success',
            duration: 3000,
            position: 'top right'
        );

        $this->dispatch('sms-schedule-changed');
    }

    public function cancelScheduledEdit(): void
    {
        $this->editScheduledId = null;
        $this->newMessage = '';
        $this->attachment = null;
    }

    protected function extractSignatureLine(string $text): ?string
    {
        if (preg_match('/(?:^|\n)(-(?:PS|GS|GSC))$/', trim($text), $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    public function resendOptInPrompt(GroupSmsService $smsService): void
    {
        if ($this->isClientUser) {
            abort(403, 'Client users cannot send messages.');
        }

        if (! $this->threadId) {
            Flux::toast(variant: 'danger', heading: 'No Thread', text: 'No conversation selected.', duration: 4000, position: 'top right');
            return;
        }

        $thread = SmsGroupThread::findOrFail($this->threadId);

        if (! $thread->hasPendingOptIn()) {
            Flux::toast(variant: 'info', heading: 'Already Opted In', text: 'All recipients have already replied START.', duration: 4000, position: 'top right');
            return;
        }

        $smsService->resendConsentPrompt($thread);

        Flux::toast(variant: 'success', heading: 'Prompt Sent', text: 'START opt-in message was resent to this thread.', duration: 4500, position: 'top right');

        $this->dispatch('messageSent');
    }

    public function openOptInModal(): void
    {
        $this->showOptInModal = true;
        $this->manualOptInReason = '';
        $this->manualOptInParticipantId = null;
    }

    public function manualOptIn(GroupSmsService $smsService): void
    {
        if ($this->isClientUser) {
            abort(403, 'Client users cannot send messages.');
        }

        $this->validate([
            'manualOptInParticipantId' => 'required|exists:sms_thread_participants,id',
            'manualOptInReason' => 'required|string|max:500',
        ], [
            'manualOptInParticipantId.required' => 'Please select a participant.',
            'manualOptInReason.required' => 'Please provide a reason for the manual opt-in.',
        ]);

        $participant = SmsThreadParticipant::findOrFail($this->manualOptInParticipantId);

        if ((int) $participant->thread_id !== $this->threadId) {
            abort(403);
        }

        $participant->update([
            'opted_in_at' => now(),
            'manual_opt_in_reason' => $this->manualOptInReason,
            'manual_opt_in_by' => auth()->id(),
        ]);

        $thread = SmsGroupThread::findOrFail($this->threadId);

        // If all participants are now opted in, send the welcome message
        $smsService->markParticipantOptedInAndSendWelcomeIfReady(
            $thread,
            $participant->phone_number,
            auth()->id(),
        );

        $name = $this->resolvePhoneDisplay($participant->phone_number);

        Flux::toast(variant: 'success', heading: 'Opted In', text: "{$name} has been manually opted in.", duration: 5000, position: 'top right');

        $this->manualOptInReason = '';
        $this->manualOptInParticipantId = null;
        $this->showOptInModal = false;

        $this->dispatch('messageSent');
    }

    /**
     * Participants pending opt-in for the current thread.
     *
     * @return \Illuminate\Support\Collection<int, SmsThreadParticipant>
     */
    #[Computed]
    public function pendingParticipants(): \Illuminate\Support\Collection
    {
        if (! $this->threadId) {
            return collect();
        }

        return SmsThreadParticipant::where('thread_id', $this->threadId)
            ->whereNull('opted_in_at')
            ->get();
    }

    #[Computed]
    public function thread(): ?SmsGroupThread
    {
        if (! $this->threadId) {
            return null;
        }

        $thread = SmsGroupThread::with([
            'project:id,address',
            'client',
            'client.users:id,first_name,last_name,nickname,preferred_language,cell_phone',
            'ownerVendor:id,business_name,options',
            'subjectVendor:id,business_name,options',
            'subjectVendor.users:id,first_name,last_name,nickname,preferred_language,cell_phone',
            'threadParticipants:id,thread_id,phone_number',
        ])->find($this->threadId);

        if (! $thread) {
            return null;
        }

        if (! $thread->client && $thread->client_id) {
            $client = Client::withoutGlobalScopes()
                ->with('users:id,first_name,last_name,nickname,preferred_language,cell_phone')
                ->find($thread->client_id);

            if ($client) {
                $thread->setRelation('client', $client);
            }
        }

        return $thread;
    }

    public function threadClientUsersFor(?Client $client): \Illuminate\Support\Collection
    {
        if (! $client || ! $this->thread) {
            return collect();
        }

        $participantPhones = $this->thread->threadParticipants
            ->pluck('phone_number')
            ->filter();

        $users = $client->relationLoaded('users')
            ? $client->users
            : $client->users()->get(['users.id', 'first_name', 'last_name', 'cell_phone']);

        return $this->filterClientUsersToThreadParticipants($users, $participantPhones);
    }

    public function filterClientUsersToThreadParticipants(iterable $users, iterable $participantPhones): \Illuminate\Support\Collection
    {
        $participantPhoneMap = collect($participantPhones)
            ->filter()
            ->flip();

        return collect($users)
            ->filter(function (User $user) use ($participantPhoneMap): bool {
                $e164 = $user->routeNotificationForTelnyx();

                return is_string($e164) && $participantPhoneMap->has($e164);
            })
            ->values();
    }

    /**
     * Initiate a click-to-call: dials the logged-in user first, then bridges to the target.
     */
    public function initiateCall(string $targetPhone): void
    {
        $this->initiateCallWithTargets([$targetPhone]);
    }

    /**
     * Initiate click-to-call to all non-company participants in the current thread.
     */
    public function initiateCallAll(): void
    {
        if (! $this->thread) {
            Flux::toast(variant: 'danger', heading: 'No Thread', text: 'No conversation selected.', duration: 5000, position: 'top right');
            return;
        }

        $targets = $this->thread->threadParticipants
            ->pluck('phone_number')
            ->filter(fn ($phone) => is_string($phone) && $phone !== '')
            ->values()
            ->all();

        $this->initiateCallWithTargets($targets);
    }

    /**
     * Start a click-to-call session for one or more targets.
     *
     * @param array<int, string> $targetPhones
     */
    private function initiateCallWithTargets(array $targetPhones): void
    {
        $user = auth()->user();
        $userPhone = $user->routeNotificationForTelnyx();

        if (! $userPhone) {
            Flux::toast(variant: 'danger', heading: 'No Phone', text: 'You don\'t have a cell phone on file.', duration: 5000, position: 'top right');
            return;
        }

        $apiKey = config('services.telnyx.api_key');
        $connectionId = config('services.telnyx.connection_id');
        $from = config('services.telnyx.from');

        $normalizedTargets = collect($targetPhones)
            ->map(fn ($phone) => $this->normalizeDialTarget($phone))
            ->filter(fn ($phone) => is_string($phone) && $phone !== '')
            ->reject(fn ($phone) => GroupSmsService::isOurNumber($phone))
            ->unique()
            ->values();

        if ($normalizedTargets->isEmpty()) {
            Flux::toast(variant: 'danger', heading: 'No Valid Numbers', text: 'No callable recipients found in this thread.', duration: 5000, position: 'top right');
            return;
        }

        $primaryTarget = $normalizedTargets->first();
        $targetCount = $normalizedTargets->count();

        if (! $apiKey || ! $connectionId) {
            Flux::toast(variant: 'danger', heading: 'Not Configured', text: 'Voice calling is not configured.', duration: 5000, position: 'top right');
            return;
        }

        // Create a call log for this outbound call
        $callLog = CallLog::create([
            'direction' => 'outgoing',
            'from_number' => $from,
            'to_number' => $primaryTarget,
            'status' => CallLog::STATUS_INITIATED,
            'user_id' => $user->id,
            'metadata' => [
                'type' => $targetCount > 1 ? 'click_to_call_multi' : 'click_to_call',
                'target_phone' => $primaryTarget,
                'target_phones' => $normalizedTargets->all(),
                'target_count' => $targetCount,
                'user_phone' => $userPhone,
            ],
        ]);

        try {
            // Step 1: Call the logged-in user's cell phone first
            $response = Http::withToken($apiKey)
                ->post('https://api.telnyx.com/v2/calls', [
                    'connection_id' => $connectionId,
                    'to' => $userPhone,
                    'from' => $from,
                    'from_display_name' => 'GS Construction',
                    'timeout_secs' => (int) config('services.telnyx.voice_timeout', 30),
                    'preferred_codecs' => config('services.telnyx.preferred_codecs'),
                    'client_state' => base64_encode(json_encode([
                        'action' => 'click_to_call',
                        'target_phone' => $primaryTarget,
                        'target_phones' => $normalizedTargets->all(),
                        'call_log_id' => $callLog->id,
                    ])),
                    'webhook_url' => $this->telnyxWebhookUrl(),
                ]);

            if ($response->successful()) {
                $data = $response->json('data');
                $callLog->update([
                    'call_control_id' => $data['call_control_id'] ?? null,
                    'call_session_id' => $data['call_session_id'] ?? null,
                    'call_leg_id' => $data['call_leg_id'] ?? null,
                ]);

                Log::channel('telnyx')->info('Click-to-call initiated', [
                    'call_log_id' => $callLog->id,
                    'user_phone' => $userPhone,
                    'target_phone' => $primaryTarget,
                    'target_count' => $targetCount,
                    'call_control_id' => $data['call_control_id'] ?? null,
                ]);

                Flux::toast(
                    variant: 'success',
                    heading: 'Calling',
                    text: $targetCount > 1
                        ? 'Your phone will ring shortly, then all recipients will be called.'
                        : 'Your phone will ring shortly...',
                    duration: 8000,
                    position: 'top right'
                );
            } else {
                $callLog->update(['status' => CallLog::STATUS_FAILED]);
                Log::channel('telnyx')->error('Click-to-call API failed', [
                    'status' => $response->status(),
                    'error' => $response->json(),
                ]);
                Flux::toast(variant: 'danger', heading: 'Call Failed', text: 'Could not initiate the call.', duration: 5000, position: 'top right');
            }
        } catch (\Exception $e) {
            $callLog->update(['status' => CallLog::STATUS_FAILED]);
            Log::channel('telnyx')->error('Click-to-call exception', ['error' => $e->getMessage()]);
            Flux::toast(variant: 'danger', heading: 'Call Failed', text: 'Could not initiate the call.', duration: 5000, position: 'top right');
        }
    }

    private function normalizeDialTarget(?string $phone): ?string
    {
        if (! is_string($phone) || trim($phone) === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone);
        if (! is_string($digits) || $digits === '') {
            return null;
        }

        if (strlen($digits) === 10) {
            return '+1' . $digits;
        }

        if (strlen($digits) === 11 && str_starts_with($digits, '1')) {
            return '+' . $digits;
        }

        return str_starts_with($phone, '+') ? $phone : ('+' . $digits);
    }

    #[Computed]
    public function smsMessages()
    {
        if (! $this->threadId) {
            return collect();
        }

        return SmsMessage::where('thread_id', $this->threadId)
            ->select(['id', 'thread_id', 'direction', 'from_number', 'to_numbers', 'text', 'media_urls', 'raw_payload', 'status', 'scheduled_at', 'created_at', 'sent_by_user_id'])
            ->with('sentByUser:id,first_name,last_name,nickname')
            ->orderByDesc('created_at')
            ->limit($this->messageLimit)
            ->get()
            ->reverse()
            ->values();
    }

    /**
     * Build phone number → display name lookup for all inbound senders.
     * Merges client user first names with resolvePhoneDisplay fallback.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function phoneNameMap(): array
    {
        $map = $this->smsMessages
            ->where('direction', 'inbound')
            ->pluck('from_number')
            ->unique()
            ->filter()
            ->mapWithKeys(fn (string $number) => [$number => $this->resolvePhoneDisplay($number)])
            ->all();

        // Client user first names take precedence
        if ($this->thread?->client) {
            foreach ($this->threadClientUsersFor($this->thread->client) as $user) {
                $telnyx = $user->routeNotificationForTelnyx();
                if ($telnyx) {
                    $map[$telnyx] = $this->preferredUserDisplayName($user, false);
                }
            }
            $rawHome = $this->thread->client->getRawOriginal('home_phone');
            if ($rawHome) {
                $formatted = \App\Services\GroupSmsService::formatE164($rawHome);
                if (! isset($map[$formatted])) {
                    $map[$formatted] = $this->thread->client->name;
                }
            }
        }

        return $map;
    }

    /**
     * Whether the loaded messages involve both phone numbers (4439 and 4200).
     * When true, we show a small badge on each message indicating which number was used.
     */
    #[Computed]
    public function threadHasMixedNumbers(): bool
    {
        $numbers = config('services.telnyx.numbers', []);

        if (count($numbers) < 2) {
            return false;
        }

        $found = $this->smsMessages
            ->map(fn (SmsMessage $msg) => $msg->isOutbound()
                ? $msg->from_number
                : collect($msg->to_numbers)->first(fn ($n) => in_array($n, $numbers))
            )
            ->filter()
            ->unique()
            ->values();

        return $found->count() > 1;
    }

    /**
     * Parse tapback reactions and build:
     * - visibleMessages: messages with tapbacks filtered out
     * - reactionsMap: message ID → [emoji => [sender_name, ...]]
     *
     * @return array{visible: \Illuminate\Support\Collection, reactions: array}
     */
    #[Computed]
    public function processedMessages(): array
    {
        $allMessages = $this->smsMessages;
        $phoneNameMap = $this->phoneNameMap;
        $tapbackIds = collect();
        $reactionsMap = [];

        // Remote edits (iMessage "Edited to …" SMS fallback): apply the new text
        // to the original message and hide the notification, so the thread reads
        // like iMessage — one bubble with an "Edited" marker. Runs before the
        // tapback pass so reactions match against the edited text.
        $editIds = collect();

        foreach ($allMessages as $msg) {
            $editedText = $msg->parseRemoteEdit();
            if ($editedText === null) {
                continue;
            }

            $editedNormalized = $this->normalizeTapbackMatchText($editedText);
            if ($editedNormalized === '') {
                continue;
            }

            // Original candidates: same sender & direction, sent before the edit,
            // not itself an edit notification. Pick the most similar text.
            $matched = $allMessages
                ->filter(fn ($candidate) => $candidate->id !== $msg->id
                    && ! $editIds->contains($candidate->id)
                    && $candidate->direction === $msg->direction
                    && $candidate->from_number === $msg->from_number
                    && ($candidate->created_at === null || $msg->created_at === null || $candidate->created_at->lte($msg->created_at))
                    && trim((string) $candidate->display_text) !== ''
                    && $candidate->parseRemoteEdit() === null)
                ->map(function ($candidate) use ($editedNormalized) {
                    similar_text($this->normalizeTapbackMatchText((string) $candidate->display_text), $editedNormalized, $percent);

                    return ['message' => $candidate, 'percent' => $percent];
                })
                ->sortByDesc('percent')
                ->first();

            // Only merge when we're confident which message was edited —
            // otherwise leave the notification visible rather than lose it.
            if (! $matched || $matched['percent'] < 40) {
                continue;
            }

            $editIds->push($msg->id);

            $original = $matched['message'];
            // In-memory only: display_text derives from text, so downstream
            // rendering/translation picks up the edited body. Never persisted.
            $original->text = $editedText;
            $original->was_edited = true;
        }

        $allMessages = $allMessages->reject(fn ($m) => $editIds->contains($m->id))->values();

        foreach ($allMessages as $msg) {
            $tapback = $msg->parseTapback();
            if (! $tapback || ! $tapback['emoji']) {
                continue;
            }

            $quotedNormalized = $this->normalizeTapbackMatchText((string) ($tapback['quoted'] ?? ''));
            $quotedLen = mb_strlen($quotedNormalized);
            $matched = $allMessages
                ->filter(function ($candidate) use ($quotedNormalized, $msg) {
                    if ($candidate->id === $msg->id) {
                        return false;
                    }
                    $candidateText = $candidate->display_text;
                    if (! $candidateText) {
                        return false;
                    }
                    $candidateNormalized = $this->normalizeTapbackMatchText((string) $candidateText);

                    if ($candidateNormalized === '' || $quotedNormalized === '') {
                        return false;
                    }

                    return str_contains($candidateNormalized, $quotedNormalized)
                        || str_contains($quotedNormalized, $candidateNormalized);
                })
                ->sortBy(fn ($c) => abs(mb_strlen($this->normalizeTapbackMatchText((string) $c->display_text)) - $quotedLen))
                ->first();

            // Generic (strict) tapbacks are only processed when the quoted text
            // actually matches a message in the thread — avoids hiding normal messages.
            if (($tapback['strict'] ?? false) && ! $matched) {
                continue;
            }

            $tapbackIds->push($msg->id);

            if ($matched) {
                $senderName = $phoneNameMap[$msg->from_number] ?? substr($msg->from_number, -4);
                $reactionsMap[$matched->id][$tapback['emoji']][] = $senderName;
            }
        }

        $withoutTapbacks = $allMessages->reject(fn ($m) => $tapbackIds->contains($m->id));

        $viewerLanguage = $this->preferredLanguageForUser(auth()->user());
        $translator = app(SmsTranslationService::class);
        $withoutTapbacks->each(function (SmsMessage $message) use ($viewerLanguage, $translator): void {
            $message->translated_display_text = $this->messageDisplayTextForViewer($message, $viewerLanguage, $translator);
            $message->original_display_text = $this->messageOriginalTextForViewer($message);

            $languageMeta = $this->messageLanguageMetaForViewer(
                $message,
                $viewerLanguage,
                (string) ($message->translated_display_text ?? ''),
                (string) ($message->original_display_text ?? '')
            );

            $message->language_badge = $languageMeta['badge'];
            $message->show_original_toggle = $languageMeta['show_original_toggle'];
        });

        return [
            'visible' => $withoutTapbacks->where('status', '!=', 'scheduled')->values(),
            // Scheduled messages render in a flex-col-reverse container, so the
            // first item in DOM is visually at the bottom. Sort descending by
            // send time (and creation time as a tie-breaker) so the earliest
            // scheduled message stays on top and later ones stack below it.
            'scheduled' => $withoutTapbacks
                ->where('status', 'scheduled')
                ->sortByDesc(fn ($m) => [
                    optional($m->scheduled_at)->getTimestamp() ?? 0,
                    $m->created_at?->getTimestamp() ?? 0,
                ])
                ->values(),
            'reactions' => $reactionsMap,
        ];
    }

    protected function messageDisplayTextForViewer(SmsMessage $message, string $viewerLanguage, SmsTranslationService $translator): ?string
    {
        $displayText = trim((string) $message->display_text);
        if ($displayText === '') {
            return null;
        }

        if ($this->shouldBypassViewerTranslation($message)) {
            return $displayText;
        }

        $rawPayload = is_array($message->raw_payload) ? $message->raw_payload : [];
        $senderLanguage = $this->normalizeLanguage((string) ($rawPayload['sender_language'] ?? ''));
        $originalText = trim((string) ($rawPayload['original_text'] ?? ''));

        if ($originalText !== '' && $this->looksLikeTranslationPromptArtifact($displayText)) {
            if ($senderLanguage !== '' && strcasecmp($senderLanguage, $viewerLanguage) === 0) {
                return $originalText;
            }

            return $translator->translate($originalText, $viewerLanguage, $senderLanguage !== '' ? $senderLanguage : null);
        }

        if (
            $message->isOutbound()
            && (int) ($message->sent_by_user_id ?? 0) === (int) auth()->id()
            && $originalText !== ''
            && $senderLanguage !== ''
            && strcasecmp($senderLanguage, $viewerLanguage) === 0
        ) {
            return $originalText;
        }

        if ($viewerLanguage === 'English') {
            if ($this->viewerAlreadySpeaksMessageLanguage($message, 'English', $displayText)) {
                return $displayText;
            }

            return $translator->translate($displayText, 'English');
        }

        if ($senderLanguage !== '' && strcasecmp($senderLanguage, $viewerLanguage) === 0 && $originalText !== '') {
            return $originalText;
        }

        if ($this->viewerAlreadySpeaksMessageLanguage($message, $viewerLanguage, $displayText)) {
            return $displayText;
        }

        return $translator->translate($displayText, $viewerLanguage);
    }

    /**
     * Whether a message is already in the viewer's language, so it can be
     * shown verbatim without a (slow) translation round-trip.
     *
     * When the source language can't be determined we assume it already
     * matches the viewer to avoid translating same-language text on every
     * render — the dominant case for English office users.
     */
    protected function viewerAlreadySpeaksMessageLanguage(SmsMessage $message, string $viewerLanguage, string $candidateText): bool
    {
        $sourceLanguage = $this->messageSourceLanguage($message, $candidateText);

        if ($sourceLanguage === null) {
            return true;
        }

        return strcasecmp($sourceLanguage, $viewerLanguage) === 0;
    }

    protected function messageOriginalTextForViewer(SmsMessage $message): ?string
    {
        if ($this->shouldBypassViewerTranslation($message)) {
            $displayText = trim((string) $message->display_text);

            return $displayText !== '' ? $displayText : null;
        }

        $rawPayload = is_array($message->raw_payload) ? $message->raw_payload : [];
        $originalText = trim((string) ($rawPayload['original_text'] ?? ''));

        if ($originalText !== '') {
            return $originalText;
        }

        $displayText = trim((string) $message->display_text);

        return $displayText !== '' ? $displayText : null;
    }

    /**
     * @return array{badge: ?string, show_original_toggle: bool}
     */
    protected function messageLanguageMetaForViewer(
        SmsMessage $message,
        string $viewerLanguage,
        string $translatedText,
        string $originalText,
    ): array {
        if ($this->shouldBypassViewerTranslation($message)) {
            return ['badge' => null, 'show_original_toggle' => false];
        }

        $sourceLanguage = $this->messageSourceLanguage($message, $originalText);
        $badge = $this->languageBadgeForLanguage($sourceLanguage);

        if ($badge === null || $sourceLanguage === null) {
            return ['badge' => null, 'show_original_toggle' => false];
        }

        if (strcasecmp($sourceLanguage, $viewerLanguage) === 0) {
            return ['badge' => null, 'show_original_toggle' => false];
        }

        $showToggle = trim($translatedText) !== ''
            && trim($originalText) !== ''
            && strcmp(trim($translatedText), trim($originalText)) !== 0;

        return [
            'badge' => $badge,
            'show_original_toggle' => $showToggle,
        ];
    }

    protected function shouldBypassViewerTranslation(SmsMessage $message): bool
    {
        $rawPayload = is_array($message->raw_payload) ? $message->raw_payload : [];

        return (string) ($rawPayload['source'] ?? '') === 'send_schedule_modal';
    }

    protected function messageSourceLanguage(SmsMessage $message, string $originalText): ?string
    {
        $rawPayload = is_array($message->raw_payload) ? $message->raw_payload : [];
        $senderLanguage = trim((string) ($rawPayload['sender_language'] ?? ''));

        $inferredLanguage = $this->inferSupportedLanguageFromText($originalText);
        if ($inferredLanguage !== null) {
            return $inferredLanguage;
        }

        if ($senderLanguage !== '') {
            return $this->normalizeLanguage($senderLanguage);
        }

        return null;
    }

    protected function languageBadgeForLanguage(?string $language): ?string
    {
        if ($language === null || $language === '') {
            return null;
        }

        return match ($this->normalizeLanguage($language)) {
            'English' => 'EN',
            'Spanish' => 'ES',
            'Polish' => 'PL',
            default => null,
        };
    }

    protected function inferSupportedLanguageFromText(string $text): ?string
    {
        $normalized = mb_strtolower(trim($text));
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/[ąćęłńóśźż]/u', $normalized) === 1) {
            return 'Polish';
        }

        if (preg_match('/[áéíóúñ¿¡]/u', $normalized) === 1) {
            return 'Spanish';
        }

        $polishHints = ['dziekuje', 'prosze', 'czesc', 'jutro', 'witam', 'tak', 'nie'];
        foreach ($polishHints as $hint) {
            if ($this->containsHintWord($normalized, $hint)) {
                return 'Polish';
            }
        }

        $spanishHints = ['hola', 'gracias', 'manana', 'por favor', 'buenos', 'buenas', 'que tal'];
        foreach ($spanishHints as $hint) {
            if ($this->containsHintWord($normalized, $hint)) {
                return 'Spanish';
            }
        }

        $englishHints = ['hello', 'hi', 'please', 'can', 'you', 'your', 'send', 'project', 'schedule', 'tasks', 'message', 'thanks'];
        $englishHits = 0;

        foreach ($englishHints as $hint) {
            if ($this->containsHintWord($normalized, $hint)) {
                $englishHits++;
            }
        }

        if ($englishHits >= 2) {
            return 'English';
        }

        return null;
    }

    private function containsHintWord(string $normalizedText, string $hint): bool
    {
        $pattern = '/\b' . preg_quote($hint, '/') . '\b/u';

        return preg_match($pattern, $normalizedText) === 1;
    }

    private function looksLikeTranslationPromptArtifact(string $text): bool
    {
        $normalized = strtolower(trim($text));

        return str_contains($normalized, 'please provide')
            && str_contains($normalized, 'translated');
    }

    protected function preferredLanguageForUser(?User $user): string
    {
        return $this->normalizeLanguage((string) ($user?->preferred_language ?: 'English'));
    }

    protected function threadRecipientLanguage(SmsGroupThread $thread): string
    {
        if ($thread->subject_vendor_id) {
            $language = $thread->subjectVendor?->users
                ?->pluck('preferred_language')
                ->filter(fn ($value) => is_string($value) && trim($value) !== '')
                ->first();

            return $this->normalizeLanguage((string) ($language ?: 'English'));
        }

        $language = $thread->client?->users
            ?->pluck('preferred_language')
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->first();

        return $this->normalizeLanguage((string) ($language ?: 'English'));
    }

    protected function normalizeLanguage(string $language): string
    {
        return app(SmsTranslationService::class)->normalizeLanguage($language);
    }

    /**
     * Normalize text used when matching tapback quoted text to actual thread messages.
     */
    private function normalizeTapbackMatchText(string $text): string
    {
        $text = trim($text);
        if ($text === '') {
            return '';
        }

        $text = $this->repairMojibakeForTapbackMatch($text);
        $text = Str::of($text)
            ->lower()
            ->ascii()
            ->replaceMatches('/[^a-z0-9\s]/u', ' ')
            ->replaceMatches('/\s+/u', ' ')
            ->trim()
            ->value();

        return $text;
    }

    /**
     * Lightweight mojibake repair for quote/body mismatches during tapback matching.
     */
    private function repairMojibakeForTapbackMatch(string $text): string
    {
        if (! preg_match('/[ÃÂâðÄÅ]/u', $text)) {
            return $text;
        }

        $candidates = [
            $text,
            @mb_convert_encoding($text, 'Windows-1252', 'UTF-8'),
            @mb_convert_encoding($text, 'ISO-8859-1', 'UTF-8'),
        ];

        $score = static function (string $value): int {
            $penalty = preg_match_all('/[ÃÂâðÄÅ]/u', $value, $m);
            $signal = preg_match_all('/["“”„óęąśłżźćń]/u', $value, $m2);

            return (int) $signal - ((int) $penalty * 2);
        };

        $best = $text;
        $bestScore = $score($text);

        foreach ($candidates as $candidate) {
            if (! is_string($candidate) || $candidate === '') {
                continue;
            }

            if (! mb_check_encoding($candidate, 'UTF-8')) {
                continue;
            }

            $candidateScore = $score($candidate);
            if ($candidateScore > $bestScore) {
                $best = $candidate;
                $bestScore = $candidateScore;
            }
        }

        $clean = @iconv('UTF-8', 'UTF-8//IGNORE', $best);

        return is_string($clean) && $clean !== '' ? $clean : $best;
    }

    /**
     * Build a publicly reachable webhook URL for Telnyx voice callbacks.
     */
    protected function telnyxWebhookUrl(): string
    {
        $appUrl = config('app.url');

        if (str_contains($appUrl, '127.0.0.1') || str_contains($appUrl, 'localhost')) {
            $publicUrl = config('services.telnyx.public_url');

            if ($publicUrl) {
                return rtrim($publicUrl, '/') . '/webhooks/telnyx/voice';
            }
        }

        return rtrim($appUrl, '/') . '/webhooks/telnyx/voice';
    }

    public function render()
    {
        // Keep the poll fingerprint in sync with what this render shows so
        // pollForUpdates() only repaints on real changes.
        $this->pollFingerprint = $this->threadId ? $this->messagesFingerprint() : null;

        // Snapshot the visible conversation to localStorage so the next time the
        // user navigates back to this thread we can paint it instantly while
        // Livewire re-hydrates in the background.
        if ($this->threadId) {
            $snapshot = collect($this->processedMessages)
                ->take(-30)
                ->map(fn ($m) => [
                    'id' => $m['id'] ?? null,
                    'direction' => $m['direction'] ?? null,
                    'from_number' => $m['from_number'] ?? null,
                    'text' => mb_substr((string) ($m['text'] ?? $m['display_text'] ?? ''), 0, 500),
                    'created_at' => isset($m['created_at']) ? (string) $m['created_at'] : null,
                    'media_urls' => $m['media_urls'] ?? [],
                ])
                ->values()
                ->all();

            $this->js(sprintf(
                "(function(){ try { localStorage.setItem('hive-sms-thread-%d', JSON.stringify(%s)); } catch(e) {} })()",
                $this->threadId,
                json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ));
        }

        return view('livewire.sms.conversation');
    }

    public function placeholder()
    {
        return view('livewire.sms.conversation_placeholder');
    }

    /**
     * Whether the phone belongs to a linked contact (user or vendor). CNAM
     * names from call logs do NOT count — those threads show the number.
     */
    public function isKnownContact(string $e164): bool
    {
        static $cache = [];

        if (isset($cache[$e164])) {
            return $cache[$e164];
        }

        $digits = preg_replace('/[^0-9]/', '', $e164);

        $normalized = $digits;
        if (strlen($normalized) === 11 && str_starts_with($normalized, '1')) {
            $normalized = substr($normalized, 1);
        }

        $last10 = strlen($digits) > 10 ? substr($digits, -10) : $digits;

        $userExists = User::where('cell_phone', $normalized)
            ->orWhere('cell_phone', '1' . $normalized)
            ->orWhere('cell_phone', $digits)
            ->orWhere('cell_phone', $last10)
            ->exists();

        if ($userExists) {
            return $cache[$e164] = true;
        }

        return $cache[$e164] = Vendor::where('business_phone', $normalized)
            ->orWhere('business_phone', $last10)
            ->orWhere('business_phone', $digits)
            ->exists();
    }

    /**
     * Resolve a display name for an E.164 phone number.
     */
    public function resolvePhoneDisplay(string $e164): string
    {
        static $cache = [];

        if (isset($cache[$e164])) {
            return $cache[$e164];
        }

        $digits = preg_replace('/[^0-9]/', '', $e164);

        $normalized = $digits;
        if (strlen($normalized) === 11 && str_starts_with($normalized, '1')) {
            $normalized = substr($normalized, 1);
        }

        $last10 = strlen($digits) > 10 ? substr($digits, -10) : $digits;

        $user = User::where('cell_phone', $normalized)
            ->orWhere('cell_phone', '1' . $normalized)
            ->orWhere('cell_phone', $digits)
            ->orWhere('cell_phone', $last10)
            ->first();

        if ($user && trim($this->preferredUserDisplayName($user, true)) !== '') {
            return $cache[$e164] = $this->preferredUserDisplayName($user, true);
        }

        $vendor = Vendor::where('business_phone', $normalized)
            ->orWhere('business_phone', $last10)
            ->orWhere('business_phone', $digits)
            ->first();

        if ($vendor && $vendor->short_name) {
            return $cache[$e164] = $vendor->short_name;
        }

        // Fall back to the latest CNAM captured on a call log, matching the
        // calls tab (HasCallActions::resolvePhoneDisplay).
        $callLogName = CallLog::query()
            ->where(fn ($q) => $q->where('from_number', $e164)->orWhere('to_number', $e164))
            ->whereNotNull('caller_name')
            ->whereNotIn('caller_name', ['Incoming Call', 'Outgoing Call'])
            ->latest()
            ->value('caller_name');

        if (is_string($callLogName) && trim($callLogName) !== '') {
            return $cache[$e164] = CallLog::formatCallerNameForDisplay($callLogName);
        }

        $display10 = strlen($normalized) === 10 ? $normalized : $last10;
        if (strlen($display10) === 10) {
            return $cache[$e164] = '(' . substr($display10, 0, 3) . ') ' . substr($display10, 3, 3) . '-' . substr($display10, 6);
        }

        return $cache[$e164] = $e164;
    }

    protected function preferredUserDisplayName(User $user, bool $includeLastName = true): string
    {
        $first = trim((string) ($user->nickname ?: $user->first_name));

        if (! $includeLastName) {
            return $first;
        }

        return trim($first . ' ' . trim((string) $user->last_name));
    }

    public function clientDisplayNameForThread(?SmsGroupThread $thread): string
    {
        if (! $thread?->client) {
            return '';
        }

        if (trim((string) $thread->client->business_name) !== '') {
            return (string) $thread->client->name;
        }

        $users = $this->threadClientUsersFor($thread->client);

        if ($users->isEmpty()) {
            return (string) $thread->client->name;
        }

        if ($users->count() === 1) {
            return $this->preferredUserDisplayName($users->first(), true);
        }

        $nameGroups = $users
            ->groupBy(fn (User $user) => trim((string) ($user->last_name ?? '')))
            ->map(function ($lastNameGroup, $lastName) {
                if ($lastNameGroup->count() === 1) {
                    return $this->preferredUserDisplayName($lastNameGroup->first(), true);
                }

                $firstNames = $lastNameGroup
                    ->map(fn (User $user) => $this->preferredUserDisplayName($user, false))
                    ->filter()
                    ->values()
                    ->all();

                return trim($this->oxfordJoin($firstNames) . ' ' . $lastName);
            })
            ->filter()
            ->values()
            ->all();

        return $this->oxfordJoin($nameGroups);
    }

    protected function oxfordJoin(array $items): string
    {
        return collect($items)->filter()->values()->join(', ', ' & ');
    }

    protected function markThreadAsRead(): void
    {
        if (! $this->threadId || ! auth()->id()) {
            return;
        }

        $latestMessageId = SmsMessage::where('thread_id', $this->threadId)->max('id');

        if (! $latestMessageId || $latestMessageId === $this->lastMarkedMessageId) {
            return;
        }

        SmsThreadRead::updateOrCreate(
            [
                'thread_id' => $this->threadId,
                'user_id' => auth()->id(),
            ],
            [
                'last_read_message_id' => $latestMessageId,
            ]
        );

        $this->lastMarkedMessageId = $latestMessageId;

        // Notify thread list to update unread indicators
        $this->dispatch('sms-thread-read');
    }
}
