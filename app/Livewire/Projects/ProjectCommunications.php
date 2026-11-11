<?php

namespace App\Livewire\Projects;

use App\Jobs\SummarizeEmailMessage;
use App\Livewire\Tasks\TaskCreate;
use App\Models\EmailMessage;
use App\Models\Project;
use App\Services\Communications\ProjectCommunicationsSearch;
use App\Services\Communications\ProjectCommunicationsTimeline;
use App\Services\Sms\MessageTaskSuggestionBuilder;
use App\Services\SmsTaskExtractionService;
use Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Calls, texts, emails and in-person meetings with a project's stakeholders, newest first, in
 * one card — a thin Livewire wrapper around
 * {@see ProjectCommunicationsTimeline}, which already authorizes nothing
 * itself. Same visibility as the Email Tracking card (show.blade.php wraps
 * both in @can('update', $project)): the owning vendor's Admins only, so
 * re-checked here too in case this component is ever mounted directly.
 */
class ProjectCommunications extends Component
{
    use AuthorizesRequests;

    /** The card opens on the latest few, so it doesn't take over the project page. */
    public const FIRST_PAGE = 5;

    /** Each "Load more" adds this many. */
    public const PER_PAGE = 10;

    public Project $project;

    /** 'all', or one of ProjectCommunicationsTimeline::KINDS. */
    public string $kind = 'all';

    /**
     * Words to find in everything said on the project (Meilisearch through
     * ProjectCommunicationsSearch). Empty = the plain timeline.
     */
    public string $search = '';

    /** @var list<array<string, mixed>> */
    public array $items = [];

    public ?string $nextBefore = null;

    public ?int $openEmailId = null;

    public ?int $openMeetingId = null;

    public bool $showMeetingModal = false;

    public bool $showEmailModal = false;

    public ?int $openCallId = null;

    public bool $showCallModal = false;

    public ?int $openTextId = null;

    public bool $showTextModal = false;

    /** @var array<string, string> */
    protected $listeners = ['refreshComponent' => '$refresh'];

    public function mount(): void
    {
        $this->authorize('update', $this->project);

        $this->loadFirstPage();
    }

    public function updatedKind(): void
    {
        $this->loadFirstPage();
    }

    public function updatedSearch(): void
    {
        // "Action items" is a tab only a search has.
        if (! $this->isSearching() && $this->kind === ProjectCommunicationsSearch::ACTION_ITEM) {
            $this->kind = 'all';
        }

        $this->loadFirstPage();
    }

    /** A search needs two characters; less is the plain timeline. */
    public function isSearching(): bool
    {
        return mb_strlen(trim($this->search)) >= 2;
    }

    /**
     * Open what an action item came from, when that is an email or a
     * meeting (both open in place; a call or text has its own page).
     */
    public function openActionItemSource(string $kind, int $id): void
    {
        match ($kind) {
            'email' => $this->selectEmail($id),
            'meeting' => $this->selectMeeting($id),
            'call' => $this->selectCall($id),
            'sms' => $this->selectText($id),
            default => null,
        };
    }

    /**
     * Open a call in place (a search result): its summary, action items and
     * transcript with the searched words marked. Only a call this project's
     * timeline shows.
     */
    public function selectCall(int $callLogId): void
    {
        if ($this->timelineItem('call', $callLogId) === null) {
            return;
        }

        $this->openCallId = $callLogId;
        $this->showCallModal = true;
    }

    /**
     * The open call: its timeline row (who, direction, duration) and the
     * record itself — re-checked against the project on every render.
     *
     * @return array{item: array<string, mixed>, call: \App\Models\CallLog}|null
     */
    #[Computed]
    public function selectedCall(): ?array
    {
        $item = $this->openCallId ? $this->timelineItem('call', $this->openCallId) : null;
        $call = $item ? \App\Models\CallLog::query()->with('transcript')->find($this->openCallId) : null;

        return $call ? ['item' => $item, 'call' => $call] : null;
    }

