<?php

namespace App\Livewire\Projects;

use App\Jobs\SummarizeEmailMessage;
use App\Livewire\Tasks\TaskCreate;
use App\Models\EmailMessage;
use App\Models\Project;
use App\Services\Communications\ProjectCommunicationsTimeline;
use App\Services\Sms\MessageTaskSuggestionBuilder;
use App\Services\SmsTaskExtractionService;
use Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Carbon;
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

    /** @var list<array<string, mixed>> */
    public array $items = [];

    public ?string $nextBefore = null;

    public ?int $openEmailId = null;

    public ?int $openMeetingId = null;

    public bool $showMeetingModal = false;

    public bool $showEmailModal = false;

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
        return in_array($this->kind, ProjectCommunicationsTimeline::ALL_KINDS, true)
            ? [$this->kind]
            : ProjectCommunicationsTimeline::ALL_KINDS;
    }

    protected function loadFirstPage(): void
    {
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
