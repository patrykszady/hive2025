<?php

namespace App\Livewire\Emails;

use App\Jobs\SummarizeEmailMessage;
use App\Models\CompanyEmail;
use App\Models\EmailMessage;
use App\Models\Project;
use App\Services\Emails\EmailPlacement;
use Flux\Flux;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * The unplaced email inbox (/emails/unplaced): stakeholder emails the AI read
 * but could not match to a project. An Admin opens each one, puts it on a
 * project (its candidates first, or any open project), or dismisses it as
 * not about any project — EmailPlacement does the writing, the same one the
 * Hive Contractors app uses.
 */
class UnplacedEmails extends Component
{
    use AuthorizesRequests;
    use WithPagination;

    public ?int $openEmailId = null;

    public bool $showEmailModal = false;

    public ?int $otherProjectId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', CompanyEmail::class);
    }

    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, EmailMessage> */
    #[Computed]
    public function emails()
    {
        return $this->query()->orderByDesc('sent_at')->paginate(25);
    }

    public function open(int $emailMessageId): void
    {
        if (! $this->query()->whereKey($emailMessageId)->exists()) {
            return;
        }

        $this->openEmailId = $emailMessageId;
        $this->otherProjectId = null;
        $this->resetErrorBag();
        $this->showEmailModal = true;
    }

    #[Computed]
    public function selectedEmail(): ?EmailMessage
    {
        return $this->openEmailId ? $this->query()->find($this->openEmailId) : null;
    }

    #[Computed]
    public function selectedEmailLatestPart(): string
    {
        return $this->selectedEmail ? SummarizeEmailMessage::latestPart((string) $this->selectedEmail->body_text) : '';
    }

    #[Computed]
    public function selectedEmailQuotedPart(): string
    {
        return $this->selectedEmail ? trim(Str::after((string) $this->selectedEmail->body_text, $this->selectedEmailLatestPart)) : '';
    }

    /** @return Collection<int, Project> */
    #[Computed]
    public function candidates(): Collection
    {
        return $this->selectedEmail ? app(EmailPlacement::class)->candidates($this->selectedEmail) : collect();
    }

    /** @return Collection<int, Project> */
    #[Computed]
    public function openProjects(): Collection
    {
        return app(EmailPlacement::class)->openProjects($this->vendorId());
    }

    public function assign(int $projectId): void
    {
        $email = $this->selectedEmail;

        if (! $email) {
            return;
        }

        app(EmailPlacement::class)->place($email, $projectId, auth()->user());

        $project = Project::withoutGlobalScopes()->find($projectId);
        $this->closeAndRefresh();

        Flux::toast(variant: 'success', heading: 'Email placed', text: 'Now on '.($project?->project_name ?: 'the project').'.', duration: 3000);
    }

    public function assignOther(): void
    {
        $this->validate(
            ['otherProjectId' => ['required', 'integer']],
            ['otherProjectId.required' => 'Choose a project.'],
        );

        $this->assign((int) $this->otherProjectId);
    }

    public function dismiss(): void
    {
        $email = $this->selectedEmail;

        if (! $email) {
            return;
        }

        app(EmailPlacement::class)->dismiss($email);
        $this->closeAndRefresh();

        Flux::toast(heading: 'Dismissed', text: 'Not about a project — it is out of the inbox.', duration: 3000);
    }

    protected function closeAndRefresh(): void
    {
        $this->showEmailModal = false;
        $this->openEmailId = null;
        $this->otherProjectId = null;
        unset($this->emails, $this->selectedEmail, $this->candidates);
    }

    protected function query(): Builder
    {
        return EmailMessage::query()
            ->where('belongs_to_vendor_id', $this->vendorId())
            ->unplaced();
    }

    protected function vendorId(): int
    {
        return (int) auth()->user()->vendor?->id;
    }

    #[Title('Unplaced Email')]
    public function render(): View
    {
        return view('livewire.emails.unplaced-emails');
    }
}