    /** Open a text in place (a search result), with the few messages around it. */
    public function selectText(int $smsMessageId): void
    {
        if ($this->timelineItem('sms', $smsMessageId) === null) {
            return;
        }

        $this->openTextId = $smsMessageId;
        $this->showTextModal = true;
    }

    /**
     * The open text inside its conversation: up to five messages before and
     * after it in the same thread, oldest first, each with its full words.
     *
     * @return array{thread_id: int, messages: list<array<string, mixed>>}|null
     */
    #[Computed]
    public function selectedText(): ?array
    {
        $item = $this->openTextId ? $this->timelineItem('sms', $this->openTextId) : null;

        if (! $item) {
            return null;
        }

        $inThread = fn () => \App\Models\SmsMessage::query()->where('thread_id', $item['thread_id']);
        $ids = collect([$this->openTextId])
            ->concat($inThread()->where('id', '<', $this->openTextId)->orderByDesc('id')->limit(5)->pluck('id'))
            ->concat($inThread()->where('id', '>', $this->openTextId)->orderBy('id')->limit(5)->pluck('id'));

        $rows = app(ProjectCommunicationsTimeline::class)
            ->forProject($this->project, null, 20, ['sms'], ['sms' => fn (Builder $query) => $query->whereIn('sms_messages.id', $ids)])['items'];
        $words = \App\Models\SmsMessage::query()->whereIn('id', $ids)->get()->keyBy('id');

        return [
            'thread_id' => (int) $item['thread_id'],
            'messages' => collect($rows)
                ->sortBy('id')
                ->map(fn (array $row) => $row + ['text' => (string) ($words[$row['id']]->display_text ?? $words[$row['id']]->text ?? '')])
                ->values()
                ->all(),
        ];
    }

    /**
     * One call or text as this project's timeline shows it, or null when it
     * is not this project's — the check every "open in place" goes through.
     *
     * @return array<string, mixed>|null
     */
    protected function timelineItem(string $kind, int $id): ?array
    {
        return app(ProjectCommunicationsTimeline::class)
            ->forProject($this->project, null, 1, [$kind], [$kind => fn (Builder $query) => $query->whereKey($id)])['items'][0] ?? null;
    }

    /** A row's excerpt with the searched words marked. */
    public function highlight(string $text): HtmlString
    {
        $words = array_map(fn (string $word) => preg_quote($word, '/'), ProjectCommunicationsSearch::words($this->search));

        if ($words === []) {
            return new HtmlString(e($text));
        }

        $parts = preg_split('/('.implode('|', $words).')/iu', $text, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$text];

        return new HtmlString(collect($parts)
            ->map(fn (string $part, int $index) => $index % 2 === 1
                ? '<mark class="rounded bg-yellow-200 px-0.5 text-zinc-900 dark:bg-yellow-500/40 dark:text-zinc-100">'.e($part).'</mark>'
                : e($part))
            ->implode(''));
    }

    public function loadMore(): void
    {
        if (! $this->nextBefore) {
            return;
        }

        $page = app(ProjectCommunicationsTimeline::class)->forProject(
            $this->project,
            Carbon::parse($this->nextBefore),
            self::PER_PAGE,
            $this->kinds(),
        );

        $this->items = array_merge($this->items, $page['items']);
        $this->nextBefore = $page['next_before'];
    }

    /**
     * Open the email detail modal — only for an email this viewer may
     * already see (their own vendor, this project). An id that fails that
     * check (another project, another vendor) is silently ignored, same as
     * a stale row clicked after a delete.
     */
    public function selectEmail(int $emailMessageId): void
    {
        $email = $this->emailQuery()->find($emailMessageId);

        if (! $email) {
            return;
        }

        $this->openEmailId = $email->id;
        $this->showEmailModal = true;
    }

    /**
     * The email open in the modal — re-checked against this project and the
     * viewer's vendor on every render, never trusted from the id alone.
     */
    #[Computed]
    public function selectedEmail(): ?EmailMessage
    {
        if (! $this->openEmailId) {
            return null;
        }

        return $this->emailQuery()->find($this->openEmailId);
    }

    /**
     * "Create Task" from the open email — the same suggestion a text gets
     * (MessageTaskSuggestionBuilder, SmsTaskExtractionService), opened in
     * the task dialog for review: an email that sets the counter measure
     * for Thursday 10/29, 11-1 fills in the project's "Counter Measure".
     */
    public function createTaskFromEmail(int $emailMessageId, SmsTaskExtractionService $extractor): void
    {
        $this->authorize('update', $this->project);

        $email = $this->emailQuery()->find($emailMessageId);

        if (! $email) {
            return;
        }

        $result = app(MessageTaskSuggestionBuilder::class)->suggestForEmail($email, $extractor);

        if (! $result['ok']) {
            Flux::toast(variant: 'warning', heading: $result['heading'], text: $result['text'], duration: $result['duration'] ?? 4000, position: 'top right');

            return;
        }

        $this->showEmailModal = false;
        $this->dispatch('prefillTaskFromSms', payload: $result['payload'])->to(TaskCreate::class);
    }

    /** The new part of the open email, without the quoted history below it. */
    #[Computed]
    public function selectedEmailLatestPart(): string
    {
        $email = $this->selectedEmail;

        return $email ? SummarizeEmailMessage::latestPart((string) $email->body_text) : '';
    }

    /** Whatever the latest part left behind — the quoted history, if any. */
    #[Computed]
    public function selectedEmailQuotedPart(): string
    {
        $email = $this->selectedEmail;

        if (! $email) {
            return '';
        }

        return trim(Str::after((string) $email->body_text, $this->selectedEmailLatestPart));
    }

    /** Open an in-person meeting's summary, action items and transcript — this project's only. */
    public function selectMeeting(int $meetingRecordingId): void
    {
        if (! \App\Models\MeetingRecording::query()->whereKey($meetingRecordingId)->where('project_id', $this->project->id)->exists()) {
            return;
        }

        $this->openMeetingId = $meetingRecordingId;
        $this->showMeetingModal = true;
    }

    #[Computed]
    public function selectedMeeting(): ?\App\Models\MeetingRecording
    {
        return $this->openMeetingId
            ? \App\Models\MeetingRecording::query()->with('recordedBy')->whereKey($this->openMeetingId)->where('project_id', $this->project->id)->first()
            : null;
    }

    protected function emailQuery(): Builder
    {
        return EmailMessage::query()
            ->where('belongs_to_vendor_id', $this->project->belongs_to_vendor_id)
            ->forProject($this->project);
    }

    /** @return list<string> */
    protected function kinds(): array
    {
        $all = $this->isSearching() ? ProjectCommunicationsSearch::KINDS : ProjectCommunicationsTimeline::ALL_KINDS;

        return in_array($this->kind, $all, true) ? [$this->kind] : $all;
    }

    protected function loadFirstPage(): void
    {
        if ($this->isSearching()) {
            $this->items = app(ProjectCommunicationsSearch::class)->search($this->project, $this->search, $this->kinds());
            $this->nextBefore = null;

            return;
        }

        $page = app(ProjectCommunicationsTimeline::class)->forProject($this->project, null, self::FIRST_PAGE, $this->kinds());

        $this->items = $page['items'];
        $this->nextBefore = $page['next_before'];
    }

    /**
     * An item's timestamp in America/Chicago: relative ("2h ago") for
     * today, the plain date otherwise.
     */
    public function formatWhen(string $iso): string
    {
        $at = Carbon::parse($iso)->setTimezone('America/Chicago');

        return $at->isToday() ? $at->diffForHumans(short: true) : $at->format('M j, Y');
    }

    public function render(): View
    {
        return view('livewire.projects.project-communications');
    }

    public function placeholder(): View
    {
        return view('livewire.projects.project-communications-placeholder');
    }
}
